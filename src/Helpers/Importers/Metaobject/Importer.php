<?php

namespace Webkul\Shopify\Helpers\Importers\Metaobject;

use Carbon\Carbon;
use Webkul\DataTransfer\Contracts\JobTrackBatch as JobTrackBatchContract;
use Webkul\DataTransfer\Helpers\Import;
use Webkul\DataTransfer\Helpers\Importers\AbstractImporter;
use Webkul\DataTransfer\Repositories\JobTrackBatchRepository;
use Webkul\Shopify\Helpers\Iterator\MetaobjectIterator;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectMappingRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;
use Webkul\Shopify\Traits\ValidatedBatched;

class Importer extends AbstractImporter
{
    use ShopifyGraphqlRequest;
    use ValidatedBatched;

    public const BATCH_SIZE = 10;

    public const UNOPIM_ENTITY_NAME = 'metaobject';

    protected mixed $credential = null;

    protected array $credentialArray = [];

    protected string $shopUrl = '';

    public function __construct(
        protected JobTrackBatchRepository $importBatchRepository,
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyMetaobjectDefinitionRepository $definitionRepository,
        protected ShopifyMetaobjectEntryRepository $entryRepository,
        protected ShopifyMetaobjectMappingRepository $definitionMappingRepository,
        protected ShopifyMetaobjectEntryMappingRepository $entryMappingRepository,
        protected ShopifyMappingRepository $shopifyMappingRepository,
    ) {
        parent::__construct($importBatchRepository);
    }

    /** @var array<string, string>|null */
    protected ?array $referenceCodeByGid = null;

    protected function initFilters(): void
    {
        $filters = $this->import->jobInstance->filters;

        $this->credential = $this->shopifyRepository->find($filters['credentials'] ?? null);
        $this->credentialArray = $this->credential?->toApiArray() ?? [];
        $this->shopUrl = (string) ($this->credential?->shopUrl ?? '');
    }

    public function getSource()
    {
        $this->initFilters();

        if (! $this->credential?->active) {
            throw new \InvalidArgumentException(trans('shopify::app.shopify.credential.errors.invalid-credential'));
        }

        return new MetaobjectIterator($this->credentialArray);
    }

    public function validateData(): void
    {
        $this->saveValidatedBatches();
    }

    public function validateRow(array $rowData, int $rowNumber): bool
    {
        return true;
    }

