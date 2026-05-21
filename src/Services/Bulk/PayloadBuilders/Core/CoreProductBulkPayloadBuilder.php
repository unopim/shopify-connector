<?php

namespace Webkul\Shopify\Services\Bulk\PayloadBuilders\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Services\ProductValueMapper;
use Webkul\Shopify\Helpers\Exporters\Product\ShopifyGraphQLDataFormatter;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Services\BulkOperationService;

class CoreProductBulkPayloadBuilder
{
    protected array $attributesAll = [];

    protected array $credentialAsArray = [];

    protected mixed $credential = null;

    protected mixed $exportMapping = null;

    protected mixed $settingMapping = null;

    protected array $productMetaFieldMapping = [];

    protected array $variantMetaFieldMapping = [];

    protected ?string $shopifyDefaultLocale = null;

    protected ?string $locationId = null;

    protected ?string $currency = null;

    protected ?string $jobChannel = null;

    public function __construct(
        protected ShopifyCredentialRepository $shopifyCredentialRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
        protected ShopifyMetaFieldRepository $shopifyMetaFieldRepository,
        protected AttributeRepository $attributeRepository,
        protected ShopifyGraphQLDataFormatter $shopifyGraphQLDataFormatter,
        protected ProductValueMapper $productValueMapper,
    ) {}

    /**
     * Build JSONL lines and manifest payload for a batch.
     */
    public function build(array $filters, array $batchRows, int $jobTrackId): array
    {
        $this->initialize($filters);

        $products = $this->fetchProducts($batchRows);
        $groupedProducts = $this->groupProducts($products);

        $lines = [];
        $manifestLines = [];
        $summary = [
            'processed' => count($groupedProducts),
            'created' => 0,
            'skipped' => 0,
        ];

        foreach ($groupedProducts as $group) {
            $payload = $this->buildPayloadForGroup($group, $jobTrackId);

            if (! $payload) {
                $summary['skipped']++;

                continue;
            }

            $summary['created']++;
            $lines[] = json_encode($payload['variables'], JSON_UNESCAPED_SLASHES);
            $manifestLines[] = $payload['manifest'];
        }

        return [
            'lines' => $lines,
            'manifest' => [
                'job_track_id' => $jobTrackId,
                'shop_url' => $this->credential?->shopUrl,
                'credential_id' => $this->credential?->id,
                'credential' => $this->credentialAsArray,
                'channel' => $this->jobChannel,
                'currency' => $this->currency,
                'phase' => BulkOperationService::CORE_PRODUCT_PHASE,
                'follow_up_context' => [
                    'publishing' => true,
                    'media' => true,
                    'translations' => count($this->credential?->storelocaleMapping ?? []) > 1,
                    'inventory' => ! empty($this->locationId),
                    'collections' => true,
                    'publication_ids' => $this->credential?->extras['salesChannel'] ?? '',
                    'location_id' => $this->locationId,
                ],
                'lines' => $manifestLines,
            ],
            'summary' => $summary,
            'credential' => $this->credentialAsArray,
        ];
    }

    /**
     * Initialize context for payload generation.
     */
    protected function initialize(array $filters): void
    {
        $this->currency = $filters['currency'] ?? null;
        $this->jobChannel = $filters['channel'] ?? null;
        $this->credential = $this->shopifyCredentialRepository->find($filters['credentials']);
        $mappings = $this->shopifyExportMappingRepository->findMany([1, 2]);

        $this->exportMapping = $mappings->first();
        $this->settingMapping = $mappings->last();
        $this->productMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCT')->get()->toArray();
        $this->variantMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCTVARIANT')->get()->toArray();
        $this->attributesAll = $this->attributeRepository->all()->keyBy('code')->all();
        $this->locationId = $this->credential?->extras['locations'] ?? null;

        $defaultLanguage = array_values(array_filter($this->credential?->storeLocales ?? [], function ($language) {
            return isset($language['defaultlocale']) && $language['defaultlocale'] === true;
        }))[0] ?? null;

        $this->shopifyDefaultLocale = $this->credential?->storelocaleMapping[$defaultLanguage['locale'] ?? ''] ?? null;
        $this->credentialAsArray = $this->credential?->toApiArray() ?? [];

        $this->shopifyGraphQLDataFormatter->setInitialData(
            $this->locationId ?? '',
            $this->currency ?? 'USD',
            $this->settingMapping,
            $this->attributesAll
        );
    }

