<?php

namespace Webkul\Shopify\Services\Bulk\Metaobject;

use Illuminate\Support\Facades\Storage;
use Webkul\Shopify\Repositories\ShopifyMetaobjectMappingRepository;
use Webkul\Shopify\Services\BulkOperationService;
use Webkul\Shopify\Services\ShopifyClientFactory;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

/**
 * Reverse of the metaobject entry export: on product import it turns a
 * metaobject_reference metafield value (a metaobject GID) back into the mapped
 * product attribute values.
 *
 * Every mapped metaobject type's entries are fetched once (a regular query on
 * manual, a bulk query on SaaS since the proxy exposes no entry read), so a
 * reference can be resolved to attributes entirely in memory at any nesting
 * depth without per-entry round-trips. Cycles are guarded by a visited set.
 */
class MetaobjectValueResolver
{
    use ShopifyGraphqlRequest;

    /** @var array<string, array{type: string, fields: array<string, string>}> */
    protected array $entriesByGid = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    protected array $mappingByType = [];

    protected bool $loaded = false;

    public function __construct(
        protected ShopifyMetaobjectMappingRepository $mappingRepository,
        protected ShopifyClientFactory $clientFactory,
        protected BulkOperationService $bulkOperationService,
    ) {}

    /**
     * Pre-fetch every mapped metaobject type's entries once for the credential.
     */
    public function preload(string $shopUrl, array $credential): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        foreach ($this->mappingRepository->findWhere(['api_url' => $shopUrl]) as $mapping) {
            $fieldMap = [];
            foreach (($mapping->fields ?? []) as $field) {
                if (! empty($field['key'])) {
                    $fieldMap[$field['key']] = $field;
                }
            }
            $this->mappingByType[$mapping->type] = $fieldMap;
        }

        if (empty($this->mappingByType)) {
            return;
        }

        $viaBulk = $this->clientFactory->isSaas($credential);

        foreach (array_keys($this->mappingByType) as $type) {
            $entries = $viaBulk
                ? $this->fetchEntriesViaBulk($type, $credential)
                : $this->fetchEntriesViaQuery($type, $credential);

            foreach ($entries as $entry) {
                if (! empty($entry['id'])) {
                    $this->entriesByGid[$entry['id']] = $entry;
                }
            }
        }
    }

    /**
     * Resolve a metaobject reference GID to a flat [attribute_code => value] map,
     * recursing through nested metaobject fields and guarding cycles.
     *
     * @return array<string, mixed>
     */
    public function resolveAttributes(string $gid, array $visited = []): array
    {
        if ($gid === '' || isset($visited[$gid]) || ! isset($this->entriesByGid[$gid])) {
            return [];
        }
        $visited[$gid] = true;

        $entry = $this->entriesByGid[$gid];
        $fieldMap = $this->mappingByType[$entry['type']] ?? [];
        $attributes = [];

        foreach ($entry['fields'] as $key => $value) {
            $field = $fieldMap[$key] ?? null;

            if (! $field || $value === null || $value === '') {
                continue;
            }

            if (($field['source'] ?? '') === 'metaobject') {
                foreach ($this->extractGids($value) as $childGid) {
                    $attributes += $this->resolveAttributes($childGid, $visited);
                }

                continue;
            }

            if (! empty($field['attribute_code'])) {
                $attributes[$field['attribute_code']] = $value;
            }
        }

        return $attributes;
    }

    /**
     * @return array<int, string>
     */
    protected function extractGids(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_filter($decoded)) : [$value];
    }

    /**
     * @return array<int, array{id: string, type: string, fields: array<string, string>}>
     */
    protected function fetchEntriesViaQuery(string $type, array $credential): array
    {
        $entries = [];
        $cursor = null;

        do {
            $response = $this->requestGraphQlApiAction('getMetaobjectsByType', $credential, [
                'type' => $type,
                'first' => 100,
                'after' => $cursor,
            ]);

            $connection = $response['body']['data']['metaobjects'] ?? [];
            $edges = $connection['edges'] ?? [];

            foreach ($edges as $edge) {
                $entries[] = $this->normalizeEntry($edge['node'] ?? []);
            }

            $cursor = (! empty($edges) && ($connection['pageInfo']['hasNextPage'] ?? false))
                ? end($edges)['cursor']
                : null;
        } while ($cursor);

        return $entries;
    }

    /**
     * @return array<int, array{id: string, type: string, fields: array<string, string>}>
     */
    protected function fetchEntriesViaBulk(string $type, array $credential): array
    {
        $query = 'query { metaobjects(type: "'.$type.'") { edges { node { id type fields { key value } } } } }';

        $response = $this->requestGraphQlApiAction('bulkOperationRunQuery', $credential, ['query' => $query]);
        $operationId = $response['body']['data']['bulkOperationRunQuery']['bulkOperation']['id'] ?? null;

        if (! $operationId) {
            return [];
        }

        $url = $this->pollBulk($credential, $operationId);

        return $url ? $this->parseBulkJsonl($url) : [];
    }

    protected function pollBulk(array $credential, string $operationId): ?string
    {
        $delay = max(1, (int) config('shopify-bulk-operations.import_poll_delay_seconds', 5));
        $deadline = time() + max(60, (int) config('shopify-bulk-operations.import_max_wait_seconds', 1800));

        while (time() < $deadline) {
            $state = $this->bulkOperationService->getOperation($credential, $operationId);
            $status = strtoupper((string) ($state['status'] ?? ''));

            if ($status === 'COMPLETED') {
                return $state['url'] ?? null;
            }

            if (in_array($status, ['FAILED', 'CANCELED', 'EXPIRED'], true)) {
                return null;
            }

            sleep($delay);
        }

        return null;
    }

    /**
     * @return array<int, array{id: string, type: string, fields: array<string, string>}>
     */
    protected function parseBulkJsonl(string $url): array
    {
        $path = 'shopify/imports/metaobjects-'.md5($url).'.jsonl';
        $this->bulkOperationService->downloadResult($url, $path);

        $disk = Storage::disk('local');

        if (! $disk->exists($path)) {
            return [];
        }

        $entries = [];

        foreach (preg_split('/\r?\n/', (string) $disk->get($path)) as $line) {
            $node = json_decode(trim($line), true);

            if (is_array($node) && ! empty($node['id'])) {
                $entries[] = $this->normalizeEntry($node);
            }
        }

        $disk->delete($path);

        return $entries;
    }

    /**
     * @return array{id: string, type: string, fields: array<string, string>}
     */
    protected function normalizeEntry(array $node): array
    {
        $fields = [];

        foreach ($node['fields'] ?? [] as $field) {
            if (isset($field['key'])) {
                $fields[$field['key']] = $field['value'] ?? null;
            }
        }

        return [
            'id' => (string) ($node['id'] ?? ''),
            'type' => (string) ($node['type'] ?? ''),
            'fields' => $fields,
        ];
    }
}
