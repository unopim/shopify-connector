<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Webkul\DAM\Repositories\AssetRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\Bulk\Media\AssetUrlResolver;
use Webkul\Shopify\Services\ProductPhaseDataService;
use Webkul\Shopify\Traits\ResolvesDamAssetRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\StagesShopifyAsset;

class MediaBulkPayloadBuilder
{
    use ResolvesDamAssetRepository;
    use ShopifyGraphqlRequest;
    use StagesShopifyAsset;

    protected const MEDIA_ENTITY_TYPE = 'productImage';

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

    protected array $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];

    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected AssetUrlResolver $assetUrlResolver,
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
                    continue;
                }

                $mediaContentType = $item['mediaContentType'] ?? 'IMAGE';

                if ($mediaContentType === 'IMAGE' && $match['byAttribute'] && ! empty($match['byAttribute']['row']->externalId)) {

                    $mediaId = $match['byAttribute']['row']->externalId;

                    $previewSource = ! empty($item['asset'])
                        ? $this->stageAssetUpload($item['asset'], $credential)
                        : ($item['url'] ?? '');

                    if (empty($previewSource)) {
                        continue;
                    }

                    $updateMedia[] = [
                        'id'                 => $mediaId,
                        'previewImageSource' => $previewSource,
                        'alt'                => $item['sku'].' - '.$item['code'],
                    ];

                    $this->updatePlan[$mediaId] = [
                        'rowId' => $match['byAttribute']['row']->id,
                        'code'  => $this->buildCode($item['code'], $item['path']),
                    ];

                    continue;
                }

                $originalSource = $item['url'] ?? '';

                if ($mediaContentType === 'VIDEO' || ! empty($item['asset'])) {
                    $originalSource = $this->stageAssetUpload($item['asset'] ?? [], $credential);
                }

                if (empty($originalSource)) {
                    continue;
                }

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

            if ($updateMedia !== []) {
                $this->updateLines[] = json_encode([
                    'productId' => $productId,
                    'media'     => $updateMedia,
                ], JSON_UNESCAPED_SLASHES);
            }

            if ($createMedia === []) {
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
     * Cached media ids are first checked against Shopify: any the platform has moved
     * to FAILED (an unreachable originalSource — e.g. a localhost app URL — leaves the
     * id stuck there) are purged and re-uploaded, otherwise the whole productSet is
     * rejected with "File ... is in the FAILED state and cannot be associated".
     *
     * @return array{files: array<int, array>, planItems: array<int, array{sku: string, code: string, alt: string}>}
     */
    public function collectProductSetFiles(string $productSku, array $variantSkus, int $credentialId, ?string $shopUrl, string $channel = 'default', string $currency = 'USD', array $credential = []): array
    {
        $desiredMedia = $this->collectMediaForProduct($productSku, $variantSkus, $credentialId, $channel, $currency);

        if ($desiredMedia === []) {
            return ['files' => [], 'planItems' => []];
        }

        $mappingsBySku = [];
        $files = [];
        $planItems = [];
        $resolvedGids = [];
        $candidateGids = [];

        foreach ($desiredMedia as $index => $item) {
            $mappings = $mappingsBySku[$item['sku']]
                ??= $this->getMediaMappings($item['sku'], $shopUrl);

            [$exact, $byAttribute] = $this->matchMapping($mappings, $item['code'], $item['path']);

            $resolvedGids[$index] = $exact['row']->externalId ?? $byAttribute['row']->externalId ?? null;

            if (! empty($resolvedGids[$index])) {
                $candidateGids[] = $resolvedGids[$index];
            }
        }

        $unusableGids = $this->unusableMediaGids($candidateGids, $credential);

        if ($unusableGids !== []) {
            $this->purgeMediaMappings($unusableGids);
        }

        foreach ($desiredMedia as $index => $item) {
            $existingGid = $resolvedGids[$index];

            if (! empty($existingGid) && ! in_array($existingGid, $unusableGids, true)) {
                $files[] = ['id' => $existingGid];

                continue;
            }

            $mediaContentType = $item['mediaContentType'] ?? 'IMAGE';
            $originalSource = $item['url'] ?? '';

            if ($mediaContentType === 'VIDEO' || ! empty($item['asset'])) {
                $originalSource = $this->stageAssetUpload($item['asset'] ?? [], $credential);
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
     * Of the given media GIDs, return those that can no longer be associated with a
     * product: FAILED on Shopify, or absent from the response. Fail-safe — any API
     * error or unexpected shape yields an empty list, preserving the reuse path and
     * SaaS proxies that do not expose getFileById.
     *
     * @param  array<int, string>  $gids
     * @return array<int, string>
     */
    protected function unusableMediaGids(array $gids, array $credential): array
    {
        $gids = array_values(array_unique(array_filter($gids)));

        if ($gids === [] || $credential === []) {
            return [];
        }

        try {
            $response = $this->requestGraphQlApiAction('getFileById', $credential, ['ids' => $gids]);
        } catch (\Throwable) {
            return [];
        }

        $nodes = $response['body']['data']['nodes'] ?? null;

        if (! is_array($nodes)) {
            return [];
        }

        $healthy = [];

        foreach ($nodes as $node) {
            if (! empty($node['id']) && ($node['fileStatus'] ?? 'READY') !== 'FAILED') {
                $healthy[$node['id']] = true;
            }
        }

        return array_values(array_filter($gids, static fn (string $gid): bool => ! isset($healthy[$gid])));
    }

    /**
     * Delete stored media mappings for the given GIDs so the next productSet
     * re-uploads the image instead of reusing a dead id.
     *
     * @param  array<int, string>  $gids
     */
    protected function purgeMediaMappings(array $gids): void
    {
        $gids = array_values(array_unique(array_filter($gids)));

        if ($gids === []) {
            return;
        }

        $rows = $this->shopifyMappingRepository
            ->where('entityType', self::MEDIA_ENTITY_TYPE)
            ->whereIn('externalId', $gids)
            ->get();

        foreach ($rows as $row) {
            $this->shopifyMappingRepository->delete($row->id);
        }
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

        $productId = $entry['product']['id'] ?? null;

        if (empty($productId)) {
            $productId = $this->resolveProductIdFromMapping($productSku, $shopUrl);
        }

        if (empty($productId)) {
            return null;
        }

        $variantSkus = $entry['manifest']['variant_skus'] ?? [];
        $desiredMedia = $this->collectMediaForProduct($productSku, $variantSkus, $credentialId, $channel, $currency);

        if ($desiredMedia === []) {
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

        $attributeCodes = array_filter(array_map(trim(...), explode(',', (string) $mediaMapping['mediaAttributes'])));

        if ($attributeCodes === []) {
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

                if (($attributes[$code]->type ?? null) === 'asset') {
                    foreach ($this->resolveAssetMediaItems($sku, $code, $rawValue) as $assetItem) {
                        $items[] = $assetItem;
                    }

                    continue;
                }

                if ($mediaType === 'gallery') {
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

        if (! $assetRepository instanceof AssetRepository) {
            return [];
        }

        $rawValue = is_array($rawValue) ? implode(',', $rawValue) : (string) $rawValue;
        $ids = array_filter(array_map(trim(...), explode(',', $rawValue)));

        if ($ids === []) {
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
                'asset'            => $asset,
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

    protected function ensureGid(string $id, string $type): string
    {
        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$type}/{$id}";
    }
}
