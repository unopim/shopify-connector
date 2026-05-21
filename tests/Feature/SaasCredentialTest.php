<?php

use Webkul\AdminApi\Tests\Traits\ApiHelperTrait;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

uses(ApiHelperTrait::class);

beforeEach(function () {
    $this->headers = $this->getAuthenticationHeaders();
});

it('should persist unopim_client_id into extras when provided', function () {
    $payload = [
        'shopUrl' => 'https://myshop.myshopify.com',
        'accessToken' => 'shpat_test_token',
        'unopim_client_id' => 'client_abc_123',
    ];

    $response = $this->withHeaders($this->headers)
        ->postJson(route('shopify.api.saas.credentials.store'), $payload);

    $response->assertStatus(201)
        ->assertJson(['success' => true]);

    $credential = ShopifyCredentialsConfig::query()
        ->where('shopUrl', 'https://myshop.myshopify.com')
        ->first();

    expect($credential)->not->toBeNull();
    expect($credential->extras['saas'])->toBeTrue();
    expect($credential->extras['unopim_client_id'])->toBe('client_abc_123');
});

it('should not set unopim_client_id in extras when omitted', function () {
    $payload = [
        'shopUrl' => 'https://noclient.myshopify.com',
        'accessToken' => 'shpat_test_token',
    ];

    $this->withHeaders($this->headers)
        ->postJson(route('shopify.api.saas.credentials.store'), $payload)
        ->assertStatus(201);

    $credential = ShopifyCredentialsConfig::query()
        ->where('shopUrl', 'https://noclient.myshopify.com')
        ->first();

    expect($credential->extras['saas'])->toBeTrue();
    expect($credential->extras)->not->toHaveKey('unopim_client_id');
});

it('should preserve existing unopim_client_id when update payload omits it', function () {
    $existing = ShopifyCredentialsConfig::factory()->create([
        'shopUrl' => 'https://preserve.myshopify.com',
        'extras' => ['saas' => true, 'unopim_client_id' => 'previous_client'],
    ]);

    $payload = [
        'shopUrl' => 'https://preserve.myshopify.com',
        'accessToken' => 'shpat_rotated_token',
    ];

    $this->withHeaders($this->headers)
        ->putJson(route('shopify.api.saas.credentials.update'), $payload)
        ->assertStatus(200);

    $credential = ShopifyCredentialsConfig::find($existing->id);

    expect($credential->extras['unopim_client_id'])->toBe('previous_client');
});

it('should overwrite unopim_client_id when a new value is provided', function () {
    $existing = ShopifyCredentialsConfig::factory()->create([
        'shopUrl' => 'https://overwrite.myshopify.com',
        'extras' => ['saas' => true, 'unopim_client_id' => 'old_client'],
    ]);

    $payload = [
        'shopUrl' => 'https://overwrite.myshopify.com',
        'accessToken' => 'shpat_test_token',
        'unopim_client_id' => 'new_client',
    ];

    $this->withHeaders($this->headers)
        ->putJson(route('shopify.api.saas.credentials.update'), $payload)
        ->assertStatus(200);

    $credential = ShopifyCredentialsConfig::find($existing->id);

    expect($credential->extras['unopim_client_id'])->toBe('new_client');
});

it('should reject a unopim_client_id that is not a string', function () {
    $payload = [
        'shopUrl' => 'https://invalid.myshopify.com',
        'accessToken' => 'shpat_test_token',
        'unopim_client_id' => ['array', 'value'],
    ];

    $this->withHeaders($this->headers)
        ->postJson(route('shopify.api.saas.credentials.store'), $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['unopim_client_id']);
});
