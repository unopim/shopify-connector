<?php

namespace Webkul\Shopify\Helpers\Exporters\Category;

use Illuminate\Support\Arr;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryFieldRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Core\Repositories\ChannelRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Helpers\Importers\Category\Storage;
use Webkul\DataTransfer\Helpers\Importers\FieldProcessor;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\DataTransfer\Validators\Import\CategoryRulesExtractor;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class Importer extends AbstractImporter
{
    use ShopifyGraphqlRequest;

    /**
     *  Error code for duplicated code
     */
    public const ERROR_DUPLICATE_CODE = 'duplicate_code';

    /**
     * Error code for non existing code
     */
    public const ERROR_CODE_NOT_FOUND_FOR_DELETE = 'slug_not_found_to_delete';

    /**
     * invalid display mode
     */
    public const INVALID_DISPLAY_MODE = 'invalid_display_mode';

    /**
     * cursor position
     */
    public $cursor = null;

    /**
     * Enabled Value per locale
     */
    public const VALUE_PER_LOCALE = 1;

    /**
     * Error code for non existing code
     */
    public const ERROR_NOT_FOUND_LOCALE = 'slug_not_found_to_delete';

    const ERROR_NOT_UNIQUE_VALUE = 'not_unique_value';

    const ERROR_RELATED_TO_CHANNEL = 'channel_related_category_root';

    /**
     * Permanent entity columns
     */
    protected array $validColumnNames = [
        'code',
        'parent',
        'locale',
    ];

    protected array $categoryFields;

    /**
     * locales storage
     */
    protected array $locales = [];

    /**
     * Permanent entity columns
     */
    protected array $permanentAttributes = ['code', 'parent', 'locale'];

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

    protected array $cachedUniqueValues = [];

    protected array $localeCachedValues = [];

    protected array $categoryFieldValidations = [];

    protected $cachedCategoryFields = [];

    protected ?array $nonDeletableCategories = null;

    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected CategoryRepository $categoryRepository,
        protected CategoryFieldRepository $categoryFieldRepository,
        protected Storage $categoryStorage,
        protected AttributeRepository $attributeRepository,
        protected LocaleRepository $localeRepository,
        protected ChannelRepository $channelRepository,
        protected CategoryRulesExtractor $categoryRulesExtractor,
        protected FieldProcessor $fieldProcessor,
        protected ShopifyCredentialRepository $shopifyRepository,
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
     * Import instance.
     *
     * @return \Webkul\DataTransfer\Helpers\Source
     */
    public function getSource()
    {
        $this->categoryStorage->init();
        $this->credential = $this->shopifyRepository->find($this->import->jobInstance->allowed_errors);

        $this->credentialArray = [
            'shopUrl'     => $this->credential->shopUrl,
            'accessToken' => $this->credential->accessToken,
            'apiVersion'  => $this->credential->apiVersion,
        ];

        $collections = new \ArrayIterator($this->getCategoriesByCursor());

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
                $this->updateParentCategoryId($category);
                $this->categoryRepository->update($category, $this->categoryStorage->get($code), withoutFormattingValues: true);
            }
        }

        if (! empty($categories['insert'])) {
            $this->createdItemsCount += count($categories['insert']);

            foreach ($categories['insert'] as $code => $category) {
                $this->updateParentCategoryId($category);
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
     * Save validated batches
     */
    protected function saveValidatedBatches(): self
    {
        $source = $this->getSource();

        $batchRows = [];

        $source->rewind();
        /**
         * Clean previous saved batches
         */
        $this->importBatchRepository->deleteWhere([
            'job_track_id' => $this->import->id,
        ]);

        while (
            $source->valid()
            || count($batchRows)
        ) {
            if (
                count($batchRows) == self::BATCH_SIZE
                || ! $source->valid()
            ) {
                $this->importBatchRepository->create([
                    'job_track_id' => $this->import->id,
                    'data'         => $batchRows,
                ]);

                $batchRows = [];
            }

            if ($source->valid()) {
                $rowData = $source->current();

                if ($this->validateRow($rowData, 1)) {
                    $batchRows[] = $this->prepareRowForDb($rowData);
                }

                $this->processedRowsCount++;

                $source->next();
            }
        }

        return $this;
    }

    /**
     * Start the import process for categories Import
     */
    public function importBatch(JobTrackBatchContract $batch): bool
    {
        $this->saveCategoryData($batch);

        return true;
    }

    public function saveCategoryData(JobTrackBatchContract $batch): bool
    {
        $collectionData = array_column($batch->data, 'node');
        $this->categoryStorage->load(Arr::pluck($collectionData, 'handle'));
        $categories = [];
        foreach ($batch->data as $rowData) {
            /**
             * Prepare categories for import
             */
            $this->prepareCategories($rowData, $categories);
        }

        $this->saveCategories($categories);

        /**
         * Update import batch summary
         */
        $batch = $this->importBatchRepository->update([
            'state'   => Import::STATE_PROCESSED,
            'summary' => [
                'created' => $this->getCreatedItemsCount(),
                'updated' => $this->getUpdatedItemsCount(),
                'deleted' => $this->getDeletedItemsCount(),
            ],
        ], $batch->id);

        return true;
    }

    public function updateParentCategoryId(&$category)
    {
        if (! empty($category['parent'])) {
            $category['parent_id'] = $this->getCategoryId($category['parent']);
        }

        unset($category['parent']);
    }

    /**
     * Get category Id by code
     */
    public function getCategoryId(?string $code)
    {
        if (! $code) {
            throw new \Exception('category code not found');
        }

        return $this->categoryRepository
            ->where('code', $code)
            ->first()?->id;
    }

    public function prepareCategories(array $collection, &$category)
    {
        $categ = $this->categoryRepository->where('code', $collection['node']['handle'])->first();

        $data = [
            'code'            => $collection['node']['handle'],
            'parent'          => null,
            'additional_data' => $categ ? $categ->toArray()['additional_data'] : [],
        ];

        if ($categ) {
            $data['additional_data'] = $this->mergeCategoryFieldValues($data['additional_data'], $category['update'][$collection['node']['handle']]['additional_data'] ?? []);

            $category['update'][$collection['node']['handle']] = array_merge($category['update'][$collection['node']['handle']] ?? [], $data);
        } else {
            $data['additional_data']['locale_specific']['en_US']['name'] = $collection['node']['title'];
            $data['additional_data'] = $this->mergeCategoryFieldValues($data['additional_data'], $category['insert'][$collection['node']['handle']]['additional_data'] ?? []);

            $category['insert'][$collection['node']['handle']] = array_merge($category['insert'][$collection['node']['handle']] ?? [], $data);
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

    /**
     * Get Categories linked to channel which should not be deleted
     */
    public function getNonDeletableCategories(): void
    {
        if (! $this->nonDeletableCategories) {
            $this->nonDeletableCategories = $this->channelRepository->pluck('root_category_id')->toArray();
        }
    }
}
