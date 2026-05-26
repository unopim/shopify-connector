<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SaaS Connector Proxy
    |--------------------------------------------------------------------------
    |
    | Base URL of the published Shopify SaaS proxy app. Shopify-bound calls
    | for credentials with extras.saas = true are routed through this proxy
    | (Authorization: Bearer <jwt>) instead of going to Shopify directly.
    |
    */

    'proxy_url' => env(
        'SHOPIFY_SAAS_PROXY_URL',
        'https://apps-sp.webkul.com/unopim'
    ),

    'request_timeout' => (int) env('SHOPIFY_SAAS_PROXY_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Revoke Endpoint
    |--------------------------------------------------------------------------
    |
    | Path on the SaaS proxy that removes the merchant's Shopify connection.
    | Called when an admin clicks "Revoke" on a SaaS credential row. The exact
    | path will be supplied by the Shopify team; override via env without
    | touching code.
    |
    */

    'revoke_path' => env('SHOPIFY_SAAS_REVOKE_PATH', '/graphql/api/user/remove.json'),

    /*
    |--------------------------------------------------------------------------
    | Sync Endpoint
    |--------------------------------------------------------------------------
    |
    | Path on the SaaS proxy that receives the regenerated UnoPim secret_key
    | and base_url for a given Shopify shop. Called when an admin clicks
    | "Sync" on a SaaS credential row.
    |
    */

    'sync_path' => env('SHOPIFY_SAAS_SYNC_PATH', '/graphql/api/user/unopimupdate.json'),
];
