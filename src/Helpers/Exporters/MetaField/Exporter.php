<?php

namespace Webkul\Shopify\Helpers\Exporters\MetaField;

use Illuminate\Support\Facades\Event;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Exceptions\InvalidLocale;
use Webkul\Shopify\Helpers\MetaobjectFieldType;
use Webkul\Shopify\Helpers\ShoifyMetaFieldType;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectMappingRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\TranslationTrait;

class Exporter extends AbstractExporter
{
    use ShopifyGraphqlRequest;
    use TranslationTrait;

    public const BATCH_SIZE = 10;

    public const NOT_FOUND_DEFINITION = 'Definition not found.';

    protected $credential;

    protected $credentialArray;

    protected $shopifyDefaultLocale;

    protected $shoifyMetaFieldTypeData;

    protected bool $exportsFile = false;

    protected ?array $currentConstraintsMap = null;

    protected array $batchAttributes = [];

    /**
     * Create a new instance of the exporter.
     */
    public function __construct(
        protected JobTrackBatchRepository $exportBatchRepository,
        protected FileExportFileBuffer $exportFileBuffer,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShoifyMetaFieldType $shoifyMetaFieldType,
        protected ShopifyMetaFieldRepository $shopifyMetaFieldRepository,
        protected ShopifyMetaobjectMappingRepository $shopifyMetaobjectMappingRepository
    ) {
        parent::__construct($exportBatchRepository, $exportFileBuffer);
    }

    /**
     * Initializes the channels and locales for the export process.
     */
    public function initialize(): void
    {
        $this->initCredential();

        $this->initDefaultLocale();
    }

