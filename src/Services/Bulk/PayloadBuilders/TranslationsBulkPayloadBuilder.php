<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Webkul\Shopify\Services\ProductPhaseDataService;

class TranslationsBulkPayloadBuilder
{
    protected array $translationFieldMap = [
        'title' => 'title',
        'descriptionHtml' => 'body_html',
        'handle' => 'handle',
        'productType' => 'product_type',
        'metafields_global_title_tag' => 'metafields.global.title_tag',
        'metafields_global_description_tag' => 'metafields.global.description_tag',
    ];

    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService
    ) {}

    /**
     * Build JSONL payload lines for translationsRegister mutation.
     *
     * One product per line with aggregated translations across all locales:
     * {
     *   "resourceId": "gid://shopify/Product/123",
     *   "translations": [
     *     { "key": "title", "value": "...", "locale": "fr" },
     *     { "key": "body_html", "value": "...", "locale": "fr" }
     *   ]
     * }
     *
     * @param  array  $entries  Successful productSet entries
     * @param  int  $credentialId  Credential ID
     * @param  string  $channel  Channel key
     * @param  string  $currency  Currency code
     * @param  array  $storeLocaleMapping  Map: shopifyLocale => unopimLocale
     * @param  array  $storeLocales  Array of locale objects from credential
     * @return array  JSONL lines
     */
    public function build(
        array $entries,
        int $credentialId,
        string $channel,
        string $currency,
        array $storeLocaleMapping,
        array $storeLocales
    ): array {
        if (count($storeLocaleMapping) < 2) {
            return [];
        }

        // Determine default shopify locale
        $defaultLanguage = null;
        foreach ($storeLocales as $language) {
            if (! empty($language['defaultlocale'])) {
                $defaultLanguage = $language;
                break;
            }
        }

        $shopifyDefaultLocale = $defaultLanguage
            ? ($storeLocaleMapping[$defaultLanguage['locale']] ?? null)
            : null;

        $lines = [];

        foreach ($entries as $entry) {
            if (! empty($entry['user_errors']) || empty($entry['product']['id'])) {
                continue;
            }

            $productId = $entry['product']['id'];
            $manifest = $entry['manifest'] ?? [];
            $productSku = $manifest['product_sku'] ?? null;

            if (! $productSku) {
                continue;
            }

            $translations = $this->buildTranslationsForProduct(
                $productId,
                $productSku,
                $manifest,
                $credentialId,
                $channel,
                $currency,
                $shopifyDefaultLocale,
                $storeLocaleMapping
            );

            if (empty($translations)) {
                continue;
            }

            $line = [
                'resourceId' => $this->ensureGid($productId, 'Product'),
                'translations' => $translations,
            ];

            $lines[] = json_encode($line, JSON_UNESCAPED_SLASHES);
        }

        return $lines;
    }

    /**
     * Build all translation entries for a single product.
     */
    protected function buildTranslationsForProduct(
        string $productId,
        string $sku,
        array $manifest,
        int $credentialId,
        string $channel,
        string $currency,
        ?string $shopifyDefaultLocale,
        array $storeLocaleMapping
    ): array {
        $translations = [];

        // Fetch product context once per product SKU
        $context = $this->productPhaseDataService->getProductContext(
            $sku,
            $credentialId,
            $channel,
            $currency
        );

        if (! $context) {
            return [];
        }

        $productData = $context['parent_data'] ?: $context['row_data'];
        $defaultFields = $context['merged_fields'] ?? [];
        $exportMapping = $context['export_mapping']->mapping ?? [];

        foreach ($storeLocaleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
            if ($shopifyDefaultLocale === $unopimLocaleCode) {
                continue; // Skip default locale
            }

            $localeFields = $this->productPhaseDataService->getAllAttributeValues(
                $productData,
                $channel,
                $unopimLocaleCode
            );

            foreach (($exportMapping['shopify_connector_settings'] ?? []) as $shopifyField => $unopimField) {
                if (! isset($this->translationFieldMap[$shopifyField])) {
                    continue;
                }

                $translationKey = $this->translationFieldMap[$shopifyField];
                $value = $localeFields[$unopimField] ?? '';
                $defaultValue = $defaultFields[$unopimField] ?? '';

                if (empty($value) || ! is_string($value)) {
                    continue;
                }

                $translations[] = [
                    'key' => $translationKey,
                    'value' => $value,
                    'locale' => $shopifyLocaleCode,
                    'translatableContentDigest' => hash('sha256', (string) $defaultValue),
                ];
            }
        }

        return $translations;
    }

    /**
     * Ensure an ID is in Shopify GID format.
     */
    protected function ensureGid(string $id, string $type): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return "gid://shopify/{$type}/{$id}";
    }
}
