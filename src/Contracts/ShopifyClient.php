<?php

namespace Webkul\Shopify\Contracts;

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
