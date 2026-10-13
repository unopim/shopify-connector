<?php

use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Services\ShopifyAccessTokenManager;

it('does not reuse a credential resolved by an earlier manager instance', function () {
    $credential = ShopifyCredentialsConfig::factory()->create(['accessToken' => 'original_token']);

    app(ShopifyAccessTokenManager::class)->ensureValidAccessToken(['credentialId' => $credential->id]);

    $credential->update(['accessToken' => 'rotated_token']);

    $resolved = app(ShopifyAccessTokenManager::class)->ensureValidAccessToken(['credentialId' => $credential->id]);

    expect($resolved['accessToken'])->toBe('rotated_token');
});
