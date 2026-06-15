<?php

namespace Webkul\Shopify\Helpers\Exporters\Category;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
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

    public const BATCH_SIZE = 10;

    public const COLLECTION_NOT_EXIST = 'Collection does not exist';

    /**
     * unopim entity name.
     *
     * @var string
     */
    public const UNOPIM_ENTITY_NAME = 'category';

    public const UPDATE_PUBLISH_CHANNEL = 'publishablePublish';

    public const UPDATE_UNPUBLISH_CHANNEL = 'unpublishableUnpublish';

    /**
     * Shopify credential.
     *
     * @var mixed
     */
    protected $credential;

    /**
     * Shopify credential as array for api request.
     *
     * @var mixed
     */
    protected $credentialArray;

    /**
     * Shopify sales channel publication ids
     */
    protected $publicationId = [];

    /**
     * Default locale of shopify store
     */
    protected $shopifyDefaultLocale;

    /**
     * Collection mapping config (row id 4).
     *
     * @var mixed
     */
    protected $collectionMapping;

    protected bool $exportsFile = false;

    /**
     * Create a new instance of the exporter.
     */
    public function __construct(
        protected JobTrackBatchRepository $exportBatchRepository,
        protected FileExportFileBuffer $exportFileBuffer,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected ShopifyExportMappingRepository $shopifyExportMappingRepository,
    ) {
        parent::__construct($exportBatchRepository, $exportFileBuffer);
    }

    /**
     * Initializes the channels and locales for the export process.
     *
     * @return void
     */
    public function initialize()
    {
        $this->initCredential();

        $this->initPublications();

        $this->initDefaultLocale();

        $this->collectionMapping = $this->shopifyExportMappingRepository->find(4);
    }

    /**
     * Initialize credentials data from filters
     */
    protected function initCredential(): void
    {
        $filters = $this->getFilters();

        $this->credential = $this->shopifyRepository->find($filters['credentials']);

        if (! $this->credential?->active) {
            $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.invalid-credential'));

            $this->export->state = ExportHelper::STATE_FAILED;
            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $this->export->save();

            throw new InvalidCredential;
        }

        $this->credentialArray = $this->credential->toApiArray();
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

    /**
     * Start the export process
     */
    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        Event::dispatch('shopify.category.export.before', $batch);

        $this->initialize();

        $this->prepareCategoriesShopify($batch, $filePath);

        /**
         * Update export batch process state summary
         */
        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        Event::dispatch('shopify.category.export.after', $batch);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function getResults()
    {
        return $this->source->with('parent_category')->orderBy('id', 'desc')->all()?->getIterator();
    }

    public function prepareCategoriesShopify(JobTrackBatchContract $batch, mixed $filePath)
    {
        $fieldMap = $this->collectionMapping?->mapping['collection_mapping'] ?? [];

        foreach ($batch->data as $rawData) {
            $mapping = $this->checkMappingInDb($rawData) ?? null;

            $category = $this->buildCollectionPayload($rawData, $fieldMap);

            if (empty($category['title'])) {
                $this->jobLogger->warning(
                    trans('shopify::app.shopify.export.mapping.collection.errors.empty_title', ['code' => $rawData['code'] ?? ''])
                );
                $this->skippedItemsCount++;

                continue;
            }

            if (empty($mapping)) {
                $responseData = $this->apiRequestShopify($category);
                $resultCollection = $responseData['body']['data']['collectionCreate'] ?? [];
                if (! empty($resultCollection['userErrors'])) {
                    $this->logWarning($resultCollection['userErrors'], $rawData['code']);
                    $this->skippedItemsCount++;

                    continue;
                }

                $this->handleAfterApiRequest($rawData, $responseData, $mapping, $this->export->id, $category);

                $this->createdItemsCount++;
            } else {
                $category['id'] = $mapping[0]['externalId'];
                $responseData = $this->apiRequestShopify($category, $category['id']);
                $resultCollection = $responseData['body']['data']['collectionUpdate'] ?? [];
                $this->logWarning($resultCollection['userErrors'], $rawData['code']);
                if (! empty($resultCollection['userErrors'])) {
                    $resultCollection = $this->handleAfterApiRequest($rawData, $responseData, $mapping, $this->export->id, $category);

                    if (! empty($resultCollection['userErrors']) || empty($resultCollection)) {
                        $this->skippedItemsCount++;
                        $this->logWarning($resultCollection['userErrors'], $rawData['code']);

                        continue;
                    }
                }

                $this->createdItemsCount++;
            }

            if (empty($resultCollection['userErrors']) && ! empty($this->publicationId)) {
                $this->updateSalesChannel($resultCollection, $this->publicationId);
            }

            $this->categoryTranslation($this->shopifyDefaultLocale, $rawData, $this->credential,
                $this->credentialArray, $resultCollection['collection'] ?? [], $fieldMap);
        }
    }

    /**
     * Update sales channel of the collection
     */
    public function updateSalesChannel($collectionResult, $publicationIds): void
    {
        $collectionId = $collectionResult['collection']['id'];
        $existingPublications = $collectionResult['collection']['resourcePublications']['edges'] ?? [];

        $existingIds = array_map(fn ($item) => $item['node']['publication']['id'], $existingPublications);
        $newIds = array_column($publicationIds, 'publicationId');
        sort($existingIds);
        sort($newIds);
        if ($existingIds !== $newIds) {
            $this->requestGraphQlApiAction(self::UPDATE_PUBLISH_CHANNEL, $this->credentialArray, [
                'id' => $collectionId,
                'input' => $publicationIds,
            ]);

            $removePublication = array_values(array_diff($existingIds, $newIds));
            if (! empty($removePublication)) {
                $this->requestGraphQlApiAction(self::UPDATE_UNPUBLISH_CHANNEL, $this->credentialArray, [
                    'id' => $collectionId,
                    'input' => array_map(fn ($id) => ['publicationId' => $id], $removePublication),
                ]);
            }
        }
    }

    /**
     * log Warning generate
     */
    public function logWarning(array $data, string $code): void
    {
        if (! empty($data) && ! empty($code)) {
            $error = json_encode($data, true);

            $this->jobLogger->warning(
                "Warning for Category with code: {$code}, : {$error}"
            );
        }
    }

    /**
     * Get locale-specific fields from the raw data.
     */
    private function getLocaleSpecificFields(array $data, ?string $locale): array
    {
        if (! is_array($data['additional_data'])) {
            return [];
        }

        if (! array_key_exists('additional_data', $data) || ! array_key_exists('locale_specific', $data['additional_data'])) {
            return [];
        }

        return $data['additional_data']['locale_specific'][$locale] ?? [];
    }

    /**
     * Merge common and locale-specific category field values for a locale.
     */
    private function getMergedFields(array $data, ?string $locale): array
    {
        $additional = $data['additional_data'] ?? [];

        if (! is_array($additional)) {
            return [];
        }

        $common = is_array($additional['common'] ?? null) ? $additional['common'] : [];
        $localeSpecific = is_array($additional['locale_specific'][$locale] ?? null) ? $additional['locale_specific'][$locale] : [];

        return array_merge($common, $localeSpecific);
    }

    /**
     * Build the Shopify CollectionInput payload from the mapping config.
     *
     * @param  array<string, string>  $fieldMap
     */
    private function buildCollectionPayload(array $rawData, array $fieldMap): array
    {
        $merged = $this->getMergedFields($rawData, $this->shopifyDefaultLocale);
        $config = $this->collectionMapping?->mapping ?? [];

        $category = [];

        $titleCode = $fieldMap['title'] ?? null;
        $category['title'] = $titleCode ? ($merged[$titleCode] ?? '') : '';

        foreach (['descriptionHtml' => 'descriptionHtml', 'handle' => 'handle'] as $mapKey => $payloadKey) {
            if (! empty($fieldMap[$mapKey]) && ! empty($merged[$fieldMap[$mapKey]])) {
                $category[$payloadKey] = $merged[$fieldMap[$mapKey]];
            }
        }

        $seo = [];
        foreach (['seoTitle' => 'title', 'seoDescription' => 'description'] as $mapKey => $seoKey) {
            if (! empty($fieldMap[$mapKey]) && ! empty($merged[$fieldMap[$mapKey]])) {
                $seo[$seoKey] = $merged[$fieldMap[$mapKey]];
            }
        }
        if (! empty($seo)) {
            $category['seo'] = $seo;
        }

        if (! empty($config['sort_order'])) {
            $category['sortOrder'] = $config['sort_order'];
        }

        if (! empty($category['title']) && $this->isSmartCollection($fieldMap, $merged)) {
            $category['ruleSet'] = $this->defaultSmartRuleSet($category['title']);
        }

        $imageUrl = $this->resolveCollectionImageUrl($config, $merged);
        if (! empty($imageUrl)) {
            $category['image'] = ['src' => $imageUrl];
        }

        return $category;
    }

    /**
     * Resolve whether the mapped collection-type boolean attribute is truthy.
     * True => Smart collection; false or unmapped => Manual.
     *
     * @param  array<string, string>  $fieldMap
     */
    private function isSmartCollection(array $fieldMap, array $merged): bool
    {
        $code = $fieldMap['collectionType'] ?? null;

        if (empty($code)) {
            return false;
        }

        $value = strtolower(trim((string) ($merged[$code] ?? '')));

        return in_array($value, ['1', 'true', 'yes'], true);
    }

    /**
     * Default rule set for a smart collection: products whose title contains the
     * collection title. Used when the type resolves to Smart but no per-rule
     * configuration exists.
     */
    private function defaultSmartRuleSet(string $title): array
    {
        return [
            'appliedDisjunctively' => false,
            'rules' => [
                [
                    'column' => 'TITLE',
                    'relation' => 'CONTAINS',
                    'condition' => $title,
                ],
            ],
        ];
    }

    /**
     * Resolve the mapped collection image attribute to a public URL.
     */
    private function resolveCollectionImageUrl(array $config, array $merged): ?string
    {
        $mediaAttr = $config['mediaMapping']['mediaAttributes'] ?? '';

        if (empty($mediaAttr)) {
            return null;
        }

        $code = is_array($mediaAttr) ? ($mediaAttr[0] ?? '') : trim(explode(',', $mediaAttr)[0]);

        if (empty($code) || empty($merged[$code])) {
            return null;
        }

        $value = $merged[$code];
        $path = is_array($value) ? ($value[0] ?? '') : (string) $value;

        if (empty($path)) {
            return null;
        }

        // Encode each path segment (spaces, brackets, etc.) so Shopify can fetch the image.
        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));

        return Storage::url($encodedPath);
    }

    /**
     * Make an API request to Shopify to create or update a category.
     */
    public function apiRequestShopify($category, $id = null)
    {
        $mutationType = $id ? 'updateCollection' : 'createCollection';

        $response = $this->requestGraphQlApiAction($mutationType, $this->credentialArray, ['input' => $category]);

        return $response;
    }
}
