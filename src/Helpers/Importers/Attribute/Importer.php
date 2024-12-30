<?php

namespace Webkul\Shopify\Helpers\Importers\Attribute;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Helpers\Importers\Category\Storage;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class Importer extends AbstractImporter
{
    use ShopifyGraphqlRequest;

    public const BATCH_SIZE = 10;

    /**
     * cursor position
     */
    public $cursor = null;

    /**
     * locales storage
     */
    protected array $locales = [];

    /**
     * Shopify job Locale.
     *
     * @var mixed
     */
    protected $locale;

    protected array $attrStrore = [];

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

    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected AttributeRepository $attributeRepository,
        protected LocaleRepository $localeRepository,
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
     * Initialize Filters
     */
    protected function initFilters(): void
    {
        $filters = $this->import->jobInstance->filters;

        $this->credential = $this->shopifyRepository->find($filters['credentials'] ?? null);

        $this->locale = $filters['locale'] ?? null;
    }

    /**
     * Import instance.
     *
     * @return \Webkul\DataTransfer\Helpers\Source
     */
    public function getSource()
    {
        $this->initFilters();
        if (! $this->credential?->active) {
            throw new \InvalidArgumentException('Invalid Credential: The credential is either disabled, incorrect, or does not exist');
        }
        $this->credentialArray = [
            'shopUrl'     => $this->credential?->shopUrl,
            'accessToken' => $this->credential?->accessToken,
            'apiVersion'  => $this->credential?->apiVersion,
        ];

        $attributeAndOption = new \ArrayIterator($this->productOptionByCursor());

        return $attributeAndOption;
    }

    /**
     * Attribute Getting by cursor
     */
    public function productOptionByCursor(): array
    {
        $cursor = null;
        $allAttribute = [];
        $formattedOption = [];
        do {
            $variables = [];
            $mutationType = 'productGettingOptions';
            if ($cursor) {
                $variables = [
                    'first'       => 50,
                    'afterCursor' => $cursor,
                ];
                $mutationType = 'productOptionByCursor';
            }
            $graphResponse = $this->requestGraphQlApiAction($mutationType, $this->credentialArray, $variables);

            $graphqlOption = ! empty($graphResponse['body']['data']['products']['edges'])
                ? $graphResponse['body']['data']['products']['edges']
                : [];

            $formattedOption = $this->formatedAttributeAndOption($graphqlOption);

            $allAttribute = array_merge($allAttribute, $formattedOption);
            $lastCursor = ! empty($graphqlOption) ? end($graphqlOption)['cursor'] : null;

            if ($cursor === $lastCursor || empty($lastCursor)) {
                break;
            }
            $cursor = $lastCursor;

        } while (! empty($graphqlOption));

        $mergedOptions = [];

        foreach ($allAttribute as $option) {
            $name = $option['name'];
            if (isset($mergedOptions[$name])) {
                $mergedOptions[$name]['code'] = array_unique(
                    array_merge($mergedOptions[$name]['code'], $option['code'])
                );
            } else {
                $mergedOptions[$name] = $option;
            }
        }

        $mergedOptions = array_values($mergedOptions);

        return $mergedOptions;
    }

    /**
     * Formating Attribute and attriute Option
     */
    public function formatedAttributeAndOption(array $options): array
    {
        $optionsArray = [];
        foreach ($options as $option) {
            $productOptions = $option['node']['options'];
            foreach ($productOptions as $productOption) {
                if ($productOption['name'] == 'Title' && in_array('Default Title', $productOption['values'])) {
                    continue;
                }

                $modified_array = array_map(function ($string) {
                    return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $string), '-');
                }, $productOption['values'] ?? []);

                $optionsArray[] = [
                    'name' => trim(preg_replace('/[^A-Za-z0-9]+/', '_', $productOption['name'])),
                    'type' => 'select',
                    'code' => $modified_array,
                ];
            }
        }

        return $optionsArray;
    }

    /**
     * Validate data for saving attribute
     */
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
     * Start the import process for Attribute Import
     */
    public function importBatch(JobTrackBatchContract $batch): bool
    {
        $this->saveAttributeData($batch);

        return true;
    }

    /**
     * Create or update attribute and attribute Options
     */
    public function saveAttributeData(JobTrackBatchContract $batch): bool
    {
        $this->initFilters();
        $attributes = [];
        foreach ($batch->data as $rowData) {
            $attributeModel = $this->attributeRepository->findOneByField('code', strtolower($rowData['name']));

            if ($attributeModel?->value_per_locale || $attributeModel?->value_per_channel) {
                continue;
            }

            if ($attributeModel) {
                $initialOrder = $attributeModel->options()->orderBy('sort_order', 'desc')->first()->sort_order ?? 0;
                $option = $attributeModel->options()->whereIn('code', $rowData['code'])->orderBy('sort_order')->get();

                $optionArray = [];
                $optionExistInAttr = array_column($option->toArray(), 'code');
                $newOptions = array_udiff($rowData['code'], $optionExistInAttr, function ($a, $b) {
                    return strcasecmp($a, $b); // Case-insensitive comparison
                });
                $initialOrder += 1;
                foreach ($newOptions as $key => $newOption) {
                    $optionKey = 'option_'.$key;
                    $optionArray[$optionKey] = [
                        'isNew'          => 'true',
                        'isDelete'       => '',
                        'code'           => $newOption,
                        'sort_order'     => $initialOrder,
                        $this->locale    => [
                            'label' => $newOption,
                        ],
                    ];
                    $initialOrder++;
                }

                $attribute = $this->attributeRepository->update(['options' => $optionArray], $attributeModel->id);
                $this->updatedItemsCount++;
            } else {
                $newOptionArray = [];
                foreach ($rowData['code'] as $newkey => $optValue) {
                    $newOptionKey = 'option_'.$newkey + 1;
                    $newOptionArray[$newOptionKey] = [
                        'position'       => $newkey,
                        'code'           => $optValue,
                        $this->locale    => [
                            'label' => $optValue,
                        ],
                    ];
                }
                $newAttrCreate = [
                    'code'        => strtolower($rowData['name']),
                    'type'        => 'select',
                    $this->locale => [
                        'name' => $rowData['name'],
                    ],
                    'options' => $newOptionArray,
                ];

                $newlyAttrCreated = $this->attributeRepository->create($newAttrCreate);
                $this->createdItemsCount++;
            }
        }

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

    /**
     * Validates row
     */
    public function validateRow(array $rowData, int $rowNumber): bool
    {
        return true;
    }
}
