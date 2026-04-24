<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class InventoryPhaseService
{
    use ShopifyGraphqlRequest;

    public function __construct(protected ProductPhaseDataService $productPhaseDataService) {}

    /**
     * Set inventory quantities in batched absolute updates.
     */
    public function handle(ShopifyBulkOperation $bulkOperation, array $operationData): array
    {
        $credentialArray = $this->buildCredentialArray($operationData['manifest']);
        $locationId = $operationData['manifest']['follow_up_context']['location_id'] ?? null;
        $quantities = [];
        $errors = [];

        if (! $locationId) {
            return ['processed' => 0, 'errors' => []];
        }

        foreach ($operationData['entries'] as $entry) {
            if (! empty($entry['user_errors'])) {
                continue;
            }

            foreach ($entry['product']['variants']['nodes'] ?? [] as $variantNode) {
                $variantSku = $variantNode['sku'] ?? null;
                $inventoryItemId = $variantNode['inventoryItem']['id'] ?? null;

                if (! $variantSku || ! $inventoryItemId) {
                    continue;
                }

                $context = $this->productPhaseDataService->getProductContext(
                    $variantSku,
                    (int) ($operationData['manifest']['credential_id'] ?? 0),
                    $operationData['manifest']['channel'] ?? 'default',
                    $operationData['manifest']['currency'] ?? 'USD'
                );

                if (! $context) {
                    continue;
                }

                $inventoryAttribute = $context['export_mapping']->mapping['shopify_connector_settings']['inventoryQuantity'] ?? null;
                if ($inventoryAttribute) {
                    $quantity = (int) ($context['merged_fields'][$inventoryAttribute] ?? 0);
                } else {
                    $defaultInventory = $context['export_mapping']->mapping['shopify_connector_defaults']['inventoryQuantity'] ?? 0;
                    $quantity = (int) $defaultInventory;
                }

                $quantities[] = [
                    'inventoryItemId' => $inventoryItemId,
                    'locationId' => $locationId,
                    'quantity' => $quantity,
                ];
            }
        }

        foreach (array_chunk($quantities, 50) as $chunk) {
            $response = $this->requestGraphQlApiAction('inventorySetQuantities', $credentialArray, [
                'input' => [
                    'name' => 'available',
                    'reason' => 'correction',
                    'ignoreCompareQuantity' => true,
                    'referenceDocumentUri' => sprintf('gid://unopim/ShopifyBulkOperation/%s', $bulkOperation->id),
                    'quantities' => $chunk,
                ],
            ]);

            $userErrors = $response['body']['data']['inventorySetQuantities']['userErrors'] ?? [];

            if (! empty($userErrors)) {
                $errors[] = $userErrors;
            }
        }

        return [
            'processed' => count($quantities),
            'errors' => $errors,
        ];
    }

    protected function buildCredentialArray(array $manifest): array
    {
        return [
            'credentialId' => $manifest['credential_id'] ?? null,
            'shopUrl' => $manifest['shop_url'] ?? null,
            'accessToken' => $manifest['credential']['accessToken'] ?? null,
            'apiVersion' => $manifest['credential']['apiVersion'] ?? null,
            'clientId' => $manifest['credential']['clientId'] ?? null,
            'clientSecret' => $manifest['credential']['clientSecret'] ?? null,
            'accessTokenExpiresAt' => $manifest['credential']['accessTokenExpiresAt'] ?? null,
        ];
    }
}
