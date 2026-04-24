<?php

namespace Webkul\Shopify\Services;

use Webkul\Shopify\Models\ShopifyBulkOperation;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class TranslationPhaseService
{
    use ShopifyGraphqlRequest;

    protected array $translationFieldMap = [
        'title' => 'title',
        'descriptionHtml' => 'body_html',
        'handle' => 'handle',
        'productType' => 'product_type',
        'metafields_global_title_tag' => 'meta_title',
        'metafields_global_description_tag' => 'meta_description',
    ];

    public function __construct(protected ProductPhaseDataService $productPhaseDataService) {}

    /**
     * Register product translations for secondary locales.
     */
    public function handle(ShopifyBulkOperation $bulkOperation, array $operationData): array
    {
        $manifest = $operationData['manifest'];
        $credentialArray = $this->buildCredentialArray($manifest);
        $processed = 0;
        $errors = [];

        foreach ($operationData['entries'] as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            $context = $this->productPhaseDataService->getProductContext(
                $entry['manifest']['product_sku'],
                (int) ($manifest['credential_id'] ?? 0),
                $manifest['channel'] ?? 'default',
                $manifest['currency'] ?? 'USD'
            );

            if (! $context || count($context['credential']->storelocaleMapping ?? []) < 2) {
                continue;
            }

            $digestResponse = $this->requestGraphQlApiAction('translatableResource', $credentialArray, [
                'resourceId' => $entry['product']['id'],
            ]);

            $translatableContent = $digestResponse['body']['data']['translatableResource']['translatableContent'] ?? [];
            $digests = [];

            foreach ($translatableContent as $content) {
                $digests[$content['key']] = $content['digest'];
            }

            $translations = [];
            $productData = $context['parent_data'] ?: $context['row_data'];
            $commonFields = $this->productPhaseDataService->getAllAttributeValues(
                $productData,
                $manifest['channel'] ?? 'default',
                $context['shopify_default_locale']
            );

            foreach ($context['credential']->storelocaleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
                if ($context['shopify_default_locale'] === $unopimLocaleCode) {
                    continue;
                }

                $localeFields = $this->productPhaseDataService->getAllAttributeValues(
                    $productData,
                    $manifest['channel'] ?? 'default',
                    $unopimLocaleCode
                );

                foreach (($context['export_mapping']->mapping['shopify_connector_settings'] ?? []) as $shopifyField => $unopimField) {
                    if (! isset($this->translationFieldMap[$shopifyField])) {
                        continue;
                    }

                    $translationKey = $this->translationFieldMap[$shopifyField];
                    $value = $localeFields[$unopimField] ?? '';

                    if ($translationKey === 'meta_title') {
                        $value = $localeFields[$unopimField] ?? '';
                    }

                    if ($translationKey === 'meta_description') {
                        $value = $localeFields[$unopimField] ?? '';
                    }

                    $digest = $digests[$translationKey] ?? null;

                    if ($digest === null || $value === '') {
                        continue;
                    }

                    $translations[] = [
                        'key' => $translationKey,
                        'value' => $value,
                        'locale' => $shopifyLocaleCode,
                        'translatableContentDigest' => $digest,
                    ];
                }
            }

            if (empty($translations)) {
                continue;
            }

            $response = $this->requestGraphQlApiAction('createTranslation', $credentialArray, [
                'id' => $entry['product']['id'],
                'translations' => $translations,
            ]);

            $userErrors = $response['body']['data']['translationsRegister']['userErrors'] ?? [];

            if (! empty($userErrors)) {
                $errors[] = [
                    'product_id' => $entry['product']['id'],
                    'errors' => $userErrors,
                ];

                continue;
            }

            $processed++;
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
        ];
    }

    protected function buildCredentialArray(array $manifest): array
    {
        return [
            'credentialId' => $manifest['credential_id'] ?? null,
            'shopUrl' => $manifest['shop_url'] ?? null,
            'accessToken' => $manifest['credential']['accessToken'] ?? null,
            'apiVersion' => $manifest['credential']['apiVersion'] ?? null,
            'clientId' => $manifest['credential']['clientId'] ?? null,
            'clientSecret' => $manifest['credential']['clientSecret'] ?? null,
            'accessTokenExpiresAt' => $manifest['credential']['accessTokenExpiresAt'] ?? null,
        ];
    }
}
