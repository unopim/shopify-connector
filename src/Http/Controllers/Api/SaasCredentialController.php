<?php

namespace Webkul\Shopify\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\Shopify\Helpers\ShoifyApiVersion;
use Webkul\Shopify\Http\Requests\SaasCredentialRequest;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;

class SaasCredentialController extends Controller
{
    public function __construct(
        protected ShopifyCredentialRepository $shopifyCredentialRepository
    ) {}

    /**
     * Store a SaaS Shopify credential.
     */
    public function store(SaasCredentialRequest $request): JsonResponse
    {
        return $this->saveCredential($request);
    }

    /**
     * Update (upsert) a SaaS Shopify credential by shopUrl.
     */
    public function update(SaasCredentialRequest $request): JsonResponse
    {
        return $this->saveCredential($request);
    }

    /**
     * Persist a SaaS credential with the minimum the manual store() flow
     * also persists: shopUrl, accessToken, apiVersion, storelocaleMapping=[].
     * Sales channels, locations, and storeLocales are fetched live by the
     * edit page (via SaasProxyClient) and saved when the admin clicks save.
     */
    protected function saveCredential(SaasCredentialRequest $request): JsonResponse
    {
        $shopUrl = rtrim($request->input('shopUrl'), '/');

        if (! preg_match('#^https?://#i', $shopUrl)) {
            $shopUrl = 'https://'.$shopUrl;
        }

        $accessToken = $request->input('accessToken');
        $apiVersion = $this->resolveLatestApiVersion();

        Log::info('Shopify SaaS credential payload received', [
            'shopUrl' => $shopUrl,
            'apiVersion' => $apiVersion,
            'accessTokenPrefix' => $this->describeToken($accessToken),
        ]);

        $existing = $this->shopifyCredentialRepository->findOneByField('shopUrl', $shopUrl);

        $extras = is_array($existing?->extras) ? $existing->extras : [];
        $extras['saas'] = true;

        if ($request->filled('unopim_client_id')) {
            $extras['unopim_client_id'] = $request->input('unopim_client_id');
        }

        $payload = [
            'shopUrl' => $shopUrl,
            'accessToken' => $accessToken,
            'clientId' => null,
            'clientSecret' => null,
            'accessTokenExpiresAt' => null,
            'apiVersion' => $apiVersion,
            'active' => true,
            'extras' => $extras,
        ];

        if (! $existing) {
            $payload['storelocaleMapping'] = [];
        }

        $credential = $existing
            ? $this->shopifyCredentialRepository->update($payload, $existing->id)
            : $this->shopifyCredentialRepository->create($payload);

        return new JsonResponse([
            'success' => true,
            'id' => $credential->id,
            'message' => $existing ? 'Credential updated successfully.' : 'Credential saved successfully.',
        ], $existing ? 200 : 201);
    }

    /**
     * Describe a Shopify access token without leaking it: report length and
     * a recognised prefix family so logs reveal whether the proxy sent an
     * Admin API token (`shpat_`), a session JWT, or something else entirely.
     */
    protected function describeToken(?string $token): array
    {
        if (empty($token)) {
            return ['present' => false];
        }

        $shape = 'unknown';
        if (str_starts_with($token, 'shpat_')) {
            $shape = 'admin_offline_token';
        } elseif (str_starts_with($token, 'shpca_')) {
            $shape = 'custom_app_token';
        } elseif (str_starts_with($token, 'eyJ')) {
            $shape = 'jwt_session_token';
        }

        return [
            'present' => true,
            'length' => strlen($token),
            'shape' => $shape,
        ];
    }

    /**
     * Resolve the latest Shopify API version supported by the package.
     */
    protected function resolveLatestApiVersion(): string
    {
        $versions = (new ShoifyApiVersion)->getApiVersion();

        return end($versions)['id'] ?? '2026-01';
    }
}
