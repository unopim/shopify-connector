<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\DAM\Models\Directory;
use Webkul\DAM\Repositories\AssetRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\Bulk\Media\AssetUrlResolver;
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

    /**
     * Code-refresh plan recorded during build().
     *
     * Maps each updated Shopify media GID to the mapping row whose `code` (attribute
     * + path) must be rewritten once the bulk productUpdateMedia completes, so a later
     * export sees the new path and does not re-update. Persisted in the phase manifest
     * for BulkResultFinalizer.
     *
     * Shape: [ "gid://shopify/MediaImage/123" => ['rowId' => int, 'code' => string] ]
     *
     * @var array<string, array{rowId: int, code: string}>
     */
    protected array $updatePlan = [];

    /**
     * Update JSONL lines recorded during build().
     *
     * One productUpdateMedia line per product whose attribute-mapped image path
     * changed — computed in the SAME pass as the create lines (no second diff /
     * getProductContext pass). The media phase persists these so the media_update
     * phase can run them via bulk without recomputing.
     *
     * @var array<int, string>
     */
    protected array $updateLines = [];

    /**
     * Mime types resolved as Shopify IMAGE media when expanding DAM assets.
     * Mirrors the non-bulk export path's allow-list.
     */
    protected array $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];

    /**
     * Lazily-resolved DAM AssetRepository, or null when DAM is not installed.
     */
    protected ?AssetRepository $resolvedAssetRepository = null;

    protected bool $assetRepositoryResolved = false;

    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected AssetUrlResolver $assetUrlResolver,
    ) {}

    /**
     * Resolve the DAM AssetRepository on demand, or null when DAM is absent.
     *
     * Resolved lazily rather than constructor-injected: DAM is an optional
     * module, and a nullable constructor default would make Laravel's container
     * short-circuit the parameter to null anyway — it only auto-builds defaulted
     * params for *bound* classes, and the concrete AssetRepository is not bound.
     * A direct container make() builds it correctly.
     */
    protected function assetRepository(): ?AssetRepository
    {
        if (! $this->assetRepositoryResolved) {
            $this->assetRepositoryResolved = true;

            if (class_exists(AssetRepository::class)) {
                try {
                    $this->resolvedAssetRepository = app(AssetRepository::class);
                } catch (\Throwable $e) {
                    $this->resolvedAssetRepository = null;
                }
            }
        }

        return $this->resolvedAssetRepository;
    }

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
     * stored mappings, in a SINGLE pass:
     *   - same attribute + same path  => skip entirely (no unnecessary update)
     *   - same attribute, path changed => record an update line (media_update phase)
     *   - attribute not mapped yet     => create the media (bulk line returned here)
     *
     * Both the create lines (returned) and the update lines + plan (recorded for
     * getUpdateLines()/getUpdatePlan()) come from this one pass, so the expensive
     * product-context diff is computed only once per export.
     *
     * @param  array  $entries  productSet entries from core bulk result
     * @param  array  $credential  credential array used for staged video uploads
     */
    public function build(array $entries, int $credentialId, ?string $shopUrl = null, string $channel = 'default', string $currency = 'USD', array $credential = []): array
    {
        $this->mediaPlan = [];
        $this->updateLines = [];
        $this->updatePlan = [];
        $lines = [];

        // Cache parsed mappings per SKU within this build to avoid repeat queries.
        $mappingsBySku = [];

        foreach ($entries as $entry) {
            $resolved = $this->resolveEntryMatches($entry, $credentialId, $shopUrl, $channel, $currency, $mappingsBySku);

            if (! $resolved) {
                continue;
            }

            $productId = $resolved['productId'];
            $createMedia = [];
            $planItems = [];
            $updateMedia = [];

            foreach ($resolved['matches'] as $match) {
                $item = $match['item'];

                if ($match['exact']) {
                    // Mapping already exists for this image path + attribute —
                    // nothing changed, skip entirely.
                    continue;
                }

                $mediaContentType = $item['mediaContentType'] ?? 'IMAGE';

                if ($mediaContentType === 'IMAGE' && $match['byAttribute'] && ! empty($match['byAttribute']['row']->externalId)) {
                    // Media already exists for this attribute but the image path
                    // changed — record an in-place update for the media_update phase.
                    // (Videos cannot be updated via previewImageSource, so they
                    // always fall through to a fresh create here.)
                    $mediaId = $match['byAttribute']['row']->externalId;

                    $updateMedia[] = [
                        'id'                 => $mediaId,
                        'previewImageSource' => $item['url'],
                        'alt'                => $item['sku'].' - '.$item['code'],
                    ];

                    // Refresh the mapping `code` with the new path once the update
                    // completes (BulkResultFinalizer reads this from the manifest).
                    $this->updatePlan[$mediaId] = [
                        'rowId' => $match['byAttribute']['row']->id,
                        'code'  => $this->buildCode($item['code'], $item['path']),
                    ];

                    continue;
                }

                // Resolve the source Shopify will fetch. Videos cannot be served
                // from an arbitrary URL — the bytes must be pushed to a staged
                // upload target first and referenced by its resourceUrl.
                $originalSource = $item['url'] ?? '';

                if ($mediaContentType === 'VIDEO') {
                    $originalSource = $this->stageVideoUpload($item['asset'] ?? [], $credential);
                }

                if (empty($originalSource)) {
                    continue;
                }

                // No mapping for this attribute — create the media. The finalizer
                // stores the mapping (composite code) once Shopify returns the id.
                $createMedia[] = [
                    'originalSource'   => $originalSource,
                    'mediaContentType' => $mediaContentType,
                    'alt'              => $item['sku'].' - '.$item['code'],
                ];

                $planItems[] = [
                    'sku'  => $item['sku'],
                    'code' => $this->buildCode($item['code'], $item['path']),
                    'alt'  => $item['sku'].' - '.$item['code'],
                ];
            }

            if (! empty($updateMedia)) {
                $this->updateLines[] = json_encode([
                    'productId' => $productId,
                    'media'     => $updateMedia,
                ], JSON_UNESCAPED_SLASHES);
            }

            if (empty($createMedia)) {
                continue;
            }

            $lineIndex = count($lines);

            $lines[] = json_encode([
                'productId' => $productId,
                'media'     => $createMedia,
            ], JSON_UNESCAPED_SLASHES);

            $this->mediaPlan[$lineIndex] = [
                'productId' => $productId,
                'items'     => $planItems,
            ];
        }

        return $lines;
    }

    /**
     * The productUpdateMedia JSONL lines recorded by the most recent build() call.
     */
    public function getUpdateLines(): array
    {
        return $this->updateLines;
    }

    /**
     * The code-refresh plan (media GID => {rowId, code}) recorded by build().
     */
    public function getUpdatePlan(): array
    {
        return $this->updatePlan;
    }

    /**
     * Build a product's complete productSet `files` list: existing media referenced
     * by id (preserved, since productSet deletes media absent from the list), new
     * media by source. Returns the files plus plan items to persist new mappings.
     *
     * @return array{files: array<int, array>, planItems: array<int, array{sku: string, code: string, alt: string}>}
     */
    public function collectProductSetFiles(string $productSku, array $variantSkus, int $credentialId, ?string $shopUrl, string $channel = 'default', string $currency = 'USD', array $credential = []): array
    {
        $desiredMedia = $this->collectMediaForProduct($productSku, $variantSkus, $credentialId, $channel, $currency);

        if (empty($desiredMedia)) {
            return ['files' => [], 'planItems' => []];
        }

        $mappingsBySku = [];
        $files = [];
        $planItems = [];

        foreach ($desiredMedia as $item) {
            $mappings = $mappingsBySku[$item['sku']]
                ??= $this->getMediaMappings($item['sku'], $shopUrl);

            [$exact, $byAttribute] = $this->matchMapping($mappings, $item['code'], $item['path']);

            $existingGid = $exact['row']->externalId ?? $byAttribute['row']->externalId ?? null;

            if (! empty($existingGid)) {
                $files[] = ['id' => $existingGid];

                continue;
            }

            $mediaContentType = $item['mediaContentType'] ?? 'IMAGE';
            $originalSource = $item['url'] ?? '';

            if ($mediaContentType === 'VIDEO') {
                $originalSource = $this->stageVideoUpload($item['asset'] ?? [], $credential);
            }

            if (empty($originalSource)) {
                continue;
            }

            $files[] = [
                'originalSource' => $originalSource,
                'contentType'    => $mediaContentType,
                'alt'            => $item['sku'].' - '.$item['code'],
            ];

            $planItems[] = [
                'sku'  => $item['sku'],
                'code' => $this->buildCode($item['code'], $item['path']),
                'alt'  => $item['sku'].' - '.$item['code'],
            ];
        }

        return ['files' => $files, 'planItems' => $planItems];
    }

    /**
     * Resolve a productSet entry to its Shopify product GID and the desired media
     * matched against stored mappings. Used by build()'s single create+update pass.
     *
     * @param  array<string, array>  $mappingsBySku  per-SKU mapping cache (by ref)
     * @return array{productId: string, matches: array<int, array{item: array, exact: ?array, byAttribute: ?array}>}|null
     */
    protected function resolveEntryMatches(array $entry, int $credentialId, ?string $shopUrl, string $channel, string $currency, array &$mappingsBySku): ?array
    {
        $productSku = $entry['manifest']['product_sku'] ?? null;

        if (! $productSku) {
            return null;
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
            return null;
        }

        $variantSkus = $entry['manifest']['variant_skus'] ?? [];
        $desiredMedia = $this->collectMediaForProduct($productSku, $variantSkus, $credentialId, $channel, $currency);

        if (empty($desiredMedia)) {
            return null;
        }

        $matches = [];

        foreach ($desiredMedia as $item) {
            $mappings = $mappingsBySku[$item['sku']]
                ??= $this->getMediaMappings($item['sku'], $shopUrl);

            [$exact, $byAttribute] = $this->matchMapping($mappings, $item['code'], $item['path']);

            $matches[] = [
                'item'        => $item,
                'exact'       => $exact,
                'byAttribute' => $byAttribute,
            ];
        }

        return [
            'productId' => $this->ensureGid($productId, 'Product'),
            'matches'   => $matches,
        ];
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
     *
     * For a simple product the SKU's mapping row holds the variant GID in
     * externalId and the product GID in relatedId (the variant sync overwrites the
     * product sync), so relatedId is preferred and externalId is the parent-row
     * fallback.
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

        return $mapping?->relatedId ?: ($mapping?->externalId ?: null);
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
                'row'       => $row,
                'attribute' => $attribute,
                'path'      => $path,
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
     * Resolve the desired media for a product and its variants.
     *
     * @return array<int, array{sku: string, code: string, path: string, url: string, mediaContentType: string, asset?: array}>
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
        $attributes = $context['attributes'] ?? [];
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

                // Asset attributes hold DAM asset IDs (not storage paths) and may
                // reference several assets — images and/or videos. Expand each via
                // the DAM repository, mirroring the non-bulk export path. DAM is an
                // optional module, so resolveAssetMediaItems() yields nothing when
                // it is not installed.
                if (($attributes[$code]->type ?? null) === 'asset') {
                    foreach ($this->resolveAssetMediaItems($sku, $code, $rawValue) as $assetItem) {
                        $items[] = $assetItem;
                    }

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
                                'sku'              => $sku,
                                'code'             => $code.'_'.$index,
                                'path'             => $resolved['path'],
                                'url'              => $resolved['url'],
                                'mediaContentType' => 'IMAGE',
                            ];
                        }
                    }

                    continue;
                }

                $path = is_array($rawValue) ? ($rawValue[0] ?? '') : $rawValue;
                $resolved = $this->resolveMedia($path);

                if ($resolved !== null) {
                    $items[] = [
                        'sku'              => $sku,
                        'code'             => $code,
                        'path'             => $resolved['path'],
                        'url'              => $resolved['url'],
                        'mediaContentType' => 'IMAGE',
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
        return $this->assetUrlResolver->resolveMedia($path);
    }

    /**
     * Whether a stored media value is already an absolute http(s) URL rather than
     * a storage path that needs resolving through a disk.
     */
    protected function isAbsoluteUrl(string $value): bool
    {
        return $this->assetUrlResolver->isAbsoluteUrl($value);
    }

    /**
     * Expand a DAM asset attribute value (comma-separated asset IDs) into media
     * items — one per asset. Images resolve to a publicly fetchable DAM URL;
     * videos carry their asset record so build() can push them through Shopify's
     * staged upload flow. Mirrors Exporter::handleImageAttribute (non-bulk path).
     *
     * Returns an empty array when DAM is not installed.
     *
     * @return array<int, array{sku: string, code: string, path: string, url: string, mediaContentType: string, asset?: array}>
     */
    protected function resolveAssetMediaItems(string $sku, string $code, mixed $rawValue): array
    {
        $assetRepository = $this->assetRepository();

        if (! $assetRepository) {
            return [];
        }

        $rawValue = is_array($rawValue) ? implode(',', $rawValue) : (string) $rawValue;
        $ids = array_filter(array_map('trim', explode(',', $rawValue)));

        if (empty($ids)) {
            return [];
        }

        $assets = $assetRepository->whereIn('id', $ids)->get();
        $items = [];

        foreach ($assets as $asset) {
            $asset = is_array($asset) ? $asset : $asset->toArray();
            $path = $asset['path'] ?? null;
            $mimeType = $asset['mime_type'] ?? null;

            if (empty($path) || empty($mimeType)) {
                continue;
            }

            // Key per asset id so re-exports can match a single asset within a
            // multi-asset attribute, mirroring the non-bulk path's "<code>_<id>".
            $assetCode = $code.'_'.$asset['id'];

            if ($mimeType === 'video/mp4') {
                $items[] = [
                    'sku'              => $sku,
                    'code'             => $assetCode,
                    'path'             => $path,
                    'url'              => '',
                    'mediaContentType' => 'VIDEO',
                    'asset'            => $asset,
                ];

                continue;
            }

            if (! in_array($mimeType, $this->imageMimeTypes, true)) {
                continue;
            }

            $url = $this->resolveAssetUrl($path);

            if (empty($url)) {
                continue;
            }

            $items[] = [
                'sku'              => $sku,
                'code'             => $assetCode,
                'path'             => $path,
                'url'              => $url,
                'mediaContentType' => 'IMAGE',
            ];
        }

        return $items;
    }

    /**
     * Build a publicly fetchable URL for a DAM asset image.
     *
     * Uses the public DAM fetch route (registered without the admin/dam
     * middleware) so Shopify can pull the file — the same source the non-bulk
     * export path uses. Returns '' when the route is unavailable.
     */
    protected function resolveAssetUrl(string $path): string
    {
        return $this->assetUrlResolver->resolveAssetUrl($path);
    }

    /**
     * Push a DAM video asset to a Shopify staged upload target and return the
     * resourceUrl to use as originalSource. Mirrors Exporter::videoAddToShopify,
     * but reads the file from the configured asset disk so it works on S3 too.
     *
     * @return string|null resourceUrl on success, null on any failure
     */
    protected function stageVideoUpload(array $asset, array $credential): ?string
    {
        if (empty($asset) || empty($credential) || ! $this->assetRepository()) {
            return null;
        }

        $path = $asset['path'] ?? null;

        if (empty($path)) {
            return null;
        }

        try {
            $response = $this->requestGraphQlApiAction('stagedUploadsCreate', $credential, [
                'input' => [
                    'filename' => $asset['file_name'] ?? 'video.mp4',
                    'mimeType' => $asset['mime_type'] ?? 'video/mp4',
                    'resource' => strtoupper((string) ($asset['file_type'] ?? 'VIDEO')),
                    'fileSize' => (string) ($asset['file_size'] ?? 0),
                ],
            ]);

            $target = $response['body']['data']['stagedUploadsCreate']['stagedTargets'][0] ?? null;

            if (! $target || empty($target['url'])) {
                return null;
            }

            $disk = Directory::getAssetDisk();

            if (! Storage::disk($disk)->exists($path)) {
                return null;
            }

            $multipart = [];

            foreach ($target['parameters'] ?? [] as $param) {
                $multipart[] = ['name' => $param['name'], 'contents' => $param['value']];
            }

            $stream = Storage::disk($disk)->readStream($path);

            if ($stream === false) {
                return null;
            }

            try {
                $multipart[] = [
                    'name'     => 'file',
                    'contents' => $stream,
                    'filename' => $asset['file_name'] ?? 'video.mp4',
                    'headers'  => ['Content-Type' => $asset['mime_type'] ?? 'video/mp4'],
                ];

                $upload = Http::asMultipart()->timeout(300)->post($target['url'], $multipart);

                if ($upload->failed()) {
                    return null;
                }
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $target['resourceUrl'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function ensureGid(string $id, string $type): string
    {
        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$type}/{$id}";
    }
}
