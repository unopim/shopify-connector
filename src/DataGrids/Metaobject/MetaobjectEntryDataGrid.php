<?php

namespace Webkul\Shopify\DataGrids\Metaobject;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Webkul\DataGrid\DataGrid;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;

class MetaobjectEntryDataGrid extends DataGrid
{
    protected $primaryColumn = 'code';

    protected $type;

    protected array $fields = [];

    protected array $managedColumns = [];

    protected array $referenceTypes = [
        'metaobject_reference',
        'product_reference',
        'variant_reference',
        'collection_reference',
    ];

    /**
     * Set the metaobject type whose entries are listed and load its fields.
     */
    public function setType($type): void
    {
        $this->type = $type;

        $definition = resolve(ShopifyMetaobjectDefinitionRepository::class)->findOneByField('code', $type);

        $this->fields = $definition?->fields ?? [];
    }

    /**
     * Prepare the query builder.
     *
     * @return Builder
     */
    public function prepareQueryBuilder()
    {
        return DB::table('wk_shopify_metaobject_entries')
            ->select('id', 'code', 'values')
            ->where('type', $this->type);
    }

    /**
     * Prepare columns: code plus one column per non-reference field.
     */
    public function prepareColumns(): void
    {
        $managed = request()->input('managedColumns', []);
        $this->managedColumns = is_array($managed) ? $managed : [];
        $useManaged = $this->managedColumns !== [];

        $this->addColumn([
            'index'      => 'code',
            'label'      => trans('shopify::app.shopify.metaobject.datagrid.code'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        foreach ($this->fields as $field) {
            if (in_array($field['type'] ?? '', $this->referenceTypes, true)) {
                continue;
            }

            $key = $field['key'] ?? '';
            $index = 'field_'.$key;
            $isImage = ($field['type'] ?? '') === 'file_reference'
                && (($field['content_type'] ?? '') === 'IMAGE' || ($field['preset'] ?? '') === 'image');

            $this->addColumn([
                'index'      => $index,
                'label'      => $field['name'] ?? $key,
                'type'       => $isImage ? 'image' : 'string',
                'searchable' => false,
                'filterable' => false,
                'sortable'   => false,
                'visible'    => $useManaged
                    ? in_array($index, $this->managedColumns, true)
                    : (($field['type'] ?? '') === 'single_line_text_field' || $isImage),
                'closure'    => fn ($row): string => $isImage
                    ? $this->imageUrl($this->fieldValue($row, $key))
                    : $this->formatValue($this->fieldValue($row, $key)),
            ]);
        }
    }

    /**
     * Advertise the built-in Manage Columns toolbar backed by our own column route.
     */
    public function formatData(): array
    {
        $data = parent::formatData();

        $data['meta']['managedColumn'] = [
            'enabled' => true,
            'columns' => $this->managedColumns,
            'route'   => route('shopify.metaobject.entry.columns', $this->type),
        ];

        return $data;
    }

    /**
     * Prepare actions.
     */
    public function prepareActions(): void
    {
        if (bouncer()->hasPermission('shopify.metaobjects.entry-save')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => fn ($row): string => route('shopify.metaobject.entry.get', $row->id),
            ]);
        }

        if (bouncer()->hasPermission('shopify.metaobjects.entry-delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => fn ($row): string => route('shopify.metaobject.entry.delete', $row->id),
            ]);
        }
    }

    /**
     * Pull a single field's value out of the row's JSON values.
     */
    protected function fieldValue($row, string $key): mixed
    {
        $values = is_array($row->values) ? $row->values : (json_decode((string) $row->values, true) ?: []);

        return $values[$key] ?? null;
    }

    /**
     * Resolve an image field value to a browser URL (empty for unresolvable refs).
     */
    protected function imageUrl(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[0] ?? '';
        }

        $value = (string) $value;

        if ($value === '' || str_starts_with($value, 'gid://')) {
            return '';
        }

        return str_starts_with($value, 'http') ? $value : Storage::disk('public')->url($value);
    }

    /**
     * Build a short, escaped display string for a non-image field value.
     */
    protected function formatValue(mixed $value): string
    {
        if ($value === null || $value === '' || $value === []) {
            return '-';
        }

        if (is_bool($value)) {
            return $value ? trans('admin::app.common.yes') : trans('admin::app.common.no');
        }

        if (is_array($value)) {
            if (isset($value['text']) || isset($value['url'])) {
                return e(Str::limit((string) ($value['text'] ?? $value['url']), 60));
            }

            $scalars = array_filter($value, 'is_scalar');

            return $scalars === []
                ? trans('shopify::app.shopify.metaobject.datagrid.complex')
                : e(Str::limit(implode(', ', $scalars), 60));
        }

        return e(Str::limit((string) $value, 60));
    }
}
