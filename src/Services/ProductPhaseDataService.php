<?php

namespace Webkul\Shopify\Services;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Product\Services\ProductValueMapper;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;

class ProductPhaseDataService
{
    public function __construct(
        protected ShopifyCredentialRepository $shopifyCredentialRepository,
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
        protected ShopifyMetaFieldRepository $shopifyMetaFieldRepository,
        protected AttributeRepository $attributeRepository,
        protected ProductValueMapper $productValueMapper,
    ) {}

    /**
     * Return all phase context needed for a given product SKU.
     */
    public function getProductContext(string $productSku, int $credentialId, string $channel, string $currency): ?array
    {
        $credential = $this->shopifyCredentialRepository->find($credentialId);

        if (! $credential) {
            return null;
        }

        $defaultLanguage = array_values(array_filter($credential->storeLocales ?? [], function ($language) {
            return isset($language['defaultlocale']) && $language['defaultlocale'] === true;
        }))[0] ?? null;

        $shopifyDefaultLocale = $credential->storelocaleMapping[$defaultLanguage['locale'] ?? ''] ?? null;
        $mappings = $this->shopifyExportMappingRepository->findMany([1, 2]);
        $exportMapping = $mappings->first();
        $settingMapping = $mappings->last();
        $attributesAll = $this->attributeRepository->all()->keyBy('code');
        $productMetaFieldMapping = $this->shopifyMetaFieldRepository->where('ownerType', 'PRODUCT')->get()->toArray();

        $product = DB::table('products')
            ->leftJoin('products as parent_products', 'products.parent_id', '=', 'parent_products.id')
            ->select(
                'products.id',
                'products.sku',
                'products.type',
                'products.status',
                'products.values',
                'products.parent_id',
                'parent_products.sku as parent_sku',
                'parent_products.values as parent_values',
                'parent_products.status as parent_status',
                'parent_products.type as parent_type'
            )
            ->where('products.sku', $productSku)
            ->first();

        if (! $product) {
            $product = DB::table('products')
                ->where('sku', $productSku)
                ->first();
        }

        if (! $product) {
            return null;
        }

        $rowData = [
            'id' => $product->id,
            'sku' => $product->sku,
            'type' => $product->type,
            'status' => $product->status,
            'values' => json_decode($product->values, true),
            'parent_id' => $product->parent_id,
        ];

        $parentData = null;

        if (! empty($product->parent_sku)) {
            $parentProduct = DB::table('products')->where('sku', $product->parent_sku)->first();

            if ($parentProduct) {
                $parentData = [
                    'id' => $parentProduct->id,
                    'sku' => $parentProduct->sku,
                    'type' => $parentProduct->type,
                    'status' => $parentProduct->status,
                    'values' => json_decode($parentProduct->values, true),
                    'super_attributes' => $this->getSuperAttributes($parentProduct->id, $attributesAll->all()),
                ];
            }
        } elseif ($product->type === 'configurable') {
            $parentData = [
                'id' => $product->id,
                'sku' => $product->sku,
                'type' => $product->type,
                'status' => $product->status,
                'values' => json_decode($product->values, true),
                'super_attributes' => $this->getSuperAttributes($product->id, $attributesAll->all()),
            ];
        }

        return [
            'credential' => $credential,
            'credential_array' => $credential->toApiArray(),
            'shopify_default_locale' => $shopifyDefaultLocale,
            'channel' => $channel,
            'currency' => $currency,
            'row_data' => $rowData,
            'parent_data' => $parentData,
            'attributes' => $attributesAll->all(),
            'export_mapping' => $exportMapping,
            'setting_mapping' => $settingMapping,
            'product_metafields' => $productMetaFieldMapping,
            'merged_fields' => $this->getAllAttributeValues($rowData, $channel, $shopifyDefaultLocale),
            'parent_merged_fields' => $parentData ? $this->getAllAttributeValues($parentData, $channel, $shopifyDefaultLocale) : [],
        ];
    }

    /**
     * Return merged attribute values for a product row.
     */
    public function getAllAttributeValues(array $rowData, string $channel, ?string $locale): array
    {
        return array_merge(
            $this->productValueMapper->getCommonFields($rowData),
            ['status' => ($rowData['status'] ?? 0) == 1 ? 'true' : 'false'],
            $locale ? $this->productValueMapper->getLocaleSpecificFields($rowData, $locale) : [],
            $channel ? $this->productValueMapper->getChannelSpecificFields($rowData, $channel) : [],
            ($channel && $locale) ? $this->productValueMapper->getChannelLocaleSpecificFields($rowData, $channel, $locale) : []
        );
    }

    /**
     * Resolve configurable attributes for a parent product.
     */
    protected function getSuperAttributes(int $productId, array $attributesAll): array
    {
        $attributeIds = DB::table('product_super_attributes')
            ->where('product_id', $productId)
            ->pluck('attribute_id')
            ->all();

        $superAttributes = [];

        foreach ($attributeIds as $attributeId) {
            $attribute = collect($attributesAll)->firstWhere('id', $attributeId);

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
}
