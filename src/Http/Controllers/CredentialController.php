<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\DataGrids\Catalog\CredentialDataGrid;
use Webkul\Shopify\Helpers\ShoifyApiVersion;
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

        return view('shopify::credential.index', compact('apiVersion'));
    }

    /**
     * Create a new Shopify credential.
     */
    public function store(CredentialForm $request): JsonResponse
    {
        $data = $request->all();
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
                    'clientId'     => [trans('shopify::app.shopify.credential.token_refresh_failed')],
                    'clientSecret' => [trans('shopify::app.shopify.credential.token_refresh_failed')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (empty($data['accessToken'])) {
            return new JsonResponse([
                'errors' => [
                    'clientId'     => [trans('shopify::app.shopify.credential.token_required_or_oauth')],
                    'clientSecret' => [trans('shopify::app.shopify.credential.token_required_or_oauth')],
                ],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response = $this->requestGraphQlApiAction('getOneProduct', $data);

        if ($response['code'] != JsonResponse::HTTP_OK) {
            return new JsonResponse([
                'errors' => [
                    'shopUrl'     => [trans('shopify::app.shopify.credential.invalid')],
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

        $response = $this->requestGraphQlApiAction('getShopPublishedLocales', $credentialData);

        $publishing = $this->requestGraphQlApiAction('getPublications', $credentialData);

        $locationGetting = $this->requestGraphQlApiAction('getignLocations', $credentialData);

        $locationAll = $locationGetting['body']['data']['locations']['edges'] ?? [];

        $publishingChannel = $publishing['body']['data']['publications']['edges'] ?? [];

        $shopLocales = $response['body']['data']['shopLocales'] ?? [];

        $apiVersion = (new ShoifyApiVersion)->getApiVersion();

        $credential->accessToken = str_repeat('*', strlen($credential->accessToken));
        $credential->clientSecret = str_repeat('*', strlen($credential->clientSecret ?? ''));

        return view('shopify::credential.edit', compact('credential', 'shopLocales', 'publishingChannel', 'locationAll', 'apiVersion'));
    }

    /**
     * Update a Shopify credential by ID.
     *
     * @return JsonResponse
     */
    public function update(int $id)
    {
        $requestData = request()->except(['code']);

        $this->validate(request(), [
            'shopUrl'      => 'required|url:http,https',
            'apiVersion'   => 'required',
            'accessToken'  => 'nullable',
            'clientId'     => 'nullable',
            'clientSecret' => 'nullable',
        ]);

        $requestData['apiVersion'] = request()->input('apiVersion');

        $credential = $this->shopifyRepository->find($id);

        if (! $credential) {
            abort(404);
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

        $response = $this->requestGraphQlApiAction('getOneProduct', $requestData);

        if ($response['code'] != 200) {
            return redirect()->route('shopify.credentials.edit', $id)
                ->withErrors([
                    'shopUrl'     => trans('shopify::app.shopify.credential.invalid'),
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
