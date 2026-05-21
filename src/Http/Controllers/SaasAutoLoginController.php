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

/**
 * Public endpoint Shopify (via the SaaS proxy) redirects to in order to log
 * an admin into UnoPim without password input. The request is authenticated
 * by an HMAC computed against the OAuth client's secret_key that was pushed
 * to Shopify via the Sync flow.
 *
 *   GET /shopify/saas/secure-login?shop=...&timestamp=...&hmac=...
 *
 * Signed data is "{shop}|{timestamp}" with SHA-256 HMAC using the secret_key
 * stored in oauth_clients.secret for the credential's extras.unopim_client_id.
 */
class SaasAutoLoginController extends Controller
{
    /**
     * Maximum age, in seconds, of an inbound signed request. Defends against
     * link replay. Five minutes matches the OAuth state-token window most
     * Shopify-facing apps use.
     */
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

        return redirect()->route('admin.dashboard.index');
    }

    /**
     * Log the failure reason (without leaking secrets to the user) and bounce
     * the visitor to the admin login page so they can authenticate manually.
     */
    protected function reject(string $reason, array $context = []): RedirectResponse
    {
        Log::warning('Shopify SaaS secure-login rejected', array_merge(['reason' => $reason], $context));

        return redirect()
            ->route('admin.session.create')
            ->withErrors(['email' => trans('shopify::app.shopify.credential.secure-login-failed')]);
    }
}
