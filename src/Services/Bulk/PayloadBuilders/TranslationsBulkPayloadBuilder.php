<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders;

use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Services\ProductPhaseDataService;

class TranslationsBulkPayloadBuilder
{
    protected array $translationFieldMap = [
        'title'                             => 'title',
        'descriptionHtml'                   => 'body_html',
        'handle'                            => 'handle',
        'productType'                       => 'product_type',
        'metafields_global_title_tag'       => 'meta_title',
        'metafields_global_description_tag' => 'meta_description',
    ];

    public function __construct(
        protected ProductPhaseDataService $productPhaseDataService,
        protected ShopifyCredentialRepository $credentialRepository,
        protected ShopifyMappingRepository $mappingRepository,
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
     * @param  array<string, string>  $metafieldAliases  Map: resultAlias => namespace.key
     * @return array JSONL lines
     */
    public function build(
        array $entries,
        int $credentialId,
        string $channel,
        string $currency,
        array $storeLocaleMapping,
        array $storeLocales,
        array $metafieldAliases = []
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

        $productIdBySku = [];
        $unresolvedSkus = [];
        $metafieldGidMap = [];

        foreach ($entries as $entry) {
            $sku = $entry['manifest']['product_sku'] ?? null;

            if (! $sku) {
                continue;
            }

            $product = $entry['product'] ?? [];

            if (empty($product['id'])) {
                $unresolvedSkus[$sku] = true;

                continue;
            }

            $productIdBySku[$sku] = $product['id'];

            // Metafield instance GIDs return inline in the core result under the aliases
            // injected into productSetBulk — works for manual and SaaS alike, so no
            // separate metafield read is needed.
            foreach ($metafieldAliases as $alias => $nameSpaceKey) {
                $gid = $product[$alias]['id'] ?? null;

                if ($gid) {
                    $metafieldGidMap[$product['id']][$nameSpaceKey] = $gid;
                }
            }
        }

        // Products recreated after a stale-mapping NOT_FOUND are absent from the core
        // result file (null product id); resolve their GID from the freshly-synced
        // mapping so their product-level translations are still registered.
        $unresolvedSkus = array_diff_key($unresolvedSkus, $productIdBySku);

        if (! empty($unresolvedSkus)) {
            $productIdBySku += $this->resolveProductGidsBySku(array_keys($unresolvedSkus), $credentialId);
        }

        $lines = [];

        foreach ($entries as $entry) {
            $productSku = $entry['manifest']['product_sku'] ?? null;
            $productId = $productSku ? ($productIdBySku[$productSku] ?? null) : null;

            if (! $productId) {
                continue;
            }

            $built = $this->buildTranslationsForProduct(
                $metafieldGidMap[$productId] ?? [],
                $productSku,
                $credentialId,
                $channel,
                $currency,
                $shopifyDefaultLocale,
                $storeLocaleMapping
            );

            if (! empty($built['product'])) {
                $lines[] = json_encode([
                    'resourceId'   => $this->ensureGid($productId, 'Product'),
                    'translations' => $built['product'],
                ], JSON_UNESCAPED_SLASHES);
            }

            foreach ($built['metafields'] as $gid => $translations) {
                $lines[] = json_encode([
                    'resourceId'   => $gid,
                    'translations' => $translations,
                ], JSON_UNESCAPED_SLASHES);
            }
        }

        return $lines;
    }

    /**
     * Resolve `productSku => productGid` from synced product mappings, for products
     * absent from the core result file (recreated after a stale-mapping NOT_FOUND).
     *
     * @param  array<int, string>  $skus
     * @return array<string, string>
     */
    protected function resolveProductGidsBySku(array $skus, int $credentialId): array
    {
        $credential = $this->credentialRepository->find($credentialId);

        if (! $credential) {
            return [];
        }

        $map = [];

        foreach ($this->mappingRepository->findWhereIn('code', array_values($skus)) as $row) {
            if ($row->entityType !== 'product' || $row->apiUrl !== $credential->shopUrl) {
                continue;
            }

            $gid = $row->relatedId ?: $row->externalId;

            if ($gid) {
                $map[$row->code] = $gid;
            }
        }

        return $map;
    }

    /**
     * Build product and metafield translation entries for a single product.
     *
     * @return array{product: array<int, array>, metafields: array<string, array<int, array>>}
     */
    protected function buildTranslationsForProduct(
        array $metafieldGidMap,
        string $sku,
        int $credentialId,
        string $channel,
        string $currency,
        ?string $shopifyDefaultLocale,
        array $storeLocaleMapping
    ): array {
        $context = $this->productPhaseDataService->getProductContext($sku, $credentialId, $channel, $currency);

        if (! $context) {
            return ['product' => [], 'metafields' => []];
        }

        $productData = $context['parent_data'] ?: $context['row_data'];
        $defaultFields = $context['merged_fields'] ?? [];
        $exportMapping = $context['export_mapping']->mapping ?? [];

        $metafieldDefs = $this->resolveTranslatableMetafields(
            $context['product_metafields'] ?? [],
            $metafieldGidMap,
            $defaultFields,
            $context['attributes'] ?? []
        );

        $productTranslations = [];
        $metafieldTranslations = [];

        foreach ($storeLocaleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
            if ($shopifyDefaultLocale === $unopimLocaleCode) {
                continue;
            }

            $localeFields = $this->productPhaseDataService->getAllAttributeValues($productData, $channel, $unopimLocaleCode);

            foreach (($exportMapping['shopify_connector_settings'] ?? []) as $shopifyField => $unopimField) {
                if (! isset($this->translationFieldMap[$shopifyField])) {
                    continue;
                }

                $value = $localeFields[$unopimField] ?? '';

                if (empty($value) || ! is_string($value)) {
                    continue;
                }

                $productTranslations[] = [
                    'key'                       => $this->translationFieldMap[$shopifyField],
                    'value'                     => $value,
                    'locale'                    => $shopifyLocaleCode,
                    'translatableContentDigest' => hash('sha256', (string) ($defaultFields[$unopimField] ?? '')),
                ];
            }

            foreach ($metafieldDefs as $def) {
                $value = $localeFields[$def['code']] ?? '';

                if (empty($value) || ! is_string($value)) {
                    continue;
                }

                $metafieldTranslations[$def['gid']][] = [
                    'key'                       => 'value',
                    'value'                     => $value,
                    'locale'                    => $shopifyLocaleCode,
                    'translatableContentDigest' => $def['digest'],
                ];
            }
        }

        return ['product' => $productTranslations, 'metafields' => $metafieldTranslations];
    }

    /**
     * Resolve translatable text-type, per-locale metafield definitions to their
     * exported GID and default-value digest.
     *
     * @return array<int, array{code: string, gid: string, digest: string}>
     */
    protected function resolveTranslatableMetafields(array $definitions, array $gidByKey, array $defaultFields, array $attributes): array
    {
        if (empty($definitions) || empty($gidByKey)) {
            return [];
        }

        $resolved = [];

        foreach ($definitions as $def) {
            if (! empty($def['listvalue']) || ! $this->isTranslatableType($def['type'] ?? null)) {
                continue;
            }

            $code = $def['code'] ?? null;

            if (! $code || empty($attributes[$code]) || ! $attributes[$code]->value_per_locale) {
                continue;
            }

            $gid = $gidByKey[$def['name_space_key'] ?? ''] ?? null;

            if (! $gid) {
                continue;
            }

            $resolved[] = [
                'code'   => $code,
                'gid'    => $gid,
                'digest' => hash('sha256', (string) ($defaultFields[$code] ?? '')),
            ];
        }

        return $resolved;
    }

    protected function isTranslatableType(?string $type): bool
    {
        return in_array($type, ['single_line_text_field', 'multi_line_text_field', 'rich_text_field'], true);
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
