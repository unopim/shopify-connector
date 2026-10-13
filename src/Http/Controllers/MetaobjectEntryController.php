<?php

namespace Webkul\Shopify\Http\Controllers;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Shopify\DataGrids\Metaobject\MetaobjectEntryDataGrid;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;
use Webkul\Shopify\Repositories\ShopifyMetaobjectEntryRepository;

class MetaobjectEntryController extends Controller
{
    protected const MAX_UPLOAD_KILOBYTES = 20480;

    protected const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    protected const VIDEO_EXTENSIONS = ['mp4', 'mov', 'webm'];

    protected const DOCUMENT_EXTENSIONS = ['pdf', 'csv', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'zip'];

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
        $type = (string) request()->input('type');

        if ($type === '') {
            return new JsonResponse(['entries' => []]);
        }

        $entries = $this->entryRepository->findWhere(['type' => $type])
            ->map(fn ($entry): array => ['id' => $entry->id, 'code' => $entry->code, 'values' => $entry->values ?? []])
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
        $query = strtolower((string) request()->input('query', ''));

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
        abort_unless(bouncer()->hasPermission('shopify.metaobjects.edit.edit'), 403);

        $entry = $this->entryRepository->find($id);

        abort_unless($entry, 404);

        return new JsonResponse(['id' => $entry->id, 'code' => $entry->code, 'values' => $entry->values ?? []]);
    }

    public function store(): JsonResponse
    {
        $data = request()->validate([
            'id'     => ['nullable', 'integer'],
            'type'   => ['required', 'string'],
            'code'   => ['required', 'string'],
            'values' => ['nullable', 'string'],
        ]);

        if (! empty($data['id'])) {
            abort_unless(bouncer()->hasPermission('shopify.metaobjects.edit.edit'), 403);

            $entry = $this->entryRepository->find((int) $data['id']);

            abort_unless($entry && $entry->type === $data['type'], 404);
        } else {
            abort_unless(bouncer()->hasPermission('shopify.metaobjects.edit.create'), 403);
        }

        $duplicate = $this->entryRepository->findOneWhere(['type' => $data['type'], 'code' => $data['code']]);

        if ($duplicate && (int) $duplicate->id !== (int) ($data['id'] ?? 0)) {
            throw ValidationException::withMessages([
                'code' => trans('shopify::app.shopify.metaobject.entry-code-taken'),
            ]);
        }

        $this->validateFiles($data['type']);

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

        try {
            if (! empty($data['id'])) {
                $this->entryRepository->update(['code' => $data['code'], 'values' => $values], (int) $data['id']);

                return new JsonResponse(['saved' => true]);
            }

            $this->entryRepository->create(['type' => $data['type'], 'code' => $data['code'], 'values' => $values]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => trans('shopify::app.shopify.metaobject.entry-code-taken'),
            ]);
        }

        return new JsonResponse(['saved' => true]);
    }

    /**
     * Validate uploads against the file fields of the entry's definition. Files are
     * served from the public disk, so only known media and document types are allowed.
     *
     * @throws ValidationException
     */
    protected function validateFiles(string $type): void
    {
        $files = (array) request()->file('files', []);

        if ($files === []) {
            return;
        }

        $fileFields = collect($this->definitionRepository->findOneByField('code', $type)?->fields ?? [])
            ->filter(fn (array $field): bool => ($field['type'] ?? '') === 'file_reference')
            ->keyBy('key');

        $rules = [];

        foreach ($files as $key => $file) {
            $field = $fileFields->get($key);

            if (! $field) {
                throw ValidationException::withMessages([
                    'files.'.$key => trans('shopify::app.shopify.metaobject.entry-file-invalid'),
                ]);
            }

            $extensions = implode(',', $this->allowedExtensions($field));

            $rules[is_array($file) ? 'files.'.$key.'.*' : 'files.'.$key] = [
                'file',
                'max:'.self::MAX_UPLOAD_KILOBYTES,
                'mimes:'.$extensions,
                'extensions:'.$extensions,
            ];
        }

        request()->validate($rules);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, string>
     */
    protected function allowedExtensions(array $field): array
    {
        $isMediaOnly = ($field['validations']['file_mode'] ?? 'any') === 'media';

        return match ($field['content_type'] ?? 'FILE') {
            'IMAGE' => self::IMAGE_EXTENSIONS,
            'VIDEO' => self::VIDEO_EXTENSIONS,
            default => $isMediaOnly
                ? [...self::IMAGE_EXTENSIONS, ...self::VIDEO_EXTENSIONS]
                : [...self::IMAGE_EXTENSIONS, ...self::VIDEO_EXTENSIONS, ...self::DOCUMENT_EXTENSIONS],
        };
    }

    public function delete(int $id): JsonResponse
    {
        abort_unless(bouncer()->hasPermission('shopify.metaobjects.edit.delete'), 403);

        $this->entryRepository->delete($id);

        return new JsonResponse(['deleted' => true]);
    }
}
