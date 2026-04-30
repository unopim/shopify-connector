<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\PublishingBulkPayloadBuilder;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;
use Webkul\Shopify\Services\BulkOperationService;

/**
 * Publishing phase service — publishes products to sales channels using bulk publishablePublish.
 *
 * Extends BasePhaseService to reuse the standard bulk operation workflow.
 */
class PublishingPhaseService extends BasePhaseService
{
    public function __construct(
        PublishingBulkPayloadBuilder $payloadBuilder,
        BulkOperationService $bulkOperationService,
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository
    ) {
        parent::__construct($bulkOperationService, $bulkOperationRepository, $credentialRepository);
        $this->payloadBuilder = $payloadBuilder;
    }

    /**
     * Build JSONL payload lines for publishing.
     */
    protected function buildPayloadLines(array $operationData): array
    {
        $publicationIds = $this->credential->extras['salesChannel'] ?? '';

        if (empty($publicationIds)) {
            return [];
        }

        return $this->payloadBuilder->build($operationData['entries'], $publicationIds);
    }

    protected function getPhaseName(): string
    {
        return 'publishing';
    }

    protected function getMutationKey(): string
    {
        return 'publishablePublishBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'publishablePublish';
    }

    /**
     * Include publication IDs in manifest for later reference.
     */
    protected function getExtraManifestData(array $operationData): array
    {
        return [
            'publication_ids' => $this->credential->extras['salesChannel'] ?? '',
        ];
    }
}
