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

        $lines = $this->payloadBuilder->build(
            $operationData['entries'],
            (int) $credentialId,
            $this->manifest['shop_url'] ?? $this->credential->shopUrl ?? null,
            $this->manifest['channel'] ?? 'default',
            $this->manifest['currency'] ?? 'USD',
            $this->credentialArray
        );

        // Persist any in-place image updates found in the same pass so the chained
        // media_update phase can run them via bulk without recomputing the diff.
        $this->persistPendingUpdate();

        return $lines;
    }

    /**
     * Whether build() found media that need updating (chained media_update phase).
     */
    public function hasPendingUpdate(): bool
    {
        return ! empty($this->payloadBuilder->getUpdateLines());
    }

    /**
     * The storage path where the pending media-update payload is written, derived
     * deterministically from the core op so the media_update phase reads the same file.
     */
    public function pendingUpdatePath(): string
    {
        return sprintf(
            'shopify/bulk/%s/media_update_%s.json',
            $this->manifest['job_track_id'],
            $this->coreBulkOperation->id
        );
    }

    /**
     * Write the precomputed update lines + code-refresh plan for the media_update phase.
     */
    protected function persistPendingUpdate(): void
    {
        if (! $this->hasPendingUpdate()) {
            return;
        }

        $this->bulkOperationService->writeManifest($this->pendingUpdatePath(), [
            'lines' => $this->payloadBuilder->getUpdateLines(),
            'plan'  => $this->payloadBuilder->getUpdatePlan(),
        ]);
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
