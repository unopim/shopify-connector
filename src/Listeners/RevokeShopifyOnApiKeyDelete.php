<?php

namespace Webkul\Shopify\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\AdminApi\Repositories\ApiKeyRepository;
use Webkul\Shopify\Http\Client\SaasProxyClient;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;

/**
 * When an admin integration (api_keys row) is deleted, look up any Shopify
 * SaaS credential whose extras.unopim_client_id matches the integration's
 * oauth_client_id. For each match, ask the SaaS proxy to revoke the Shopify
 * connection and then delete the local credential row.
 *
 * The integration delete itself proceeds regardless; upstream revoke failures
 * are logged but do not block the listener from removing the local row, since
 * leaving an orphaned credential pointing at a now-revoked OAuth client is
 * worse than retrying revoke manually.
 */
class RevokeShopifyOnApiKeyDelete
{
    public function __construct(
        protected ApiKeyRepository $apiKeyRepository,
        protected ShopifyCredentialRepository $credentialRepository
    ) {}

    public function handle(int $apiKeyId): void
    {
        $apiKey = $this->apiKeyRepository->find($apiKeyId);
        $oauthClientId = $apiKey?->oauth_client_id;

        if (! $oauthClientId) {
            return;
        }

        $credentials = ShopifyCredentialsConfig::query()
            ->whereJsonContains('extras->saas', true)
            ->where('extras->unopim_client_id', $oauthClientId)
            ->get();

        Log::info('Shopify integration-delete cleanup', [
            'api_key_id' => $apiKeyId,
            'oauth_client_id' => $oauthClientId,
            'matched' => $credentials->pluck('id')->all(),
        ]);

        foreach ($credentials as $credential) {
            $this->revokeAndDelete($credential);
        }
    }

    protected function revokeAndDelete(ShopifyCredentialsConfig $credential): void
    {
        $proxy = new SaasProxyClient(
            (string) config('shopify.saas.proxy_url'),
            (string) $credential->accessToken,
            (int) config('shopify.saas.request_timeout', 30)
        );

        $revoked = $proxy->revoke(
            (string) config('shopify.saas.revoke_path'),
            $this->extractShopDomain((string) $credential->shopUrl)
        );

        if (! $revoked) {
            Log::warning('Shopify SaaS revoke failed during integration delete; removing local row anyway', [
                'credential_id' => $credential->id,
                'shopUrl' => $credential->shopUrl,
            ]);
        }

        $this->credentialRepository->delete($credential->id);
    }

    protected function extractShopDomain(string $shopUrl): string
    {
        $host = parse_url($shopUrl, PHP_URL_HOST);

        return $host ?: trim(preg_replace('#^https?://#i', '', $shopUrl), '/');
    }
}