    /**
     * Fetch product rows for the current batch.
     */
    protected function fetchProducts(array $batchRows): array
    {
        $skus = array_column($batchRows, 'sku');
        $tablePrefix = DB::getTablePrefix();

        return DB::table('products')
            ->leftJoin('attribute_families as aft', 'products.attribute_family_id', '=', 'aft.id')
            ->leftJoin('products as parent_products', 'products.parent_id', '=', 'parent_products.id')
            ->leftJoin('product_super_attributes as psa', function ($join) {
                $join->on('parent_products.id', '=', 'psa.product_id')
                    ->orOn('products.id', '=', 'psa.product_id');
            })
            ->leftJoin('attributes as attr', 'psa.attribute_id', '=', 'attr.id')
            ->select(
                'products.id',
                'products.sku',
                'products.status',
                'products.type',
                'products.values',
                'products.attribute_family_id',
                'products.additional',
                'aft.code as attribute_family_code',
                'parent_products.id as parent_id',
                'parent_products.sku as parent_sku',
                'parent_products.type as parent_type',
                'parent_products.status as parent_status',
                'parent_products.values as parent_values',
                'parent_products.attribute_family_id as parent_attribute_family_id',
                DB::raw("COALESCE(GROUP_CONCAT(DISTINCT {$tablePrefix}attr.code ORDER BY {$tablePrefix}attr.code ASC SEPARATOR ','), '') as super_attributes")
            )
            ->where(function ($query) use ($skus) {
                $query->whereIn('products.sku', $skus)
                    ->orWhereIn('parent_products.sku', $skus);
            })
            ->where('products.type', '!=', 'configurable')
            ->groupBy('products.id')
            ->get()
            ->map(function ($product) {
                $parent = $product?->parent_values ? [
                    'id' => $product->parent_id,
                    'sku' => $product->parent_sku,
                    'type' => $product->parent_type,
                    'status' => $product->parent_status,
                    'values' => json_decode($product->parent_values, true),
                    'attribute_family_id' => $product->parent_attribute_family_id,
                    'super_attributes' => $this->hydrateSuperAttributes(explode(',', $product->super_attributes)),
                ] : null;

                return [
                    'id' => $product->id,
                    'sku' => $product->sku,
                    'type' => $product->type,
                    'parent' => $parent,
                    'status' => $product->status,
                    'values' => json_decode($product->values, true),
                    'parent_id' => $product->parent_id,
                    'attribute_family_id' => $product->attribute_family_id,
                    'additional' => $product->additional ? json_decode($product->additional, true) : [],
                    'super_attributes' => [],
                ];
            })
            ->all();
    }

    /**
     * Group rows by Shopify product.
     */
    protected function groupProducts(array $products): array
    {
        $grouped = [];

        foreach ($products as $product) {
            $groupSku = $product['parent']['sku'] ?? $product['sku'];

            if (! isset($grouped[$groupSku])) {
                $grouped[$groupSku] = [
                    'product_sku' => $groupSku,
                    'parent' => $product['parent'],
                    'variants' => [],
                ];
            }

            $grouped[$groupSku]['variants'][] = $product;
        }

        return array_values($grouped);
    }

    /**
     * Build a single productSet payload and manifest line.
     */
    protected function buildPayloadForGroup(array $group, int $jobTrackId): ?array
    {
        $parentData = $group['parent'] ?? null;
        $productSku = $group['product_sku'];
        $productMapping = $this->findMapping($productSku);
        $firstVariant = $group['variants'][0] ?? null;

        if (! $firstVariant) {
            return null;
        }

        $parentMergedFields = $parentData ? $this->getAllAttributeValues($parentData) : [];
        $productMergedFields = $parentData ? $parentMergedFields : $this->getAllAttributeValues($firstVariant);
        $productOptions = $this->buildProductOptions($parentData, $group['variants']);
        $productCollections = $this->resolveCollectionIds($group);
        $productIdentifierId = $productMapping[0]['relatedId'] ?? $productMapping[0]['externalId'] ?? null;

        $formattedProduct = $this->shopifyGraphQLDataFormatter->formatDataForGraphql(
            $this->getAllAttributeValues($firstVariant),
            $this->exportMapping->mapping ?? [],
            $this->shopifyDefaultLocale ?? 'en',
            $parentMergedFields,
            $this->productMetaFieldMapping,
            $this->variantMetaFieldMapping
        );

        $productInput = $this->normalizeProductInput($formattedProduct, $productOptions);
        $productInput['handle'] = ($productInput['handle'] ?? null) ?: Str::slug(($productInput['title'] ?? null) ?: $productSku);

        $variantManifest = [];
        $variants = [];

        foreach ($group['variants'] as $variantRow) {
            $variantMapping = $this->findMapping($variantRow['sku']);
            $variantMergedFields = $this->getAllAttributeValues($variantRow);
            $optionValues = $this->buildVariantOptionValues($parentData, $variantMergedFields);
            $formattedVariant = $this->shopifyGraphQLDataFormatter->formatDataForGraphql(
                $variantMergedFields,
                $this->exportMapping->mapping ?? [],
                $this->shopifyDefaultLocale ?? 'en',
                $parentMergedFields,
                $this->productMetaFieldMapping,
                $this->variantMetaFieldMapping
            );

            $variants[] = $this->normalizeVariantInput(
                $formattedVariant['variant'] ?? [],
                ! empty($parentData) ? ($formattedVariant['metafields'] ?? []) : [],
                $optionValues,
                $variantMapping[0]['externalId'] ?? null,
                ! empty($parentData)
            );
            $variantManifest[] = [
                'sku' => $variantRow['sku'],
                'has_media' => $this->variantHasMedia($variantMergedFields),
            ];
        }

        $productInput['variants'] = $variants;

        return [
            'variables' => [
                'identifier' => $productIdentifierId
                    ? ['id' => $productIdentifierId]
                    : ['handle' => $productInput['handle']],
                'input' => $productInput,
            ],
            'manifest' => [
                'product_sku' => $productSku,
                'product_handle' => $productInput['handle'],
                'variant_skus' => array_column($variantManifest, 'sku'),
                'phase_context' => [
                    'collections' => $productCollections,
                    'publishing' => ! empty($this->credential?->extras['salesChannel']),
                    'translations' => count($this->credential?->storelocaleMapping ?? []) > 1,
                    'inventory' => ! empty($this->locationId),
                    'media' => collect($variantManifest)->contains('has_media', true),
                ],
            ],
        ];
    }

