<?php

namespace Webkul\Shopify\Http\Client;

use Illuminate\Support\Facades\Log;
use Webkul\Shopify\Contracts\ShopifyClient;
use Webkul\Shopify\Services\ShopifyAccessTokenManager;

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
        $apiClient = $this->resolveApiClient();

        $response = $apiClient->request($operation, $variables);

        if (
            isset($response['code'])
            && (int) $response['code'] === 401
            && $this->accessTokenManager->canAutoGenerateAccessToken($this->credential)
        ) {
            try {
                $this->credential = $this->accessTokenManager->regenerateAccessToken($this->credential);

                $apiClient = new GraphQLApiClient($this->credential['shopUrl'], $this->credential['accessToken'], $this->credential['apiVersion']);
                $response = $apiClient->request($operation, $variables);
            } catch (\Throwable $e) {
                Log::error('Shopify token regeneration failed', [
                    'message'    => $e->getMessage(),
                    'credential' => [
                        'credentialId' => $this->credential['credentialId'] ?? null,
                        'shopUrl'      => $this->credential['shopUrl'] ?? null,
                        'apiVersion'   => $this->credential['apiVersion'] ?? null,
                    ],
                ]);
            }
        }

        return $response;
    }

    /**
     * Execute a pool of Shopify operations concurrently.
     *
     * @param  array<string, array{endpoint: string, parameters?: array}>  $operations
     * @return array<string, array{code: int|null, body: array}>
     */
    public function requestPool(array $operations): array
    {
        return $this->resolveApiClient()->requestPool($operations);
    }

    /**
     * Resolve initialized GraphQL API client.
     */
    protected function resolveApiClient(): GraphQLApiClient
    {
        if (! $this->credential || ! isset($this->credential['shopUrl'], $this->credential['apiVersion'])) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credentials-provided'));
        }

        $this->credential = $this->accessTokenManager->ensureValidAccessToken($this->credential);

        if (empty($this->credential['accessToken'])) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credentials-provided'));
        }

        return new GraphQLApiClient($this->credential['shopUrl'], $this->credential['accessToken'], $this->credential['apiVersion']);
    }
}
