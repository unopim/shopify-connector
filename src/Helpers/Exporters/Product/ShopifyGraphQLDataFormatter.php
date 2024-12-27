<?php

namespace Webkul\Shopify\Helpers\Exporters\Product;

use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Contracts\Attribute;

class ShopifyGraphQLDataFormatter
{
    protected $productIndexes = ['title', 'handle', 'vendor', 'descriptionHtml', 'productType'];

    protected $seoFields = ['metafields_global_title_tag', 'metafields_global_description_tag'];

    protected $variantIndexes = ['inventoryPolicy', 'barcode', 'taxable', 'compareAtPrice', 'sku', 'inventoryTracked', 'cost', 'weight', 'price', 'inventoryQuantity'];

    protected $metaFieldAttributeCode = [];

    protected $currency = 'USD';

    protected $locationId = null;

    protected $separators = [
        'colon' => ': ',
        'dash'  => '- ',
        'space' => ' ',
    ];

    protected $settingMapping;

    protected $attributeRepository;

    protected $defaultLocale;

    /**
     * Formats raw product data for GraphQL API based on export mapping and other parameters.
     * */
    public function formatDataForGraphql(array $rawData, array $exportMapping, string $locale, array $parentData = [], $mappingDefn = []): array
    {
        $status = $this->getStatus($rawData, $parentData);
        $formatted = [
            'title'  => $parentData['sku'] ?? $rawData['sku'],
            'status' => $status,
        ];

        if ($this->locationId) {
            $formatted['variant']['inventoryQuantities']['locationId'] = $this->locationId;
            $formatted['variant']['inventoryQuantities']['availableQuantity'] = 0;
        }

        $formatted = $this->processShopifyConnectorSettings($formatted, $rawData, $exportMapping, $locale, $parentData);
        $formatted = $this->processShopifyConnectorDefaults($formatted, $exportMapping);
        $formatted = $this->processShopifyConnectorOthers($formatted, $rawData, $exportMapping, $locale, $parentData, $mappingDefn);

        return $formatted;
    }

    /**
     * Return the metafield attribute
     * */
    public function getMetafieldAttrCode(): array
    {
        return $this->metaFieldAttributeCode;
    }

    /**
     * Get status of the product
     * */
    protected function getStatus(array $rawData, array $parentData): string
    {
        $status = 'ACTIVE';

        if (! empty($rawData['status']) && $rawData['status'] == 'false') {
            $status = 'DRAFT';
        }

        if (! empty($parentData['status']) && $parentData['status'] == 'false') {
            $status = 'DRAFT';
        }

        if (! empty($parentData['status']) && $parentData['status'] == 'true') {
            $status = 'ACTIVE';
        }

        return $status;
    }

    /**
     * Processes Shopify connector settings and maps fields from raw data to the formatted output.
     * */
    protected function processShopifyConnectorSettings(array $formatted, array $rawData, array $exportMapping, string $locale, array $parentData = [])
    {
        foreach ($exportMapping['shopify_connector_settings'] ?? [] as $shopifyField => $unopimField) {
            if (in_array($shopifyField, $this->productIndexes)) {
                $typeCastValues = $parentData[$unopimField] ?? @$rawData[$unopimField] ?? '';
                $formatted[$shopifyField] = (string) $typeCastValues;

                continue;
            }

            if (in_array($shopifyField, $this->seoFields)) {
                $name = $shopifyField === 'metafields_global_title_tag' ? 'title' : 'description';
                $formatted['seo'][$name] = $parentData[$unopimField] ?? @$rawData[$unopimField] ?? '';

                continue;
            }

            if (in_array($shopifyField, $this->variantIndexes)) {
                $formatted = $this->processVariantFields($formatted, $rawData, $shopifyField, $unopimField);

                continue;
            }

            if ($shopifyField == 'tags') {
                $formatted[$shopifyField] = $this->processTags($rawData, $parentData, $unopimField, $locale);

                continue;
            }
        }

        $formatted['variant']['inventoryItem']['sku'] = $rawData['sku'] ?? '';

        return $formatted;
    }

