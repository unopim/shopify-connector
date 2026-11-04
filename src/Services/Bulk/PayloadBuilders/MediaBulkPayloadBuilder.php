<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

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

    /** @var array<int, array> */
    protected array $mediaPlan = [];

    /** @var array<string, array{rowId: int, code: string}> */
    protected array $updatePlan = [];

    /** @var array<int, string> */
    protected array $updateLines = [];

    protected array $imageMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/jpg'];

    /** @var array<string, array<int, array{row: object, attribute: string, path: string}>> */
    protected array $mediaMappingsBySkuCache = [];

    /** @var array<string, bool> */
    protected array $verifiedGidStatusCache = [];

    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected AssetUrlResolver $assetUrlResolver,
    ) {}

    /**
     * Reset in-memory caches between export batches.
     */
    public function resetCaches(): void
    {
        $this->mediaMappingsBySkuCache = [];
        $this->verifiedGidStatusCache = [];
    }

    /**
     * Prewarm media mappings in bulk for a list of SKUs.
     *
     * @param  array<int, string>  $skus
     */
    public function prewarmMediaMappings(array $skus, ?string $shopUrl): void
    {
        if (empty($shopUrl) || $skus === []) {
            return;
        }

        $skusToFetch = array_values(array_filter($skus, fn (string $sku): bool => ! isset($this->mediaMappingsBySkuCache[$sku])));

        if ($skusToFetch === []) {
            return;
        }

        foreach (array_chunk($skusToFetch, 500) as $chunk) {
            $rows = $this->shopifyMappingRepository
                ->where('entityType', self::MEDIA_ENTITY_TYPE)
                ->whereIn('relatedSource', $chunk)
                ->where('apiUrl', $shopUrl)
                ->get();

            $grouped = [];

            foreach ($rows as $row) {
                [$attribute, $path] = $this->parseCode($row->code);

                $grouped[$row->relatedSource][] = [
                    'row'       => $row,
                    'attribute' => $attribute,
                    'path'      => $path,
                ];
            }

            foreach ($chunk as $sku) {
                $this->mediaMappingsBySkuCache[$sku] = $grouped[$sku] ?? [];
            }
        }
    }

    /**
     * Get candidate media GIDs across prewarmed SKUs.
     *
     * @param  array<int, string>  $skus
     * @return array<int, string>
     */
    public function getPrewarmedGids(array $skus): array
    {
        $gids = [];

        foreach ($skus as $sku) {
            foreach ($this->mediaMappingsBySkuCache[$sku] ?? [] as $mapping) {
                $gid = $mapping['row']->externalId ?? null;
                if (! empty($gid)) {
                    $gids[] = $gid;
                }
            }
        }

        return array_values(array_unique($gids));
    }

    /**
     * Prewarm and batch-verify media GID health on Shopify in chunks of 250.
     *
     * @param  array<int, string>  $gids
     */
    public function prewarmMediaGids(array $gids, array $credential): void
    {
        $unique = array_values(array_unique(array_filter($gids)));
        $needed = array_values(array_filter($unique, fn (string $gid): bool => ! isset($this->verifiedGidStatusCache[$gid])));

        if ($needed === [] || $credential === []) {
            return;
        }

        foreach (array_chunk($needed, 250) as $chunk) {
            try {
                $response = $this->requestGraphQlApiAction('getFileById', $credential, ['ids' => $chunk]);
            } catch (\Throwable) {
                foreach ($chunk as $gid) {
                    $this->verifiedGidStatusCache[$gid] = true;
                }

                continue;
            }

            $nodes = $response['body']['data']['nodes'] ?? null;

            if (! is_array($nodes)) {
                foreach ($chunk as $gid) {
                    $this->verifiedGidStatusCache[$gid] = true;
                }

                continue;
            }

            $healthy = [];
            foreach ($nodes as $node) {
                if (! empty($node['id']) && ($node['fileStatus'] ?? 'READY') !== 'FAILED') {
                    $healthy[$node['id']] = true;
                }
            }

            foreach ($chunk as $gid) {
                $this->verifiedGidStatusCache[$gid] = isset($healthy[$gid]);
            }
        }
    }

    /**
     * Build JSONL payload lines for productCreateMedia.
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
     * Build productSet files list and plan items.
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
     * Filter out unusable media GIDs (failed or deleted on Shopify).
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

        $uncached = array_values(array_filter($gids, fn (string $gid): bool => ! isset($this->verifiedGidStatusCache[$gid])));
        if ($uncached !== []) {
            $this->prewarmMediaGids($uncached, $credential);
        }

        $unusable = [];
        foreach ($gids as $gid) {
            if (isset($this->verifiedGidStatusCache[$gid]) && ! $this->verifiedGidStatusCache[$gid]) {
                $unusable[] = $gid;
            }
        }

        return $unusable;
    }

    /**
     * Purge stored media mappings for unusable GIDs.
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
     * Resolve entry to Shopify product GID and desired media matches.
     *
     * @param  array<string, array>  $mappingsBySku
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
     * Look up Shopify product GID from local mapping.
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

        if (isset($this->mediaMappingsBySkuCache[$sku])) {
            return $this->mediaMappingsBySkuCache[$sku];
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

        return $this->mediaMappingsBySkuCache[$sku] = $mappings;
    }

    /**
     * Match desired media against stored mappings.
     *
     * @return array{0: ?array, 1: ?array}
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
     * Split a stored code into attribute and path.
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
     * Check if stored media value is already an absolute URL.
     */
    protected function isAbsoluteUrl(string $value): bool
    {
        return $this->assetUrlResolver->isAbsoluteUrl($value);
    }

    /**
     * Expand DAM asset attribute value into media items.
     *
     * @return array<int, array{sku: string, code: string, path: string, url: string, mediaContentType: string, asset?: array}>
     */
    protected function resolveAssetMediaItems(string $sku, string $code, mixed $rawValue): array
    {
        $assetRepository = $this->assetRepository();

        if (! is_object($assetRepository)) {
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
     * Build publicly fetchable URL for a DAM asset image.
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
