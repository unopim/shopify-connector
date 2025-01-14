<?php

namespace Webkul\Shopify\Helpers\Exporters\Product;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Webkul\Attribute\Repositories\AttributeFamilyGroupMappingRepository;
use Webkul\Attribute\Repositories\AttributeGroupRepository;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Exceptions\InvalidLocale;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Traits\DataMappingTrait;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\TranslationTrait;

class Exporter extends AbstractExporter
{
    use DataMappingTrait;
    use ShopifyGraphqlRequest;
    use TranslationTrait;

    public const UNOPIM_ENTITY_NAME = 'product';

    public const NOT_EXIST_PRODUCT = 'Product does not exist';

    public const NOT_EXIST_PRODUCT_VARIANT = 'Product variant does not exist';

    protected $productIndexes = ['title', 'handle', 'vendor', 'descriptionHtml', 'productType'];

    protected $seoFileds = ['metafields_global_title_tag', 'metafields_global_description_tag'];

    protected $variantIndexes = ['price', 'weight', 'cost', 'compareAtPrice', 'barcode', 'taxable', 'inventoryPolicy', 'sku', 'inventoryTracked', 'inventoryQuantity'];

    protected $credential;

    protected $imageData = [];

    public const BATCH_SIZE = 100;

    /**
     * @var array
     */
    protected $channelsAndLocales = [];

    protected $childImageAttr = [];

    protected $removeImgAttr = [];

    protected $parentImageAttr = [];

    protected $parentRemoveImgAttr = [];

    /**
     * @var array
     */
    protected $currencies = [];

    /**
     * @var array
     */
    protected $attributes = [];

    protected $childCount = [];

    protected $removeCollectionId = [];

    protected $currency;

    protected $jobChannel;

    protected $settingMapping;

    protected $shopifyDefaultLocale;

    protected $imageAttributes;

    protected $updateMedia = [];

    protected $collectionsToLeaveIds = [];

    protected $metaFieldAttributeCode = [];

    protected $definitionMapping = [];

    protected $productId = [];

    protected $credentialAsArray = [];

    protected $exportMapping;

    protected $publicationId = [];

    protected $locationId;

    protected $productOptions;

    protected bool $exportsFile = false;

    public $seprators = [
        'colon' => ': ',
        'dash'  => '- ',
        'space' => ' ',
    ];

    /**
     * Create a new instance.
     *
     * @return void
     */
    public function __construct(
        protected JobTrackBatchRepository $exportBatchRepository,
        protected FileExportFileBuffer $exportFileBuffer,
        protected ChannelRepository $channelRepository,
        protected AttributeRepository $attributeRepository,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected ShopifyExportMappingRepository $shopifyExportmapping,
        protected AttributeGroupRepository $attributeGroupRepository,
        protected AttributeFamilyGroupMappingRepository $attributeFamilyGroupMappingRepository,
        protected ShopifyGraphQLDataFormatter $shopifyGraphQLDataFormatter
    ) {
        parent::__construct($exportBatchRepository, $exportFileBuffer);
    }

    /**
     * Initializes the channels and locales for the export process.
     *
     * @return void
     */
    public function initilize()
    {
        $this->initCredential();

        $this->initPublications();

        $this->initDefaultLocale();

        $this->shopifyGraphQLDataFormatter->setInitialData($this->locationId, $this->currency, $this->attributeRepository, $this->settingMapping);
    }

    /**
     * Initialize credentials data from filters
     */
    protected function initCredential(): void
    {
        $filters = $this->getFilters();

        $this->currency = $filters['currency'];

        $this->jobChannel = $filters['channel'];

        $this->credential = $this->shopifyRepository->find($filters['credentials']);
        $this->definitionMapping = $this->credential?->extras;

        $mappings = $this->shopifyExportmapping->findMany([1, 2]);

        $this->exportMapping = $mappings->first();

        $this->settingMapping = $mappings->last();

        if (! $this->credential?->active) {
            $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.invalid-credential'));

            $this->export->state = ExportHelper::STATE_FAILED;

            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $this->export->save();

            throw new InvalidCredential;
        }

        $this->credentialAsArray = [
            'shopUrl'     => $this->credential?->shopUrl,
            'accessToken' => $this->credential?->accessToken,
            'apiVersion'  => $this->credential?->apiVersion,
        ];
    }

    /**
     * Initialize publication from credentials data
     */
    protected function initPublications(): void
    {
        if (empty($this->credential->extras['salesChannel'])) {
            return;
        }

        $salesChannel = explode(',', $this->credential->extras['salesChannel']);

        $this->locationId = $this->credential->extras['locations'] ?? null;

        foreach ($salesChannel as $value) {
            $this->publicationId[] = [
                'publicationId' => $value,
            ];
        }
    }

    /**
     * Initialize default locale from credentials data
     */
    protected function initDefaultLocale(): void
    {
        if ($this->credential->storeLocales) {
            $defaultLanguage = array_values(array_filter($this->credential->storeLocales, function ($language) {
                return isset($language['defaultlocale']) && $language['defaultlocale'] === true;
            }))[0] ?? null;

            $this->shopifyDefaultLocale = $this->credential->storelocaleMapping[$defaultLanguage['locale']] ?? null;
        }

        if (empty($this->shopifyDefaultLocale)) {
            $this->export->state = ExportHelper::STATE_FAILED;

            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-locale')];

            $this->export->save();

            throw new InvalidLocale;
        }
    }

    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        Event::dispatch('shopify.product.export.before', $batch);

