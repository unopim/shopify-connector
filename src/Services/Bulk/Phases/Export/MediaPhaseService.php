<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\MediaBulkPayloadBuilder;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;
use Webkul\Shopify\Services\BulkOperationService;

/**
 * Media phase service — uploads product images via bulk productCreateMedia.
 */
class MediaPhaseService extends BasePhaseService
{
    public function __construct(
        MediaBulkPayloadBuilder $payloadBuilder,
        BulkOperationService $bulkOperationService,
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository
    ) {
        parent::__construct($bulkOperationService, $bulkOperationRepository, $credentialRepository);
        $this->payloadBuilder = $payloadBuilder;
    }

    protected function buildPayloadLines(array $operationData): array
    {
        $credentialId = $this->manifest['credential_id'] ?? $this->credential->id;

        return $this->payloadBuilder->build(
            $operationData['entries'],
            (int) $credentialId,
            $this->manifest['shop_url'] ?? $this->credential->shopUrl ?? null,
            $this->manifest['channel'] ?? 'default',
            $this->manifest['currency'] ?? 'USD',
            $this->credentialArray
        );
    }

    /**
     * Persist the media plan in the phase manifest so BulkResultFinalizer can map
     * the created Shopify media IDs back to their (SKU, attribute) and store them.
     */
    protected function getExtraManifestData(array $operationData): array
    {
        return ['media_plan' => $this->payloadBuilder->getMediaPlan()];
    }

    protected function getPhaseName(): string
    {
        return 'media';
    }

    protected function getMutationKey(): string
    {
        return 'productCreateMediaBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'productCreateMedia';
    }
}