    /**
     * Processes Shopify connector defaults and applies default values to the formatted output.
     *
     * */
    protected function processShopifyConnectorDefaults(array $formatted, array $exportMapping)
    {
        foreach ($exportMapping['shopify_connector_defaults'] ?? [] as $shopifyField => $defaultValue) {
            $formatted = $this->applyDefaultValue($formatted, $shopifyField, $defaultValue);
        }

        return $formatted;
    }

    /**
     * Processes additional Shopify connector fields, applying metafields from the export mapping.
     **/
    protected function processShopifyConnectorOthers(
        array $formatted,
        array $rawData,
        array $exportMapping,
        ?string $locale,
        array $parentData,
        array $mappingDefn = []
    ): array {
        foreach ($exportMapping['shopify_connector_others'] ?? [] as $shopifyMetafieldType => $unopimMetaField) {
            $formatted = $this->applyMetaFields($formatted, $rawData, $shopifyMetafieldType, $unopimMetaField, $locale, $parentData, $mappingDefn);
        }

        return $formatted;
    }

    /**
     * Processes specific variant fields and formats them for Shopify.
     * */
    protected function processVariantFields(
        array $formatted,
        array $rawData,
        string $shopifyField,
        string $unopimField
    ): array {
        switch ($shopifyField) {
            case 'inventoryPolicy':
                $formatted['variant'][$shopifyField] = ! empty($rawData[$unopimField]) && $rawData[$unopimField] == 'true' ? 'CONTINUE' : 'DENY';

                break;
            case 'barcode':
                $barCode = @$rawData[$unopimField] ?? '';
                $formatted['variant'][$shopifyField] = (string) $barCode;

                break;
            case 'taxable':
                $formatted['variant']['taxable'] = @$rawData[$unopimField] == 'false' ? false : true;

                break;
            case 'compareAtPrice':
                $formatted['variant']['compareAtPrice'] = (int) ($rawData[$unopimField][$this->currency] ?? 0);

                break;
            case 'sku':
                $skuValues = @$rawData[$unopimField] ?? '';
                $formatted['variant']['inventoryItem']['sku'] = (string) $skuValues;

                break;
            case 'inventoryTracked':
                $formatted['variant']['inventoryItem']['tracked'] = @$rawData[$unopimField] == 'false' ? false : true;

                break;
            case 'cost':
                $formatted['variant']['inventoryItem']['cost'] = (float) ($rawData[$unopimField][$this->currency] ?? 0);

                break;
            case 'weight':
                $formatted['variant']['inventoryItem']['measurement']['weight'] = [
                    'value' => (float) ($rawData[$unopimField] ?? 0),
                    'unit'  => 'GRAMS',
                ];

                break;
            case 'price':
                $formatted['variant']['price'] = (float) @$rawData[$unopimField][$this->currency] ?? 0;

                break;
            case 'inventoryQuantity':
                if ($this->locationId) {
                    $formatted['variant']['inventoryQuantities']['availableQuantity'] = (int) @$rawData[$unopimField];
                }

                break;
        }

        return $formatted;
    }

    /**
     * Processes tags based on raw and parent data, Unopim fields, and locale
     */
    protected function processTags(array $rawData, array $parentData, string $unopimField, string $locale): array
    {
        $attributeData = [];

        $unopimAttr = explode(',', $unopimField);

        foreach ($unopimAttr as $attributeCode) {
            $attribute = $this->attributeRepository->findOneByField('code', $attributeCode);

            $attributeLabel = empty($attribute->translate($locale)->name) ? $attribute->code : $attribute->translate($locale)->name;

            $value = strip_tags(@$parentData[$attributeCode] ?? @$rawData[$attributeCode] ?? null);

            if (! $value) {
                continue;
            }

            if (
                isset($this->settingMapping->mapping['enable_tags_attribute'])
                && filter_var($this->settingMapping->mapping['enable_tags_attribute'])
            ) {
                $separators = $this->separators[$this->settingMapping->mapping['tagSeprator']] ?? ':';
                $attributeData[] = $attributeLabel.$separators.$value;

                continue;
            }

            if ($this->settingMapping->mapping['enable_named_tags_attribute'] ?? false) {
                $attributeData[] = $attributeLabel.':'.$attribute->type.':'.$value;

                continue;
            }

            $attributeData[] = $value;
        }

        return $attributeData;
    }

