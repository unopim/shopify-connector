<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Shopify\DataGrids\Metaobject\MetaobjectDataGrid;
use Webkul\Shopify\DataGrids\Metaobject\MetaobjectFieldDataGrid;
use Webkul\Shopify\Helpers\MetaobjectFieldType;
use Webkul\Shopify\Repositories\ShopifyMetaFieldRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryMappingRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;

class MetaobjectController extends Controller
{
    public function __construct(
        protected ShopifyMetaobjectDefinitionRepository $definitionRepository,
        protected ShopifyMetaobjectEntryRepository $entryRepository,
        protected ShopifyMetaobjectAttributeRepository $attributeBindingRepository,
        protected AttributeRepository $attributeRepository,
        protected ShopifyMetaFieldRepository $metaFieldRepository,
        protected ShopifyMetaobjectEntryMappingRepository $entryMappingRepository,
    ) {}

    public function forAttribute(): JsonResponse
    {
        $binding = $this->attributeBindingRepository->bindingFor((int) request()->get('attribute_id'));

        if (! $binding) {
            return new JsonResponse(['entries' => []]);
        }

        $definition = $this->definitionRepository->find($binding->definition_id);

        $entries = $definition
            ? $this->entryRepository->findWhere(['type' => $definition->code])
                ->map(fn ($entry) => ['code' => $entry->code])
                ->values()
                ->all()
            : [];

        return new JsonResponse(['entries' => $entries]);
    }

    public function fields(int $id): JsonResponse
    {
        $definition = $this->definitionRepository->find($id);

        return new JsonResponse([
            'formFields' => $definition ? $this->normalizeFields($definition->fields ?? []) : [],
        ]);
    }

    public function index(): View|JsonResponse
    {
        if (request()->ajax()) {
            return app(MetaobjectDataGrid::class)->toJson();
        }

        return view('shopify::metaobject.index', [
            'fieldTypes' => MetaobjectFieldType::supportedTypes(),
        ]);
    }

    public function create(): View
    {
        return view('shopify::metaobject.create', [
            'fieldTypes' => MetaobjectFieldType::supportedTypes(),
        ]);
    }

