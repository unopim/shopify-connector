<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\CollectionsBulkPayloadBuilder;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;
use Webkul\Shopify\Services\BulkOperationService;

/**
 * Collection assignment phase service — adds products to collections using bulk collectionAddProducts.
 */
class CollectionAssignmentPhaseService extends BasePhaseService
{
    public function __construct(
        CollectionsBulkPayloadBuilder $payloadBuilder,
        BulkOperationService $bulkOperationService,
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository
    ) {
        parent::__construct($bulkOperationService, $bulkOperationRepository, $credentialRepository);
        $this->payloadBuilder = $payloadBuilder;
    }

    protected function buildPayloadLines(array $operationData): array
    {
        return $this->payloadBuilder->build($operationData['entries']);
    }

    protected function getPhaseName(): string
    {
        return 'collections';
    }

    protected function getMutationKey(): string
    {
        return 'collectionAddProductsBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'collectionAddProducts';
    }
}