    /**
     * Initialize credentials data from filters.
     */
    protected function initCredential(): void
    {
        $filters = $this->getFilters();
        $this->shoifyMetaFieldTypeData = $this->shoifyMetaFieldType->getMetaFieldTypeInShopify();
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
     * Initialize default locale from credentials data.
     */
    protected function initDefaultLocale(): void
    {
        if ($this->credential->storeLocales) {
            $defaultLanguage = array_values(array_filter($this->credential->storeLocales, fn (array $language): bool => isset($language['defaultlocale']) && $language['defaultlocale'] === true))[0] ?? null;

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
     * Start the export process.
     */
    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        Event::dispatch('shopify.metafield.export.before', $batch);

        $this->initialize();

        $this->prepareMetafieldShopify($batch, $filePath);

        $this->syncMetafieldExtras($batch->data);

        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        Event::dispatch('shopify.metafield.export.after', $batch);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    protected function getResults()
    {
        return $this->source->orderBy('id', 'desc')->all()?->getIterator();
    }

    /**
     * Prepare and export metafield definitions to Shopify concurrently.
     */
    public function prepareMetafieldShopify(JobTrackBatchContract $batch, mixed $filePath): void
    {
        $this->loadBatchAttributes($batch->data ?? []);

        $shopUrl = $this->credentialArray['shopUrl'];
        $prepared = [];
        $operations = [];
        $followUpPinOperations = [];
        $followUpPinData = [];

        foreach ($batch->data as $rawData) {
            $key = (string) $rawData['id'];
            $id = $this->extractId($rawData['apiUrl'] ?? null, $shopUrl);
            $formattedData = $this->prepareMetaFieldDefinition($rawData, $id);

            $needsFollowUpPin = ! empty($formattedData['_needs_follow_up_pin']);
            unset($formattedData['_needs_follow_up_pin']);

            $prepared[$key] = [
                'rawData'             => $rawData,
                'id'                  => $id,
                'formattedData'       => $formattedData,
                'needs_follow_up_pin' => $needsFollowUpPin,
            ];

            $operations[$key] = [
                'endpoint'   => $id ? 'metafieldDefinitionUpdate' : 'metafieldDefinitionCreate',
                'parameters' => ['input' => $formattedData],
            ];
        }

        if (empty($operations)) {
            return;
        }

        $responses = $this->requestGraphQlApiActionPool($operations, $this->credentialArray);
        $adoptOperations = [];
        $adoptData = [];

        foreach ($prepared as $key => $item) {
            $rawData = $item['rawData'];
            $id = $item['id'];
            $formattedData = $item['formattedData'];
            $responseData = $responses[$key] ?? [];

            if (! empty($responseData['body']['errors'])) {
                $this->handleErrors($responseData['body']['errors'], $rawData['code']);

                continue;
            }

            if ($id) {
                $result = $responseData['body']['data']['metafieldDefinitionUpdate'] ?? [];
                $userErrors = $result['userErrors'] ?? [];

                if ($this->hasPinnedLimitError($userErrors)) {
                    $responseData = $this->retryUnpinned('metafieldDefinitionUpdate', $formattedData, $rawData['code']);
                    $result = $responseData['body']['data']['metafieldDefinitionUpdate'] ?? [];
                    $userErrors = $result['userErrors'] ?? [];
                    $item['needs_follow_up_pin'] = false;
                }

                if (! empty($userErrors)) {
                    $errorsMessage = array_column($userErrors, 'message');

                    if (in_array(self::NOT_FOUND_DEFINITION, $errorsMessage, true)) {
                        $this->processCreateFlow($rawData, $shopUrl);

                        continue;
                    }

                    $this->handleErrors($userErrors, $rawData['code']);

                    continue;
                }

                if (! empty($item['needs_follow_up_pin'])) {
                    $followUpPinOperations[$key] = [
                        'endpoint'   => 'metafieldDefinitionUpdate',
                        'parameters' => [
                            'input' => [
                                'key'       => $formattedData['key'],
                                'namespace' => $formattedData['namespace'] ?? 'custom',
                                'ownerType' => $formattedData['ownerType'] ?? 'PRODUCT',
                                'pin'       => true,
                            ],
                        ],
                    ];
                    $followUpPinData[$key] = $rawData;
                }

                $this->createdItemsCount++;
            } else {
                $result = $responseData['body']['data']['metafieldDefinitionCreate'] ?? [];
                $userErrors = $result['userErrors'] ?? [];

                if ($this->hasPinnedLimitError($userErrors)) {
                    $responseData = $this->retryUnpinned('metafieldDefinitionCreate', $formattedData, $rawData['code']);
                    $result = $responseData['body']['data']['metafieldDefinitionCreate'] ?? [];
                    $userErrors = $result['userErrors'] ?? [];
                }

                if (($userErrors[0]['code'] ?? null) === 'TAKEN') {
                    $updateData = $this->prepareMetaFieldDefinition($rawData, true);
                    $needsPin = ! empty($updateData['_needs_follow_up_pin']);
                    unset($updateData['_needs_follow_up_pin']);

                    if (! $needsPin) {
                        $updateData['pin'] = $formattedData['pin'];
                    }

                    $adoptOperations[$key] = [
                        'endpoint'   => 'metafieldDefinitionUpdate',
                        'parameters' => ['input' => $updateData],
                    ];

                    $adoptData[$key] = [
                        'rawData'             => $rawData,
                        'updateData'          => $updateData,
                        'needs_follow_up_pin' => $needsPin,
                    ];
                } elseif (! empty($userErrors)) {
                    $this->handleErrors($userErrors, $rawData['code']);
                } else {
                    $metaId = $result['createdDefinition']['id'] ?? null;

                    if ($metaId) {
                        $this->shopifyMetaFieldRepository->update(
                            ['apiUrl' => json_encode([$shopUrl => $metaId], JSON_UNESCAPED_SLASHES)],
                            $rawData['id']
                        );

                        $this->createdItemsCount++;
                    }
                }
            }
        }

        if (! empty($adoptOperations)) {
            $adoptResponses = $this->requestGraphQlApiActionPool($adoptOperations, $this->credentialArray);

            foreach ($adoptData as $key => $adopt) {
                $rawData = $adopt['rawData'];
                $updateData = $adopt['updateData'];
                $response = $adoptResponses[$key] ?? [];
                $updateErrors = $response['body']['data']['metafieldDefinitionUpdate']['userErrors'] ?? [];

                if ($this->hasPinnedLimitError($updateErrors)) {
                    $response = $this->retryUnpinned('metafieldDefinitionUpdate', $updateData, $rawData['code']);
                    $updateErrors = $response['body']['data']['metafieldDefinitionUpdate']['userErrors'] ?? [];
                    $adopt['needs_follow_up_pin'] = false;
                }

                if (! empty($updateErrors)) {
                    $this->handleErrors($updateErrors, $rawData['code']);

                    continue;
                }

                if (! empty($adopt['needs_follow_up_pin'])) {
                    $followUpPinOperations[$key] = [
                        'endpoint'   => 'metafieldDefinitionUpdate',
                        'parameters' => [
                            'input' => [
                                'key'       => $updateData['key'],
                                'namespace' => $updateData['namespace'] ?? 'custom',
                                'ownerType' => $updateData['ownerType'] ?? 'PRODUCT',
                                'pin'       => true,
                            ],
                        ],
                    ];
                    $followUpPinData[$key] = $rawData;
                }

                $metaId = $response['body']['data']['metafieldDefinitionUpdate']['updatedDefinition']['id'] ?? null;

                if ($metaId) {
                    $this->shopifyMetaFieldRepository->update(
                        ['apiUrl' => json_encode([$shopUrl => $metaId], JSON_UNESCAPED_SLASHES)],
                        $rawData['id']
                    );

                    $this->createdItemsCount++;
                }
            }
        }

        if (! empty($followUpPinOperations)) {
            $pinResponses = $this->requestGraphQlApiActionPool($followUpPinOperations, $this->credentialArray);

            foreach ($pinResponses as $key => $pinResponse) {
                $rawData = $followUpPinData[$key] ?? null;
                $userErrors = $pinResponse['body']['data']['metafieldDefinitionUpdate']['userErrors'] ?? [];

                if (! empty($userErrors) && ! $this->hasPinnedLimitError($userErrors)) {
                    $this->logWarning($userErrors, $rawData['code'] ?? '');
                }
            }
        }
    }

    /**
     * Preload attributes for all rows in the current batch.
     */
    protected function loadBatchAttributes(array $rows): void
    {
        $codes = array_values(array_filter(array_column($rows, 'code')));

        if (empty($codes)) {
            $this->batchAttributes = [];

            return;
        }

        $this->batchAttributes = resolve(AttributeRepository::class)
            ->with(['options.translations'])
            ->findWhereIn('code', $codes)
            ->keyBy('code')
            ->all();
    }

    /**
     * Resolve an attribute from preloaded batch attributes or repository.
     */
    protected function findAttribute(string $code): mixed
    {
        return $this->batchAttributes[$code]
            ?? resolve(AttributeRepository::class)->findOneByField('code', $code);
    }

    /**
     * Register exported definition codes in the credential's metafield extras.
     */
    private function syncMetafieldExtras(array $rows): void
    {
        $product = [];
        $variant = [];

        foreach ($rows as $row) {
            $code = $row['code'] ?? null;

            if (empty($code)) {
                continue;
            }

            $namespace = explode('.', (string) ($row['name_space_key'] ?? ''))[0] ?: 'custom';

            if (($row['ownerType'] ?? '') === 'PRODUCTVARIANT') {
                $variant[$code] = $namespace;
            } else {
                $product[$code] = $namespace;
            }
        }

        if ($product === [] && $variant === []) {
            return;
        }

        $extras = $this->credential->extras ?? [];
        $extras['productMetafield'] = array_merge($extras['productMetafield'] ?? [], $product);
        $extras['productVariantMetafield'] = array_merge($extras['productVariantMetafield'] ?? [], $variant);

        $this->shopifyRepository->update(['extras' => $extras], $this->credential->id);
    }

    /**
     * Extract the Shopify definition GID for the given shop URL.
     */
    private function extractId(?string $apiUrl, string $shopUrl): ?string
    {
        if (! $apiUrl) {
            return null;
        }

        $metaFieldInApi = json_decode($apiUrl, true);

        return $metaFieldInApi[$shopUrl] ?? null;
    }

    /**
     * Process create flow for a metafield definition.
     */
    private function processCreateFlow(array $rawData, string $shopUrl, $id = null): void
    {
        $formattedData = $this->prepareMetaFieldDefinition($rawData, $id);
        unset($formattedData['_needs_follow_up_pin']);
        $responseData = $this->apiRequestShopify($formattedData, $id);

        $this->createMetafieldDefinitionMapping($responseData, $rawData, $shopUrl, $formattedData);
    }

    /**
     * Create mapping for a metafield definition response with fallback on pinned limit.
     */
    public function createMetafieldDefinitionMapping(array $responseData, array $rawData, string $shopUrl, ?array $formattedData = null): void
    {
        if (! empty($responseData['body']['errors'])) {
            $this->handleErrors($responseData['body']['errors'], $rawData['code']);

            return;
        }

        $formattedData ??= $this->prepareMetaFieldDefinition($rawData);
        $result = $responseData['body']['data']['metafieldDefinitionCreate'] ?? [];
        $userErrors = $result['userErrors'] ?? [];

        if ($this->hasPinnedLimitError($userErrors)) {
            $responseData = $this->retryUnpinned('metafieldDefinitionCreate', $formattedData, $rawData['code']);
            $result = $responseData['body']['data']['metafieldDefinitionCreate'] ?? [];
            $userErrors = $result['userErrors'] ?? [];
        }

        if (($userErrors[0]['code'] ?? null) === 'TAKEN') {
            $updateData = $this->prepareMetaFieldDefinition($rawData, true);
            $needsFollowUpPin = ! empty($updateData['_needs_follow_up_pin']);
            unset($updateData['_needs_follow_up_pin']);

            if (! $needsFollowUpPin) {
                $updateData['pin'] = $formattedData['pin'];
            }

            $response = $this->requestGraphQlApiAction('metafieldDefinitionUpdate', $this->credentialArray, ['input' => $updateData]);
            $updateErrors = $response['body']['data']['metafieldDefinitionUpdate']['userErrors'] ?? [];

            if ($this->hasPinnedLimitError($updateErrors)) {
                $response = $this->retryUnpinned('metafieldDefinitionUpdate', $updateData, $rawData['code']);
                $updateErrors = $response['body']['data']['metafieldDefinitionUpdate']['userErrors'] ?? [];
                $needsFollowUpPin = false;
            }

            if (! empty($updateErrors)) {
                $this->handleErrors($updateErrors, $rawData['code']);

                return;
            }

            if ($needsFollowUpPin) {
                $this->requestGraphQlApiAction('metafieldDefinitionUpdate', $this->credentialArray, [
                    'input' => [
                        'key'       => $updateData['key'],
                        'namespace' => $updateData['namespace'] ?? 'custom',
                        'ownerType' => $updateData['ownerType'] ?? 'PRODUCT',
                        'pin'       => true,
                    ],
                ]);
            }

            $metaId = $response['body']['data']['metafieldDefinitionUpdate']['updatedDefinition']['id'] ?? null;
        } elseif (! empty($userErrors)) {
            $this->handleErrors($userErrors, $rawData['code']);

            return;
        } else {
            $metaId = $result['createdDefinition']['id'] ?? null;
        }

        if ($metaId) {
            $apiUrlData = [$shopUrl => $metaId];

            $this->shopifyMetaFieldRepository->update(
                ['apiUrl' => json_encode($apiUrlData, JSON_UNESCAPED_SLASHES)],
                $rawData['id']
            );

            $this->createdItemsCount++;
        }
    }

    /**
     * Retry a metafield operation with pin set to false.
     */
    protected function retryUnpinned(string $mutation, array $formattedData, string $code): array
    {
        $formattedData['pin'] = false;
        unset($formattedData['_needs_follow_up_pin']);

        $response = $this->requestGraphQlApiAction($mutation, $this->credentialArray, ['input' => $formattedData]);
        $userErrors = $response['body']['data'][$mutation]['userErrors'] ?? [];

        if (empty($userErrors)) {
            $this->jobLogger->warning("Shopify pinned limit reached or unsupported pinning for attribute: {$code}. Exported as unpinned.");
        }

        return $response;
    }

    /**
     * Check if user errors contain PINNED_LIMIT_REACHED or UNSUPPORTED_PINNING.
     */
    protected function hasPinnedLimitError(array $userErrors): bool
    {
        $codes = array_column($userErrors, 'code');

        return in_array('PINNED_LIMIT_REACHED', $codes, true)
            || in_array('UNSUPPORTED_PINNING', $codes, true);
    }

    /**
     * Handle and record export errors for an attribute code.
     */
    private function handleErrors(array $errors, string $code): void
    {
        $this->logWarning($errors, $code);

        $this->skippedItemsCount++;
    }

    /**
     * Determine whether the metafield definition represents an email attribute.
     */
    protected function isEmailMetafield(array $rowData): bool
    {
        if (($rowData['type'] ?? '') !== 'single_line_text_field') {
            return false;
        }

        $validations = json_decode((string) ($rowData['validations'] ?? ''), true);

        if (is_array($validations) && ($validations['content_type'] ?? null) === 'email') {
            return true;
        }

        $attribute = $this->findAttribute($rowData['code'] ?? '');

        return $attribute?->type === 'text' && $attribute?->validation === 'email';
    }

    /**
     * Prepare the metafield definition payload for Shopify.
     */
    public function prepareMetaFieldDefinition($rowData, $id = null): array
    {
        $formattedData = [
            'access' => [
                'storefront' => $rowData['storefronts'] ? 'PUBLIC_READ' : 'NONE',
            ],
            'ownerType' => $rowData['ownerType'] == 'PRODUCT' ? 'PRODUCT' : 'PRODUCTVARIANT',
        ];

        if (! empty($rowData['name_space_key'])) {
            $nameSapceAndKey = explode('.', $rowData['name_space_key']);
            $formattedData['namespace'] = $nameSapceAndKey[0];
            $formattedData['key'] = $nameSapceAndKey[1];
        }

        $type = $rowData['type'] ?? null;

        if ($type && isset($rowData['listvalue'])) {
            $type = $rowData['listvalue'] ? 'list.'.$type : $type;
        }

        if (! $id) {
            $formattedData['type'] = $type;
        }

        if (! empty($rowData['validations'])) {
            $validations = [];
            $validationDatas = json_decode($rowData['validations'], true);
            $maxunit = $validationDatas['maxunit'] ?? null;
            $minunit = $validationDatas['minunit'] ?? null;
            unset($validationDatas['maxunit'], $validationDatas['minunit']);

            foreach ($validationDatas as $key => $validationData) {
                if (in_array($key, ['content_type', 'file_types', 'reference_source', 'association_type', 'reference_as', 'link_text_attribute', 'metaobject_type', 'metaobject_definition_id'], true)) {
                    continue;
                }

                if ($validationData == null) {
                    continue;
                }

                if ($maxunit && $key == 'max') {
                    $validationData = json_encode([
                        'value' => $validationData,
                        'unit'  => $maxunit,
                    ], true);
                } elseif ($minunit && $key == 'min') {
                    $validationData = json_encode([
                        'value' => $validationData,
                        'unit'  => $minunit,
                    ], true);
                }

                $key = in_array($type, ['list.rating', 'rating']) ? 'scale_'.$key : $key;

                $validations[] = [
                    'name'  => $key,
                    'value' => $validationData ?? 0,
                ];
            }

            if (($validationDatas['content_type'] ?? null) === 'choice_list') {
                $choices = $this->resolveChoiceListValues($rowData['code'] ?? '');

                if ($choices !== []) {
                    $validations[] = [
                        'name'  => 'choices',
                        'value' => json_encode($choices, JSON_UNESCAPED_SLASHES),
                    ];
                }
            }

            $fileTypeOptions = $this->resolveFileTypeOptions(
                $validationDatas['content_type'] ?? null,
                $validationDatas['file_types'] ?? []
            );

            if ($fileTypeOptions !== []) {
                $validations[] = [
                    'name'  => 'file_type_options',
                    'value' => json_encode($fileTypeOptions),
                ];
            }

            if (($rowData['type'] ?? null) === 'metaobject_reference') {
                $metaobjectType = $validationDatas['metaobject_type'] ?? null;

                $definitionGid = $metaobjectType
                    ? $this->shopifyMetaobjectMappingRepository->findOneWhere([
                        'api_url' => $this->credentialArray['shopUrl'],
                        'type'    => $metaobjectType,
                    ])?->gid
                    : null;

                if ($definitionGid) {
                    $validations[] = ['name' => 'metaobject_definition_id', 'value' => $definitionGid];
                } else {
                    $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.metaobject-definition-missing', [
                        'type'  => $metaobjectType ?? '',
                        'store' => $this->credentialArray['shopUrl'],
                    ]));
                }
            }

            $formattedData['validations'] = $validations;
        }

        if ($this->isEmailMetafield($rowData)) {
            $emailValidations = array_values(array_filter(
                $formattedData['validations'] ?? [],
                fn (array $validation): bool => ($validation['name'] ?? '') !== 'regex'
            ));

            $emailValidations[] = ['name' => 'regex', 'value' => MetaobjectFieldType::EMAIL_REGEX];

            $formattedData['validations'] = $emailValidations;
        }

        $formattedData['name'] = $rowData['attribute'] ?? '';
        $formattedData['description'] = $rowData['description'] ?? '';
        $formattedData['pin'] = (bool) $rowData['pin'];

        $capabilities = [];

        if (! empty($rowData['options'])) {
            $options = json_decode($rowData['options'], true);

            foreach ($options as $key => $option) {
                if (isset($this->shoifyMetaFieldTypeData[$rowData['type']][$key])) {
                    $capabilities[$key] = [
                        'enabled' => (bool) $option,
                    ];
                }
            }
        }

        if ($capabilities !== []) {
            $formattedData['capabilities'] = $capabilities;
        }

        $desired = array_values(array_filter(array_map(
            fn ($gid): string => substr((string) $gid, strrpos((string) $gid, '/') + 1),
            (array) ($rowData['taxonomy_category'] ?? [])
        )));

        if ($desired !== []) {
            $formattedData['pin'] = false;
        }

        if (! $id && $desired !== []) {
            $formattedData['constraints'] = ['key' => 'category', 'values' => $desired];
        } elseif ($id) {
            $current = $this->fetchCurrentCategoryConstraint($rowData);
            $toCreate = array_values(array_diff($desired, $current));
            $toDelete = array_values(array_diff($current, $desired));

            if ($toCreate !== [] || $toDelete !== []) {
                $values = array_merge(
                    array_map(fn (string $v): array => ['create' => $v], $toCreate),
                    array_map(fn (string $v): array => ['delete' => $v], $toDelete)
                );

                $formattedData['constraintsUpdates'] = ['key' => 'category', 'values' => $values];
            }

            if ($current !== [] && $desired === [] && ! empty($rowData['pin'])) {
                $formattedData['pin'] = false;
                $formattedData['_needs_follow_up_pin'] = true;
            }
        }

        return $formattedData;
    }

