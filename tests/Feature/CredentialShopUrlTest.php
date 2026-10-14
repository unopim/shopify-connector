<?php

use Illuminate\Support\Facades\Http;
use Webkul\Shopify\Http\Requests\CredentialForm;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

beforeEach(function () {
    $this->loginAsAdmin();

    $this->updatePayload = fn (array $overrides = []): array => array_merge([
        'apiVersion'                => '2023-04',
        'storeLocales'              => json_encode([['locale' => 'en', 'primary' => true]]),
        'salesChannel'              => 'online',
        'locationAttributeMappings' => [],
    ], $overrides);
});

it('rejects a non myshopify shop url when creating a credential', function () {
    ShopifyCredentialsConfig::query()->delete();

    Http::fake();

    $this->postJson(route('shopify.credentials.store'), [
        'clientId'     => 'test_client_id',
        'clientSecret' => 'test_client_secret',
        'apiVersion'   => '2023-04',
        'shopUrl'      => 'http://169.254.169.254',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shopUrl' => trans('shopify::app.shopify.credential.shop-url-myshopify')]);

    Http::assertNothingSent();
});

it('refuses to move a credential to another shop url while reusing the masked secrets', function () {
    $credential = ShopifyCredentialsConfig::factory()->create([
        'shopUrl'      => 'https://victim.myshopify.com',
        'accessToken'  => 'real_access_token',
        'clientId'     => 'test_client_id',
        'clientSecret' => 'real_client_secret',
    ]);

    Http::fake();

    $this->putJson(route('shopify.credentials.update', $credential->id), ($this->updatePayload)([
        'shopUrl'      => 'https://attacker.myshopify.com',
        'clientId'     => 'test_client_id',
        'accessToken'  => str_repeat('*', strlen('real_access_token')),
        'clientSecret' => str_repeat('*', strlen('real_client_secret')),
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accessToken' => trans('shopify::app.shopify.credential.secret-required-on-url-change')]);

    Http::assertNothingSent();

    expect($credential->fresh()->shopUrl)->toBe('https://victim.myshopify.com');
});

it('rejects changing the shop url to a non myshopify host', function () {
    $credential = ShopifyCredentialsConfig::factory()->create(['shopUrl' => 'https://victim.myshopify.com']);

    Http::fake();

    $this->putJson(route('shopify.credentials.update', $credential->id), ($this->updatePayload)([
        'shopUrl'     => 'http://10.0.0.5',
        'accessToken' => 'new_access_token',
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['shopUrl']);

    Http::assertNothingSent();
});

it('tests the connection against the new shop url with the newly entered token', function () {
    $credential = ShopifyCredentialsConfig::factory()->create([
        'shopUrl'      => 'https://old-store.myshopify.com',
        'accessToken'  => 'old_access_token',
        'clientId'     => '',
        'clientSecret' => '',
    ]);

    Http::fake([
        'https://new-store.myshopify.com/*' => Http::response(['data' => ['products' => ['edges' => []]]], 200),
        '*'                                 => Http::response([], 500),
    ]);

    $this->putJson(route('shopify.credentials.update', $credential->id), ($this->updatePayload)([
        'shopUrl'     => 'https://new-store.myshopify.com',
        'accessToken' => 'new_access_token',
    ]))->assertOk();

    Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://new-store.myshopify.com')
        && $request->header('X-Shopify-Access-Token') === ['new_access_token']);

    expect($credential->fresh())
        ->shopUrl->toBe('https://new-store.myshopify.com')
        ->accessToken->toBe('new_access_token');
});

it('keeps saving a credential whose existing custom domain shop url is unchanged', function () {
    $credential = ShopifyCredentialsConfig::factory()->create([
        'shopUrl'      => 'https://www.mystore.com',
        'accessToken'  => 'existing_access_token',
        'clientId'     => '',
        'clientSecret' => '',
    ]);

    Http::fake(['https://www.mystore.com/*' => Http::response(['data' => []], 200)]);

    $this->putJson(route('shopify.credentials.update', $credential->id), ($this->updatePayload)([
        'shopUrl'     => 'https://www.mystore.com',
        'accessToken' => str_repeat('*', strlen('existing_access_token')),
    ]))->assertOk();

    expect($credential->fresh()->accessToken)->toBe('existing_access_token');
});

it('does not accept a myshopify shop url followed by a trailing newline', function () {
    expect(preg_match(CredentialForm::SHOP_URL_PATTERN, "https://store.myshopify.com\n"))->toBe(0)
        ->and(preg_match(CredentialForm::SHOP_URL_PATTERN, 'https://store.myshopify.com/'))->toBe(1);
});

it('shows a translated message instead of shopify raw reason when the client credentials are rejected on create', function () {
    ShopifyCredentialsConfig::query()->delete();

    Http::fake([
        'new-store.myshopify.com/admin/oauth/access_token' => Http::response([
            'error'             => 'shop_not_permitted',
            'error_description' => 'Client credentials cannot be performed on this shop.',
        ], 400),
    ]);

    $this->postJson(route('shopify.credentials.store'), [
        'clientId'     => 'test_client_id',
        'clientSecret' => 'test_client_secret',
        'apiVersion'   => '2023-04',
        'shopUrl'      => 'https://new-store.myshopify.com',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['clientId' => trans('shopify::app.shopify.credential.token_refresh_failed')])
        ->assertDontSee('Client credentials cannot be performed on this shop.');
});
