<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\ProductPhaseDataService;

class MediaBulkPayloadBuilder
{
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
     * @param  array  $entries  productSet entries from core bulk result
     */
    public function build(array $entries, int $credentialId, ?string $shopUrl = null, string $channel = 'default', string $currency = 'USD'): array
    {
        $lines = [];

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

            $variantSkus = $entry['manifest']['variant_skus'] ?? [];
            $media = $this->collectMediaForProduct($productSku, $variantSkus, $credentialId, $channel, $currency);

            if (empty($media)) {
                continue;
            }

            $lines[] = json_encode([
                'productId' => $this->ensureGid($productId, 'Product'),
                'media' => $media,
            ], JSON_UNESCAPED_SLASHES);
        }

        return $lines;
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
     * Resolve mapped media URLs for a product and its variants.
     */
    protected function collectMediaForProduct(string $productSku, array $variantSkus, int $credentialId, string $channel, string $currency): array
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
        $seenUrls = [];
        $media = [];

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

                $paths = $mediaType === 'gallery'
                    ? (is_array($rawValue) ? $rawValue : [$rawValue])
                    : [is_array($rawValue) ? ($rawValue[0] ?? '') : $rawValue];

                foreach ($paths as $path) {
                    if (! is_string($path) || $path === '') {
                        continue;
                    }

                    $encodedPath = str_replace(' ', '%20', ltrim($path, '/'));
                    $fullUrl = Storage::url($encodedPath);

                    if (empty($fullUrl) || isset($seenUrls[$fullUrl])) {
                        continue;
                    }

                    $seenUrls[$fullUrl] = true;

                    $media[] = [
                        'originalSource' => $fullUrl,
                        'mediaContentType' => 'IMAGE',
                        'alt' => $sku.' - '.$code,
                    ];
                }
            }
        }

        return $media;
    }

    protected function ensureGid(string $id, string $type): string
    {
        return str_starts_with($id, 'gid://') ? $id : "gid://shopify/{$type}/{$id}";
    }
}
