<?php

namespace Webkul\Shopify\Services\Bulk\Metaobject;

use Webkul\Shopify\Repositories\ShopifyMetaobjectMappingRepository;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class MetaobjectEntryBuilder
{
    use ShopifyGraphqlRequest;

    protected array $cache = [];

    public function __construct(protected ShopifyMetaobjectMappingRepository $mappingRepository) {}

    public function buildEntryGid(string $type, string $sku, array $credential, callable $resolveValue): ?string
    {
        $cacheKey = ($credential['shopUrl'] ?? '').'::'.$type.'::'.$sku;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $this->cache[$cacheKey] = null;

        $mapping = $this->mappingRepository->findOneWhere([
            'api_url' => $credential['shopUrl'] ?? '',
            'type' => $type,
        ]);

        if (! $mapping) {
            return null;
        }

        $entryFields = [];

        foreach (($mapping->fields ?? []) as $field) {
            if (($field['source'] ?? '') === 'metaobject') {
                $childType = $field['child_type'] ?? '';
                $childGid = $childType !== '' ? $this->buildEntryGid($childType, $sku, $credential, $resolveValue) : null;
                $value = $childGid
                    ? (! empty($field['list']) ? json_encode([$childGid], JSON_UNESCAPED_SLASHES) : $childGid)
                    : null;
            } else {
                $value = $resolveValue($field);
            }

            if ($value !== null && $value !== '') {
                $entryFields[] = ['key' => $field['key'], 'value' => (string) $value];
            }
        }

        if (empty($entryFields)) {
            return null;
        }

        $response = $this->requestGraphQlApiAction('metaobjectUpsert', $credential, [
            'handle' => ['type' => $type, 'handle' => $type.'-'.$this->slug($sku)],
            'metaobject' => ['fields' => $entryFields],
        ]);

        $errors = $response['body']['data']['metaobjectUpsert']['userErrors'] ?? [];

        if (! empty($errors)) {
            logger()->warning('Shopify metaobjectUpsert error', ['type' => $type, 'sku' => $sku, 'errors' => $errors]);
        }

        return $this->cache[$cacheKey] = $response['body']['data']['metaobjectUpsert']['metaobject']['id'] ?? null;
    }

    protected function slug(string $value): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $value)), '-');
    }

    public function fileAttributeCodes(string $type, string $shopUrl, array $seen = []): array
    {
        if (in_array($type, $seen, true)) {
            return [];
        }

        $seen[] = $type;

        $mapping = $this->mappingRepository->findOneWhere(['api_url' => $shopUrl, 'type' => $type]);

        if (! $mapping) {
            return [];
        }

        $codes = [];

        foreach ($mapping->fields ?? [] as $field) {
            if (($field['source'] ?? '') === 'metaobject') {
                $childType = $field['child_type'] ?? '';

                if ($childType !== '') {
                    $codes = array_merge($codes, $this->fileAttributeCodes($childType, $shopUrl, $seen));
                }

                continue;
            }

            if (($field['shopify_type'] ?? '') === 'file_reference' && ! empty($field['attribute_code'])) {
                $codes[] = $field['attribute_code'];
            }
        }

        return array_values(array_unique($codes));
    }
}
