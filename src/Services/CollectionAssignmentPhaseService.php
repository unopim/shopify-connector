<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class CollectionAssignmentPhaseService
{
    use ShopifyGraphqlRequest;

    /**
     * Sync manual collection membership for exported products.
     */
    public function handle(ShopifyBulkOperation $bulkOperation, array $operationData): array
    {
        $credentialArray = $this->buildCredentialArray($operationData['manifest']);
        $processed = 0;
        $errors = [];

        foreach ($operationData['entries'] as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            $targetCollections = $entry['manifest']['phase_context']['collections'] ?? [];
            $response = $this->requestGraphQlApiAction('productCollections', $credentialArray, [
                'id' => $entry['product']['id'],
            ]);

            $existingCollections = array_column(
                array_column($response['body']['data']['product']['collections']['edges'] ?? [], 'node'),
                'id'
            );

            $toAdd = array_values(array_diff($targetCollections, $existingCollections));
            $toRemove = array_values(array_diff($existingCollections, $targetCollections));

            foreach ($toAdd as $collectionId) {
                $result = $this->requestGraphQlApiAction('collectionAddProducts', $credentialArray, [
                    'id' => $collectionId,
                    'productIds' => [$entry['product']['id']],
                ]);

                $userErrors = $result['body']['data']['collectionAddProducts']['userErrors'] ?? [];

                if (! empty($userErrors)) {
                    $errors[] = [
                        'collection_id' => $collectionId,
                        'product_id' => $entry['product']['id'],
                        'errors' => $userErrors,
                    ];
                }
            }

            foreach ($toRemove as $collectionId) {
                $result = $this->requestGraphQlApiAction('collectionRemoveProducts', $credentialArray, [
                    'id' => $collectionId,
                    'productIds' => [$entry['product']['id']],
                ]);

                $userErrors = $result['body']['data']['collectionRemoveProducts']['userErrors'] ?? [];

                if (! empty($userErrors)) {
                    $errors[] = [
                        'collection_id' => $collectionId,
                        'product_id' => $entry['product']['id'],
                        'errors' => $userErrors,
                    ];
                }
            }

            $processed++;
        }

        return [
            'processed' => $processed,
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
