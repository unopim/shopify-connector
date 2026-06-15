<?php

namespace Webkul\Shopify\Traits;

use Webkul\Shopify\Contracts\ShopifyCredentialsConfig;

/**
 * Trait for handling Shopify translation requests.
 */
trait TranslationTrait
{
    protected $translationShopifyFields = [
        'title',
        'descriptionHtml',
        'metafields_global_title_tag',
        'metafields_global_description_tag',
        'handle',
        'productType',
    ];

    /**
     * Handles metafield translations for Shopify.
     */
    protected function metafieldTranslation(
        string $shopifyDefaultLocale,
        string $channel,
        array $rowData,
        array $addedmetafields,
        array $parentValues,
        ShopifyCredentialsConfig $credential,
        array $credentialAsArray,
        $namespaceKeys,
    ): void {
        $storeloacleMapping = $credential->storelocaleMapping;
        if ($storeloacleMapping) {
            $commonFields = $this->getCommonFields($rowData);
            foreach ($addedmetafields as $keydMeta => $addedMetaField) {

                $formatedVariable = [
                    'id' => $addedMetaField['node']['id'],
                    'translations' => [],
                ];
                $namespaceKey = $addedMetaField['node']['namespace'].'.'.$addedMetaField['node']['key'];

                foreach ($storeloacleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
                    if ($shopifyDefaultLocale == $unopimLocaleCode || empty($namespaceKeys[$namespaceKey])) {
                        continue;
                    }

                    $channelLocaleSpecificFields = $this->getChannelLocaleSpecificFields($rowData, $channel, $unopimLocaleCode);
                    $localeSpecificFields = $this->getLocaleSpecificFields($rowData, $unopimLocaleCode);
                    $allData = array_merge($localeSpecificFields, $channelLocaleSpecificFields, $commonFields);
                    $value = $allData[$namespaceKeys[$namespaceKey]] ?? '';
                    $jsonData = $addedMetaField['node']['value'];
                    $data = json_decode($jsonData, true);
                    if (is_array($data)) {
                        foreach ($data as $key => $value2) {
                            if (is_array($value2)) {
                                continue;
                            }
                            $jsonData = '{ "'.$key.'": "'.$value2.'" }';
                        }
                    }

                    $formatedVariable['translations'][] = [
                        'key' => 'value',
                        'value' => $value,
                        'locale' => $shopifyLocaleCode,
                        'translatableContentDigest' => hash('sha256', $jsonData),
                    ];
                }

                if ($formatedVariable) {
                    $response = $this->requestGraphQlApiAction('createTranslation', $credentialAsArray, $formatedVariable);
                }
            }
        }
    }

    /**
     * Handles product translation for Shopify.
     */
    protected function productTranslation(
        string $productId,
        string $shopifyDefaultLocale,
        string $channel,
        array $rowData,
        object $credential,
        array $credentialAsArray,
        array $productData,
        array $matchAttribute
    ): void {
        $formatedVariable = [
            'id' => $productId,
            'translations' => [],
        ];

        $storeloacleMapping = $credential->storelocaleMapping;
        if ($storeloacleMapping) {
            $commonFields = $this->getCommonFields($rowData);

            foreach ($matchAttribute as $shopifyField => $unopimField) {
                $defaultValue = $productData[$shopifyField] ?? '';
                if ($shopifyField == 'metafields_global_title_tag') {
                    $defaultValue = $productData['seo']['title'] ?? '';
                    $shopifyField = 'metafields.global.title_tag';
                }

                if ($shopifyField == 'metafields_global_description_tag') {
                    $defaultValue = $productData['seo']['description'] ?? '';
                    $shopifyField = 'metafields.global.description_tag';
                }

                if ($shopifyField == 'productType') {
                    $shopifyField = 'product_type';
                }

                if ($shopifyField == 'descriptionHtml') {
                    $shopifyField = 'body_html';
                }

                foreach ($storeloacleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
                    if ($shopifyDefaultLocale == $unopimLocaleCode) {
                        continue;
                    }

                    $channelLocaleSpecificFields = $this->getChannelLocaleSpecificFields($rowData, $channel, $unopimLocaleCode);
                    $localeSpecificFields = $this->getLocaleSpecificFields($rowData, $unopimLocaleCode);
                    $allData = array_merge($localeSpecificFields, $channelLocaleSpecificFields, $commonFields);

                    $formatedVariable['translations'][] = [
                        'key' => $shopifyField,
                        'value' => $allData[$unopimField] ?? '',
                        'locale' => $shopifyLocaleCode,
                        'translatableContentDigest' => hash('sha256', $defaultValue),
                    ];
                }
            }

            if ($formatedVariable) {
                $response = $this->requestGraphQlApiAction('createTranslation', $credentialAsArray, $formatedVariable);
            }
        }
    }

