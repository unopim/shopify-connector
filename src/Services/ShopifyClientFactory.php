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

    /**
     * Whether file_reference metafields can be uploaded for this credential.
     *
     * File uploads need Shopify's `fileCreate`/`getFileById` operations. Manual
     * credentials call Shopify directly and always support them. SaaS routes
     * through the proxy, which does not yet expose those endpoints, so file
     * metafields are skipped there until the proxy ships them. Flip this to a
     * plain `true` once the proxy is confirmed.
     */
    public function supportsFileReference(array $credential): bool
    {
        return empty($credential['extras']['saas']);
    }

    /**
     * Whether the credential is routed through the SaaS proxy.
     *
     * File uploads diverge by transport: manual credentials call `fileCreate`
     * directly per file, while SaaS routes through the proxy and must batch them
     * through a bulk operation (the single `fileCreate` op is not proxied).
     */
    public function isSaas(array $credential): bool
    {
        return ! empty($credential['extras']['saas']);
    }
}
