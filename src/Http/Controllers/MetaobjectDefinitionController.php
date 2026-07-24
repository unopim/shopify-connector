<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Shopify\Helpers\MetaobjectFieldType;
use Webkul\Shopify\Helpers\ShoifyMetaFieldType;
use Webkul\Shopify\Repositories\ShopifyCredentialRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectMappingRepository;
use Webkul\Shopify\Services\ShopifyClientFactory;
use Webkul\Shopify\Traits\ShopifyGraphqlRequest;

class MetaobjectDefinitionController extends Controller
{
    use ShopifyGraphqlRequest;

    public function __construct(
        protected ShopifyCredentialRepository $shopifyRepository,
        protected ShopifyClientFactory $clientFactory,
        protected AttributeRepository $attributeRepository,
        protected ShopifyMetaobjectMappingRepository $mappingRepository,
    ) {}

    protected function contentTypesMap(): array
    {
        return (new ShoifyMetaFieldType)->getMetaFieldType();
    }

    public function store(): JsonResponse
    {
        $credential = $this->activeCredential();

        if (! $credential) {
            return new JsonResponse([
                'errors' => ['credential' => [trans('shopify::app.shopify.metafield.index.metaobject-manual-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = request()->all();

        [$fieldDefinitions, $mappingFields] = $this->buildDefinition($data);

        if (empty($fieldDefinitions)) {
            return new JsonResponse([
                'errors' => ['fields' => [trans('shopify::app.shopify.metafield.index.metaobject-fields-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $name = trim($data['name'] ?? '');
        $options = $data['options'] ?? [];

        $definition = array_filter([
            'name' => $name,
            'type' => $this->slug($name, '-'),
            'capabilities' => [
                'publishable' => ['enabled' => ! empty($options['publishable'])],
                'translatable' => ['enabled' => ! empty($options['translatable'])],
                'onlineStore' => ['enabled' => ! empty($options['onlineStore'])],
            ],
            'access' => [
                'storefront' => ! empty($options['storefront']) ? 'PUBLIC_READ' : 'NONE',
                'customerAccount' => ! empty($options['customerAccount']) ? 'READ' : 'NONE',
            ],
            'fieldDefinitions' => $fieldDefinitions,
        ], fn ($value) => $value !== '' && $value !== []);

        $response = $this->requestGraphQlApiAction('metaobjectDefinitionCreate', $credential->toApiArray(), ['definition' => $definition]);
        $payload = $response['body']['data']['metaobjectDefinitionCreate'] ?? [];

        if (! empty($payload['userErrors'])) {
            return new JsonResponse([
                'errors' => ['shopify' => array_column($payload['userErrors'], 'message')],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $created = $payload['metaobjectDefinition'] ?? [];
        $type = $created['type'] ?? '';
        $gid = $created['id'] ?? '';

        $this->mappingRepository->updateOrCreateByType($credential->shopUrl, $type, [
            'gid' => $gid,
            'name' => $created['name'] ?? $name,
            'fields' => $mappingFields,
        ]);

        return new JsonResponse(['definition' => [
            'id' => $type.'|'.$gid,
            'label' => $created['name'] ?? $type,
        ]]);
    }

    public function fields(): JsonResponse
    {
        $credential = $this->activeCredential();

        if (! $credential) {
            return new JsonResponse(['fields' => []]);
        }

        $type = explode('|', (string) request()->get('definition'), 2)[0];

        if ($type === '') {
            return new JsonResponse(['fields' => []]);
        }

        $response = $this->requestGraphQlApiAction('metaobjectDefinitionByType', $credential->toApiArray(), ['type' => $type]);
        $definition = $response['body']['data']['metaobjectDefinitionByType'] ?? [];

        $existing = $this->mappingRepository->findWhere(['api_url' => $credential->shopUrl, 'type' => $type])->first();
        $mapped = [];

        foreach (($existing->fields ?? []) as $field) {
            $mapped[$field['key']] = $field;
        }

        $labels = $this->attributeLabels($mapped);
        $fields = [];

        foreach (($definition['fieldDefinitions'] ?? []) as $fieldDefinition) {
            [$source, $base, $list, $as] = $this->deriveSource($fieldDefinition['type']['name'] ?? '');

            if ($source === 'unsupported') {
                continue;
            }

            $previous = $mapped[$fieldDefinition['key']] ?? [];

            $childGid = '';
            if ($source === 'metaobject') {
                foreach ($fieldDefinition['validations'] ?? [] as $validation) {
                    if (($validation['name'] ?? '') === 'metaobject_definition_id') {
                        $childGid = (string) ($validation['value'] ?? '');
                        break;
                    }
                    if (($validation['name'] ?? '') === 'metaobject_definition_ids') {
                        $ids = json_decode((string) ($validation['value'] ?? '[]'), true);
                        $childGid = is_array($ids) ? (string) ($ids[0] ?? '') : '';
                        break;
                    }
                }
            }

            $fields[] = [
                'key' => $fieldDefinition['key'],
                'name' => $fieldDefinition['name'] ?? $fieldDefinition['key'],
                'shopify_type' => $base,
                'source' => $source,
                'list' => $list,
                'as' => $as,
                'attribute_code' => $previous['attribute_code'] ?? '',
                'attribute_label' => $labels[$previous['attribute_code'] ?? ''] ?? '',
                'assoc_type' => $previous['assoc_type'] ?? 'related_products',
                'child' => isset($previous['child_type']) ? $previous['child_type'].'|' : '',
                'child_gid' => $childGid,
            ];
        }

        return new JsonResponse([
            'type' => $type,
            'name' => $definition['name'] ?? $type,
            'fields' => $fields,
        ]);
    }

    public function map(): JsonResponse
    {
        $credential = $this->activeCredential();

        if (! $credential) {
            return new JsonResponse([
                'errors' => ['credential' => [trans('shopify::app.shopify.metafield.index.metaobject-manual-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = request()->all();
        [$type, $gid] = array_pad(explode('|', (string) ($data['definition'] ?? ''), 2), 2, null);

        if (! $type) {
            return new JsonResponse([
                'errors' => ['fields' => [trans('shopify::app.shopify.metafield.index.metaobject-fields-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $mappingFields = [];

        foreach ($data['fields'] ?? [] as $field) {
            $key = $field['key'] ?? '';

            if ($key === '') {
                continue;
            }

            $source = $field['source'] ?? 'attribute';
            $entry = ['key' => $key, 'name' => $field['name'] ?? $key, 'source' => $source, 'list' => ! empty($field['list'])];

            if ($source === 'association') {
                $entry['assoc_type'] = $field['assoc_type'] ?? 'related_products';
                $entry['as'] = $field['as'] ?? 'product';
            } elseif ($source === 'categories') {
                $entry['as'] = 'collection';
            } elseif ($source === 'metaobject') {
                $entry['child_type'] = explode('|', (string) ($field['child'] ?? ''), 2)[0];
            } else {
                $entry['attribute_code'] = $field['attribute_code'] ?? '';
                $entry['shopify_type'] = $field['shopify_type'] ?? '';
            }

            $mappingFields[] = $entry;
        }

        $this->syncDefinition($credential->toApiArray(), $type, $gid ?? '', $mappingFields);

        $this->mappingRepository->updateOrCreateByType($credential->shopUrl, $type, [
            'gid' => $gid ?? '',
            'name' => $data['name'] ?? $type,
            'fields' => $mappingFields,
        ]);

        return new JsonResponse(['saved' => true]);
    }

    protected function syncDefinition(array $credential, string $type, string $gid, array $mappingFields): void
    {
        if ($gid === '' || empty($mappingFields)) {
            return;
        }

        $response = $this->requestGraphQlApiAction('metaobjectDefinitionByType', $credential, ['type' => $type]);
        $existing = [];

        foreach ($response['body']['data']['metaobjectDefinitionByType']['fieldDefinitions'] ?? [] as $fieldDefinition) {
            $existing[$fieldDefinition['key']] = $fieldDefinition;
        }

        $operations = [];

        foreach ($mappingFields as $field) {
            $key = $field['key'] ?? '';

            if ($key === '') {
                continue;
            }

            $type_ = $this->shopifyFieldType($field);
            $name = $field['name'] ?? $key;

            if (! isset($existing[$key])) {
                $operations[] = ['create' => ['key' => $key, 'name' => $name, 'type' => $type_]];

                continue;
            }

            if (($existing[$key]['type']['name'] ?? '') !== $type_) {
                logger()->warning('Shopify metaobject field type changed, skipped', [
                    'type' => $type,
                    'key' => $key,
                    'shopify' => $existing[$key]['type']['name'] ?? '',
                    'mapped' => $type_,
                ]);

                continue;
            }

            if (($existing[$key]['name'] ?? '') !== $name) {
                $operations[] = ['update' => ['key' => $key, 'name' => $name]];
            }
        }

        if (empty($operations)) {
            return;
        }

        $update = $this->requestGraphQlApiAction('metaobjectDefinitionUpdate', $credential, [
            'id' => $gid,
            'definition' => ['fieldDefinitions' => $operations],
        ]);

        $errors = $update['body']['data']['metaobjectDefinitionUpdate']['userErrors'] ?? [];

        if (! empty($errors)) {
            logger()->warning('Shopify metaobjectDefinitionUpdate error', ['type' => $type, 'errors' => $errors]);
        }
    }

    protected function shopifyFieldType(array $field): string
    {
        $base = $field['shopify_type'] ?? '';

        if ($base === '') {
            $base = match ($field['source'] ?? 'attribute') {
                'association' => ($field['as'] ?? 'product') === 'variant' ? 'variant_reference' : 'product_reference',
                'categories' => 'collection_reference',
                'metaobject' => 'metaobject_reference',
                default => 'single_line_text_field',
            };
        }

        return ! empty($field['list']) ? 'list.'.$base : $base;
    }

    protected function attributeLabels(array $mapped): array
    {
        $codes = [];

        foreach ($mapped as $field) {
            if (($field['source'] ?? '') === 'attribute' && ! empty($field['attribute_code'])) {
                $codes[] = $field['attribute_code'];
            }
        }

        if (empty($codes)) {
            return [];
        }

        $labels = [];

        foreach ($this->attributeRepository->findWhereIn('code', array_unique($codes)) as $attribute) {
            $labels[$attribute->code] = $attribute->name ?: $attribute->code;
        }

        return $labels;
    }

    protected function deriveSource(string $shopifyType): array
    {
        $list = str_starts_with($shopifyType, 'list.');
        $base = $list ? substr($shopifyType, 5) : $shopifyType;

        return match ($base) {
            'product_reference' => ['association', $base, $list, 'product'],
            'variant_reference' => ['association', $base, $list, 'variant'],
            'collection_reference' => ['categories', $base, $list, 'collection'],
            'metaobject_reference' => ['metaobject', $base, $list, ''],
            'file_reference' => ['attribute', $base, $list, ''],
            default => [str_ends_with($base, '_reference') ? 'unsupported' : 'attribute', $base, $list, ''],
        };
    }

    protected function buildDefinition(array $data): array
    {
        $fieldDefinitions = [];
        $mappingFields = [];
        $usedKeys = [];

        foreach ($data['fields'] ?? [] as $field) {
            $source = $field['source'] ?? 'attribute';

            $resolved = $this->resolveField($source, $field);

            if (! $resolved) {
                continue;
            }

            $name = trim($field['name'] ?? '') ?: $resolved['label'];
            $key = $this->uniqueKey($name, $usedKeys);

            if ($key === '') {
                continue;
            }

            $usedKeys[] = $key;
            $list = ! empty($field['list']);

            $entry = [
                'key' => $key,
                'name' => $name,
                'type' => $list ? 'list.'.$resolved['type'] : $resolved['type'],
                'required' => ! empty($field['required']),
            ];

            if ($source === 'metaobject' && ! empty($field['child_definition_id'])) {
                $entry['validations'] = [['name' => 'metaobject_definition_id', 'value' => $field['child_definition_id']]];
            }

            $fieldDefinitions[] = $entry;
            $mappingFields[] = array_merge(['key' => $key, 'name' => $name, 'source' => $source, 'list' => $list], $resolved['store']);
        }

        return [$fieldDefinitions, $mappingFields];
    }

    protected function resolveField(string $source, array $field): ?array
    {
        if ($source === 'attribute') {
            $code = $field['attribute_code'] ?? '';
            $attribute = $code ? $this->attributeRepository->findByField('code', $code)->first() : null;

            if (! $attribute) {
                return null;
            }

            $allowed = array_column($this->contentTypesMap()[$attribute->type] ?? [], 'id');
            $type = $field['shopify_type'] ?? '';

            if (! in_array($type, $allowed, true)) {
                $type = MetaobjectFieldType::fromAttributeType($attribute->type);
            }

            return [
                'type' => $type,
                'label' => $attribute->name ?: $code,
                'store' => ['attribute_code' => $code, 'shopify_type' => $type],
            ];
        }

        if ($source === 'association') {
            $as = ($field['as'] ?? 'product') === 'variant' ? 'variant_reference' : 'product_reference';

            return [
                'type' => $as,
                'label' => 'Association',
                'store' => ['assoc_type' => $field['assoc_type'] ?? 'related_products', 'as' => $field['as'] ?? 'product'],
            ];
        }

        if ($source === 'categories') {
            return ['type' => 'collection_reference', 'label' => 'Categories', 'store' => ['as' => 'collection']];
        }

        if ($source === 'metaobject' && ! empty($field['child_type'])) {
            return ['type' => 'metaobject_reference', 'label' => 'Metaobject', 'store' => ['child_type' => $field['child_type']]];
        }

        return null;
    }

    protected function uniqueKey(string $name, array $used): string
    {
        $base = $this->slug($name, '_');

        if ($base === '') {
            return '';
        }

        $key = $base;
        $suffix = 2;

        while (in_array($key, $used, true)) {
            $key = $base.'_'.$suffix++;
        }

        return $key;
    }

    protected function slug(string $name, string $separator): string
    {
        return trim(strtolower(preg_replace('/[^a-zA-Z0-9]+/', $separator, $name)), $separator);
    }

    protected function activeCredential()
    {
        $credentials = $this->shopifyRepository->findWhere([['active', '=', 1]]);

        return $credentials->first(fn ($item) => ! empty($item->extras['saas'])) ?? $credentials->first();
    }
}
