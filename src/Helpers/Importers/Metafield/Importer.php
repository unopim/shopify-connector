<?php

namespace Webkul\Shopify\Helpers\Importers\Metafield;

use Illuminate\Support\Str;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Core\Repositories\LocaleRepository;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Helpers\Importers\Category\Storage;
use Webkul\DataTransfer\Helpers\Source;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;
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

    protected $attributeType = [
        'single_line_text_field' => 'text',
        'id'                     => 'text',
        'json'                   => 'textarea',
        'number_integer'         => 'text',
        'multi_line_text_field'  => 'textarea',
        'rich_text_field'        => 'textarea',
        'color'                  => 'text',
        'rating'                 => 'text',
        'url'                    => 'text',
        'boolean'                => 'boolean',
        'number_decimal'         => 'text',
        'dimension'              => 'text',
        'weight'                 => 'text',
        'volume'                 => 'text',
        'date'                   => 'date',
        'date_time'              => 'datetime',
        'file_reference'         => 'image',
        'list.file_reference'    => 'gallery',
        'link'                   => 'text',
    ];

    protected $numberType = ['number_integer', 'dimension', 'weight', 'volume'];

    protected $decimalType = ['number_decimal'];

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

    protected ?array $metaobjectTypeByGid = null;

    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected AttributeRepository $attributeRepository,
        protected LocaleRepository $localeRepository,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyMetaFieldRepository $shopifyMetaFieldRepository,
        protected ShopifyMetaobjectDefinitionRepository $metaobjectDefinitionRepository,
        protected ShopifyMetaobjectAttributeRepository $metaobjectAttributeRepository,
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
        $this->credentialArray = $this->credential?->toApiArray() ?? [];

        $productMetafieldDefinition = $this->metaFieldAttrByCursor();
        $productVariantMetaField = $this->metaFieldProductVariant();

        $requestData = $this->credential?->extras;
        $requestData['productMetafield'] = array_combine(array_column($productMetafieldDefinition, 'code'), array_column($productMetafieldDefinition, 'namespace'));
        $requestData['productVariantMetafield'] = array_combine(array_column($productVariantMetaField, 'code'), array_column($productVariantMetaField, 'namespace'));
        $filters = $this->import->jobInstance->filters;

        $this->shopifyRepository->update(['extras' => $requestData], $filters['credentials']);

        $mergeMetafield = array_merge($productVariantMetaField, $productMetafieldDefinition);

        $metafieldProductAttr = new \ArrayIterator($mergeMetafield);

        return $metafieldProductAttr;
    }

    /**
     * ProductVariant Attr
     */
    public function metaFieldProductVariant(): array
    {
        $cursor = null;
        $allAttribute = [];
        $formattedOption = [];
        do {
            $variables = [];
            $mutationType = 'metafieldDefinitionsProductVariantType';
            $variables = [
                'first' => 20,
                'after' => $cursor,
            ];
            $graphResponse = $this->requestGraphQlApiAction($mutationType, $this->credentialArray, $variables);

            $metafieldAttribute = ! empty($graphResponse['body']['data']['metafieldDefinitions']['edges'])
                ? $graphResponse['body']['data']['metafieldDefinitions']['edges']
                : [];

            $formattedOption = $this->formatedAttribute($metafieldAttribute);

            $allAttribute = array_merge($allAttribute, $formattedOption);
            $lastCursor = ! empty($metafieldAttribute) ? end($metafieldAttribute)['cursor'] : null;

            if ($cursor === $lastCursor || empty($lastCursor)) {
                break;
            }

            $cursor = $lastCursor;

        } while (! empty($metafieldAttribute));

        return $allAttribute;
    }

    /**
     * Attribute Getting by cursor
     */
    public function metaFieldAttrByCursor(): array
    {
        $cursor = null;
        $allAttribute = [];
        $formattedOption = [];
        do {
            $variables = [];
            $mutationType = 'metafieldDefinitionsProductType';
            $variables = [
                'first' => 20,
                'after' => $cursor,
            ];
            $graphResponse = $this->requestGraphQlApiAction($mutationType, $this->credentialArray, $variables);

            $metafieldAttribute = ! empty($graphResponse['body']['data']['metafieldDefinitions']['edges'])
                ? $graphResponse['body']['data']['metafieldDefinitions']['edges']
                : [];
            $formattedOption = $this->formatedAttribute($metafieldAttribute);

            $allAttribute = array_merge($allAttribute, $formattedOption);
            $lastCursor = ! empty($metafieldAttribute) ? end($metafieldAttribute)['cursor'] : null;

            if ($cursor === $lastCursor || empty($lastCursor)) {
                break;
            }

            $cursor = $lastCursor;

        } while (! empty($metafieldAttribute));

        return $allAttribute;
    }

    /**
     * Formating Attribute and attriute Option
     */
    public function formatedAttribute(array $attributes): array
    {
        $attributesArray = [];

        foreach ($attributes as $attribute) {
            $metafieldType = $attribute['node']['type']['name'];
            $baseType = preg_replace('/^list\./', '', $metafieldType);

            if ($baseType === 'metaobject_reference') {
                $attributesArray[] = $this->metaobjectAttributeRow($attribute);

                continue;
            }

            if (in_array($baseType, ['product_reference', 'variant_reference', 'collection_reference'], true)) {
                $this->persistDefinitionIfMissing($attribute);

                continue;
            }

            if (! isset($this->attributeType[$metafieldType])) {
                continue;
            }

            $attributeFormate = [
                'code'        => $attribute['node']['key'],
                'type'        => $this->attributeType[$metafieldType],
                'namespace'   => $attribute['node']['namespace'],
                $this->locale => [
                    'name' => $attribute['node']['name'],
                ],
            ];

            if (in_array($metafieldType, $this->numberType)) {
                $attributeFormate['validation'] = 'number';
            } elseif (in_array($metafieldType, $this->decimalType)) {
                $attributeFormate['validation'] = 'decimal';
            } elseif ($metafieldType === 'link') {
                $attributeFormate['validation'] = 'url';
            }

            if ($metafieldType === 'file_reference') {
                $fileTypes = (string) (collect($attribute['node']['validations'] ?? [])
                    ->firstWhere('name', 'file_type_options')['value'] ?? '');
                $attributeFormate['type'] = str_contains($fileTypes, 'Video')
                    ? 'gallery'
                    : (str_contains($fileTypes, 'Image') ? 'image' : 'file');
            }

            $this->persistDefinitionIfMissing($attribute);

            $attributesArray[] = $attributeFormate;
        }

        return $attributesArray;
    }

    /**
     * Build a batch row for a metaobject_reference metafield so the attribute,
     * binding and metafield are created (and counted) during batch processing.
     *
     * @return array<string, mixed>
     */
    protected function metaobjectAttributeRow(array $attribute): array
    {
        $node = $attribute['node'];
        $typeName = $node['type']['name'];
        $name = $node['name'] ?? $node['key'];
        $definitionGid = (string) (collect($node['validations'] ?? [])->firstWhere('name', 'metaobject_definition_id')['value'] ?? '');
        $metaobjectType = $this->metaobjectTypeByGid()[$definitionGid] ?? null;

        return [
            'code'        => $this->metaobjectAttributeCode($name),
            'type'        => 'shopify_metaobject',
            'namespace'   => $node['namespace'],
            $this->locale => ['name' => $name],
            'metaobject'  => [
                'name'            => $name,
                'is_list'         => str_contains($typeName, 'list'),
                'type_name'       => $typeName,
                'metaobject_type' => $metaobjectType,
                'name_space_key'  => "{$node['namespace']}.{$node['key']}",
                'owner_type'      => $node['ownerType'],
                'pin'             => ! empty($node['pinnedPosition']),
                'api_url'         => json_encode([$this->credentialArray['shopUrl'] => $node['id']]),
                'validations'     => json_encode(array_filter([
                    'reference_source'         => 'metaobject',
                    'metaobject_definition_id' => $definitionGid,
                    'metaobject_type'          => $metaobjectType,
                ], fn ($value) => $value !== null && $value !== '')),
            ],
        ];
    }

    /**
     * Create the shopify_metaobject attribute, its definition binding and the
     * mapped metafield for a metaobject batch row, counting the attribute.
     *
     * @param  array<string, mixed>  $rowData
     */
    protected function saveMetaobjectAttribute(array $rowData): void
    {
        $meta = $rowData['metaobject'];
        $code = $rowData['code'];
        $existing = $this->attributeRepository->findOneByField('code', strtolower($code));

        if ($existing) {
            $attributeId = $existing->id;
            $this->updatedItemsCount++;
        } else {
            $attributeId = $this->attributeRepository->create([
                'code'        => $code,
                'type'        => 'shopify_metaobject',
                $this->locale => $rowData[$this->locale] ?? ['name' => $meta['name']],
            ])->id;
            $this->createdItemsCount++;
        }

        if (! empty($meta['metaobject_type'])) {
            $definition = $this->metaobjectDefinitionRepository->findOneWhere(['code' => $meta['metaobject_type']]);

            if ($definition) {
                $this->metaobjectAttributeRepository->saveBinding((int) $attributeId, (int) $definition->id, (bool) $meta['is_list']);
            }
        }

        $existingMeta = $this->shopifyMetaFieldRepository->findOneWhere([
            ['name_space_key', '=', $meta['name_space_key']],
            ['ownerType', '=', $meta['owner_type']],
        ]);

        if ($existingMeta) {
            return;
        }

        $this->shopifyMetaFieldRepository->create([
            'ownerType'       => $meta['owner_type'],
            'type'            => 'metaobject_reference',
            'name_space_key'  => $meta['name_space_key'],
            'code'            => $code,
            'attribute'       => $meta['name'],
            'attributeLabel'  => $meta['name'],
            'pin'             => $meta['pin'],
            'listvalue'       => $meta['is_list'],
            'ContentTypeName' => $meta['type_name'],
            'apiUrl'          => $meta['api_url'],
            'validations'     => $meta['validations'],
        ]);
    }

    /**
     * Build a unique attribute code from a metafield name, reusing an existing
     * code only when it already belongs to a shopify_metaobject attribute.
     */
    protected function metaobjectAttributeCode(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'metaobject';
        $code = $base;
        $suffix = 1;

        while (($attribute = $this->attributeRepository->findOneWhere(['code' => $code])) && $attribute->type !== 'shopify_metaobject') {
            $code = $base.'_'.(++$suffix);
        }

        return $code;
    }

    private function persistDefinitionIfMissing(array $attribute): void
    {
        $data = $this->formatDataForMetafield($attribute);

        $existing = $this->shopifyMetaFieldRepository->findOneWhere([
            ['name_space_key', '=', $data['name_space_key']],
            ['ownerType', '=', $data['ownerType']],
        ]);

        if (! $existing) {
            $this->shopifyMetaFieldRepository->create($data);

            return;
        }

        if (array_key_exists('taxonomy_category', $data)) {
            $this->shopifyMetaFieldRepository->update(['taxonomy_category' => $data['taxonomy_category']], $existing->id);
        }
    }

    public function formatDataForMetafield(array $metafieldDefinition): array
    {
        $node = $metafieldDefinition['node'];
        $typeName = $node['type']['name'];
        $nameSpaceKey = "{$node['namespace']}.{$node['key']}";

        $data = [
            'ownerType'       => $node['ownerType'],
            'type'            => $typeName,
            'name_space_key'  => $nameSpaceKey,
            'code'            => $node['key'],
            'attribute'       => $node['name'],
            'pin'             => ! empty($node['pinnedPosition']),
            'listvalue'       => str_contains($typeName, 'list'),
            'ContentTypeName' => $typeName,
            'apiUrl'          => json_encode([$this->credentialArray['shopUrl'] => $node['id']]),
        ];

        // Handle rating validations efficiently
        if ($typeName === 'rating') {
            $validations = collect($node['validations']);
            $scaleMin = $validations->firstWhere('name', 'scale_min')['value'] ?? 0;
            $scaleMax = $validations->firstWhere('name', 'scale_max')['value'] ?? 0;

            $data['validations'] = json_encode([
                'min' => (string) $scaleMin,
                'max' => (string) $scaleMax,
            ]);
        }

        if ($typeName === 'id') {
            $validations = collect($node['validations'] ?? []);
            $data['validations'] = json_encode(array_filter([
                'min'   => $validations->firstWhere('name', 'min')['value'] ?? null,
                'max'   => $validations->firstWhere('name', 'max')['value'] ?? null,
                'regex' => $validations->firstWhere('name', 'regex')['value'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));
        }

        if (str_contains($typeName, 'file_reference')) {
            $data['type'] = 'file_reference';
            $fileTypesRaw = (string) (collect($node['validations'] ?? [])
                ->firstWhere('name', 'file_type_options')['value'] ?? '');
            $hasImage = str_contains($fileTypesRaw, 'Image');
            $hasVideo = str_contains($fileTypesRaw, 'Video');

            // Image+video restriction is a generic File type limited to media;
            // a single kind maps to that content type; none means any file type.
            if ($hasImage && $hasVideo) {
                $validations = ['content_type' => 'FILE', 'file_types' => ['Image', 'Video']];
            } elseif ($hasImage) {
                $validations = ['content_type' => 'IMAGE'];
            } elseif ($hasVideo) {
                $validations = ['content_type' => 'VIDEO'];
            } else {
                $validations = ['content_type' => 'FILE'];
            }

            $data['validations'] = json_encode($validations);
        }

        $referenceBase = preg_replace('/^list\./', '', $typeName);
        if (in_array($referenceBase, ['product_reference', 'variant_reference', 'collection_reference'], true)) {
            $data['type'] = $referenceBase;
            $associationType = in_array($node['key'], ['up_sells', 'cross_sells'], true) ? $node['key'] : 'related_products';
            $data['validations'] = json_encode($referenceBase === 'collection_reference'
                ? ['reference_source' => 'categories']
                : ['reference_source' => 'association', 'association_type' => $associationType,
                    'reference_as'    => $referenceBase === 'variant_reference' ? 'variant' : 'product']);
        }

        if ($referenceBase === 'metaobject_reference') {
            $data['type'] = 'metaobject_reference';
            $definitionGid = (string) (collect($node['validations'] ?? [])->firstWhere('name', 'metaobject_definition_id')['value'] ?? '');
            $data['validations'] = json_encode(array_filter([
                'reference_source'         => 'metaobject',
                'metaobject_definition_id' => $definitionGid,
                'metaobject_type'          => $this->metaobjectTypeByGid()[$definitionGid] ?? null,
            ], fn ($value) => $value !== null && $value !== ''));
        }

        if (array_key_exists('constraints', $node)) {
            $data['taxonomy_category'] = (($node['constraints']['key'] ?? null) === 'category')
                ? array_map(
                    fn ($value) => 'gid://shopify/TaxonomyCategory/'.$value['value'],
                    $node['constraints']['values']['nodes'] ?? []
                )
                : [];
        }

        return $data;
    }

    /**
     * Resolve every Shopify metaobject definition GID to its type once, so a
     * metaobject_reference metafield's metaobject_definition_id validation can be
     * stored with the type the export/UI expect. Works on manual and SaaS.
     *
     * @return array<string, string>
     */
    protected function metaobjectTypeByGid(): array
    {
        if ($this->metaobjectTypeByGid !== null) {
            return $this->metaobjectTypeByGid;
        }

        $map = [];
        $cursor = null;

        do {
            $response = $this->requestGraphQlApiAction('metaobjectDefinitions', $this->credentialArray, [
                'first' => 50,
                'after' => $cursor,
            ]);

            $connection = $response['body']['data']['metaobjectDefinitions'] ?? [];
            $edges = $connection['edges'] ?? [];

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                if (! empty($node['id']) && ! empty($node['type'])) {
                    $map[$node['id']] = $node['type'];
                }
            }

            $cursor = (! empty($edges) && ($connection['pageInfo']['hasNextPage'] ?? false))
                ? end($edges)['cursor']
                : null;
        } while ($cursor);

        return $this->metaobjectTypeByGid = $map;
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
            if (isset($rowData['metaobject'])) {
                $this->saveMetaobjectAttribute($rowData);

                continue;
            }

            $attributeModel = $this->attributeRepository->findOneByField('code', strtolower($rowData['code']));
            if ($attributeModel) {
                $this->updatedItemsCount++;
            } else {
                unset($rowData['namespace']);
                $newlyAttrCreated = $this->attributeRepository->create($rowData);
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
