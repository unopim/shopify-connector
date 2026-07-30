<?php

namespace Webkul\Shopify\Helpers\Exporters\Metaobject;

use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Export as ExportHelper;
use Webkul\DataTransfer\Helpers\Exporters\AbstractExporter;
use Webkul\DataTransfer\Jobs\Export\File\FlatItemBuffer as FileExportFileBuffer;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Exceptions\InvalidCredential;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectMappingRepository;
use Webkul\Shopify\Services\Bulk\Files\FileReferenceUploader;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class Exporter extends AbstractExporter
{
    use ShopifyGraphqlRequest;

    public const BATCH_SIZE = 20;

    public const UNOPIM_ENTITY_NAME = 'metaobject';

    protected bool $exportsFile = false;

    protected array $credentialArray = [];

    protected string $shopUrl = '';

    /** @var array<string, string> */
    protected array $definitionGidRun = [];

    /** @var array<string, string> */
    protected array $entryGidRun = [];

    /** @var array<string, string> */
    protected array $fileGidMap = [];

    protected bool $filesLoaded = false;

    /** @var array<string, string> */
    protected array $productGidMap = [];

    /** @var array<string, string> */
    protected array $variantGidMap = [];

    /** @var array<string, string> */
    protected array $collectionGidMap = [];

    protected bool $referenceMapsLoaded = false;

    public function __construct(
        protected JobTrackBatchRepository $exportBatchRepository,
        protected FileExportFileBuffer $exportFileBuffer,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyMetaobjectDefinitionRepository $definitionRepository,
        protected ShopifyMetaobjectEntryRepository $entryRepository,
        protected ShopifyMetaobjectMappingRepository $definitionMappingRepository,
        protected ShopifyMetaobjectEntryMappingRepository $entryMappingRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
        protected FileReferenceUploader $fileReferenceUploader,
    ) {
        parent::__construct($exportBatchRepository, $exportFileBuffer);
    }

    public function initialize()
    {
        $filters = $this->getFilters();
        $credential = $this->shopifyRepository->find($filters['credentials']);

        if (! $credential?->active) {
            $this->export->state = ExportHelper::STATE_FAILED;
            $this->export->errors = [trans('shopify::app.shopify.export.errors.invalid-credential')];
            $this->export->save();

            throw new InvalidCredential;
        }

        $this->credentialArray = $credential->toApiArray();
        $this->shopUrl = (string) $credential->shopUrl;
    }

    protected function getResults()
    {
        return $this->source->all()?->getIterator();
    }

    public function exportBatch(JobTrackBatchContract $batch, $filePath): bool
    {
        $this->initialize();

        $this->loadFileGidMap();
        $this->loadReferenceMaps();

        foreach ($batch->data as $row) {
            $code = $row['code'] ?? '';

            if ($code === '') {
                continue;
            }

            $gid = $this->ensureDefinition($code);

            if (! $gid) {
                $this->skippedItemsCount++;

                continue;
            }

            $this->exportEntries($code);
            $this->createdItemsCount++;
        }

        $this->updateBatchState($batch->id, ExportHelper::STATE_PROCESSED);

        return true;
    }

    protected function ensureDefinition(string $code, array $visited = []): ?string
    {
        if (isset($this->definitionGidRun[$code])) {
            return $this->definitionGidRun[$code];
        }

        if (in_array($code, $visited, true)) {
            return null;
        }

        $visited[] = $code;

        $definition = $this->definitionRepository->findOneWhere(['code' => $code]);

        if (! $definition) {
            return null;
        }

        $fields = $definition->fields ?? [];
        $childGids = [];

        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'metaobject_reference' && ! empty($field['child'])) {
                $childGids[$field['child']] = $this->ensureDefinition($field['child'], $visited);
            }
        }

        $existing = $this->requestGraphQlApiAction('metaobjectDefinitionByType', $this->credentialArray, ['type' => $code]);
        $gid = $existing['body']['data']['metaobjectDefinitionByType']['id'] ?? null;

        if (! $gid) {
            $response = $this->requestGraphQlApiAction('metaobjectDefinitionCreate', $this->credentialArray, [
                'definition' => [
                    'name'             => $definition->name,
                    'type'             => $code,
                    'access'           => ['storefront' => 'PUBLIC_READ'],
                    'capabilities'     => ['publishable' => ['enabled' => true]],
                    'fieldDefinitions' => $this->buildFieldDefinitions($fields, $childGids),
                ],
            ]);

            $errors = $response['body']['data']['metaobjectDefinitionCreate']['userErrors'] ?? [];

            if (! empty($errors)) {
                $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.metaobject-definition-failed', ['code' => $code]).' '.json_encode($errors));

                return null;
            }

            $gid = $response['body']['data']['metaobjectDefinitionCreate']['metaobjectDefinition']['id'] ?? null;
        } else {
            $this->syncDefinitionFields($gid, $code, $fields, $childGids, $existing['body']['data']['metaobjectDefinitionByType']['fieldDefinitions'] ?? []);
        }

        if (! $gid) {
            return null;
        }

        $this->definitionMappingRepository->updateOrCreateByType($this->shopUrl, $code, [
            'gid'    => $gid,
            'name'   => $definition->name,
            'fields' => $fields,
        ]);

        return $this->definitionGidRun[$code] = $gid;
    }

    /**
     * Add locally-added fields to an existing Shopify definition (create only).
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, ?string>  $childGids
     * @param  array<int, array<string, mixed>>  $shopifyFields
     */
    protected function syncDefinitionFields(string $gid, string $code, array $fields, array $childGids, array $shopifyFields): void
    {
        $existingKeys = array_column($shopifyFields, 'key');
        $operations = [];

        foreach ($this->buildFieldDefinitions($fields, $childGids) as $definition) {
            if (! in_array($definition['key'], $existingKeys, true)) {
                $operations[] = ['create' => $definition];
            }
        }

        if (empty($operations)) {
            return;
        }

        $response = $this->requestGraphQlApiAction('metaobjectDefinitionUpdate', $this->credentialArray, [
            'id'         => $gid,
            'definition' => ['fieldDefinitions' => $operations],
        ]);

        $errors = $response['body']['data']['metaobjectDefinitionUpdate']['userErrors'] ?? [];

        if (! empty($errors)) {
            $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.metaobject-definition-failed', ['code' => $code]).' '.json_encode($errors));
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<string, ?string>  $childGids
     * @return array<int, array<string, mixed>>
     */
    protected function buildFieldDefinitions(array $fields, array $childGids): array
    {
        $definitions = [];

        foreach ($fields as $field) {
            $type = $field['type'] ?? '';

            if ($type === '') {
                continue;
            }

            $entry = [
                'key'      => $field['key'],
                'name'     => $field['name'] ?? $field['key'],
                'type'     => ! empty($field['list']) ? 'list.'.$type : $type,
                'required' => ! empty($field['required']),
            ];

            $validations = $this->fieldValidations($field);

            if ($type === 'metaobject_reference' && ! empty($childGids[$field['child'] ?? ''])) {
                $validations[] = ['name' => 'metaobject_definition_id', 'value' => $childGids[$field['child']]];
            }

            if (! empty($validations)) {
                $entry['validations'] = $validations;
            }

            $definitions[] = $entry;
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, array{name: string, value: string}>
     */
    protected function fieldValidations(array $field): array
    {
        $type = $field['type'] ?? '';
        $rules = $field['validations'] ?? [];
        $unit = $rules['unit'] ?? null;
        $isRating = $type === 'rating';
        $out = [];

        foreach (['min', 'max'] as $bound) {
            if (($rules[$bound] ?? '') === '') {
                continue;
            }

            $value = $unit
                ? json_encode(['value' => $rules[$bound], 'unit' => $unit], JSON_UNESCAPED_SLASHES)
                : (string) $rules[$bound];

            $out[] = ['name' => $isRating ? 'scale_'.$bound : $bound, 'value' => $value];
        }

        if (($rules['max_precision'] ?? '') !== '') {
            $out[] = ['name' => 'max_precision', 'value' => (string) $rules['max_precision']];
        }

        if (($rules['regex'] ?? '') !== '') {
            $out[] = ['name' => 'regex', 'value' => (string) $rules['regex']];
        }

        if (($rules['choices'] ?? '') !== '') {
            $choices = array_values(array_filter(array_map('trim', explode(',', (string) $rules['choices']))));

            if (! empty($choices)) {
                $out[] = ['name' => 'choices', 'value' => json_encode($choices, JSON_UNESCAPED_SLASHES)];
            }
        }

        if ($type === 'file_reference' && ! empty($field['content_type'])) {
            $out[] = ['name' => 'file_type_options', 'value' => json_encode([ucfirst(strtolower((string) $field['content_type']))], JSON_UNESCAPED_SLASHES)];
        }

        return $out;
    }

    protected function exportEntries(string $code): void
    {
        foreach ($this->entryRepository->findWhere(['type' => $code]) as $entry) {
            $this->upsertEntry($entry);
        }
    }

    protected function upsertEntry(object $entry, array $visited = []): ?string
    {
        $runKey = $entry->type.'::'.$entry->code;

        if (isset($this->entryGidRun[$runKey])) {
            return $this->entryGidRun[$runKey];
        }

        if (in_array($runKey, $visited, true)) {
            return null;
        }

        $visited[] = $runKey;

        $definition = $this->definitionRepository->findOneWhere(['code' => $entry->type]);

        if (! $definition) {
            return null;
        }

        $fields = $this->buildEntryFields($definition->fields ?? [], $entry->values ?? [], $visited);

        if (empty($fields)) {
            return null;
        }

        $response = $this->requestGraphQlApiAction('metaobjectUpsert', $this->credentialArray, [
            'handle'     => ['type' => $entry->type, 'handle' => $this->slug($entry->code)],
            'metaobject' => [
                'fields'       => $fields,
                'capabilities' => ['publishable' => ['status' => 'ACTIVE']],
            ],
        ]);

        $errors = $response['body']['data']['metaobjectUpsert']['userErrors'] ?? [];

        if (! empty($errors)) {
            $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.metaobject-entry-failed', ['code' => $entry->code]).' '.json_encode($errors));

            return null;
        }

        $gid = $response['body']['data']['metaobjectUpsert']['metaobject']['id'] ?? null;

        if (! $gid) {
            return null;
        }

        $this->entryMappingRepository->putGid((int) $entry->id, $this->shopUrl, $gid);

        return $this->entryGidRun[$runKey] = $gid;
    }

    /**
     * @param  array<int, array<string, mixed>>  $definitionFields
     * @param  array<string, mixed>  $values
     * @return array<int, array{key: string, value: string}>
     */
    protected function buildEntryFields(array $definitionFields, array $values, array $visited): array
    {
        $fields = [];

        foreach ($definitionFields as $field) {
            $key = $field['key'] ?? '';
            $raw = $values[$key] ?? null;

            if ($key === '' || $raw === null || $raw === '') {
                continue;
            }

            $value = $this->resolveFieldValue($field, $raw, $visited);

            if ($value !== null && $value !== '') {
                $fields[] = ['key' => $key, 'value' => (string) $value];
            }
        }

        return $fields;
    }

    protected function resolveFieldValue(array $field, mixed $raw, array $visited): ?string
    {
        $type = $field['type'] ?? '';
        $list = ! empty($field['list']);

        if ($type === 'boolean') {
            return $raw ? 'true' : 'false';
        }

        if ($type === 'file_reference') {
            $paths = $list ? (array) (is_string($raw) ? json_decode($raw, true) : $raw) : [$raw];
            $gids = [];

            foreach (array_filter($paths) as $path) {
                if (! empty($this->fileGidMap[$path])) {
                    $gids[] = $this->fileGidMap[$path];
                }
            }

            if (empty($gids)) {
                return null;
            }

            return $list ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0];
        }

        if ($type === 'rich_text_field') {
            return $this->richText((string) $raw);
        }

        if ($type === 'metaobject_reference') {
            $codes = $list ? (array) (is_string($raw) ? json_decode($raw, true) : $raw) : [$raw];
            $gids = [];

            foreach (array_filter($codes) as $childCode) {
                $childEntry = $this->entryRepository->findOneWhere(['type' => $field['child'] ?? '', 'code' => $childCode]);
                $childGid = $childEntry ? $this->upsertEntry($childEntry, $visited) : null;

                if ($childGid) {
                    $gids[] = $childGid;
                }
            }

            if (empty($gids)) {
                return null;
            }

            return $list ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0];
        }

        if (in_array($type, ['product_reference', 'variant_reference', 'collection_reference'], true)) {
            $map = match ($type) {
                'product_reference'    => $this->productGidMap,
                'variant_reference'    => $this->variantGidMap,
                'collection_reference' => $this->collectionGidMap,
            };

            $identifiers = $list ? (array) (is_string($raw) ? json_decode($raw, true) : $raw) : [$raw];
            $gids = [];

            foreach (array_filter($identifiers) as $identifier) {
                if (! empty($map[$identifier])) {
                    $gids[] = $map[$identifier];
                } else {
                    $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.metaobject-reference-unmapped', ['identifier' => $identifier]));
                }
            }

            if (empty($gids)) {
                return null;
            }

            return $list ? json_encode($gids, JSON_UNESCAPED_SLASHES) : $gids[0];
        }

        return is_array($raw) ? json_encode($raw, JSON_UNESCAPED_SLASHES) : (string) $raw;
    }

    protected function richText(string $text): string
    {
        $blocks = preg_split('/\n{2,}/', trim($text)) ?: [];
        $children = [];

        foreach ($blocks as $block) {
            if (trim($block) === '') {
                continue;
            }

            $children[] = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $block]]];
        }

        if (empty($children)) {
            $children[] = ['type' => 'paragraph', 'children' => [['type' => 'text', 'value' => $text]]];
        }

        return json_encode(['type' => 'root', 'children' => $children], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function slug(string $value): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value)), '-');
    }

    protected function loadFileGidMap(): void
    {
        if ($this->filesLoaded) {
            return;
        }

        $this->filesLoaded = true;

        $fileValues = [];

        foreach ($this->definitionRepository->all() as $definition) {
            $fileKeys = [];

            foreach ($definition->fields ?? [] as $field) {
                if (($field['type'] ?? '') === 'file_reference') {
                    $fileKeys[] = $field['key'];
                }
            }

            if (empty($fileKeys)) {
                continue;
            }

            foreach ($this->entryRepository->findWhere(['type' => $definition->code]) as $entry) {
                foreach ($fileKeys as $key) {
                    $raw = $entry->values[$key] ?? null;

                    if ($raw === null || $raw === '') {
                        continue;
                    }

                    $paths = is_string($raw) && str_starts_with($raw, '[') ? (json_decode($raw, true) ?: []) : [$raw];

                    foreach (array_filter((array) $paths) as $path) {
                        $fileValues[] = ['path' => $path, 'content_type' => $this->contentTypeFor($path)];
                    }
                }
            }
        }

        if (! empty($fileValues)) {
            $this->fileGidMap = $this->fileReferenceUploader->buildGidMap($fileValues, $this->credentialArray, $this->export->id);
        }
    }

    protected function loadReferenceMaps(): void
    {
        if ($this->referenceMapsLoaded) {
            return;
        }

        $this->referenceMapsLoaded = true;

        $products = $this->shopifyMappingRepository
            ->where('entityType', 'product')
            ->where('apiUrl', $this->shopUrl)
            ->get(['code', 'externalId', 'relatedId']);

        foreach ($products as $row) {
            if (empty($row->code)) {
                continue;
            }

            $this->productGidMap[$row->code] = $row->relatedId ?: $row->externalId;

            if (! empty($row->relatedId) && ! empty($row->externalId)) {
                $this->variantGidMap[$row->code] = $row->externalId;
            }
        }

        $categories = $this->shopifyMappingRepository
            ->where('entityType', 'category')
            ->where('apiUrl', $this->shopUrl)
            ->get(['code', 'externalId']);

        foreach ($categories as $row) {
            if (! empty($row->code) && ! empty($row->externalId)) {
                $this->collectionGidMap[$row->code] = $row->externalId;
            }
        }
    }

    protected function contentTypeFor(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true)) {
            return 'IMAGE';
        }

        if (in_array($extension, ['mp4', 'mov', 'webm'], true)) {
            return 'VIDEO';
        }

        return 'FILE';
    }
}