    public function store(): JsonResponse
    {
        $data = request()->validate([
            'name'   => 'required|string',
            'fields' => 'nullable|array',
        ]);

        $fields = $this->buildFields($data['fields'] ?? []);

        if (empty($fields)) {
            return new JsonResponse([
                'errors' => ['fields' => [trans('shopify::app.shopify.metaobject.fields-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $definition = $this->definitionRepository->create([
            'name'   => $data['name'],
            'code'   => $this->uniqueCode($data['name']),
            'fields' => $fields,
        ]);

        return new JsonResponse([
            'id'   => $definition->id,
            'code' => $definition->code,
            'name' => $definition->name,
        ]);
    }

    public function edit(int $id): View
    {
        $definition = $this->definitionRepository->findOrFail($id);

        return view('shopify::metaobject.edit', [
            'definition'     => $definition,
            'definitionData' => $definition->only(['id', 'name', 'code', 'fields']),
            'formFields'     => $this->normalizeFields($definition->fields ?? []),
            'fieldTypes'     => MetaobjectFieldType::supportedTypes(),
            'options'        => $this->resolveOptions($definition),
        ]);
    }

    public function update(int $id): JsonResponse|RedirectResponse
    {
        $data = request()->validate([
            'name'   => 'required|string',
            'fields' => 'nullable|array',
        ]);

        $fields = $this->buildFields($data['fields'] ?? []);

        if (empty($fields)) {
            return new JsonResponse([
                'errors' => ['fields' => [trans('shopify::app.shopify.metaobject.fields-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->definitionRepository->update([
            'name'   => $data['name'],
            'fields' => $fields,
        ], $id);

        if (request()->ajax()) {
            return new JsonResponse(['message' => trans('shopify::app.shopify.metaobject.saved')]);
        }

        session()->flash('success', trans('shopify::app.shopify.metaobject.saved'));

        return redirect()->route('shopify.metaobject.edit', $id);
    }

    public function updateGeneral(int $id): JsonResponse
    {
        $name = trim((string) request()->input('name'));

        if ($name === '') {
            return new JsonResponse([
                'errors' => ['name' => [trans('shopify::app.shopify.metaobject.name-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->definitionRepository->update([
            'name'    => $name,
            'options' => [
                'active_draft'            => request()->boolean('active_draft'),
                'translations'            => request()->boolean('translations'),
                'web_pages'               => request()->boolean('web_pages'),
                'storefront_access'       => request()->boolean('storefront_access'),
                'customer_account_access' => request()->boolean('customer_account_access'),
            ],
        ], $id);

        return new JsonResponse([
            'message'      => trans('shopify::app.shopify.metaobject.saved'),
            'redirect_url' => route('shopify.metaobject.edit', $id),
        ]);
    }

    /**
     * Merge a definition's stored options with the backward-compatible defaults
     * (storefront read + active-draft on, matching the original hardcoded export).
     *
     * @return array<string, bool>
     */
    public function resolveOptions($definition): array
    {
        return array_merge([
            'active_draft'            => true,
            'translations'            => false,
            'web_pages'               => false,
            'storefront_access'       => true,
            'customer_account_access' => false,
        ], $definition->options ?? []);
    }

    public function fieldDatagrid(int $id): JsonResponse
    {
        $datagrid = app(MetaobjectFieldDataGrid::class);
        $datagrid->setDefinition($id);

        return $datagrid->toJson();
    }

    public function fieldGet(int $id, string $key): JsonResponse
    {
        $definition = $this->definitionRepository->findOrFail($id);

        $field = collect($this->normalizeFields($definition->fields ?? []))->firstWhere('key', $key);

        abort_if(! $field, JsonResponse::HTTP_NOT_FOUND);

        return new JsonResponse(['field' => $field]);
    }

    public function fieldStore(int $id): JsonResponse
    {
        $definition = $this->definitionRepository->findOrFail($id);
        $fields = $definition->fields ?? [];

        $built = $this->buildFields([request()->all()]);

        if (empty($built)) {
            return new JsonResponse([
                'errors' => ['name' => [trans('shopify::app.shopify.metaobject.fields-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $field = $built[0];
        $field['key'] = $this->uniqueKey($field['name'], array_column($fields, 'key'));
        $fields[] = $field;

        $this->definitionRepository->update(['fields' => $fields], $id);

        return new JsonResponse(['message' => trans('shopify::app.shopify.metaobject.field-saved')]);
    }

    public function fieldUpdate(int $id, string $key): JsonResponse
    {
        $definition = $this->definitionRepository->findOrFail($id);
        $fields = $definition->fields ?? [];

        $index = collect($fields)->search(fn ($field): bool => ($field['key'] ?? '') === $key);

        abort_if($index === false, JsonResponse::HTTP_NOT_FOUND);

        $built = $this->buildFields([request()->all()]);

        if (empty($built)) {
            return new JsonResponse([
                'errors' => ['name' => [trans('shopify::app.shopify.metaobject.fields-required')]],
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $field = $built[0];
        $field['key'] = $key;
        $fields[$index] = $field;

        $this->definitionRepository->update(['fields' => array_values($fields)], $id);

        return new JsonResponse(['message' => trans('shopify::app.shopify.metaobject.field-saved')]);
    }

    public function fieldDestroy(int $id, string $key): JsonResponse
    {
        $definition = $this->definitionRepository->findOrFail($id);
        $fields = $definition->fields ?? [];

        if (count($fields) <= 1) {
            return new JsonResponse([
                'message' => trans('shopify::app.shopify.metaobject.last-field'),
            ], JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $fields = array_values(array_filter($fields, fn ($field): bool => ($field['key'] ?? '') !== $key));

        $this->definitionRepository->update(['fields' => $fields], $id);

        return new JsonResponse(['message' => trans('shopify::app.shopify.metaobject.field-deleted')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->deleteDefinition($id);

        return new JsonResponse(['message' => trans('shopify::app.shopify.metaobject.deleted')]);
    }

    public function massDestroy(): JsonResponse
    {
        foreach ((array) request()->input('indices', []) as $id) {
            $this->deleteDefinition((int) $id);
        }

        return new JsonResponse(['message' => trans('shopify::app.shopify.metaobject.deleted')]);
    }

    /**
     * Remove a definition together with its entries, the bound attribute and any
     * metaobject_reference metafield pointing at that attribute.
     */
    protected function deleteDefinition(int $id): void
    {
        $definition = $this->definitionRepository->find($id);

        if (! $definition) {
            return;
        }

        $entryIds = $this->entryRepository->findWhere(['type' => $definition->code])->pluck('id')->all();
        $this->entryMappingRepository->deleteForEntries($entryIds);
        $this->entryRepository->deleteWhere(['type' => $definition->code]);

        foreach ($this->attributeBindingRepository->bindingsForDefinition($id) as $binding) {
            $attribute = $this->attributeRepository->find($binding->attribute_id);

            if (! $attribute) {
                continue;
            }

            $this->metaFieldRepository->deleteWhere([
                ['code', '=', $attribute->code],
                ['type', '=', 'metaobject_reference'],
            ]);

            Event::dispatch('catalog.attribute.delete.before', $attribute->id);
            $this->attributeRepository->delete($attribute->id);
            Event::dispatch('catalog.attribute.delete.after', $attribute->id);
        }

        $this->attributeBindingRepository->deleteForDefinition($id);
        $this->definitionRepository->delete($id);
    }

    public function definitions(): JsonResponse
    {
        $exclude = (int) request()->get('exclude');

        $options = $this->definitionRepository->all(['id', 'name', 'code'])
            ->filter(fn ($definition) => $definition->id !== $exclude)
            ->map(fn ($definition) => ['id' => $definition->id, 'code' => $definition->code, 'name' => $definition->name])
            ->values()
            ->all();

        return new JsonResponse(['options' => $options]);
    }

    protected function uniqueCode(string $name): string
    {
        $base = Str::slug($name, '_') ?: 'metaobject';
        $code = $base;
        $suffix = 1;

        while ($this->definitionRepository->findOneWhere(['code' => $code])) {
            $code = $base.'_'.(++$suffix);
        }

        return $code;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    protected function buildFields(array $fields): array
    {
        $built = [];
        $usedKeys = [];

        foreach ($fields as $field) {
            $name = trim($field['name'] ?? '');
            $type = $field['type'] ?? '';

            if ($name === '' || $type === '') {
                continue;
            }

            $key = $this->uniqueKey($name, $usedKeys);
            $usedKeys[] = $key;

            $preset = null;
            $contentType = $field['content_type'] ?? '';

            if ($type === 'email') {
                $preset = 'email';
                $type = 'single_line_text_field';
            } elseif ($type === 'choice_list') {
                $preset = 'choice_list';
                $type = 'single_line_text_field';
            } elseif ($type === 'image') {
                $preset = 'image';
                $type = 'file_reference';
                $contentType = 'IMAGE';
            } elseif ($type === 'video') {
                $preset = 'video';
                $type = 'file_reference';
                $contentType = 'VIDEO';
            } elseif ($type === 'file_reference') {
                $contentType = 'FILE';
            }

            $built[] = array_filter([
                'key'          => $key,
                'name'         => $name,
                'type'         => $type,
                'list'         => ! empty($field['list']),
                'required'     => ! empty($field['required']),
                'child'        => $type === 'metaobject_reference' ? ($field['child'] ?? '') : '',
                'preset'       => $preset,
                'content_type' => $type === 'file_reference' ? $contentType : '',
                'validations'  => $this->cleanValidations($field['validations'] ?? []),
            ], fn ($value) => $value !== '' && $value !== null && $value !== []);
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $validations
     * @return array<string, mixed>
     */
    protected function cleanValidations(array $validations): array
    {
        $clean = [];

        foreach (['min', 'max', 'unit', 'max_precision', 'regex', 'choices', 'file_mode'] as $key) {
            if (($validations[$key] ?? '') !== '' && ($validations[$key] ?? null) !== null) {
                $clean[$key] = $validations[$key];
            }
        }

        return $clean;
    }

    protected function uniqueKey(string $name, array $usedKeys): string
    {
        $base = Str::slug($name, '_') ?: 'field';
        $key = $base;
        $suffix = 1;

        while (in_array($key, $usedKeys, true)) {
            $key = $base.'_'.(++$suffix);
        }

        return $key;
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    protected function normalizeFields(array $fields): array
    {
        return array_map(fn ($field) => [
            'key'          => $field['key'] ?? '',
            'name'         => $field['name'] ?? '',
            'shopify_type' => $field['type'] ?? '',
            'source'       => ($field['type'] ?? '') === 'metaobject_reference' ? 'metaobject' : 'attribute',
            'list'         => ! empty($field['list']),
            'required'     => ! empty($field['required']),
            'child_type'   => $field['child'] ?? '',
            'content_type' => $field['content_type'] ?? '',
            'preset'       => $field['preset'] ?? '',
            'validations'  => $field['validations'] ?? [],
        ], $fields);
    }
}