    /**
     * Map a file_reference metafield's content type into Shopify file_type_options.
     *
     * @param  array<int, string>  $fileTypes
     * @return array<int, string>
     */
    protected function resolveFileTypeOptions(?string $contentType, array $fileTypes): array
    {
        return match ($contentType) {
            'IMAGE' => ['Image'],
            'VIDEO' => ['Video'],
            'FILE'  => array_values($fileTypes),
            default => [],
        };
    }

    /**
     * Resolve choices list options for select or multiselect attributes.
     *
     * @return array<int, string>
     */
    protected function resolveChoiceListValues(string $code): array
    {
        if (empty($code) || empty($this->shopifyDefaultLocale)) {
            return [];
        }

        $attribute = $this->findAttribute($code);

        if (! $attribute || ! in_array($attribute->type, ['select', 'multiselect'], true)) {
            return [];
        }

        $choices = [];

        foreach ($attribute->options as $option) {
            $translations = $option->translations ?? collect();
            $translation = $translations->firstWhere('locale', $this->shopifyDefaultLocale);
            $label = $translation?->label;

            $choices[] = ! empty($label) ? $label : $option->code;
        }

        return $choices;
    }

    /**
     * Fetch current category constraints for the metafield definition.
     *
     * @return array<int, string>
     */
    private function fetchCurrentCategoryConstraint(array $rowData): array
    {
        return $this->currentCategoryConstraints()[$rowData['name_space_key'] ?? ''] ?? [];
    }

