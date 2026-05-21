<?php

namespace Webkul\Shopify\Http\Client;

use Illuminate\Support\Facades\Log;
use Webkul\Shopify\Contracts\ShopifyClient;
use Webkul\Shopify\Services\ShopifyAccessTokenManager;

/**
 * Shopify client for manually-entered credentials.
 *
 * Talks to the merchant's Shopify Admin GraphQL endpoint directly using their
 * own access token, and owns the manual-credential token lifecycle:
 * proactive validation before each call and 401 auto-regeneration with a
 * single retry. Knows nothing about the SaaS proxy flow.
 */
class ManualShopifyClient implements ShopifyClient
{
    /**
     * @param  array  $credential  Credential data assembled by the caller
     *                             (shopUrl, accessToken, apiVersion, clientId, ...).
     */
    public function __construct(
        protected array $credential,
        protected ShopifyAccessTokenManager $accessTokenManager
    ) {}

    /**
     * {@inheritdoc}
     */
    public function request(string $operation, array $variables = []): array
    {
        $credential = $this->credential;

        if (! $credential || ! isset($credential['shopUrl'], $credential['apiVersion'])) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credentials-provided'));
        }

        $credential = $this->accessTokenManager->ensureValidAccessToken($credential);

        if (empty($credential['accessToken'])) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credentials-provided'));
        }

        $apiClient = new GraphQLApiClient($credential['shopUrl'], $credential['accessToken'], $credential['apiVersion']);

        $response = $apiClient->request($operation, $variables);

        if (
            isset($response['code'])
            && (int) $response['code'] === 401
            && $this->accessTokenManager->canAutoGenerateAccessToken($credential)
        ) {
            try {
                $credential = $this->accessTokenManager->regenerateAccessToken($credential);

                $apiClient = new GraphQLApiClient($credential['shopUrl'], $credential['accessToken'], $credential['apiVersion']);
                $response = $apiClient->request($operation, $variables);
            } catch (\Throwable $e) {
                Log::error('Shopify token regeneration failed', [
                    'message' => $e->getMessage(),
                    'credential' => [
                        'credentialId' => $credential['credentialId'] ?? null,
                        'shopUrl' => $credential['shopUrl'] ?? null,
                        'apiVersion' => $credential['apiVersion'] ?? null,
                    ],
                ]);
            }
        }

        return $response;
    }
}
