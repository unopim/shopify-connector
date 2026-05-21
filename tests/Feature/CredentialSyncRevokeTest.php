<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;

use function Pest\Laravel\post;

/**
 * Feature tests for CredentialController::sync() and CredentialController::revoke().
 *
 * Both endpoints operate only on SaaS credentials (extras.saas = true) and talk
 * to the published Shopify SaaS proxy through SaasProxyClient. The proxy itself
 * is faked with Http::fake(), so these tests cover the controller flow:
 * ownership resolution, OAuth-client checks, and proxy success/failure handling.
 */

/**
 * Build a fully-wired, admin-owned SaaS credential:
 *   - an oauth_clients row (the regenerated UnoPim integration secret),
 *   - an api_keys row linking the admin to that OAuth client (ownership), and
 *   - the SaaS ShopifyCredentialsConfig row whose extras point at the client.
 *
 * Options let individual tests break one link at a time.
 */
function createOwnedSaasCredential(int $adminId, array $options = []): ShopifyCredentialsConfig
{
    $options = array_merge([
        'shopUrl' => 'https://owned.myshopify.com',
        'secret' => 'regenerated_secret_key',
        'oauthRevoked' => false,
        'apiKeyRevoked' => false,
        'owned' => true,
    ], $options);

    // oauth_clients uses a UUID string primary key, so insertGetId() cannot be
    // relied on to return a usable id; generate the id explicitly.
    $oauthClientId = (string) Str::uuid();

    DB::table('oauth_clients')->insert([
        'id' => $oauthClientId,
        'name' => 'UnoPim Shopify Integration',
        'secret' => $options['secret'],
        'redirect' => 'http://localhost',
        'personal_access_client' => false,
        'password_client' => false,
        'revoked' => $options['oauthRevoked'],
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($options['owned']) {
        DB::table('api_keys')->insert([
            'name' => 'Shopify Integration Key',
            'admin_id' => $adminId,
            'oauth_client_id' => $oauthClientId,
            'permission_type' => 'all',
            'revoked' => $options['apiKeyRevoked'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return ShopifyCredentialsConfig::factory()->create([
        'shopUrl' => $options['shopUrl'],
        'accessToken' => 'saas_jwt_token',
        'extras' => [
            'saas' => true,
            'unopim_client_id' => $oauthClientId,
        ],
    ]);
}

/*
|--------------------------------------------------------------------------
| Sync
|--------------------------------------------------------------------------
*/

it('should sync the SaaS credential and push the secret to the proxy', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id);

    Http::fake([
        '*unopimupdate.json' => Http::response(['user' => ['status' => 'success']], 200),
    ]);

    post(route('shopify.credentials.sync', $credential->id))
        ->assertStatus(200)
        ->assertJson(['message' => trans('shopify::app.shopify.credential.sync-success')]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'unopimupdate.json')
            && $request['domain'] === 'owned.myshopify.com'
            && $request['secret_key'] === 'regenerated_secret_key'
            && $request['base_url'] === rtrim((string) config('app.url'), '/');
    });
});

it('should abort with 404 when syncing a credential that does not exist', function () {
    $this->loginAsAdmin();

    post(route('shopify.credentials.sync', 99999))
        ->assertStatus(404);
});

it('should abort with 404 when syncing a non-SaaS credential', function () {
    $this->loginAsAdmin();

    $credential = ShopifyCredentialsConfig::factory()->create();

    post(route('shopify.credentials.sync', $credential->id))
        ->assertStatus(404);
});

it('should abort with 403 when syncing a credential the admin does not own', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id, ['owned' => false]);

    post(route('shopify.credentials.sync', $credential->id))
        ->assertStatus(403);
});

it('should return client-not-found when no active UnoPim integration exists', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id, ['oauthRevoked' => true]);

    post(route('shopify.credentials.sync', $credential->id))
        ->assertStatus(404)
        ->assertJson(['message' => trans('shopify::app.shopify.credential.sync-client-not-found')]);
});

it('should return missing-data when the OAuth client has no secret', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id, ['secret' => '']);

    post(route('shopify.credentials.sync', $credential->id))
        ->assertStatus(422)
        ->assertJson(['message' => trans('shopify::app.shopify.credential.sync-missing-data')]);
});

it('should return sync-failed when the proxy rejects the sync', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id);

    Http::fake([
        '*unopimupdate.json' => Http::response(['user' => ['status' => 'error']], 200),
    ]);

    post(route('shopify.credentials.sync', $credential->id))
        ->assertStatus(502)
        ->assertJson(['message' => trans('shopify::app.shopify.credential.sync-failed')]);
});

it('should return sync-failed when the proxy responds with an HTTP error', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id);

    Http::fake([
        '*unopimupdate.json' => Http::response([], 500),
    ]);

    post(route('shopify.credentials.sync', $credential->id))
        ->assertStatus(502)
        ->assertJson(['message' => trans('shopify::app.shopify.credential.sync-failed')]);
});

/*
|--------------------------------------------------------------------------
| Revoke
|--------------------------------------------------------------------------
*/

it('should revoke the SaaS credential and delete the local row', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id);

    Http::fake([
        '*remove.json' => Http::response(['status' => true], 200),
    ]);

    post(route('shopify.credentials.revoke', $credential->id))
        ->assertStatus(200)
        ->assertJson(['message' => trans('shopify::app.shopify.credential.revoke-success')]);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'remove.json')
            && $request['domain'] === 'owned.myshopify.com';
    });

    $this->assertDatabaseMissing($this->getFullTableName(ShopifyCredentialsConfig::class), [
        'id' => $credential->id,
    ]);
});

it('should keep the credential when the proxy revoke fails', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id);

    Http::fake([
        '*remove.json' => Http::response([], 502),
    ]);

    post(route('shopify.credentials.revoke', $credential->id))
        ->assertStatus(502)
        ->assertJson(['message' => trans('shopify::app.shopify.credential.revoke-failed')]);

    $this->assertDatabaseHas($this->getFullTableName(ShopifyCredentialsConfig::class), [
        'id' => $credential->id,
    ]);
});

it('should abort with 404 when revoking a non-SaaS credential', function () {
    $this->loginAsAdmin();

    $credential = ShopifyCredentialsConfig::factory()->create();

    post(route('shopify.credentials.revoke', $credential->id))
        ->assertStatus(404);
});

it('should abort with 403 when revoking a credential the admin does not own', function () {
    $admin = $this->loginAsAdmin();

    $credential = createOwnedSaasCredential($admin->id, ['owned' => false]);

    post(route('shopify.credentials.revoke', $credential->id))
        ->assertStatus(403);
});