    /**
     * Handles productoption translation for Shopify.
     */
    protected function updateProductOptionsTranslation(
        string $shopifyDefaultLocale,
        ?array $optionResult,
        array $superAttribute,
        object $credential,
        array $credentialAsArray
    ): void {
        $storeloacleMapping = $credential->storelocaleMapping;
        if ($storeloacleMapping && $optionResult) {
            foreach ($optionResult as $key => $option) {

                $formatedVariable = [
                    'id' => $option['id'],
                    'translations' => [],
                ];

                $defaultValue = $option['name'];

                foreach ($storeloacleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
                    if ($shopifyDefaultLocale == $unopimLocaleCode) {
                        continue;
                    }

                    $filtered = array_filter($superAttribute[$key]['translations'], function ($item) use ($unopimLocaleCode) {
                        return $item['locale'] == $unopimLocaleCode;
                    });

                    if (empty($filtered)) {
                        continue;
                    }
                    $attrLabel = reset($filtered)['name'];

                    $formatedVariable['translations'][] = [
                        'key' => 'name',
                        'value' => $attrLabel,
                        'locale' => $shopifyLocaleCode,
                        'translatableContentDigest' => hash('sha256', $defaultValue),
                    ];
                }

                if ($formatedVariable) {
                    $response = $this->requestGraphQlApiAction('createTranslation', $credentialAsArray, $formatedVariable);
                }
            }
        }
    }

    /**
     * Handles productoptionvalues translation for Shopify.
     */
    protected function updateProductOptionValuesTranslation(string $shopifyDefaultLocale, ?array $optionsGetting, array $optionValuesTranslation, object $credential, array $credentialAsArray): void
    {
        $storeloacleMapping = $credential->storelocaleMapping;
        if ($storeloacleMapping && $optionsGetting) {
            foreach ($optionsGetting as $key => $Value) {
                $data = $Value['optionValues'];
                $optionCode = array_keys($optionValuesTranslation);
                $names = array_column($data, 'name');
                $index = array_search($optionCode[$key], $names);

                if ($index === false || ! isset($data[$index])) {
                    continue;
                }

                $defaultValue = $data[$index]['name'];
                $id = $data[$index]['id'];
                $formatedVariable = [
                    'id' => $id,
                    'translations' => [],
                ];
                $allData = $optionValuesTranslation[$defaultValue];

                foreach ($storeloacleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
                    if ($shopifyDefaultLocale == $unopimLocaleCode) {
                        continue;
                    }

                    $result = array_filter($allData, function ($item) use ($unopimLocaleCode) {
                        return $item['locale'] === $unopimLocaleCode;
                    });
                    if (empty($result)) {
                        continue;
                    }
                    $label = reset($result)['label'] ?? '';
                    $formatedVariable['translations'][] = [
                        'key' => 'name',
                        'value' => $label ?? '',
                        'locale' => $shopifyLocaleCode,
                        'translatableContentDigest' => hash('sha256', $defaultValue),
                    ];
                }

                $response = $this->requestGraphQlApiAction('createTranslation', $credentialAsArray, $formatedVariable);
            }
        }
    }

    /**
     * Handles category translation for Shopify.
     */
    public function categoryTranslation(
        string $locale,
        array $rawData,
        ShopifyCredentialsConfig $credential,
        array $credentialAsArray,
        array $collectionResult,
        array $fieldMap = []
    ): void {
        if (! empty($collectionResult)) {
            $storeloacleMapping = $credential->storelocaleMapping;
            $formatedVariable = [
                'id' => $collectionResult['id'],
                'translations' => [],
            ];

            /**
             * Map of collection mapping key => Shopify translation key and the
             * digest source. Digests are computed once since $collectionResult
             * is constant across locales.
             */
            $translatableFields = [
                'title' => ['key' => 'title', 'digest' => hash('sha256', $collectionResult['title'] ?? '')],
                'descriptionHtml' => ['key' => 'body_html', 'digest' => hash('sha256', $collectionResult['descriptionHtml'] ?? '')],
                'seoTitle' => ['key' => 'meta_title', 'digest' => hash('sha256', $collectionResult['seo']['title'] ?? '')],
                'seoDescription' => ['key' => 'meta_description', 'digest' => hash('sha256', $collectionResult['seo']['description'] ?? '')],
                'handle' => ['key' => 'handle', 'digest' => hash('sha256', $collectionResult['handle'] ?? '')],
            ];

            foreach ($storeloacleMapping as $shopifyLocaleCode => $unopimLocaleCode) {
                if ($locale == $unopimLocaleCode) {
                    continue;
                }

                $localeSpecificFields = $this->getLocaleSpecificFields($rawData, $unopimLocaleCode);

                foreach ($translatableFields as $mapKey => $meta) {
                    $code = $fieldMap[$mapKey] ?? null;

                    if (empty($code) || empty($localeSpecificFields[$code])) {
                        continue;
                    }

                    $formatedVariable['translations'][] = [
                        'key' => $meta['key'],
                        'value' => $localeSpecificFields[$code],
                        'locale' => $shopifyLocaleCode,
                        'translatableContentDigest' => $meta['digest'],
                    ];
                }
            }
            if (! empty($formatedVariable['translations'])) {
                $this->requestGraphQlApiAction('createTranslation', $credentialAsArray, $formatedVariable);
            }
        }
    }
}
