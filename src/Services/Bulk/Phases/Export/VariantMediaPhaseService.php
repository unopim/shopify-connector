<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;
use Webkul\Shopify\Services\BulkOperationService;

/**
 * Variant-media phase — links freshly-created product media to the specific variant
 * it belongs to via bulk productVariantAppendMedia (productCreateMedia only attaches
 * media at the product level). Runs after the media phase, reading the productImage
 * mappings it persisted (mediaId + variant SKU + product GID). Uses bulk transport so
 * it also works on SaaS, where the proxy exposes bulkOperationRunMutation but not the
 * single productVariantAppendMedia mutation.
 */
class VariantMediaPhaseService extends BasePhaseService
{
    public function __construct(
        BulkOperationService $bulkOperationService,
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
    ) {
        parent::__construct($bulkOperationService, $bulkOperationRepository, $credentialRepository);
    }

    /**
     * One JSONL line per product: { productId, variantMedia: [{ variantId, mediaIds }] },
     * built from this job's productImage mappings whose SKU resolves to a variant.
     */
    protected function buildPayloadLines(array $operationData): array
    {
        $manifest = $operationData['manifest'];
        $shopUrl = $manifest['shop_url'] ?? null;
        $jobTrackId = $manifest['job_track_id'] ?? null;

        if (! $shopUrl || ! $jobTrackId) {
            return [];
        }

        $mappings = $this->shopifyMappingRepository
            ->where('entityType', 'productImage')
            ->where('jobInstanceId', $jobTrackId)
            ->where('apiUrl', $shopUrl)
            ->get();

        $byProduct = [];
        foreach ($mappings as $mapping) {
            $variantGid = $this->resolveVariantGid($mapping->relatedSource, $shopUrl);

            if (! $variantGid || empty($mapping->externalId) || empty($mapping->relatedId)) {
                continue;
            }

            $byProduct[$mapping->relatedId][$variantGid][] = $mapping->externalId;
        }

        $lines = [];
        foreach ($byProduct as $productId => $variants) {
            $variantMedia = [];
            foreach ($variants as $variantGid => $mediaIds) {
                foreach (array_values(array_unique($mediaIds)) as $mediaId) {
                    $variantMedia[] = [
                        'variantId' => $variantGid,
                        'mediaIds' => [$mediaId],
                    ];
                }
            }

            $lines[] = json_encode(['productId' => $productId, 'variantMedia' => $variantMedia]);
        }

        return $lines;
    }

    /**
     * Resolve a SKU to its Shopify ProductVariant GID, or null when the SKU maps to a
     * product (e.g. the configurable parent) rather than a variant.
     */
    protected function resolveVariantGid(?string $sku, string $shopUrl): ?string
    {
        if (! $sku) {
            return null;
        }

        $externalId = $this->shopifyMappingRepository
            ->where('entityType', 'product')
            ->where('code', $sku)
            ->where('apiUrl', $shopUrl)
            ->first()?->externalId;

        return is_string($externalId) && str_contains($externalId, '/ProductVariant/')
            ? $externalId
            : null;
    }

    protected function getPhaseName(): string
    {
        return 'variant_media';
    }

    protected function getMutationKey(): string
    {
        return 'productVariantAppendMediaBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'productVariantAppendMedia';
    }
}
