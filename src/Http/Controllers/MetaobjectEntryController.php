<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\DataGrids\Metaobject\MetaobjectEntryDataGrid;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;

class MetaobjectEntryController extends Controller
{
    protected array $referenceTypes = [
        'metaobject_reference',
        'product_reference',
        'variant_reference',
        'collection_reference',
    ];

    public function __construct(
        protected ShopifyMetaobjectEntryRepository $entryRepository,
        protected ShopifyMetaobjectDefinitionRepository $definitionRepository,
    ) {}

    public function list(): JsonResponse
    {
        $type = (string) request()->get('type');

        if ($type === '') {
            return new JsonResponse(['entries' => []]);
        }

        $entries = $this->entryRepository->findWhere(['type' => $type])
            ->map(fn ($entry) => ['id' => $entry->id, 'code' => $entry->code, 'values' => $entry->values ?? []])
            ->values()
            ->all();

        return new JsonResponse(['entries' => $entries]);
    }

    public function datagrid(string $type): JsonResponse
    {
        $datagrid = resolve(MetaobjectEntryDataGrid::class);
        $datagrid->setType($type);

        return $datagrid->toJson();
    }

    public function columns(string $type): JsonResponse
    {
        $definition = $this->definitionRepository->findOneByField('code', $type);
        $query = strtolower((string) request()->get('query', ''));

        $options = [];

        foreach ($definition?->fields ?? [] as $field) {
            if (in_array($field['type'] ?? '', $this->referenceTypes, true)) {
                continue;
            }

            $label = $field['name'] ?? ($field['key'] ?? '');

            if ($query !== '' && ! str_contains(strtolower($label), $query)) {
                continue;
            }

            $options[] = ['code' => 'field_'.($field['key'] ?? ''), 'label' => $label];
        }

        return new JsonResponse(['options' => $options, 'page' => 1, 'lastPage' => 1]);
    }

    public function get(int $id): JsonResponse
    {
        $entry = $this->entryRepository->find($id);

        if (! $entry) {
            abort(404);
        }

        return new JsonResponse(['id' => $entry->id, 'code' => $entry->code, 'values' => $entry->values ?? []]);
    }

    public function store(): JsonResponse
    {
        if (! bouncer()->hasPermission('shopify.metaobjects.entry-save')) {
            abort(403);
        }

        $data = request()->validate([
            'id'     => 'nullable|integer',
            'type'   => 'required|string',
            'code'   => 'required|string',
            'values' => 'nullable|string',
        ]);

        $values = json_decode($data['values'] ?? '[]', true);
        $values = is_array($values) ? $values : [];

        foreach ((array) request()->file('files', []) as $key => $file) {
            if (is_array($file)) {
                $stored = array_map(fn ($one) => $one->store('shopify/metaobject-entries', 'public'), $file);
                $existing = isset($values[$key]) && is_array($values[$key]) ? $values[$key] : [];
                $values[$key] = array_values(array_merge($existing, $stored));

                continue;
            }

            $values[$key] = $file->store('shopify/metaobject-entries', 'public');
        }

        if (! empty($data['id'])) {
            $this->entryRepository->update(['code' => $data['code'], 'values' => $values], (int) $data['id']);

            return new JsonResponse(['saved' => true]);
        }

        $this->entryRepository->updateOrCreateByCode($data['type'], $data['code'], $values);

        return new JsonResponse(['saved' => true]);
    }

    public function delete(int $id): JsonResponse
    {
        if (! bouncer()->hasPermission('shopify.metaobjects.entry-delete')) {
            abort(403);
        }

        $this->entryRepository->delete($id);

        return new JsonResponse(['deleted' => true]);
    }
}
