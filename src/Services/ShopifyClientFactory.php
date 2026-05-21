<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Contracts\ShopifyClient;
use Webkul\Shopify\Http\Client\ManualShopifyClient;
use Webkul\Shopify\Http\Client\SaasProxyClient;

/**
 * Resolves the correct ShopifyClient implementation for a credential.
 *
 * This is the single place in the connector that knows about the
 * manual-vs-SaaS split. Every other layer depends only on the ShopifyClient
 * contract, so the two transports never reference each other and a new
 * transport can be added here without touching existing code.
 */
class ShopifyClientFactory
{
    public function __construct(
        protected ShopifyAccessTokenManager $accessTokenManager
    ) {}

    /**
     * Build the Shopify client for the given credential.
     *
     * Credentials installed from the Shopify App Store carry `extras.saas`
     * and are routed through the proxy; everything else talks to Shopify
     * directly with the merchant's own access token.
     *
     * @param  array  $credential  Credential data assembled by exporters/importers.
     */
    public function make(array $credential): ShopifyClient
    {
        if (! empty($credential['extras']['saas'])) {
            return new SaasProxyClient(
                (string) config('shopify.saas.proxy_url'),
                (string) ($credential['accessToken'] ?? ''),
                (int) config('shopify.saas.request_timeout', 30)
            );
        }

        return new ManualShopifyClient($credential, $this->accessTokenManager);
    }
}
