<?php

namespace Webkul\Shopify\Helpers\Importers\Category;

use Illuminate\Support\Arr;
use Webkul\Category\Repositories\CategoryFieldRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Helpers\Importers\Category\Storage;
use Webkul\DataTransfer\Helpers\Source;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Helpers\Iterator\CategoryIterator;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyExportMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Traits\DataMappingTrait;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\ValidatedBatched;

class Importer extends AbstractImporter
{
    use DataMappingTrait;
    use ShopifyGraphqlRequest;
    use ValidatedBatched;

    public const BATCH_SIZE = 10;

    public const UNOPIM_ENTITY_NAME = 'category';

    /**
     * cursor position
     */
    public $cursor = null;

    protected array $categoryFields;

    /**
     * locales storage
     */
    protected array $locales = [];

    /**
     * Shopify credential.
     *
     * @var mixed
     */
    protected $credential;

    /**
     * Shopify job Locale.
     *
     * @var mixed
     */
    protected $locale;

    /**
     * Shopify credential as array for api request.
     *
     * @var mixed
     */
    protected $credentialArray;

    protected $cachedCategoryFields = [];

    protected ?array $nonDeletableCategories = null;

    protected ?int $rootCategoryId = null;

    protected $importMapping;

    protected $collectionMapping;

    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected CategoryRepository $categoryRepository,
        protected CategoryFieldRepository $categoryFieldRepository,
        protected Storage $categoryStorage,
        protected LocaleRepository $localeRepository,
        protected ChannelRepository $channelRepository,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyExportMappingRepository $shopifyExportmapping,
        protected ShopifyMappingRepository $shopifyMappingRepository,
    ) {
        parent::__construct($importBatchRepository);

        $this->initLocales();
    }

    /**
     * Initialize locales
     */
    protected function initLocales(): void
    {
        $this->locales = $this->localeRepository->getActiveLocales()->pluck('code')->toArray();
    }

    /**
     * Initialize Filters
     */
    protected function initFilters(): void
    {
        $filters = $this->import->jobInstance->filters;

        $this->credential = $this->shopifyRepository->find($filters['credentials'] ?? null);

        $this->locale = $filters['locale'] ?? null;

        $this->rootCategoryId = core()->getDefaultChannel()?->root_category_id;

        if (! $this->rootCategoryId) {
            $channelWithRoot = $this->channelRepository
                ->all()
                ->first(fn ($channel) => ! empty($channel->root_category_id));

            $this->rootCategoryId = $channelWithRoot?->root_category_id;
        }

        $this->importMapping = $this->shopifyExportmapping->find(3);

        $this->collectionMapping = $this->shopifyExportmapping->find(4);

        $this->credentialArray = $this->credential?->toApiArray() ?? [];
    }

    /**
     * Import instance.
     *
     * @return Source
     */
    public function getSource()
    {
        $this->categoryStorage->init();
        $this->initFilters();
        if (! $this->credential?->active) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credential'));
        }

        $collections = new CategoryIterator($this->credentialArray);

        return $collections;
    }

    /**
     * Save categories from current batch
     */
    public function saveCategories(array $categories): void
    {
        /** single insert/update in the db because of parent  */
        if (! empty($categories['update'])) {
            $this->updatedItemsCount += count($categories['update']);
            foreach ($categories['update'] as $code => $category) {
                $categoryId = $this->categoryStorage->get($code);

                if (! $categoryId) {
                    $existing = $this->categoryRepository->findOneByField('code', $code);
                    $categoryId = $existing?->id;
                }

                if (! $categoryId) {
                    continue;
                }

                unset($category['parent_id']);

                $this->categoryRepository->update($category, $categoryId, withoutFormattingValues: true);
            }
        }

        if (! empty($categories['insert'])) {
            $this->createdItemsCount += count($categories['insert']);
            foreach ($categories['insert'] as $code => $category) {
                $newCategory = $this->categoryRepository->create($category, withoutFormattingValues: true);
                if ($newCategory) {
                    $this->categoryStorage->set($code, $newCategory?->id);
                }
            }
        }
    }

    public function validateData(): void
    {
        $this->saveValidatedBatches();
    }

    /**
     * Start the import process for Category Import
     */
    public function importBatch(JobTrackBatchContract $batch): bool
    {
        $this->saveCategoryData($batch);

        return true;
    }

    /**
     * save the category data
     */
    public function saveCategoryData(JobTrackBatchContract $batch): bool
    {
        $this->initFilters();
        if (! empty($this->collectionMapping?->mapping['collection_mapping']['title'])) {
            $collectionData = array_column($batch->data, 'node');
            $this->categoryStorage->load(Arr::pluck($collectionData, 'handle'));
            $categories = [];
            foreach ($batch->data as $rowData) {
                $this->prepareCategories($rowData, $categories);
            }
            $this->saveCategories($categories);
        }
        /**
         * Update import batch summary
         */
        $batch = $this->importBatchRepository->update([
            'state' => Import::STATE_PROCESSED,
            'summary' => [
                'created' => $this->getCreatedItemsCount(),
                'updated' => $this->getUpdatedItemsCount(),
                'deleted' => $this->getDeletedItemsCount(),
            ],
        ], $batch->id);

        return true;
    }

    /**
     * Prepare categories for import (mapping-driven from the id=4 collection mapping).
     */
    public function prepareCategories(array $collection, &$category)
    {
        $node = $collection['node'];
        $fieldMap = $this->collectionMapping?->mapping['collection_mapping'] ?? [];

        $categ = $this->categoryRepository->findOneByField('code', $node['handle']);

        $data = [
            'code' => $node['handle'],
            'parent_id' => $categ?->parent_id ?? $this->rootCategoryId,
            'additional_data' => $categ ? $categ->toArray()['additional_data'] : [],
        ];

        $values = [
            $fieldMap['title'] => $node['title'] ?? '',
        ];

        /**
         * Direct mapping key => Shopify node value. Each is written only when
         * the field is mapped, preserving the original insertion order.
         */
        $directFields = [
            'descriptionHtml' => $node['descriptionHtml'] ?? '',
            'seoTitle' => $node['seo']['title'] ?? '',
            'seoDescription' => $node['seo']['description'] ?? '',
            'handle' => $node['handle'] ?? '',
        ];

        foreach ($directFields as $mapKey => $value) {
            if (! empty($fieldMap[$mapKey])) {
                $values[$fieldMap[$mapKey]] = $value;
            }
        }

        if (! empty($fieldMap['collectionType'])) {
            $values[$fieldMap['collectionType']] = empty($node['ruleSet']) ? 'false' : 'true';
        }

        $this->applyCollectionTranslations($node, $fieldMap, $values);

        foreach ($values as $code => $value) {
            $this->writeCategoryFieldValue($data, $code, $value);
        }

        $this->mapCollectionImage($collection, $data);

        if (! $this->checkMappingInDb(['code' => $node['handle']])) {
            $this->parentMapping($node['handle'], $node['id'], $this->import->id);
        }

        if ($categ) {
            $data['additional_data'] = $this->mergeCategoryFieldValues($data['additional_data'] ?? [], $category['update'][$node['handle']]['additional_data'] ?? []);
            $category['update'][$node['handle']] = array_merge($category['update'][$node['handle']] ?? [], $data);
        } else {
            $data['additional_data'] = $this->mergeCategoryFieldValues($data['additional_data'], $category['insert'][$node['handle']]['additional_data'] ?? []);
            $category['insert'][$node['handle']] = array_merge($category['insert'][$node['handle']] ?? [], $data);
        }
    }

    /**
     * Write a resolved value into the category field's correct bucket
     * (locale_specific for value-per-locale fields, common otherwise).
     */
    protected function writeCategoryFieldValue(array &$data, string $code, $value): void
    {
        $field = $this->getCategoryFieldByCode($code);

        if (! $field) {
            return;
        }

        if ($field->value_per_locale) {
            $data['additional_data'][CategoryRepository::LOCALE_VALUES_KEY][$this->locale][$code] = $value;
        } else {
            $data['additional_data'][CategoryRepository::COMMON_VALUES_KEY][$code] = $value;
        }
    }

    /**
     * Override mapped text values with the current locale's Shopify translations.
     * For a non-default Shopify locale with no translation, the value is cleared
     * so the default-locale text is not copied across locales.
     */
    protected function applyCollectionTranslations(array $node, array $fieldMap, array &$values): void
    {
        $shopifyLocale = array_search($this->locale, (array) ($this->credential?->storelocaleMapping ?? []), true);

        if (empty($node['id']) || empty($shopifyLocale)) {
            return;
        }

        $keyToCode = array_filter([
            'title' => $fieldMap['title'] ?? null,
            'body_html' => $fieldMap['descriptionHtml'] ?? null,
            'meta_title' => $fieldMap['seoTitle'] ?? null,
            'meta_description' => $fieldMap['seoDescription'] ?? null,
        ]);

        $defaultShopifyLocale = collect((array) ($this->credential?->storeLocales ?? []))
            ->firstWhere('defaultlocale', true)['locale'] ?? null;
        $isDefault = $shopifyLocale === $defaultShopifyLocale;

        try {
            $response = $this->requestGraphQlApiAction('getCollectionTranslations', $this->credentialArray, [
                'resourceId' => $node['id'],
                'locale' => $shopifyLocale,
            ]);

            $translations = collect($response['body']['data']['translatableResource']['translations'] ?? []);

            foreach ($keyToCode as $key => $code) {
                $translated = $translations->firstWhere('key', $key);

                if ($translated !== null) {
                    $values[$code] = (string) ($translated['value'] ?? '');
                } elseif (! $isDefault) {
                    $values[$code] = '';
                }
            }
        } catch (\Throwable $e) {
            // Keep default-locale values on translation fetch failure.
        }
    }

    /**
     * Merge Attribute values for each section with previous section
     */
    protected function mergeCategoryFieldValues(array $newValues, array $oldValues): array
    {
        if (! empty($oldValues[CategoryRepository::COMMON_VALUES_KEY])) {
            $newValues[CategoryRepository::COMMON_VALUES_KEY] = array_filter(
                array_merge($newValues[CategoryRepository::COMMON_VALUES_KEY] ?? [], $oldValues[CategoryRepository::COMMON_VALUES_KEY])
            );
        }

        foreach ($this->locales as $localeCode) {
            $newValues[CategoryRepository::LOCALE_VALUES_KEY][$localeCode] = array_filter(
                array_merge($newValues[CategoryRepository::LOCALE_VALUES_KEY][$localeCode] ?? [], $oldValues[CategoryRepository::LOCALE_VALUES_KEY][$localeCode] ?? [])
            );

            if (empty($newValues[CategoryRepository::LOCALE_VALUES_KEY][$localeCode])) {
                unset($newValues[CategoryRepository::LOCALE_VALUES_KEY][$localeCode]);
            }
        }

        return array_filter($newValues);
    }

    public function getCategoryFields()
    {
        if (! isset($this->categoryFields)) {
            $this->cachedCategoryFields = $this->categoryFieldRepository->where('status', 1)->get();

            $this->categoryFields = $this->cachedCategoryFields->pluck('code')->toArray();
        }

        return $this->categoryFields;
    }

    /**
     * Map Shopify collection image to category additional data.
     */
    protected function mapCollectionImage(array $collection, array &$data): void
    {
        $imageUrl = $collection['node']['image']['url'] ?? null;

        $targetFields = $this->resolveCategoryMediaFields();

        if (empty($targetFields)) {
            return;
        }

        if (empty($imageUrl)) {
            $this->clearMappedCollectionImage($data, $targetFields);

            return;
        }

        foreach ($targetFields as $fieldCode) {
            $field = $this->getCategoryFieldByCode($fieldCode);

            if (! $field || ! in_array($field->type, ['image', 'file'], true)) {
                continue;
            }

            $imagePath = 'category'.DIRECTORY_SEPARATOR.($collection['node']['handle'] ?? 'shopify').DIRECTORY_SEPARATOR.$fieldCode.DIRECTORY_SEPARATOR;
            $storedPath = $this->handleUrlField($imageUrl, $imagePath);

            if (! $storedPath) {
                continue;
            }

            if ($field->value_per_locale) {
                $data['additional_data'][CategoryRepository::LOCALE_VALUES_KEY][$this->locale][$fieldCode] = $storedPath;
            } else {
                $data['additional_data'][CategoryRepository::COMMON_VALUES_KEY][$fieldCode] = $storedPath;
            }

            break;
        }
    }

    /**
     * Clear mapped category image fields when Shopify collection has no image.
     */
    protected function clearMappedCollectionImage(array &$data, array $targetFields): void
    {
        foreach ($targetFields as $fieldCode) {
            $field = $this->getCategoryFieldByCode($fieldCode);

            if (! $field || ! in_array($field->type, ['image', 'file'], true)) {
                continue;
            }

            if ($field->value_per_locale) {
                unset($data['additional_data'][CategoryRepository::LOCALE_VALUES_KEY][$this->locale][$fieldCode]);

                if (empty($data['additional_data'][CategoryRepository::LOCALE_VALUES_KEY][$this->locale] ?? [])) {
                    unset($data['additional_data'][CategoryRepository::LOCALE_VALUES_KEY][$this->locale]);
                }

                if (empty($data['additional_data'][CategoryRepository::LOCALE_VALUES_KEY] ?? [])) {
                    unset($data['additional_data'][CategoryRepository::LOCALE_VALUES_KEY]);
                }
            } else {
                unset($data['additional_data'][CategoryRepository::COMMON_VALUES_KEY][$fieldCode]);

                if (empty($data['additional_data'][CategoryRepository::COMMON_VALUES_KEY] ?? [])) {
                    unset($data['additional_data'][CategoryRepository::COMMON_VALUES_KEY]);
                }
            }
        }
    }

    /**
     * Resolve target category media fields from import mapping.
     */
    protected function resolveCategoryMediaFields(): array
    {
        $mediaAttributes = [];

        $collectionMediaMapping = $this->collectionMapping?->mapping['mediaMapping']['mediaAttributes'] ?? [];
        if (! is_array($collectionMediaMapping)) {
            $collectionMediaMapping = explode(',', (string) $collectionMediaMapping);
        }
        $mediaAttributes = array_merge($mediaAttributes, $collectionMediaMapping);

        $newMapping = $this->importMapping?->mapping['mediaMapping']['mediaAttributes'] ?? [];
        if (! is_array($newMapping)) {
            $newMapping = explode(',', (string) $newMapping);
        }
        $mediaAttributes = array_merge($mediaAttributes, $newMapping);

        $legacyMapping = $this->importMapping?->mapping['shopify_connector_settings']['images'] ?? [];
        if (! is_array($legacyMapping)) {
            $legacyMapping = explode(',', (string) $legacyMapping);
        }
        $mediaAttributes = array_merge($mediaAttributes, $legacyMapping);

        $mediaAttributes = array_values(array_filter(array_map('trim', $mediaAttributes)));

        return array_values(array_unique($mediaAttributes));
    }

    /**
     * Get active category field by code.
     */
    protected function getCategoryFieldByCode(string $code): mixed
    {
        $this->getCategoryFields();

        return $this->cachedCategoryFields->firstWhere('code', $code);
    }

    /**
     * Check if category code exists
     */
    public function isCategoryExist(string $code): bool
    {
        return $this->categoryStorage->has($code);
    }

    /**
     * Categories Getting by cursor
     */
    public function getCategoriesByCursor(): array
    {
        $cursor = null;
        $allCollections = [];

        do {
            $variables = [
                'first' => 5,
            ];
            $collectionGettingType = 'manualCollectionGetting';
            if ($cursor) {
                $variables['afterCursor'] = $cursor;
                $collectionGettingType = 'GetCollectionsByCursor';
            }
            $graphResponse = $this->requestGraphQlApiAction($collectionGettingType, $this->credentialArray, $variables);

            $graphqlCollection = ! empty($graphResponse['body']['data']['collections']['edges'])
                ? $graphResponse['body']['data']['collections']['edges']
                : [];
            $allCollections = array_merge($allCollections, $graphqlCollection);

            $lastCursor = ! empty($graphqlCollection) ? end($graphqlCollection)['cursor'] : null;

            if ($cursor === $lastCursor || empty($lastCursor)) {
                break;
            }
            $cursor = $lastCursor;

        } while (! empty($graphqlCollection));

        return $allCollections;
    }

    /**
     * Validates row
     */
    public function validateRow(array $rowData, int $rowNumber): bool
    {
        return true;
    }
}
