<?php

namespace Webkul\Shopify\Services\Bulk\Phases\Export;

use Webkul\Shopify\Repositories\ShopifyBulkOperationRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\Bulk\PayloadBuilders\InventoryBulkPayloadBuilder;
use Webkul\Shopify\Services\Bulk\Phases\BasePhaseService;
use Webkul\Shopify\Services\BulkOperationService;

/**
 * Inventory phase service — sets stock levels using bulk inventorySetOnHandQuantities.
 */
class InventoryPhaseService extends BasePhaseService
{
    public function __construct(
        InventoryBulkPayloadBuilder $payloadBuilder,
        BulkOperationService $bulkOperationService,
        ShopifyBulkOperationRepository $bulkOperationRepository,
        ShopifyCredentialRepository $credentialRepository
    ) {
        parent::__construct($bulkOperationService, $bulkOperationRepository, $credentialRepository);
        $this->payloadBuilder = $payloadBuilder;
    }

    protected function buildPayloadLines(array $operationData): array
    {
        // Location can be from core follow_up_context or credential extras
        $locationId = $this->manifest['follow_up_context']['location_id'] ?? $this->credential->extras['locations'] ?? null;

        if (! $locationId) {
            return [];
        }

        $credentialId = $this->manifest['credential_id'] ?? $this->credential->id;

        return $this->payloadBuilder->build(
            $operationData['entries'],
            $locationId,
            0, // defaultQuantity
            $credentialId,
            $this->manifest['channel'] ?? 'default',
            $this->manifest['currency'] ?? 'USD'
        );
    }

    protected function getPhaseName(): string
    {
        return 'inventory';
    }

    protected function getMutationKey(): string
    {
        return 'inventorySetOnHandQuantitiesBulk';
    }

    protected function getManifestMutationName(): string
    {
        return 'inventorySetOnHandQuantities';
    }

    protected function getExtraManifestData(array $operationData): array
    {
        return [
            'location_id' => $this->credential->extras['locations'] ?? null,
        ];
    }
}