    public function importBatch(JobTrackBatchContract $batch): bool
    {
        $this->initFilters();

        foreach ($batch->data as $row) {
            $type = $row['type'] ?? '';

            if ($type === '') {
                continue;
            }

            $this->saveDefinition($row, $type);
            $this->importEntries($type, $row['fields'] ?? []);
        }

        $this->importBatchRepository->update([
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
     * @param  array<string, mixed>  $row
     */
    protected function saveDefinition(array $row, string $type): void
    {
        $name = $row['name'] ?? $type;
        $fields = $row['fields'] ?? [];
        $options = $row['options'] ?? [];
        $definition = $this->definitionRepository->findOneWhere(['code' => $type]);

        if ($definition) {
            $fields = $this->preserveEmailPresets($fields, $definition->fields ?? []);
            $this->definitionRepository->update(['name' => $name, 'fields' => $fields, 'options' => $options], $definition->id);
            $this->updatedItemsCount++;
        } else {
            $this->definitionRepository->create(['name' => $name, 'code' => $type, 'fields' => $fields, 'options' => $options]);
            $this->createdItemsCount++;
        }

        if (! empty($row['id'])) {
            $this->definitionMappingRepository->updateOrCreateByType($this->shopUrl, $type, [
                'gid'    => $row['id'],
                'name'   => $name,
                'fields' => $fields,
            ]);
        }
    }

    /**
     * Shopify has no email field type (email exports as single_line_text_field), so a
     * re-import cannot tell email apart from plain text. Carry the email preset over
     * from the existing local definition by field key.
     *
     * @param  array<int, array<string, mixed>>  $imported
     * @param  array<int, array<string, mixed>>  $existing
     * @return array<int, array<string, mixed>>
     */
    protected function preserveEmailPresets(array $imported, array $existing): array
    {
        $emailKeys = [];

        foreach ($existing as $field) {
            if (($field['type'] ?? '') === 'single_line_text_field' && ($field['preset'] ?? '') === 'email') {
                $emailKeys[$field['key'] ?? ''] = true;
            }
        }

        if ($emailKeys === []) {
            return $imported;
        }

        return array_map(function ($field) use ($emailKeys) {
            if (($field['type'] ?? '') === 'single_line_text_field'
                && empty($field['preset'])
                && ! empty($emailKeys[$field['key'] ?? ''])) {
                $field['preset'] = 'email';
            }

            return $field;
        }, $imported);
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     */
    protected function importEntries(string $type, array $fields): void
    {
        $fieldByKey = [];

        foreach ($fields as $field) {
            $fieldByKey[$field['key']] = $field;
        }

        $cursor = null;

        do {
            $variables = ['type' => $type, 'first' => 50];

            if ($cursor) {
                $variables['after'] = $cursor;
            }

            $response = $this->requestGraphQlApiAction('getMetaobjectsByType', $this->credentialArray, $variables);
            $edges = $response['body']['data']['metaobjects']['edges'] ?? [];

            foreach ($edges as $edge) {
                $node = $edge['node'] ?? [];
                $handle = $node['handle'] ?? '';

                if ($handle === '') {
                    continue;
                }

                $values = $this->reverseEntryValues($node['fields'] ?? [], $fieldByKey);
                $entry = $this->entryRepository->updateOrCreateByCode($type, $handle, $values);

                if (! empty($node['id'])) {
                    $this->entryMappingRepository->putGid((int) $entry->id, $this->shopUrl, $node['id']);
                }
            }

            $cursor = ! empty($edges) ? end($edges)['cursor'] : null;
        } while (($response['body']['data']['metaobjects']['pageInfo']['hasNextPage'] ?? false) && $cursor);
    }

    /**
     * @param  array<int, array{key: string, value: string}>  $nodeFields
     * @param  array<string, array<string, mixed>>  $fieldByKey
     * @return array<string, mixed>
     */
    protected function reverseEntryValues(array $nodeFields, array $fieldByKey): array
    {
        $values = [];

        foreach ($nodeFields as $nodeField) {
            $key = $nodeField['key'] ?? '';
            $raw = $nodeField['value'] ?? null;
            $field = $fieldByKey[$key] ?? null;

            if ($key === '' || $raw === null || $raw === '' || ! $field) {
                continue;
            }

            $type = $field['type'] ?? '';
            $list = ! empty($field['list']);

            if ($type === 'file_reference') {
                $paths = $this->importFiles($raw, $list);

                if (! empty($paths)) {
                    $values[$key] = $list ? $paths : $paths[0];
                }

                continue;
            }

            if ($type === 'metaobject_reference') {
                $codes = $this->resolveReferenceCodes($raw, $list);

                if (! empty($codes)) {
                    $values[$key] = $list ? $codes : $codes[0];
                }

                continue;
            }

            if (in_array($type, ['product_reference', 'variant_reference', 'collection_reference'], true)) {
                $identifiers = $this->resolveReferenceIdentifiers($raw, $list);

                if (! empty($identifiers)) {
                    $values[$key] = $list ? $identifiers : $identifiers[0];
                }

                continue;
            }

            if ($type === 'boolean') {
                $values[$key] = $raw === 'true' || $raw === '1' || $raw === true;

                continue;
            }

            if ($type === 'rich_text_field') {
                $values[$key] = $this->richTextToPlain((string) $raw);

                continue;
            }

            if ($list) {
                $decoded = json_decode((string) $raw, true);
                $elements = is_array($decoded) ? $decoded : [];

                $values[$key] = array_values(array_map(
                    fn ($element) => $this->normalizeImportedScalar($type, $element),
                    $elements
                ));

                continue;
            }

            if ($type === 'link') {
                $decoded = json_decode((string) $raw, true);
                $values[$key] = is_array($decoded) ? $decoded : $raw;

                continue;
            }

            $values[$key] = $this->normalizeImportedScalar($type, $raw);
        }

        return $values;
    }

    /**
     * Download file_reference GID(s) to the public disk and return their paths.
     *
     * @return array<int, string>
     */
    protected function importFiles(mixed $raw, bool $list): array
    {
        $gids = $list ? (json_decode((string) $raw, true) ?: []) : [$raw];
        $gids = array_values(array_filter((array) $gids));

        if (empty($gids)) {
            return [];
        }

        $response = $this->requestGraphQlApiAction('getFileById', $this->credentialArray, ['ids' => $gids]);
        $urlByGid = [];

        foreach ($response['body']['data']['nodes'] ?? [] as $node) {
            if (! empty($node['id'])) {
                $urlByGid[$node['id']] = $node['image']['url'] ?? $node['url'] ?? ($node['sources'][0]['url'] ?? null);
            }
        }

        $paths = [];

        foreach ($gids as $gid) {
            $url = $urlByGid[$gid] ?? null;
            $stored = $url ? $this->handleUrlField($url, 'shopify/metaobject-entries/') : false;

            if ($stored) {
                $paths[] = $stored;
            }
        }

        return $paths;
    }

    protected function richTextToPlain(string $json): string
    {
        $document = json_decode($json, true);

        if (! is_array($document)) {
            return $json;
        }

        $blocks = [];

        foreach ($document['children'] ?? [] as $block) {
            $blocks[] = $this->nodeText($block);
        }

        return trim(implode("\n\n", $blocks));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    protected function nodeText(array $node): string
    {
        if (($node['type'] ?? '') === 'text') {
            return (string) ($node['value'] ?? '');
        }

        $text = '';

        foreach ($node['children'] ?? [] as $child) {
            $text .= $this->nodeText($child);
        }

        return $text;
    }

    /**
     * @return array<int, string>
     */
    /**
     * Reverse a Shopify scalar element to the plain value the entry UI edits:
     * measurement/rating objects collapse to their value, money to "amount CUR".
     */
    protected function normalizeImportedScalar(string $type, mixed $element): mixed
    {
        if (in_array($type, ['dimension', 'volume', 'weight', 'rating'], true)) {
            $decoded = is_array($element) ? $element : (is_string($element) ? json_decode($element, true) : null);

            return is_array($decoded) ? ($decoded['value'] ?? '') : $element;
        }

        if ($type === 'money') {
            $decoded = is_array($element) ? $element : (is_string($element) ? json_decode($element, true) : null);

            return is_array($decoded) ? ($decoded['amount'] ?? '') : $element;
        }

        if ($type === 'date' || $type === 'date_time') {
            try {
                $date = Carbon::parse((string) $element);
            } catch (\Throwable $e) {
                return $element;
            }

            return $type === 'date' ? $date->format('Y-m-d') : $date->format('Y-m-d H:i:s');
        }

        return $element;
    }

    protected function resolveReferenceCodes(mixed $raw, bool $list): array
    {
        $gids = $list ? (json_decode((string) $raw, true) ?: []) : [$raw];
        $codes = [];

        foreach (array_filter((array) $gids) as $gid) {
            $entryId = $this->entryMappingRepository->entryIdForGid((string) $gid, $this->shopUrl);
            $entry = $entryId ? $this->entryRepository->find($entryId) : null;

            if ($entry) {
                $codes[] = $entry->code;
            }
        }

        return $codes;
    }

    /**
     * @return array<int, string>
     */
    protected function resolveReferenceIdentifiers(mixed $raw, bool $list): array
    {
        $gids = $list ? (json_decode((string) $raw, true) ?: []) : [$raw];
        $map = $this->referenceCodeByGid();
        $codes = [];

        foreach (array_filter((array) $gids) as $gid) {
            if (! empty($map[$gid])) {
                $codes[] = $map[$gid];
            } else {
                $this->jobLogger->warning(trans('shopify::app.shopify.export.errors.metaobject-reference-unmapped', ['identifier' => $gid]));
            }
        }

        return $codes;
    }

    /**
     * @return array<string, string>
     */
    protected function referenceCodeByGid(): array
    {
        if ($this->referenceCodeByGid !== null) {
            return $this->referenceCodeByGid;
        }

        $map = [];

        $rows = $this->shopifyMappingRepository
            ->whereIn('entityType', ['product', 'category'])
            ->where('apiUrl', $this->shopUrl)
            ->get(['code', 'externalId']);

        foreach ($rows as $row) {
            if (! empty($row->externalId) && ! empty($row->code)) {
                $map[$row->externalId] = $row->code;
            }
        }

        return $this->referenceCodeByGid = $map;
    }
}