    /**
     * Hydrate super attributes from codes.
     */
    protected function hydrateSuperAttributes(array $codes): array
    {
        $superAttributes = [];

        foreach ($codes as $attributeCode) {
            $attribute = $this->attributesAll[$attributeCode] ?? null;

            if (! $attribute) {
                continue;
            }

            $superAttributes[] = [
                'id' => $attribute->id,
                'code' => $attribute->code,
                'name' => $attribute->name,
                'type' => $attribute->type,
                'translations' => $attribute->translations->toArray(),
            ];
        }

        return $superAttributes;
    }

    /**
     * Build product options from configurable attributes.
     */
    protected function buildProductOptions(?array $parentData, array $variants): array
    {
        if (empty($parentData['super_attributes'])) {
            return [[
                'name' => 'Title',
                'position' => 1,
                'values' => [[
                    'name' => 'Default Title',
                ]],
            ]];
        }

        $options = [];

        foreach ($parentData['super_attributes'] as $index => $attributeMeta) {
            if ($index > 2) {
                continue;
            }

            $optionName = $this->resolveOptionName($attributeMeta);
            $values = [];

            foreach ($variants as $variant) {
                $variantValues = $this->getAllAttributeValues($variant);
                $value = $variantValues[$attributeMeta['code']] ?? null;

                if (! $value || isset($values[$value])) {
                    continue;
                }

                $values[$value] = ['name' => $value];
            }

            if (empty($values)) {
                continue;
            }

            $options[] = [
                'name' => $optionName,
                'position' => $index + 1,
                'values' => array_values($values),
            ];
        }

        return $options;
    }

    /**
     * Build option values for a variant row.
     */
    protected function buildVariantOptionValues(?array $parentData, array $variantMergedFields): array
    {
        if (empty($parentData['super_attributes'])) {
            return [[
                'optionName' => 'Title',
                'name' => 'Default Title',
            ]];
        }

        $optionValues = [];

        foreach ($parentData['super_attributes'] as $attributeMeta) {
            $value = $variantMergedFields[$attributeMeta['code']] ?? null;

            if (! $value) {
                continue;
            }

            $optionValues[] = [
                'optionName' => $this->resolveOptionName($attributeMeta),
                'name' => $value,
            ];
        }

        return $optionValues;
    }

    /**
     * Normalize formatter output into ProductSetInput fields.
     */
    protected function normalizeProductInput(array $formattedProduct, array $productOptions): array
    {
        $productInput = array_filter([
            'title' => $formattedProduct['title'] ?? null,
            'status' => $formattedProduct['status'] ?? null,
            'handle' => $formattedProduct['handle'] ?? null,
            'vendor' => $formattedProduct['vendor'] ?? null,
            'descriptionHtml' => $formattedProduct['descriptionHtml'] ?? null,
            'productType' => $formattedProduct['productType'] ?? null,
            'tags' => $formattedProduct['tags'] ?? null,
            'seo' => $formattedProduct['seo'] ?? null,
            'metafields' => $formattedProduct['parentMetaFields'] ?? $formattedProduct['metafields'] ?? null,
        ], fn ($value) => ! is_null($value) && $value !== []);

        if (! empty($productOptions)) {
            $productInput['productOptions'] = $productOptions;
        }

        return $productInput;
    }

