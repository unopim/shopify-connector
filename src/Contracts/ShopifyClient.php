<?php

namespace Webkul\Shopify\Contracts;

/**
 * Contract for a Shopify API client.
 *
 * One implementation exists per credential transport:
 *  - ManualShopifyClient — talks to the Shopify Admin GraphQL API directly
 *    with the merchant's own access token.
 *  - SaasProxyClient — talks to the published SaaS proxy app with the proxy
 *    JWT, for credentials installed from the Shopify App Store.
 *
 * Callers (the ShopifyGraphqlRequest trait, exporters, importers) depend only
 * on this contract and never on a concrete transport, so the manual and SaaS
 * flows stay fully independent of each other. The single place that knows
 * which implementation a credential needs is ShopifyClientFactory.
 */
interface ShopifyClient
{
    /**
     * Execute a Shopify operation by its connector endpoint name.
     *
     * Implementations must return the Shopify GraphQL-shaped envelope so
     * consumers can read responses uniformly regardless of transport:
     *     ['code' => int|null, 'body' => ['data' => [...], 'errors' => [...]]]
     *
     * @param  string  $operation  Internal endpoint name (e.g. 'createCollection').
     * @param  array  $variables  Operation variables.
     * @return array{code: int|null, body: array}
     */
    public function request(string $operation, array $variables = []): array;
}