        $this->initilize();
        $products = $this->prepareProductsForShopify($batch, $filePath);

        /**
         * Update export batch process state summary
         */
        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        Event::dispatch('shopify.product.export.after', $batch);

        return true;
    }

    protected function getResults()
    {
        $filters = $this->getFilters();

        $skus = null;

        if (isset($filters['productfilter']) && ! empty($filters['productfilter'])) {
            $skus = explode(',', $filters['productfilter']);
            $skus = array_map('trim', $skus);
        }

        $qb = $this->source->with([
            'attribute_family',
            'parent.super_attributes',
            'super_attributes',
        ])->where('type', '!=', 'configurable')->orderBy('id', 'desc');

        if ($skus) {
            $qb->whereIn('sku', $skus)
                ->orWhereIn('parent_id', function ($query) use ($skus) {
                    $query->select('id')
                        ->from('products')
                        ->whereIn('sku', $skus);
                });
        }

        return $qb->get()?->getIterator();
    }

    public function prepareProductsForShopify(JobTrackBatchContract $batch, mixed $filePath)
    {
        foreach ($batch->data as $key => $rowData) {
            $productResult = $this->processProductData($rowData);
            if (! $productResult) {
                continue;
            }

            $this->createdItemsCount++;
        }
    }

    public function processProductData(array $rowData): ?bool
    {
        $finalCategories = [];

        $this->imageData = [];

        $parentData = [];

        $parentMapping = [];

        $parentMergedFields = [];

        $skipParent = false;

        $optionsGetting = [];

        $optionValuesTranslation = [];

        $finalOption = [];

        $productOptionValues = [];

        $variableOption = [];

        $rowData['code'] = $rowData['sku'];

        $mapping = $this->checkMappingInDb($rowData) ?? null;

        $mergedFields = $this->gettingAllAttrValue($rowData);

        $this->getCategoriesByCode($rowData['values']['categories'] ?? [], $finalCategories);

        if (isset($rowData['parent']) && ! empty($rowData['parent'])) {
            $parentMapping = $this->checkMappingInDb(['code' => $rowData['parent']['sku']]) ?? null;

            $skipParent = $parentMapping ? $this->export->id == $parentMapping[0]['jobInstanceId'] : false;

            $parentData = $rowData['parent'];

            $parentMergedFields = $this->gettingAllAttrValue($parentData);

            unset($rowData['parent']);

            $this->getCategoriesByCode($parentData['values']['categories'] ?? [], $finalCategories);

            [$variableOption, $productOptionValues, $finalOption, $optionValuesTranslation] = $this->processSuperAttributes($parentData['super_attributes'], $this->shopifyDefaultLocale, $mergedFields, $parentMapping, $mapping);
        }

        $formattedGraphqlData = $this->formatGraphqlData($mergedFields, $parentMergedFields, $finalCategories, $finalOption, $parentMapping);

        $imageData = $this->formatDataForGraphqlImage($mergedFields, $this->exportMapping->mapping['shopify_connector_settings'] ?? [], $parentMergedFields ?? []);

        if (! empty($imageData)) {
            $this->imageData = array_merge($imageData[$rowData['sku']] ?? [], $imageData[@$parentData['sku']] ?? []);
        }

        $variantData = $formattedGraphqlData['variant'];

        unset($formattedGraphqlData['variant']);

        if (empty($mapping) && ! empty($parentMapping)) {
            $resultVariant = $this->processVariantCreationResult($formattedGraphqlData, $variantData, $imageData, $rowData, $productOptionValues, $parentMapping);

            if (! $resultVariant) {
                return null;
            }

            ['variantId' => $variantId, 'optionsGetting' => $optionsGetting, 'productId' => $productId] = $resultVariant;
        } else {
            if (empty($mapping)) {
                $createResult = $this->createShopifyProduct(
                    $formattedGraphqlData,
                    $parentData,
                    $rowData,
                    $variantData,
                    $imageData,
                    $productOptionValues
                );

                if (! $createResult) {
                    return null;
                }

                ['variantId' => $variantId, 'optionsGetting' => $optionsGetting, 'productId' => $productId] = $createResult;
            } else {
                $productId = ! empty($parentMapping) ? $parentMapping[0]['externalId'] : $mapping[0]['externalId'];

                ['variantId' => $variantId, 'optionsGetting' => $optionsGetting] = $this->processProductUpdate(
                    $skipParent,
                    $formattedGraphqlData,
                    $productId,
                    $parentMapping,
                    $mapping,
                    $parentData,
                    $rowData,
                    $imageData,
                    $variantData,
                    $variableOption
                );
            }

            $this->handleProductProcessingForTranslation(
                $productId,
                $parentMergedFields,
                $mergedFields,
                $parentData,
                $rowData,
                $formattedGraphqlData
            );
        }

        $this->handleChildProductTranslation(
            $parentData,
            $mergedFields,
            $skipParent,
            $optionsGetting,
            $optionValuesTranslation,
            $productId,
            $variantId,
            $rowData
        );

        return true;
    }

    /**
     * Creates a Shopify product using the GraphQL API, either as a standalone product or a variant of an existing product.
     */
    private function createShopifyProduct(
        array $formattedGraphqlData,
        array $parentData,
        array $rowData,
        array $variantData,
        array $imageData,
        array $productOptionValues
    ): ?array {
        $productOption = [];

        if (empty($parentData)) {
            $formattedGraphqlData['productOptions'][] = [
                'name'   => 'Title',
                'values' => [
                    [
                        'name' => 'default',
                    ],
                ],
            ];
        }
        $result = $this->apiRequestShopifyProduct($formattedGraphqlData, $this->credentialAsArray);
        if (! $this->checkNotExistError($result)) {
            return null;
        }
        if ((isset($result['body']['data']['productCreate']['userErrors']) && ! empty($result['body']['data']['productCreate']['userErrors']))) {
            $this->logWarning($result['body']['data']['productCreate']['userErrors'], $parentData['sku'] ?? $rowData['sku']);
            $this->skippedItemsCount++;

            return null;
        }

        $productDataByApi = $result['body']['data']['productCreate']['product'];

        $variants = $productDataByApi['variants']['edges'];

        $productId = $productDataByApi['id'];

        $imageIds = $productDataByApi['media']['nodes'];

        $variantMediaId = reset($imageIds)['id'] ?? null;

        $this->parentMapping($parentData['sku'] ?? $rowData['sku'], $productId, $this->export->id);

        $this->imageIdMapping($imageIds, $imageData, $rowData, $parentData ?? [], $productId);

        if (empty($parentData)) {
            foreach ($variants as $variant) {
                $variantId = $variant['node']['id'];
                $variantData['id'] = $variantId;
                $finalVariantData = ['input' => $variantData];
                $finalVariantData['input']['productId'] = $productId;

                $result = $this->apiRequestShopifyDefaultVariantCreate($finalVariantData, $this->credentialAsArray);

                if (! $this->checkNotExistError($result)) {
                    return null;
                }
            }
        }

        if (! empty($parentData)) {
            $result = $this->productVariantFormatAndCreate($formattedGraphqlData, $variantData, [], $rowData, $productOptionValues, $productId, $variantMediaId);

            $variantId = $result['body']['data']['productVariantsBulkCreate']['productVariants'][0]['id'];
            $productOption = $result['body']['data']['productVariantsBulkCreate']['product']['options'];

            $this->parentMapping($rowData['sku'], $variantId, $this->export->id, $productId);

            foreach ($variants as $variant) {
                $this->requestGraphQlApiAction('productVariantDelete', $this->credentialAsArray, ['id' => $variant['node']['id']]);
            }
        }

        return [
            'variantId'      => $variantId,
            'optionsGetting' => $productOption,
            'productId'      => $productId,
        ];
    }

    /**
     * Processes product updates in Shopify, handling both parent and variant product updates.
     *
     * */
    private function processProductUpdate(
        bool $skipParent,
        array $formattedGraphqlData,
        string $productId,
        array $parentMapping,
        array $mapping,
        array $parentData,
        array $rowData,
        array $imageData,
        array $variantData,
        array $variableOption
    ): array|null|bool {
        $productOption = [];

        if (! $skipParent) {
            $result = $this->updateProductWithMetafields($formattedGraphqlData, $this->credentialAsArray, $productId, $parentMapping, $mapping, $parentData, $rowData);
            $errorUpdate = $result['body']['data']['productUpdate']['userErrors'] ?? [];

            if (isset($errorUpdate[0]['message']) && $errorUpdate[0]['message'] == self::NOT_EXIST_PRODUCT) {
                $this->deleteProductMapping($productId);
                if (! empty($parentData)) {
                    $rowData['parent'] = $parentData;
                }

                $notExistProductCreated = $this->processProductData($rowData);

                if ($notExistProductCreated) {
                    return true;
                }
            }

            if (! empty($errorUpdate)) {
                $this->logWarning($errorUpdate, $parentData['sku'] ?? $rowData['sku']);
                $this->skippedItemsCount++;

                return null;
            }

            if (! $result) {
                return null;
            }
        }

        $this->handleMediaUpdates($productId, $rowData, $parentData, $imageData);

        if (empty($parentMapping)) {
            $this->handleAfterApiRequest($rowData, $result, $mapping, $this->export->id, $formattedGraphqlData);
            $variants = $result['body']['data']['productUpdate']['product']['variants']['edges'];

            foreach ($variants as $variant) {
                $variantId = $variant['node']['id'];
                $variantDataFormatted = ['input' => array_merge($variant['node'], $variantData)];
                $this->requestGraphQlApiAction('ProductVariantUpdate', $this->credentialAsArray, $variantDataFormatted);
            }
        } else {
            $productOption = $this->updateProductOptions($parentData, $variableOption);

            $hasDefaultTitleWithVariants = current(array_filter($productOption[0]['optionValues'], function ($optionValue) {
                return $optionValue['name'] === 'Default Title' && $optionValue['hasVariants'] === true;
            }));

            if ($hasDefaultTitleWithVariants) {
                $this->deleteProductMapping($productId);
                if (! empty($parentData)) {
                    $rowData['parent'] = $parentData;
                }
                $variable = [
                    'input' => [
                        'id' => $productId,
                    ],
                ];
                $this->requestGraphQlApiAction('productDelete', $this->credentialAsArray, $variable);
                $notExistProductCreated = $this->processProductData($rowData);

                if ($notExistProductCreated) {
                    return true;
                }
            }

            if (! empty($this->updateMedia) && empty($variantData['mediaId'])) {
                $variantData['mediaId'] = reset($this->updateMedia)['id'];
            }

            $variantResult = $this->updateProductVariant(
                $variantData,
                $formattedGraphqlData,
                $mapping,
                $productId,
                $rowData,
                $parentData
            );

            if (! $variantResult) {
                return null;
            }

            $variantId = $mapping[0]['externalId'];
        }

        return [
            'variantId'      => $variantId,
            'optionsGetting' => $productOption,
        ];
    }

    /**
     * Formats data to be used for a GraphQL request to Shopify.
     * */
    public function formatGraphqlData(
        array $mergedFields,
        array $parentMergedFields,
        array $finalCategories,
        array $finalOption,
        array $parentMapping
    ): array {
        $formattedGraphqlData = $this->shopifyGraphQLDataFormatter->formatDataForGraphql($mergedFields, $this->exportMapping->mapping ?? [], $this->shopifyDefaultLocale, $parentMergedFields, $this->definitionMapping);
        $this->metaFieldAttributeCode = $this->shopifyGraphQLDataFormatter->getMetafieldAttrCode();
        $finalCategories = array_filter($finalCategories);
        $formattedGraphqlData['collectionsToJoin'] = $finalCategories;

        if (! empty($parentMergedFields) && empty($parentMapping)) {
            $formattedGraphqlData['productOptions'] = $finalOption;
        }

        if (! empty($this->publicationId)) {
            $formattedGraphqlData['publications'] = $this->publicationId;
        }

        return $formattedGraphqlData;
    }

    /**
     * Checks if the API result contains errors
     * */
    public function checkNotExistError(array $result): bool
    {
        if (isset($result['body']['errors'])) {
            $error = json_encode($result['body']['errors'], true);
            $this->jobLogger->warning($error);

            return false;
        }

        return true;
    }

    /**
     * Checks if the API result contains errors
     * */
    public function logWarning(array $data, string $identifier): void
    {
        if (! empty($data) && ! empty($identifier)) {
            $error = json_encode($data, true);
            $this->jobLogger->warning(
                "Warning for product with SKU: {$identifier}, : {$error}"
            );
        }
    }

    /**
     * Update product with Metafields
     * */
    public function updateProductWithMetafields(
        array $formattedGraphqlData,
        array $credentialAsArray,
        string $productId,
        array $parentMapping,
        array $mapping,
        array $parentData = [],
        array $rowData = []
    ): ?array {
        if (! empty($formattedGraphqlData['metafields'])) {
            $externalId = $parentMapping[0]['externalId'] ?? $mapping[0]['externalId'];
            $this->filterNewMetaFieldsOnly($credentialAsArray, $externalId, null, $formattedGraphqlData['metafields']);
        }

        $result = $this->apiRequestShopifyProduct($formattedGraphqlData, $credentialAsArray, $productId);
        if (! $this->checkNotExistError($result)) {
            return null;
        }

        if (! empty($result['body']['data']['productUpdate']['userErrors'])) {
            return $result;
        }

        $sku = $parentData['sku'] ?? $rowData['sku'];
        $mappingId = $parentMapping[0]['id'] ?? $mapping[0]['id'];
        $this->updateMapping($sku, $productId, $this->export->id, $mappingId);
        if (! empty($parentData)) {
            $this->productOptions[$parentData['sku']] = $result['body']['data']['productUpdate']['product']['options'];
        }

        return $result;
    }

    /**
     * Handles the translation
     * */
    public function handleChildProductTranslation(
        array $parentData,
        array $mergedFields,
        bool $skipParent,
        ?array $optionsGetting,
        array $optionValuesTranslation,
        string $productId,
        ?string $variantId,
        array $rowData
    ): void {
        if (! empty($parentData)) {
            $childValues = array_values(array_intersect($this->metaFieldAttributeCode, array_keys($mergedFields)));

            if (! $skipParent) {
                $this->updateProductOptionsTranslation(
                    $this->shopifyDefaultLocale,
                    $optionsGetting,
                    $parentData['super_attributes'],
                    $this->credential,
                    $this->credentialAsArray
                );
            }

            $this->updateProductOptionValuesTranslation(
                $this->shopifyDefaultLocale,
                $optionsGetting,
                $optionValuesTranslation,
                $this->credential,
                $this->credentialAsArray
            );

            $addedMetafieldsInVariant = $this->getExisitingMetafields($this->credentialAsArray, $productId, $variantId);

            $this->metafieldTranslation(
                $this->shopifyDefaultLocale,
                $this->jobChannel,
                $rowData,
                $addedMetafieldsInVariant,
                $childValues,
                $this->credential,
                $this->credentialAsArray,
                'Variant'
            );
        }
    }

    /**
     * Handles the processing of product translations for a given product ID.
     *  */
    public function handleProductProcessingForTranslation(
        string $productId,
        array $parentMergedFields,
        array $mergedFields,
        array $parentData,
        array $rowData,
        array $formattedGraphqlData
    ): void {
        if (! empty($productId) && ! in_array($productId, $this->productId)) {
            $this->productId[] = $productId;

            $productData = ! empty($parentMergedFields) ? $parentMergedFields : $mergedFields;

            $productItem = ! empty($parentData) ? $parentData : $rowData;

            $parentValues = array_values(array_intersect($this->metaFieldAttributeCode, array_keys($productData)));

            $addedMetafields = $this->getExisitingMetafields($this->credentialAsArray, $productId, null);

            $filteredMetafields = array_filter($addedMetafields, function ($item) {
                return ! in_array($item['node']['key'], ['description_tag', 'title_tag']);
            });

            $filteredMetafields = array_values($filteredMetafields);

            $this->metafieldTranslation(
                $this->shopifyDefaultLocale,
                $this->jobChannel,
                $productItem,
                $filteredMetafields,
                $parentValues,
                $this->credential,
                $this->credentialAsArray
            );

            $matchedAttr = array_intersect_key(
                $this->exportMapping->mapping['shopify_connector_settings'] ?? [],
                array_flip($this->translationShopifyFields)
            );

            $this->productTranslation(
                $productId,
                $this->shopifyDefaultLocale,
                $this->jobChannel,
                $productItem,
                $this->credential,
                $this->credentialAsArray,
                $formattedGraphqlData,
                $matchedAttr
            );
        }
    }

    /**
     * Updates a product variant with the provided data.
     * */
    public function updateProductVariant(
        array $variantData,
        array $formattedGraphqlData,
        array $mapping,
        string $productId,
        array $rowData,
        ?array $parentData
    ): string|bool|null {
        $variantData['id'] = $variantId = $mapping[0]['externalId'];

        if (isset($formattedGraphqlData['metafields'])) {
            $variantData['metafields'] = $formattedGraphqlData['metafields'];
        }

        $variantInput = ['input' => $variantData];

        if (! empty($formattedGraphqlData['metafields'])) {
            $this->filterNewMetaFieldsOnly($this->credentialAsArray, $productId, $variantId, $formattedGraphqlData['metafields']);
        }

        $result = $this->requestGraphQlApiAction('ProductVariantUpdate', $this->credentialAsArray, $variantInput);

        $errorUpdate = $result['body']['data']['productVariantUpdate']['userErrors'] ?? [];

        if (isset($errorUpdate[0]['message']) && $errorUpdate[0]['message'] == self::NOT_EXIST_PRODUCT_VARIANT) {
            $this->deleteProductVariantMapping($variantId, $rowData['sku']);
            if (! empty($parentData)) {
                $rowData['parent'] = $parentData;
            }

            $notExistProductCreated = $this->processProductData($rowData);

            if ($notExistProductCreated) {
                return true;
            }
        }

        if (! empty($errorUpdate)) {
            $this->logWarning($errorUpdate, $rowData['sku']);
            $this->skippedItemsCount++;

            return null;
        }

        if (! $this->checkNotExistError($result)) {
            return null;
        }

        $updatedVariantId = $result['body']['data']['productVariantUpdate']['productVariant']['id'];

        $this->updateMapping($rowData['sku'], $updatedVariantId, $this->export->id, $mapping[0]['id']);

        return $updatedVariantId;
    }

    /**
     * Updates product options for a given parent product.
     *
     * */
    public function updateProductOptions(array $parentData, array $variableOption): array
    {
        $optionsGetting = [];

        foreach ($this->productOptions[$parentData['sku']] ?? [] as $key => $value) {
            $variableOption[$key]['optionInput']['id'] = $value['id'];
            $names = array_column($value['optionValues'], 'name');

            if (in_array($variableOption[$key]['optionValuesToUpdate'][0]['name'], $names)) {
                $index = array_search($variableOption[$key]['optionValuesToUpdate'][0]['name'], $names);
                $variableOption[$key]['optionValuesToUpdate'][0]['id'] = $value['optionValues'][$index]['id'];
            } else {
                $variableOption[$key]['optionValuesToAdd'][0]['name'] = $variableOption[$key]['optionValuesToUpdate'][0]['name'];
                unset($variableOption[$key]['optionValuesToUpdate']);
            }

            $optionResult = $this->requestGraphQlApiAction('productOptionUpdated', $this->credentialAsArray, $variableOption[$key]);

            $optionsGetting = $optionResult['body']['data']['productOptionUpdate']['product']['options'];

            $this->productOptions[$parentData['sku']] = $optionsGetting;
        }

        return $optionsGetting;
    }

    /**
     * Handles media updates for a Shopify product.
     * */
    public function handleMediaUpdates(
        string $productId,
        array $rowData,
        array $parentData,
        array $imageData,
    ): void {
        if (! empty($this->updateMedia)) {
            $productMedias = $this->requestGraphQlApiAction('productUpdateMedia', $this->credentialAsArray, [
                'productId' => $productId,
                'media'     => $this->updateMedia,
            ]);

            $mediaIdsUnassign = $productMedias['body']['data']['productUpdateMedia']['media'] ?? [];
            $removingIds = array_values(array_filter($this->removeImgAttr));
            $input = array_map(function ($media) use ($productId) {
                return [
                    'id'              => $media['id'],
                    'referencesToAdd' => [$productId],
                ];
            }, $mediaIdsUnassign);
            $inputs = [];
            if (! empty($removingIds)) {
                $inputs = array_map(function ($mediaRemove) use ($productId) {
                    return [
                        'id'                 => $mediaRemove,
                        'referencesToRemove' => [$productId],
                    ];
                }, $removingIds);
            }

            $input = array_merge($input, $inputs);

            $jsonData = ['input' => $input];

            $this->requestGraphQlApiAction('productFileUpdate', $this->credentialAsArray, $jsonData);
        }

        if (! empty($this->imageData)) {
            $newImageAdded = [
                'productId' => $productId,
                'media'     => $this->imageData,
            ];

            $resultImage = $this->requestGraphQlApiAction('productCreateMedia', $this->credentialAsArray, $newImageAdded);

            $mediasUpdate = $this->updateMedia = $resultImage['body']['data']['productCreateMedia']['media'];
            if (! empty($mediasUpdate)) {
                $this->mapMediaImages($rowData, $mediasUpdate, $productId, $imageData, $this->childImageAttr);

                if (! empty($parentData)) {
                    $this->mapMediaImages($parentData, $mediasUpdate, $productId, $imageData, $this->parentImageAttr);
                }
            }
        }
    }

    /**
     * Maps media images to a Shopify product and updates the media information.
     * */
    private function mapMediaImages(array $data, array &$mediasUpdate, string $productId, array $imageData, array $mappingImageAttr): void
    {
        foreach ($imageData[$data['sku']] ?? [] as $key => $imageUrl) {
            $this->imageMapping(
                'productImage',
                $mappingImageAttr[$key],
                $mediasUpdate[$key]['id'],
                $this->export->id,
                $productId,
                $data['sku']
            );

            unset($mediasUpdate[$key]);
        }

        $mediasUpdate = array_values(array_filter($mediasUpdate));
    }

    /**
     * Formats and creates a Shopify product variant with the provided data.
     * */
    public function productVariantFormatAndCreate(
        array $formattedGraphqlData,
        array $variantData,
        array $imageData,
        array $rowData,
        array $productOptionValues,
        string $parentId,
        ?string $variantMediaId = null
    ) {
        if (isset($formattedGraphqlData['metafields'])) {
            $variantData['metafields'] = $formattedGraphqlData['metafields'];
        }

        if ($imageData && isset($imageData[$rowData['sku']])) {
            $variantData['mediaSrc'] = reset($imageData[$rowData['sku']])['originalSource'] ?? '';
            $finalVariant['media'] = $imageData[$rowData['sku']];
        }

        if ($variantMediaId) {
            $variantData['mediaId'] = $variantMediaId;
        }

        $finalVariant['variantsInput'] = $variantData + $productOptionValues;
        $finalVariant['productId'] = $parentId;

        if (empty($finalVariant['media'])) {
            $finalVariant['media'] = [];
        }

        $result = $this->requestGraphQlApiAction('CreateProductVariants', $this->credentialAsArray, $finalVariant);

        return $result;
    }

    /**
     * Processes the result of product variant creation and handles associated data such as media mapping and options.
     */
    public function processVariantCreationResult(
        array $formattedGraphqlData,
        array $variantData,
        array $imageData,
        array $rowData,
        array $productOptionValues,
        array $parentMapping
    ): ?array {
        $result = $this->productVariantFormatAndCreate(
            $formattedGraphqlData,
            $variantData,
            $imageData,
            $rowData,
            $productOptionValues,
            $parentMapping[0]['externalId']
        );

        if (
            isset($result['body']['data']['productVariantsBulkCreate']['userErrors'])
            && ! empty($result['body']['data']['productVariantsBulkCreate']['userErrors'])
        ) {
            $this->logWarning($result['body']['data']['productVariantsBulkCreate']['userErrors'], $rowData['sku']);
            $this->skippedItemsCount++;

            return null;
        }

        $variantId = $result['body']['data']['productVariantsBulkCreate']['productVariants'][0]['id'];

        $optionsGetting = $result['body']['data']['productVariantsBulkCreate']['product']['options'];

        $productId = $result['body']['data']['productVariantsBulkCreate']['product']['id'];

        if ($imageData && isset($imageData[$rowData['sku']])) {
            $medias = array_slice(
                $result['body']['data']['productVariantsBulkCreate']['product']['media']['nodes'],
                -count($imageData[$rowData['sku']])
            );

            if (! empty($medias)) {
                foreach ($imageData[$rowData['sku']] as $key => $imageUrl) {
                    $this->imageMapping(
                        'productImage',
                        $this->imageAttributes[$key],
                        $medias[$key]['id'],
                        $this->export->id,
                        $parentMapping[0]['externalId'],
                        $rowData['sku']
                    );
                }
            }
        }

        $this->parentMapping($rowData['sku'], $variantId, $this->export->id, $productId);

        return [
            'variantId'      => $variantId,
            'optionsGetting' => $optionsGetting,
            'productId'      => $productId,
        ];
    }

    /**
     * Maps the provided image IDs to product or variant images for both the child and parent products.
     */
    public function imageIdMapping(
        array $imageIds,
        array $imageData,
        array $rowData,
        array $parentData,
        string $productId
    ): void {
        foreach ($imageData[$rowData['sku']] ?? [] as $key => $imageUrl) {
            $this->imageMapping('productImage', $this->imageAttributes[$key], $imageIds[$key]['id'], $this->export->id, $productId, $rowData['sku']);

            unset($imageIds[$key]['id']);
        }

        $imageIds = array_values(array_filter($imageIds));

        foreach ($imageData[@$parentData['sku']] ?? [] as $key => $imageUrl) {
            $this->imageMapping('productImage', $this->imageAttributes[$key], $imageIds[$key]['id'], $this->export->id, $productId, $parentData['sku']);

            unset($imageIds[$key]['id']);
        }
    }

    /**
     * Retrieves category external IDs based on the provided category codes.
     */
    public function getCategoriesByCode(array $categoriesCode, array &$finalCategories): void
    {
        foreach ($categoriesCode ?? [] as $key => $value) {
            $check = $this->checkMappingInDb(['code' => $value], 'category');

            if (isset($check[0]['externalId'])) {
                $finalCategories[] = $check[0]['externalId'];
            }
        }
    }

    /**
     * process super attributes
     */
    private function processSuperAttributes(
        array $superAttributes,
        string $shopifyDefaultLocale,
        array $mergedFields,
        ?array $parentMapping,
        ?array $mapping
    ): ?array {
        $optionsValues = ['optionValues' => []];

        $variableOption = [];

        $finalOption = [];

        foreach ($superAttributes as $key => $optionvalues) {
            $translationsOption = $optionvalues['translations'];
            $name = $optionvalues['code'];

            if (isset($this->settingMapping->mapping['option_name_label']) && $this->settingMapping->mapping['option_name_label']) {
                $name = array_column(array_filter($translationsOption, fn ($item) => $item['locale'] === $shopifyDefaultLocale), 'name')[0] ?? $optionvalues['name'];
            }

            if ($key < 3) {
                $options = [
                    'name'   => $name,
                    'values' => [['name' => 'default']],
                ];
                $finalOption[] = $options;
            }

            $attribute = $this->attributeRepository->findOneByField('code', $optionvalues['code']);

            $optionTrans = $attribute->options()->where('code', '=', $mergedFields[$optionvalues['code']])->first()->toArray();

            $optionsValues['optionValues'][] = [
                'name'       => $mergedFields[$optionvalues['code']],
                'optionName' => $name,
            ];

            $optionValuesTranslation[$mergedFields[$optionvalues['code']]] = $optionTrans['translations'];

            if (! empty($parentMapping) && ! empty($mapping)) {
                $optionValuesToUpdate = [
                    [
                        'id'   => null,
                        'name' => $mergedFields[$optionvalues['code']],
                    ],
                ];

                $variableOption[] = [
                    'productId'   => $parentMapping[0]['externalId'],
                    'optionInput' => [
                        'id'   => null,
                        'name' => $name,
                    ],
                    'optionValuesToUpdate' => $optionValuesToUpdate,
                ];
            }
        }

        return [
            $variableOption,
            $optionsValues,
            $finalOption,
            $optionValuesTranslation,
        ];
    }

    /**
     * getting all attribute values
     */
    public function gettingAllAttrValue(array $rowData): array
    {
        $commonFields = $this->getCommonFields($rowData);

        $commonFields['status'] = $rowData['status'] == 1 ? 'true' : 'false';

        $localeSpecificFields = $this->getLocaleSpecificFields($rowData, $this->shopifyDefaultLocale);

        $channelSpecificFields = $this->getChannelSpecificFields($rowData, $this->jobChannel);

        $channelLocaleSpecificFields = $this->getChannelLocaleSpecificFields($rowData, $this->jobChannel, $this->shopifyDefaultLocale);

        return array_merge($commonFields, $localeSpecificFields, $channelSpecificFields, $channelLocaleSpecificFields);
    }

    /**
     * Sends a request to the Shopify API to create or update a product.
     *
     *  */
    public function apiRequestShopifyProduct(array $formattedGraphqlData, array $credential, ?string $id = null): ?array
    {
        if (isset($formattedGraphqlData['parentMetaFields'])) {
            $formattedGraphqlData['metafields'] = $formattedGraphqlData['parentMetaFields'];

            unset($formattedGraphqlData['parentMetaFields']);
        }

        if ($id) {
            $formattedGraphqlData['id'] = $id;

            $response = $this->requestGraphQlApiAction('productUpdate', $credential, ['input' => $formattedGraphqlData]);
        } else {
            $response = $this->requestGraphQlApiAction('createProduct', $credential, ['input' => $formattedGraphqlData, 'media' => $this->imageData]);
        }

        return $response;
    }

    /**
     * Creates a new product variant in Shopify and deletes the original variant.
     *
     * */
    public function apiRequestShopifyDefaultVariantCreate(array $variantData, array $credential, bool $model = false): ?array
    {
        $idv = $variantData['input']['id'];

        unset($variantData['input']['id']);

        $response = $this->requestGraphQlApiAction('productVariantCreate', $credential, $variantData);

        $this->requestGraphQlApiAction('productVariantDelete', $credential, ['id' => $idv]);

        return $response;
    }

    /**
     * Deletes existing metafields for a given product or variant.
     *
     * */
    public function filterNewMetaFieldsOnly(array $credential, string $productId, ?string $variantId, array $metafields): void
    {
        $existingMetaFields = $this->getExisitingMetafields($credential, $productId, $variantId);

        foreach ($existingMetaFields as $existingMetaField) {
            $input['input']['id'] = $existingMetaField['node']['id'];

            $response = $this->requestGraphQlApiAction('deleteMetafield', $credential, $input);
        }
    }

    /**
     * Retrieves existing metafields for a specified product or variant from the Shopify API.
     */
    public function getExisitingMetafields(array $credential, string $productId, ?string $variantId): ?array
    {
        if ($variantId) {
            $endPoint = 'productVariantMetafield';
            $variable = [
                'id' => $variantId,
            ];
        }

        $existingMetaFields = [];
        $url = null;

        do {
            if (! $url) {
                $endPoint = 'productMetafields';
                $variable = [
                    'id' => $productId,
                ];
                $productType = 'product';

                if ($variantId) {
                    $endPoint = 'productVariantMetafield';
                    $variable = [
                        'id' => $variantId,
                    ];
                    $productType = 'productVariant';
                }
            } else {
                $endPoint = 'productMetafieldsByCursor';
                $variable = [
                    'id'          => $productId,
                    'first'       => 50,
                    'afterCursor' => $url,
                ];
                $productType = 'product';

                if ($variantId) {
                    $endPoint = 'productVariantMetafieldByCursor';

                    $variable = [
                        'id'          => $variantId,
                        'first'       => 50,
                        'afterCursor' => $url,
                    ];

                    $productType = 'productVariant';
                }
            }

            $response = $this->requestGraphQlApiAction($endPoint, $credential, $variable, $productType);

            if (! isset($response['body']['data'][$productType]['metafields'])) {
                return [];
            }

            $gettingMetaFields = $response['body']['data'][$productType]['metafields']['edges'];

            if (! empty($response['body']['data'][$productType]['metafields']['edges'])) {
                $existingMetaFields = array_merge($existingMetaFields, $gettingMetaFields);
            }

            $lastCursor = @end($gettingMetaFields)['cursor'];

            if (isset($gettingMetaFields) && $url !== $lastCursor) {
                $url = $lastCursor;
            }
        } while ($gettingMetaFields);

        return $existingMetaFields;
    }

    /**
     * Handles Product images.
     */
    public function formatDataForGraphqlImage(array $rawData, array $exportSeting, array $parentrawData): array
    {
        $medias = [];

        if (isset($exportSeting['images']) && ! empty($exportSeting['images'])) {
            $imagesAttr = explode(',', $exportSeting['images']);
            $imageAttrCode = [];
            $updateMedia = [];

            foreach ($imagesAttr as $imageAttr) {

                if (! empty($rawData[$imageAttr])) {
                    $medias = $this->processMedia($imageAttr, $rawData, $imageAttrCode, $updateMedia, $medias);
                    $this->childImageAttr[] = $imageAttr;
                } else {
                    $mappingImageC = $this->checkMappingInDbForImage($imageAttr, 'productImage', $rawData['sku']);
                    $this->removeImgAttr[] = $mappingImageC[0]['externalId'] ?? null;
                }

                if (! empty($parentrawData[$imageAttr])) {
                    $medias = $this->processMedia($imageAttr, $parentrawData, $imageAttrCode, $updateMedia, $medias);
                    $this->parentImageAttr[] = $imageAttr;
                } else {
                    if (isset($parentrawData['sku'])) {
                        $mappingImageP = $this->checkMappingInDbForImage($imageAttr, 'productImage', $parentrawData['sku']);
                        $this->removeImgAttr[] = $mappingImageP[0]['externalId'] ?? null;
                    }

                }
            }

            $this->imageAttributes = $imageAttrCode;
            $this->updateMedia = $updateMedia;
        }

        return $medias;
    }

    /**
     * Processes media data for a given image attribute and item data.
     *
     * */
    public function processMedia(string $imageAttr, array $itemData, array &$imageAttrCode, array &$updateMedia, array $medias): array
    {
        $mappingImage = $this->checkMappingInDbForImage($imageAttr, 'productImage', $itemData['sku']);

        $fullUrl = Storage::url(@$itemData[$imageAttr]);

        if (! empty($mappingImage)) {
            $updateMedia[] = [
                'alt'                => 'Some more alt text',
                'id'                 => $mappingImage[0]['externalId'],
                'previewImageSource' => $fullUrl,
            ];

            return $medias;
        }

        if (! in_array($imageAttr, $imageAttrCode)) {
            $imageAttrCode[] = $imageAttr;
        }

        $medias[$itemData['sku']][] = [
            'alt'              => 'This is image description',
            'mediaContentType' => 'IMAGE',
            'originalSource'   => $fullUrl,
        ];

        return $medias;
    }

    /**
     * Get Locale Specific Attributes
     */
    protected function getLocaleSpecificFields(array $data, $locale): array
    {
        if (
            ! array_key_exists('values', $data)
            || ! array_key_exists('locale_specific', $data['values'])
        ) {
            return [];
        }

        return $data['values']['locale_specific'][$locale] ?? [];
    }

    /**
     * Get Locale and channel Specific Attributes
     */
    public function getChannelLocaleSpecificFields(array $data, string $channel, string $locale): array
    {
        if (
            ! array_key_exists('values', $data)
            || ! array_key_exists('channel_locale_specific', $data['values'])
        ) {
            return [];
        }

        return $data['values']['channel_locale_specific'][$channel][$locale] ?? [];
    }

    /**
     * Get channel Specific Attributes
     */
    protected function getChannelSpecificFields(array $data, $channel): array
    {
        if (
            ! array_key_exists('values', $data)
            || ! array_key_exists('channel_specific', $data['values'])
        ) {
            return [];
        }

        return $data['values']['channel_specific'][$channel] ?? [];
    }

    /**
     * Get Common Attributes
     */
    protected function getCommonFields(array $data): array
    {
        if (
            ! array_key_exists('values', $data)
            || ! array_key_exists('common', $data['values'])
        ) {
            return [];
        }

        return $data['values']['common'];
    }
}