    /**
     * Normalize formatter output into ProductSet variant input fields.
     */
    protected function normalizeVariantInput(
        array $variantPayload,
        array $variantMetafields,
        array $optionValues,
        ?string $variantId,
        bool $includeVariantMetafields
    ): array {
        unset($variantPayload['inventoryQuantities']);

        $inventoryItem = $variantPayload['inventoryItem'] ?? [];

        $variantInput = array_filter([
            'id' => $variantId,
            'price' => $variantPayload['price'] ?? null,
            'compareAtPrice' => $variantPayload['compareAtPrice'] ?? null,
            'barcode' => $variantPayload['barcode'] ?? null,
            'taxable' => $variantPayload['taxable'] ?? null,
            'inventoryPolicy' => $variantPayload['inventoryPolicy'] ?? null,
            'metafields' => $includeVariantMetafields ? ($variantMetafields ?: null) : null,
            'inventoryItem' => empty($inventoryItem) ? null : $inventoryItem,
        ], fn ($value) => ! is_null($value) && $value !== []);

        // Shopify's productSet bulk input expects optionValues to be present
        // for variant rows, even when the product has no configurable options.
        $variantInput['optionValues'] = array_values($optionValues);

        return $variantInput;
    }

    /**
     * Merge current values according to UnoPim product scopes.
     */
    protected function getAllAttributeValues(array $rowData): array
    {
        return array_merge(
            $this->productValueMapper->getCommonFields($rowData),
            ['status' => $rowData['status'] == 1 ? 'true' : 'false'],
            $this->productValueMapper->getLocaleSpecificFields($rowData, $this->shopifyDefaultLocale ?? ''),
            $this->productValueMapper->getChannelSpecificFields($rowData, $this->jobChannel ?? ''),
            $this->productValueMapper->getChannelLocaleSpecificFields($rowData, $this->jobChannel ?? '', $this->shopifyDefaultLocale ?? '')
        );
    }

    /**
     * Resolve configured option labels.
     */
    protected function resolveOptionName(array $attributeMeta): string
    {
        if (! ($this->settingMapping->mapping['option_name_label'] ?? false)) {
            return $attributeMeta['code'];
        }

        $translation = array_values(array_filter($attributeMeta['translations'], function ($item) {
            return $item['locale'] === $this->shopifyDefaultLocale;
        }))[0] ?? null;

        return $translation['name'] ?? $attributeMeta['name'] ?? $attributeMeta['code'];
    }

    /**
     * Resolve collection ids for a grouped product.
     */
    protected function resolveCollectionIds(array $group): array
    {
        $categoryCodes = [];

        foreach ($group['variants'] as $variant) {
            $categoryCodes = array_merge($categoryCodes, $variant['values']['categories'] ?? []);
        }

        if (! empty($group['parent']['values']['categories'] ?? [])) {
            $categoryCodes = array_merge($categoryCodes, $group['parent']['values']['categories']);
        }

        $categoryCodes = array_unique(array_filter($categoryCodes));
        $collectionIds = [];

        foreach ($categoryCodes as $code) {
            $mapping = $this->shopifyMappingRepository->where('code', $code)
                ->where('entityType', 'category')
                ->where('apiUrl', $this->credential?->shopUrl)
                ->get()
                ->toArray();

            if (! empty($mapping[0]['externalId'])) {
                $collectionIds[] = $mapping[0]['externalId'];
            }
        }

        return array_values(array_unique($collectionIds));
    }

    /**
     * Find a Shopify mapping by SKU.
     */
    protected function findMapping(string $sku): ?array
    {
        $mapping = $this->shopifyMappingRepository->where('code', $sku)
            ->where('entityType', 'product')
            ->where('apiUrl', $this->credential?->shopUrl)
            ->get()
            ->toArray();

        return empty($mapping) ? null : $mapping;
    }

    /**
     * Detect whether the variant has media configured in the export mapping.
     */
    protected function variantHasMedia(array $mergedFields): bool
    {
        $mediaMapping = $this->exportMapping->mapping['mediaMapping']['mediaAttributes'] ?? null;

        if (! $mediaMapping) {
            return false;
        }

        foreach (explode(',', $mediaMapping) as $attributeCode) {
            if (! empty($mergedFields[$attributeCode])) {
                return true;
            }
        }

        return false;
    }
}
