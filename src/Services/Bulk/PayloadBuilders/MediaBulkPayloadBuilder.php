<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\ProductPhaseDataService;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class MediaBulkPayloadBuilder
{
    use ShopifyGraphqlRequest;

    /**
     * entityType used to store media mappings in wk_shopify_data_mapping.
     *
     * A media mapping row is keyed by:
     *   - code          => "<attribute><CODE_SEPARATOR><image path>"
     *   - relatedSource => product SKU
     *   - externalId    => Shopify Media ID
     *   - relatedId     => Shopify Product ID
     *   - apiUrl        => shop URL
     *
     * The attribute code and the source image path are concatenated into the
     * existing `code` column (no schema change) so a mapping is uniquely
     * identified by both — letting re-exports skip media whose path is unchanged.
     */
    protected const MEDIA_ENTITY_TYPE = 'productImage';

    /**
     * Separator joining the attribute code and image path inside `code`.
     * Chosen to be absent from attribute codes and storage paths.
     */
    protected const CODE_SEPARATOR = '|';

    /**
     * Per-line media plan recorded during build().
     *
     * MediaPhaseService persists this in the phase manifest so BulkResultFinalizer
     * can map the Shopify media IDs returned by productCreateMedia back to their
     * mapping `code` (attribute + path) and store the mapping.
     *
     * Shape: [ lineIndex => ['productId' => string, 'items' => [['sku','code','alt'], ...]] ]
     *
     * @var array<int, array>
     */
    protected array $mediaPlan = [];

    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService,
        protected ShopifyMappingRepository $shopifyMappingRepository,
    ) {}

    /**
     * Build JSONL payload lines for productCreateMedia.
     *
     * One product per line:
     * {
     *   "productId": "gid://shopify/Product/123",
     *   "media": [
     *     { "originalSource": "https://...", "mediaContentType": "IMAGE", "alt": "..." }
     *   ]
     * }
     *
     * For each media the (attribute, image path) pair is matched against the
     * stored mappings:
     *   - same attribute + same path  => skip entirely (no unnecessary update)
     *   - same attribute, path changed => update the existing Shopify media
     *   - attribute not mapped yet     => create the media (bulk line)
     *
     * @param  array  $entries  productSet entries from core bulk result
     * @param  array  $credential  credential array used for the inline media-update calls
     */
    public function build(array $entries, int $credentialId, ?string $shopUrl = null, string $channel = 'default', string $currency = 'USD', array $credential = []): array
    {
        $this->mediaPlan = [];
        $lines = [];

        // Cache parsed mappings per SKU within this build to avoid repeat queries.
        $mappingsBySku = [];

        foreach ($entries as $entry) {
            $productSku = $entry['manifest']['product_sku'] ?? null;

            if (! $productSku) {
                continue;
            }

            // Prefer the bulk-result product id. If the line failed (e.g. stale
            // mapping), BulkResultFinalizer may have recreated the product
            // out-of-band and written the new id to wk_shopify_data_mapping.
            // Fall back to that mapping so recreated products still get media.
            $productId = $entry['product']['id'] ?? null;

            if (empty($productId)) {
                $productId = $this->resolveProductIdFromMapping($productSku, $shopUrl);
            }

            if (empty($productId)) {
                continue;
            }

            $productId = $this->ensureGid($productId, 'Product');
            $variantSkus = $entry['manifest']['variant_skus'] ?? [];

            $desiredMedia = $this->collectMediaForProduct($productSku, $variantSkus, $credentialId, $channel, $currency);

            if (empty($desiredMedia)) {
                continue;
            }

            $createMedia = [];
            $updateMedia = [];
            $codeRefresh = [];
            $planItems = [];

            foreach ($desiredMedia as $item) {
                $sku = $item['sku'];
                $attribute = $item['code'];
                $path = $item['path'];
                $alt = $sku.' - '.$attribute;

                $mappings = $mappingsBySku[$sku]
                    ??= $this->getMediaMappings($sku, $shopUrl);

                [$exact, $byAttribute] = $this->matchMapping($mappings, $attribute, $path);

                if ($exact) {
                    // Mapping already exists for this image path + attribute —
                    // nothing changed, skip the media update entirely.
                    continue;
                }

                $compositeCode = $this->buildCode($attribute, $path);

                if ($byAttribute && ! empty($byAttribute['row']->externalId)) {
                    // Media already exists for this attribute but the image path
                    // changed — update the existing Shopify media in place.
                    $updateMedia[] = [
                        'id' => $byAttribute['row']->externalId,
                        'previewImageSource' => $item['url'],
                        'alt' => $alt,
                    ];

                    // Refresh the mapping `code` with the new path once the
                    // update succeeds (see updateExistingMedia below).
                    $codeRefresh[] = [
                        'id' => $byAttribute['row']->id,
                        'code' => $compositeCode,
                    ];

                    continue;
                }

                // No mapping for this attribute — create the media. The finalizer
                // stores the mapping (composite code) once Shopify returns the id.
                $createMedia[] = [
                    'originalSource' => $item['url'],
                    'mediaContentType' => 'IMAGE',
                    'alt' => $alt,
                ];

                $planItems[] = [
                    'sku' => $sku,
                    'code' => $compositeCode,
                    'alt' => $alt,
                ];
            }

            if (! empty($updateMedia) && $this->updateExistingMedia($productId, $updateMedia, $credential)) {
                foreach ($codeRefresh as $refresh) {
                    $this->shopifyMappingRepository->update(['code' => $refresh['code']], $refresh['id']);
                }
            }

            if (empty($createMedia)) {
                continue;
            }

            $lineIndex = count($lines);

            $lines[] = json_encode([
                'productId' => $productId,
                'media' => $createMedia,
            ], JSON_UNESCAPED_SLASHES);

            $this->mediaPlan[$lineIndex] = [
                'productId' => $productId,
                'items' => $planItems,
            ];
        }

        return $lines;
    }

    /**
     * The per-line media plan recorded by the most recent build() call.
     */
    public function getMediaPlan(): array
    {
        return $this->mediaPlan;
    }

    /**
     * Look up a Shopify productId from the local mapping table.
     */
    protected function resolveProductIdFromMapping(string $sku, ?string $shopUrl): ?string
    {
        if (empty($shopUrl)) {
            return null;
        }

        $mapping = $this->shopifyMappingRepository
            ->where('code', $sku)
            ->where('entityType', 'product')
            ->where('apiUrl', $shopUrl)
            ->first();

        return $mapping?->externalId ?: null;
    }

    /**
     * Fetch and parse all media mappings stored for a SKU.
     *
     * @return array<int, array{row: object, attribute: string, path: string}>
     */
    protected function getMediaMappings(string $sku, ?string $shopUrl): array
    {
        if (empty($shopUrl)) {
            return [];
        }

        $rows = $this->shopifyMappingRepository
            ->where('entityType', self::MEDIA_ENTITY_TYPE)
            ->where('relatedSource', $sku)
            ->where('apiUrl', $shopUrl)
            ->get();

        $mappings = [];

        foreach ($rows as $row) {
            [$attribute, $path] = $this->parseCode($row->code);

            $mappings[] = [
                'row' => $row,
                'attribute' => $attribute,
                'path' => $path,
            ];
        }

        return $mappings;
    }

    /**
     * Match a desired media against stored mappings.
     *
     * @return array{0: ?array, 1: ?array} [exact (attribute + path), byAttribute (attribute only)]
     */
    protected function matchMapping(array $mappings, string $attribute, string $path): array
    {
        $exact = null;
        $byAttribute = null;

        foreach ($mappings as $mapping) {
            if ($mapping['attribute'] !== $attribute) {
                continue;
            }

            $byAttribute = $mapping;

            if ($mapping['path'] === $path) {
                $exact = $mapping;

                break;
            }
        }

        return [$exact, $byAttribute];
    }

    /**
     * Join an attribute code and image path into a single `code` value.
     */
    protected function buildCode(string $attribute, string $path): string
    {
        return $attribute.self::CODE_SEPARATOR.$path;
    }

    /**
     * Split a stored `code` back into [attribute, path].
     *
     * Legacy rows without a path keep the whole value as the attribute.
     *
     * @return array{0: string, 1: string}
     */
    protected function parseCode(?string $code): array
    {
        $code = (string) $code;
        $position = strpos($code, self::CODE_SEPARATOR);

        if ($position === false) {
            return [$code, ''];
        }

        return [substr($code, 0, $position), substr($code, $position + 1)];
    }

    /**
     * Update already-mapped media on Shopify when its source path changed.
     *
     * @return bool whether the update completed without errors
     */
    protected function updateExistingMedia(string $productId, array $media, array $credential): bool
    {
        if (empty($credential)) {
            return false;
        }

        try {
            $response = $this->requestGraphQlApiAction('productUpdateMedia', $credential, [
                'productId' => $productId,
                'media' => $media,
            ]);
        } catch (\Throwable $e) {
            return false;
        }

        $payload = $response['body']['data']['productUpdateMedia'] ?? [];

        return empty($payload['mediaUserErrors']) && ! empty($payload['media']);
    }

    /**
     * Resolve the desired media for a product and its variants.
     *
     * @return array<int, array{sku: string, code: string, path: string, url: string}>
     */
    protected function collectMediaForProduct(string $productSku, array $variantSkus, int $credentialId, string $channel, string $currency, array $exportedAlts = []): array
    {
        $context = $this->productPhaseDataService->getProductContext($productSku, $credentialId, $channel, $currency);

        if (! $context) {
            return [];
        }

        $mediaMapping = $context['export_mapping']->mapping['mediaMapping'] ?? [];

        if (empty($mediaMapping['mediaAttributes'])) {
            return [];
        }

        $attributeCodes = array_filter(array_map('trim', explode(',', (string) $mediaMapping['mediaAttributes'])));

        if (empty($attributeCodes)) {
            return [];
        }

        $mediaType = $mediaMapping['mediaType'] ?? 'image';
        $items = [];

        $skuList = array_values(array_unique(array_merge([$productSku], array_filter($variantSkus))));

        foreach ($skuList as $sku) {
            $skuContext = $sku === $productSku
                ? $context
                : $this->productPhaseDataService->getProductContext($sku, $credentialId, $channel, $currency);

            if (! $skuContext) {
                continue;
            }

            foreach ($attributeCodes as $code) {
                $rawValue = $skuContext['merged_fields'][$code] ?? null;

                if (empty($rawValue)) {
                    continue;
                }

                if ($mediaType === 'gallery') {
                    // Gallery: one media per slot — keyed "code_<index>" to match
                    // the convention used by the non-bulk export path.
                    $paths = is_array($rawValue) ? array_values($rawValue) : [$rawValue];

                    foreach ($paths as $index => $path) {
                        $resolved = $this->resolveMedia($path);

                        if ($resolved !== null) {
                            $items[] = [
                                'sku' => $sku,
                                'code' => $code.'_'.$index,
                                'path' => $resolved['path'],
                                'url' => $resolved['url'],
                            ];
                        }
                    }

                    continue;
                }

                $path = is_array($rawValue) ? ($rawValue[0] ?? '') : $rawValue;
                $resolved = $this->resolveMedia($path);

                if ($resolved !== null) {
                    $items[] = [
                        'sku' => $sku,
                        'code' => $code,
                        'path' => $resolved['path'],
                        'url' => $resolved['url'],
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Normalize a stored media path and resolve it to a public URL.
     *
     * @return array{path: string, url: string}|null
     */
    protected function resolveMedia(mixed $path): ?array
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $normalizedPath = ltrim($path, '/');
        $fullUrl = Storage::url(str_replace(' ', '%20', $normalizedPath));

        if (empty($fullUrl)) {
            return null;
        }

        return ['path' => $normalizedPath, 'url' => $fullUrl];
    }

    protected function ensureGid(string $id, string $type): string
    {
        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$type}/{$id}";
    }
}
