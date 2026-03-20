<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;

class ShopifyAccessTokenManager
{
    /**
     * In-memory credential cache to avoid repeated DB hits in a single process.
     */
    protected static array $credentialCache = [];

    public function __construct(
        protected ShopifyCredentialRepository $shopifyCredentialRepository
    ) {}

    public function canAutoGenerateAccessToken(array $credential): bool
    {
        return ! empty($credential['shopUrl'])
            && ! empty($credential['clientId'])
            && ! empty($credential['clientSecret']);
    }

    public function ensureValidAccessToken(array $credential): array
    {
        $credential = $this->resolveCredential($credential);

        if (! $this->canAutoGenerateAccessToken($credential)) {
            return $credential;
        }

        $token = (string) ($credential['accessToken'] ?? '');
        $expiresAt = $credential['accessTokenExpiresAt'] ?? null;

        if ($token === '' || $this->isExpiringSoon($expiresAt)) {
            return $this->regenerateAccessToken($credential);
        }

        return $credential;
    }

    /**
     * @throws \RuntimeException
     */
    public function regenerateAccessToken(array $credential): array
    {
        $credential = $this->resolveCredential($credential);

        if (! $this->canAutoGenerateAccessToken($credential)) {
            throw new \RuntimeException(trans('shopify::app.shopify.credential.auto_refresh_not_configured'));
        }

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(30)
            ->post($this->tokenEndpoint($credential['shopUrl']), [
                'client_id'     => $credential['clientId'],
                'client_secret' => $credential['clientSecret'],
                'grant_type'    => 'client_credentials',
            ]);

        if (! $response->ok()) {
            $message = $response->json('error_description')
                ?? $response->json('error')
                ?? trans('shopify::app.shopify.credential.unable_to_refresh_access_token');

            throw new \RuntimeException($message);
        }

        $accessToken = $response->json('access_token');

        if (empty($accessToken)) {
            throw new \RuntimeException(trans('shopify::app.shopify.credential.invalid_access_token_response'));
        }

        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        $accessTokenExpiresAt = $expiresIn > 0
            ? Carbon::now()->addSeconds($expiresIn)->toDateTimeString()
            : null;

        $credential['accessToken'] = $accessToken;
        $credential['accessTokenExpiresAt'] = $accessTokenExpiresAt;

        if (! empty($credential['credentialId'])) {
            $this->shopifyCredentialRepository->update([
                'accessToken'          => $accessToken,
                'accessTokenExpiresAt' => $accessTokenExpiresAt,
            ], $credential['credentialId']);
        }

        if (! empty($credential['credentialId'])) {
            self::$credentialCache[(int) $credential['credentialId']] = $credential;
        }

        return $credential;
    }

    protected function resolveCredential(array $credential): array
    {
        $credentialId = (int) ($credential['credentialId'] ?? 0);

        if ($credentialId <= 0) {
            return $credential;
        }

        if (isset(self::$credentialCache[$credentialId])) {
            return array_merge($credential, self::$credentialCache[$credentialId]);
        }

        $credentialModel = $this->shopifyCredentialRepository->find($credentialId);

        if (! $credentialModel) {
            return $credential;
        }

        $resolved = array_merge($credential, [
            'credentialId'         => $credentialModel->id,
            'shopUrl'              => $credentialModel->shopUrl,
            'accessToken'          => $credentialModel->accessToken,
            'apiVersion'           => $credential['apiVersion'] ?? $credentialModel->apiVersion,
            'clientId'             => $credentialModel->clientId,
            'clientSecret'         => $credentialModel->clientSecret,
            'accessTokenExpiresAt' => optional($credentialModel->accessTokenExpiresAt)?->toDateTimeString(),
        ]);

        self::$credentialCache[$credentialId] = $resolved;

        return $resolved;
    }

    protected function isExpiringSoon(?string $expiresAt): bool
    {
        if (empty($expiresAt)) {
            return false;
        }

        return Carbon::parse($expiresAt)->subMinutes(5)->isPast();
    }

    protected function tokenEndpoint(string $shopUrl): string
    {
        $shopUrl = rtrim($shopUrl, '/');

        return $shopUrl.'/admin/oauth/access_token';
    }
}
