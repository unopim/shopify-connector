<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\DataGrids\Catalog\CredentialDataGrid;
use Webkul\Shopify\Helpers\ShoifyApiVersion;
use Webkul\Shopify\Http\Client\SaasProxyClient;
use Webkul\Shopify\Http\Requests\CredentialForm;
use Webkul\Shopify\Models\ShopifyCredentialsConfig;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Services\ShopifyAccessTokenManager;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class CredentialController extends Controller
{
    use ShopifyGraphqlRequest;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct(
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyAccessTokenManager $shopifyAccessTokenManager
    ) {}

    /**
     * Display a listing of the resource.
     *
     * @return View
     */
    public function index()
    {
        if (request()->ajax()) {
            return app(CredentialDataGrid::class)->toJson();
        }

        $apiVersion = (new ShoifyApiVersion)->getApiVersion();

        $hasSaas = ShopifyCredentialsConfig::query()
            ->whereJsonContains('extras->saas', true)
            ->exists();

        return view('shopify::credential.index', compact('apiVersion', 'hasSaas'));
    }

    /**
     * Create a new Shopify credential.
     */
    public function store(CredentialForm $request): JsonResponse
    {
        $hasSaas = ShopifyCredentialsConfig::query()
            ->whereJsonContains('extras->saas', true)
            ->exists();

        if ($hasSaas) {
            return new JsonResponse([
                'message' => trans('shopify::app.shopify.credential.saas-locked'),
            ], JsonResponse::HTTP_FORBIDDEN);
        }

        $data = $request->all();
        $data['storelocaleMapping'] = $this->normalizeStoreLocaleMapping($data['storelocaleMapping'] ?? []);
        $url = $data['shopUrl'];
        $url = $data['shopUrl'] = rtrim($url, '/');

        if (strpos($url, 'http') !== 0) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [trans('shopify::app.shopify.credential.invalidurl')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $credential = $this->shopifyRepository->findWhere(['shopUrl' => $url])->first();

        if ($credential) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [trans('shopify::app.shopify.credential.already_taken')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data['active'] = 1;

        try {
            $data = $this->prepareCredentialToken($data);
        } catch (\RuntimeException $exception) {
            return new JsonResponse([
                'errors' => [
                    'clientId' => [trans('shopify::app.shopify.credential.token_refresh_failed')],
                    'clientSecret' => [trans('shopify::app.shopify.credential.token_refresh_failed')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (empty($data['accessToken'])) {
            return new JsonResponse([
                'errors' => [
                    'clientId' => [trans('shopify::app.shopify.credential.token_required_or_oauth')],
                    'clientSecret' => [trans('shopify::app.shopify.credential.token_required_or_oauth')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = $this->requestGraphQlApiAction('getOneProduct', $data);

        if ($response['code'] != JsonResponse::HTTP_OK) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [trans('shopify::app.shopify.credential.invalid')],
                    'accessToken' => [trans('shopify::app.shopify.credential.invalid')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $credentialCreate = $this->shopifyRepository->create($data);

            session()->flash('success', trans('shopify::app.shopify.credential.created'));
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'clientId')) {
                return response()->json([
                    'message' => trans('shopify::app.shopify.credential.system_update_required'),
                ], 422);
            }

            return new JsonResponse([
                'errors' => [
                    'shopUrl' => [$e->getMessage()],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse([
            'redirect_url' => route('shopify.credentials.edit', $credentialCreate->id),
        ]);
    }

    /**
     * Delete a Shopify credential by ID.
     */
    public function destroy(int $id): JsonResponse
    {
        $this->shopifyRepository->delete($id);

        return new JsonResponse([
            'message' => trans('shopify::app.shopify.credential.delete-success'),
        ]);
    }

    /**
     * Sync the regenerated UnoPim secret + base URL with the Shopify proxy
     * for this credential's shop. Steps:
     *   1. Pull extras.unopim_client_id + shopUrl from the row.
     *   2. Verify that client_id exists in the integration system.
     *   3. Regenerate the OAuth client's secret (and revoke old tokens).
     *   4. POST { secret_key, base_url, domain } to the proxy sync endpoint.
     *   5. Return success / failure with a meaningful message.
     */
    public function sync(int $id): JsonResponse
    {
        $credential = $this->resolveOwnedSaasCredential($id);
        $unopimClientId = $credential->extras['unopim_client_id'] ?? null;
        $shopDomain = $this->extractShopDomain((string) $credential->shopUrl);

        if (! $unopimClientId || ! $shopDomain) {
            return new JsonResponse([
                'message' => trans('shopify::app.shopify.credential.sync-missing-data'),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $oauthClient = DB::table('oauth_clients')
            ->where('id', $unopimClientId)
            ->where('revoked', false)
            ->first();

        $integrationExists = $oauthClient && DB::table('api_keys')
            ->where('oauth_client_id', $unopimClientId)
            ->where('revoked', false)
            ->exists();

        if (! $integrationExists) {
            return new JsonResponse([
                'message' => trans('shopify::app.shopify.credential.sync-client-not-found'),
            ], JsonResponse::HTTP_NOT_FOUND);
        }

        if (empty($oauthClient->secret)) {
            return new JsonResponse([
                'message' => trans('shopify::app.shopify.credential.sync-missing-data'),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $payload = [
            'secret_key' => $oauthClient->secret,
            'base_url' => rtrim((string) config('app.url'), '/'),
            'domain' => $shopDomain,
        ];

        $proxy = new SaasProxyClient(
            (string) config('shopify.saas.proxy_url'),
            (string) $credential->accessToken,
            (int) config('shopify.saas.request_timeout', 30)
        );

        $result = $proxy->syncUnopim((string) config('shopify.saas.sync_path'), $payload);
        $status = $result['body']['user']['status'] ?? null;
        $okStatus = $status === 'success';

        if (! $result['ok'] || ! $okStatus) {
            Log::warning('Shopify SaaS sync failed', [
                'credential_id' => $credential->id,
                'domain' => $shopDomain,
                'http_code' => $result['code'],
                'body' => $result['body'],
            ]);

            return new JsonResponse([
                'message' => trans('shopify::app.shopify.credential.sync-failed'),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        return new JsonResponse([
            'message' => trans('shopify::app.shopify.credential.sync-success'),
        ]);
    }

    /**
     * Revoke the SaaS credential: ask the proxy to remove the Shopify
     * connection, then delete the local row only on success.
     */
    public function revoke(int $id): JsonResponse
    {
        $credential = $this->resolveOwnedSaasCredential($id);

        $proxy = new SaasProxyClient(
            (string) config('shopify.saas.proxy_url'),
            (string) $credential->accessToken,
            (int) config('shopify.saas.request_timeout', 30)
        );

        $revoked = $proxy->revoke(
            (string) config('shopify.saas.revoke_path'),
            $this->extractShopDomain((string) $credential->shopUrl)
        );

        if (! $revoked) {
            return new JsonResponse([
                'message' => trans('shopify::app.shopify.credential.revoke-failed'),
            ], JsonResponse::HTTP_BAD_GATEWAY);
        }

        $this->shopifyRepository->delete($credential->id);

        return new JsonResponse([
            'message' => trans('shopify::app.shopify.credential.revoke-success'),
        ]);
    }

    /**
     * Reduce a stored shopUrl ("https://shop.myshopify.com/") to the bare
     * domain ("shop.myshopify.com") expected by the proxy revoke endpoint.
     */
    protected function extractShopDomain(string $shopUrl): string
    {
        $host = parse_url($shopUrl, PHP_URL_HOST);

        return $host ?: trim(preg_replace('#^https?://#i', '', $shopUrl), '/');
    }

    /**
     * Load the SaaS credential and verify the logged-in admin owns it via
     * matching extras.unopim_client_id against an api_keys.oauth_client_id row.
     */
    protected function resolveOwnedSaasCredential(int $id): ShopifyCredentialsConfig
    {
        $credential = $this->shopifyRepository->find($id);

        if (! $credential || empty($credential->extras['saas'])) {
            abort(404);
        }

        $unopimClientId = $credential->extras['unopim_client_id'] ?? null;
        $adminId = auth()->guard('admin')->id() ?? auth()->id();

        if (! $unopimClientId || ! $adminId) {
            abort(403);
        }

        $owns = DB::table('api_keys')
            ->where('admin_id', $adminId)
            ->where('oauth_client_id', $unopimClientId)
            ->exists();

        if (! $owns) {
            abort(403);
        }

        return $credential;
    }

    /**
     * Edit a Shopify credential by ID.
     *
     * @return View
     */
    public function edit(int $id)
    {
        $credential = $this->shopifyRepository->find($id);

        if (! $credential) {
            abort(404);
        }

        $credentialData = $credential->getAttributes();
        $credentialData['credentialId'] = $credential->id;

        $isSaas = ! empty($credential->extras['saas']);

        if ($isSaas) {
            $proxy = new SaasProxyClient(
                (string) config('shopify.saas.proxy_url'),
                (string) $credential->accessToken,
                (int) config('shopify.saas.request_timeout', 30)
            );

            $shopLocales = $proxy->shopLocales();
            $publishingChannel = $proxy->publications();
            $locationAll = $proxy->locations();
        } else {
            $response = $this->requestGraphQlApiAction('getShopPublishedLocales', $credentialData);
            $publishing = $this->requestGraphQlApiAction('getPublications', $credentialData);
            $locationGetting = $this->requestGraphQlApiAction('getignLocations', $credentialData);

            $locationAll = $locationGetting['body']['data']['locations']['edges'] ?? [];
            $publishingChannel = $publishing['body']['data']['publications']['edges'] ?? [];
            $shopLocales = $response['body']['data']['shopLocales'] ?? [];
        }

        $apiVersion = (new ShoifyApiVersion)->getApiVersion();

        $credential->accessToken = str_repeat('*', strlen($credential->accessToken));
        $credential->clientSecret = str_repeat('*', strlen($credential->clientSecret ?? ''));

        return view('shopify::credential.edit', compact('credential', 'shopLocales', 'publishingChannel', 'locationAll', 'apiVersion', 'isSaas'));
    }

    /**
     * Update a Shopify credential by ID.
     *
     * @return JsonResponse
     */
    public function update(int $id)
    {
        $requestData = request()->except(['code']);
        $requestData['storelocaleMapping'] = $this->normalizeStoreLocaleMapping($requestData['storelocaleMapping'] ?? []);

        $this->validate(request(), [
            'shopUrl' => 'required|url:http,https',
            'apiVersion' => 'required',
            'accessToken' => 'nullable',
            'clientId' => 'nullable',
            'clientSecret' => 'nullable',
        ]);

        $requestData['apiVersion'] = request()->input('apiVersion');

        $credential = $this->shopifyRepository->find($id);

        if (! $credential) {
            abort(404);
        }

        $isSaas = ! empty($credential->extras['saas']);

        if ($isSaas) {
            /**
             * SaaS credentials authenticate through the Shopify proxy, so the
             * connection fields are shown read-only on the edit screen. Ignore
             * whatever the form posts for them and keep the stored values —
             * only the publishing channel, location and locale mapping (the
             * three configurable APIs) may be reconfigured here.
             */
            $requestData['shopUrl'] = $credential->shopUrl;
            $requestData['clientId'] = $credential->clientId;
            $requestData['clientSecret'] = $credential->clientSecret;
            $requestData['accessToken'] = $credential->accessToken;
            $requestData['apiVersion'] = $credential->apiVersion;
        }

        try {
            $requestData = $this->prepareCredentialToken($requestData, $credential);
        } catch (\RuntimeException) {
            return redirect()->route('shopify.credentials.edit', $id)
                ->withErrors([
                    'accessToken' => trans('shopify::app.shopify.credential.token_refresh_failed'),
                ])
                ->withInput();
        }

        if (empty($requestData['accessToken'])) {
            return redirect()->route('shopify.credentials.edit', $id)
                ->withErrors([
                    'accessToken' => trans('shopify::app.shopify.credential.token_required_or_oauth'),
                ])
                ->withInput();
        }

        if ($isSaas) {
            $proxy = new SaasProxyClient(
                (string) config('shopify.saas.proxy_url'),
                (string) $requestData['accessToken'],
                (int) config('shopify.saas.request_timeout', 30)
            );
            $connectionOk = $proxy->ping();
        } else {
            $response = $this->requestGraphQlApiAction('getOneProduct', $requestData);
            $connectionOk = ($response['code'] ?? null) == 200;
        }

        if (! $connectionOk) {
            return redirect()->route('shopify.credentials.edit', $id)
                ->withErrors([
                    'shopUrl' => trans('shopify::app.shopify.credential.invalid'),
                    'accessToken' => trans('shopify::app.shopify.credential.invalid'),
                ])
                ->withInput();
        }

        $keyOrder = ['name', 'locale', 'primary', 'published'];

        $languages = json_decode($requestData['storeLocales'], true);

        $languages = array_map(function ($item) use ($keyOrder) {
            return array_merge(array_flip($keyOrder), $item);
        }, $languages);

        $languages = array_map(function ($language) {
            if ($language['primary']) {
                $language['defaultlocale'] = true;
            }

            return $language;
        }, $languages);

        $requestData['storeLocales'] = $languages;

        $extras = is_array($credential->extras) ? $credential->extras : [];

        $extras['locations'] = $requestData['locations'] ?? null;

        $extras['salesChannel'] = $requestData['salesChannel'] ?? null;

        $requestData['extras'] = $extras;

        unset($requestData['salesChannel']);
        unset($requestData['locations']);

        $this->shopifyRepository->update($requestData, $id);

        session()->flash('success', trans('shopify::app.shopify.credential.update-success'));

        return redirect()->route('shopify.credentials.edit', $id);
    }

    protected function prepareCredentialToken(array $requestData, ?ShopifyCredentialsConfig $credential = null): array
    {
        $requestData['shopUrl'] = rtrim((string) ($requestData['shopUrl'] ?? ''), '/');

        $requestData['accessToken'] = $this->resolveMaskedValue(
            (string) ($requestData['accessToken'] ?? ''),
            $credential?->accessToken
        );
        $requestData['clientSecret'] = $this->resolveMaskedValue(
            (string) ($requestData['clientSecret'] ?? ''),
            $credential?->clientSecret
        );
        if (! empty($credential?->id)) {
            $requestData['credentialId'] = $credential->id;
        }

        if (! empty($credential?->accessTokenExpiresAt)) {
            $requestData['accessTokenExpiresAt'] = $credential->accessTokenExpiresAt->toDateTimeString();
        }

        if ($this->shopifyAccessTokenManager->canAutoGenerateAccessToken($requestData)) {
            $requestData = $this->shopifyAccessTokenManager->ensureValidAccessToken($requestData);
        }

        return $requestData;
    }

    /**
     * Preserve blank locale mappings as empty strings instead of null.
     */
    protected function normalizeStoreLocaleMapping(array $storelocaleMapping): array
    {
        foreach ($storelocaleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
            $storelocaleMapping[$shopifyLocaleCode] = $unopimLocaleCode ?? '';
        }

        return $storelocaleMapping;
    }

    protected function resolveMaskedValue(string $incomingValue, ?string $existingValue): string
    {
        if (empty($existingValue)) {
            return $incomingValue;
        }

        $maskedValue = str_repeat('*', strlen($existingValue));

        if ($incomingValue === $maskedValue) {
            return $existingValue;
        }

        return $incomingValue;
    }
}
