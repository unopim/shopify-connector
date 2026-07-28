<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;

class MetaobjectEntryController extends Controller
{
    public function __construct(
        protected ShopifyMetaobjectEntryRepository $entryRepository,
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

    public function store(): JsonResponse
    {
        $data = request()->validate([
            'id' => 'nullable|integer',
            'type' => 'required|string',
            'code' => 'required|string',
            'values' => 'nullable|string',
        ]);

        $values = json_decode($data['values'] ?? '[]', true);
        $values = is_array($values) ? $values : [];

        foreach ((array) request()->file('files', []) as $key => $file) {
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
        $this->entryRepository->delete($id);

        return new JsonResponse(['deleted' => true]);
    }
}
