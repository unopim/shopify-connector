<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\User\Models\Admin;

class SaasAutoLoginController extends Controller
{
    protected const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $shop = (string) $request->query('shop', '');
        $timestamp = (string) $request->query('timestamp', '');
        $hmac = (string) $request->query('hmac', '');

        if ($shop === '' || $timestamp === '' || $hmac === '') {
            return $this->reject('missing_parameters');
        }

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return $this->reject('stale_timestamp', ['shop' => $shop, 'timestamp' => $timestamp]);
        }
        $credential = ShopifyCredentialsConfig::query()
            ->whereJsonContains('extras->saas', true)
            ->where('shopUrl', 'like', '%'.$shop.'%')
            ->first();

        if (! $credential) {
            return $this->reject('credential_not_found', ['shop' => $shop]);
        }

        $unopimClientId = $credential->extras['unopim_client_id'] ?? null;

        if (! $unopimClientId) {
            return $this->reject('missing_unopim_client_id', ['shop' => $shop]);
        }

        $apiKey = DB::table('api_keys')
            ->where('oauth_client_id', $unopimClientId)
            ->where('revoked', false)
            ->first();

        if (! $apiKey) {
            return $this->reject('integration_not_found', ['shop' => $shop, 'client_id' => $unopimClientId]);
        }

        $secret = DB::table('oauth_clients')
            ->where('id', $unopimClientId)
            ->where('revoked', false)
            ->value('secret');

        if (! $secret) {
            return $this->reject('secret_unavailable', ['shop' => $shop, 'client_id' => $unopimClientId]);
        }

        $data = $shop.'|'.$timestamp;
        $expected = hash_hmac('sha256', $data, $secret);

        if (! hash_equals($expected, $hmac)) {
            return $this->reject('hmac_mismatch', ['shop' => $shop]);
        }

        $admin = Admin::find($apiKey->admin_id);

        if (! $admin || ! $admin->status) {
            return $this->reject('admin_inactive', ['shop' => $shop, 'admin_id' => $apiKey->admin_id]);
        }
        auth()->guard('admin')->login($admin);
        $request->session()->regenerate();

        return to_route('admin.dashboard.index');
    }

    /**
     * Log the failure reason (without leaking secrets to the user) and bounce
     * the visitor to the admin login page so they can authenticate manually.
     */
    protected function reject(string $reason, array $context = []): RedirectResponse
    {
        Log::warning('Shopify SaaS secure-login rejected', array_merge(['reason' => $reason], $context));

        return to_route('admin.session.create')
            ->withErrors(['email' => trans('shopify::app.shopify.credential.secure-login-failed')]);
    }
}
