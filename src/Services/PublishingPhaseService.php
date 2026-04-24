<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class PublishingPhaseService
{
    use ShopifyGraphqlRequest;

    /**
     * Publish synced products to configured sales channels.
     */
    public function handle(ShopifyBulkOperation $bulkOperation, array $operationData): array
    {
        $credentialArray = $this->buildCredentialArray($operationData['manifest']);
        $publicationIds = array_filter(explode(',', $operationData['manifest']['follow_up_context']['publication_ids'] ?? ''));
        $processed = 0;
        $errors = [];

        if (empty($publicationIds)) {
            return ['processed' => 0, 'errors' => []];
        }

        foreach ($operationData['entries'] as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            $response = $this->requestGraphQlApiAction('publishablePublish', $credentialArray, [
                'id' => $entry['product']['id'],
                'input' => array_map(fn ($publicationId) => ['publicationId' => $publicationId], $publicationIds),
            ]);

            $userErrors = $response['body']['data']['publishablePublish']['userErrors'] ?? [];

            if (! empty($userErrors)) {
                $errors[] = [
                    'product_id' => $entry['product']['id'],
                    'errors' => $userErrors,
                ];

                continue;
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
