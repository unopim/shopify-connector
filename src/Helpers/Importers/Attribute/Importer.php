<?php

namespace Webkul\Shopify\Helpers\Importers\Attribute;

use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Helpers\Source;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Helpers\Iterator\AttributeIterator;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\ValidatedBatched;

class Importer extends AbstractImporter
{
    use ShopifyGraphqlRequest;
    use ValidatedBatched;

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
     * @return Source
     */
    public function getSource()
    {
        $this->initFilters();

        if (! $this->credential?->active) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credential'));
        }
        $this->credentialArray = [
            'credentialId' => $this->credential?->id,
            'shopUrl' => $this->credential?->shopUrl,
            'accessToken' => $this->credential?->accessToken,
            'apiVersion' => $this->credential?->apiVersion,
            'clientId' => $this->credential?->clientId,
            'clientSecret' => $this->credential?->clientSecret,
            'accessTokenExpiresAt' => optional($this->credential?->accessTokenExpiresAt)?->toDateTimeString(),
        ];

        $shopifyLocaleForCurrent = array_search($this->locale, (array) ($this->credential?->storelocaleMapping ?? []), true);

        return new AttributeIterator($this->credentialArray, $shopifyLocaleForCurrent ?: null);
    }

    /**
     * Validate data for saving attribute
     */
    public function validateData(): void
    {
        $this->saveValidatedBatches();
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

        foreach ($batch->data as $rowData) {
            $attributeModel = $this->attributeRepository->findOneByField('code', strtolower($rowData['name']));

            if ($attributeModel?->value_per_locale || $attributeModel?->value_per_channel) {
                continue;
            }

            if ($attributeModel) {
                $existingOptions = $attributeModel->options()->get(['id', 'code', 'sort_order']);
                $existingOptionsByCode = [];
                $optionArray = [];

                foreach ($existingOptions as $existingOption) {
                    $existingOptionsByCode[strtolower($existingOption->code)] = $existingOption;
                }

                $initialOrder = ($existingOptions->max('sort_order') ?? 0) + 1;
                $newOptionIndex = 0;

                foreach ($rowData['code'] as $optValue) {
                    $existingOption = $existingOptionsByCode[strtolower($optValue)] ?? null;
                    $optionLabel = $rowData['labels'][$optValue] ?? $optValue;

                    if ($existingOption) {
                        $optionArray[$existingOption->id] = [
                            'isNew' => 'false',
                            'isDelete' => 'false',
                            'code' => $existingOption->code,
                            'sort_order' => $existingOption->sort_order,
                            $this->locale => [
                                'label' => $optionLabel,
                            ],
                        ];

                        continue;
                    }

                    $optionArray['option_'.$newOptionIndex] = [
                        'isNew' => 'true',
                        'isDelete' => '',
                        'code' => $optValue,
                        'sort_order' => $initialOrder,
                        $this->locale => [
                            'label' => $optionLabel,
                        ],
                    ];

                    $initialOrder++;
                    $newOptionIndex++;
                }

                $this->attributeRepository->update([
                    $this->locale => [
                        'name' => $rowData['label'] ?? $rowData['name'],
                    ],
                    'options' => $optionArray,
                ], $attributeModel->id);

                $this->updatedItemsCount++;
            } else {
                $newOptionArray = [];
                foreach ($rowData['code'] as $newkey => $optValue) {
                    $newOptionKey = 'option_'.($newkey + 1);
                    $optionLabel = $rowData['labels'][$optValue] ?? $optValue;
                    $newOptionArray[$newOptionKey] = [
                        'position' => $newkey,
                        'code' => $optValue,
                        $this->locale => [
                            'label' => $optionLabel,
                        ],
                    ];
                }
                $newAttrCreate = [
                    'code' => strtolower($rowData['name']),
                    'type' => 'select',
                    $this->locale => [
                        'name' => $rowData['label'] ?? $rowData['name'],
                    ],
                    'options' => $newOptionArray,
                ];

                $this->attributeRepository->create($newAttrCreate);
                $this->createdItemsCount++;
            }
        }

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
     * Validates row
     */
    public function validateRow(array $rowData, int $rowNumber): bool
    {
        return true;
    }
}