    /**
     * Applies default values to the formatted data for Shopify fields.
     */
    protected function applyDefaultValue(array $formatted, string $shopifyField, $defaultValue): array
    {
        $defaultValue = $defaultValue ?? '';

        if (in_array($shopifyField, $this->productIndexes)) {
            $formatted[$shopifyField] = $defaultValue;
        } elseif (in_array($shopifyField, $this->seoFields)) {
            $name = $shopifyField === 'metafields_global_title_tag' ? 'title' : 'description';
            $formatted['seo'][$name] = $defaultValue;
        } elseif (in_array($shopifyField, $this->variantIndexes)) {
            $formatted = $this->applyDefaultVariantValue($formatted, $shopifyField, $defaultValue);
        } elseif ($shopifyField == 'tags') {
            $formatted[$shopifyField] = $defaultValue;
        }

        return $formatted;
    }

    /**
     * Applies default values to Shopify variant fields.
     */
    protected function applyDefaultVariantValue(array $formatted, string $shopifyField, string $defaultValue): array
    {
        switch ($shopifyField) {
            case 'inventoryPolicy':
                $formatted['variant'][$shopifyField] = $defaultValue && strtolower($defaultValue) == 'true' ? 'CONTINUE' : 'DENY';
                break;
            case 'barcode':
                $formatted['variant'][$shopifyField] = (string) $defaultValue;
                break;
            case 'price':
                $formatted['variant'][$shopifyField] = (float) $defaultValue;
                break;
            case 'taxable':
                $formatted['variant']['taxable'] = $defaultValue && strtolower($defaultValue) == 'true' ? true : false;
                break;
            case 'inventoryTracked':
                $formatted['variant']['inventoryItem']['tracked'] = $defaultValue && strtolower($defaultValue) == 'true' ? true : false;
                break;
            case 'compareAtPrice':
                $formatted['variant']['compareAtPrice'] = (int) $defaultValue;
                break;
            case 'inventoryQuantity':
                if ($this->locationId) {
                    $formatted['variant']['inventoryQuantities']['availableQuantity'] = (int) $defaultValue;
                }
                break;
            case 'sku':
                $formatted['variant']['inventoryItem']['sku'] = $defaultValue;
                break;
            case 'cost':
                $formatted['variant']['inventoryItem']['cost'] = (float) $defaultValue;
                break;
            case 'weight':
                $formatted['variant']['inventoryItem']['measurement']['weight'] = [
                    'value' => (float) $defaultValue,
                    'unit'  => 'GRAMS',
                ];
                break;
        }

        return $formatted;
    }