    /**
     * Fetch all category constraints across metafield definitions.
     *
     * @return array<string, array<int, string>>
     */
    private function currentCategoryConstraints(): array
    {
        if ($this->currentConstraintsMap !== null) {
            return $this->currentConstraintsMap;
        }

        $map = [];
        $cursor = null;

        do {
            $response = $this->requestGraphQlApiAction('metafieldDefinitionsProductType', $this->credentialArray, [
                'first' => 250,
                'after' => $cursor,
            ]);

            $edges = $response['body']['data']['metafieldDefinitions']['edges'] ?? [];

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $nsKey = ($node['namespace'] ?? '').'.'.($node['key'] ?? '');

                if (($node['constraints']['key'] ?? null) === 'category') {
                    $valuesData = $node['constraints']['values'] ?? [];
                    $values = array_map(fn (array $v) => $v['value'], $valuesData['nodes'] ?? []);

                    $hasNextConstraint = $valuesData['pageInfo']['hasNextPage'] ?? false;
                    $constraintCursor = $valuesData['pageInfo']['endCursor'] ?? null;
                    $defId = $node['id'] ?? null;

                    while ($hasNextConstraint && $defId) {
                        $moreResponse = $this->requestGraphQlApiAction('metafieldDefinitionCategoryConstraints', $this->credentialArray, [
                            'id'    => $defId,
                            'after' => $constraintCursor,
                        ]);

                        $moreConstraints = $moreResponse['body']['data']['node']['constraints']['values'] ?? [];
                        foreach ($moreConstraints['nodes'] ?? [] as $moreNode) {
                            $values[] = $moreNode['value'];
                        }

                        $hasNextConstraint = $moreConstraints['pageInfo']['hasNextPage'] ?? false;
                        $constraintCursor = $moreConstraints['pageInfo']['endCursor'] ?? null;
                    }

                    $map[$nsKey] = $values;
                }
            }

            $lastCursor = ! empty($edges) ? end($edges)['cursor'] : null;

            if ($cursor === $lastCursor || empty($lastCursor)) {
                break;
            }

            $cursor = $lastCursor;
        } while (! empty($edges));

        return $this->currentConstraintsMap = $map;
    }

    /**
     * Make an API request to Shopify to create or update a metafield definition.
     */
    public function apiRequestShopify($metaFieldFormattedData, $id = null): array
    {
        $mutationType = $id ? 'metafieldDefinitionUpdate' : 'metafieldDefinitionCreate';

        return $this->requestGraphQlApiAction($mutationType, $this->credentialArray, ['input' => $metaFieldFormattedData]);
    }

    /**
     * Log a warning for an attribute code.
     */
    public function logWarning(array $data, string $code): void
    {
        if ($data !== [] && ! empty($code)) {
            $error = json_encode($data, true);

            $this->jobLogger->warning(
                "Warning for MetaField with attribute code: {$code}, : {$error}"
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

        if (! array_key_exists('locale_specific', $data['additional_data'])) {
            return [];
        }

        return $data['additional_data']['locale_specific'][$locale] ?? [];
    }
}