    /**
     * Applies Shopify metafields for both raw and parent data.
     */
    protected function applyMetaFields(
        array $formatted,
        array $rawData,
        string $shopifyMetafieldType,
        string $unopimMetaField,
        string $locale,
        array $parentData,
        array $mappingDefn = []
    ): array {
        $attr = explode(',', $unopimMetaField);

        foreach ($attr as $unoAttribute) {
            $this->metaFieldAttributeCode[] = $unoAttribute;

            $attribute = $this->attributeRepository->findOneByField('code', $unoAttribute);

            $mappingDefnAll = array_merge($mappingDefn['productMetafield'] ?? [], $mappingDefn['productVariantMetafield'] ?? []);

            if (array_key_exists($unoAttribute, $mappingDefnAll)) {

                $namespace = $mappingDefnAll[$unoAttribute];

            } else {

                $namespace = $this->getMetaFieldNamespace($attribute);

            }

            if (! $namespace) {
                continue;
            }

            $metaFieldKey = $this->getAttributeLabelOrCodeForMetaField($attribute, $unoAttribute, $locale);

            $metafieldType = $this->getShopifyMetafieldType($shopifyMetafieldType);

            if (! empty(@$rawData[$unoAttribute])) {
                $metafieldValue = $attribute->type == 'price' ? @$rawData[$unoAttribute][$this->currency] : $this->stripTagMetafield(@$rawData[$unoAttribute]);

                $formatted['metafields'][] = [
                    'key'       => $metaFieldKey,
                    'value'     => $metafieldValue,
                    'type'      => $metafieldType,
                    'namespace' => $namespace,
                ];
            }

            if (! empty($parentData)) {
                if (empty(@$parentData[$unoAttribute])) {
                    continue;
                }

                $parentMetaFieldValue = $attribute->type == 'price' ? @$parentData[$unoAttribute][$this->currency] : $this->stripTagMetafield(@$parentData[$unoAttribute]);

                $formatted['parentMetaFields'][] = [
                    'key'       => $metaFieldKey,
                    'value'     => $parentMetaFieldValue,
                    'type'      => $metafieldType,
                    'namespace' => $namespace,
                ];
            }
        }

        return $formatted;
    }

    /**
     * striptag metafields value remove html entities and code and new line
     */
    protected function stripTagMetafield(string $metafieldValue): string
    {
        $metafieldValue = strip_tags($metafieldValue);
        $metafieldValue = preg_replace('/&#?[a-z0-9]{2,8};/i', '', $metafieldValue);
        $metafieldValue = str_replace(["\r\n", "\r", "\n"], PHP_EOL, $metafieldValue);
        $metafieldValue = preg_replace('/\s+/', ' ', $metafieldValue);

        return $metafieldValue;
    }

    /**
     * Retrieves the namespace for a given attribute.
     */
    protected function getMetaFieldNamespace($attribute): ?string
    {
        if (isset($this->settingMapping->mapping['metaFieldsNameSpace']) && $this->settingMapping->mapping['metaFieldsNameSpace'] == 'code') {
            $results = DB::table('attribute_group_mappings')
                ->join('attribute_family_group_mappings', 'attribute_group_mappings.attribute_family_group_id', '=', 'attribute_family_group_mappings.id')
                ->join('attribute_groups', 'attribute_family_group_mappings.attribute_group_id', '=', 'attribute_groups.id')
                ->select('attribute_group_mappings.*', 'attribute_family_group_mappings.*', 'attribute_groups.*')
                ->get();

            $item = $results->firstWhere('attribute_id', $attribute->id);

            return $item ? $item->code : null;
        }

        return 'global';
    }

    /**
     * Sets the initial data for the class properties.
     */
    public function setInitialData(string $locationId, string $currency, $attributeRepo, $settings)
    {
        $this->locationId = $locationId;
        $this->currency = $currency;
        $this->attributeRepository = $attributeRepo;
        $this->settingMapping = $settings;
    }

    /**
     * returns shopify metafield type according to mapping
     */
    protected function getShopifyMetafieldType(string $type): ?string
    {
        return match ($type) {
            'meta_fields_string'  => 'single_line_text_field',
            'meta_fields_integer' => 'number_integer',
            'meta_fields_json'    => 'json',
            default               => null,
        };
    }

    /**
     * Returns attribute label or code according to metafield setting for metaFieldsKey
     */
    protected function getAttributeLabelOrCodeForMetaField(Attribute $attribute, string $attributeCode, string $locale): string
    {
        $metaFieldKey = $attributeCode;

        if (
            isset($this->settingMapping->mapping['metaFieldsKey'])
            && $this->settingMapping->mapping['metaFieldsKey'] == 'label'
        ) {
            $translatedName = $attribute->translate($locale)->name;

            $metaFieldKey = ! empty($translatedName) ? $translatedName : $metaFieldKey;
        }

        return $metaFieldKey;
    }
}
