<?php

namespace Webkul\Shopify\DataGrids\Metaobject;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;
use Webkul\Shopify\Helpers\MetaobjectFieldType;
use Webkul\Shopify\Repositories\ShopifyMetaobjectDefinitionRepository;

class MetaobjectFieldDataGrid extends DataGrid
{
    protected $primaryColumn = 'key';

    protected int $definitionId = 0;

    protected array $fields = [];

    protected array $typeLabels = [];

    /**
     * Load the definition whose JSON fields back this grid.
     */
    public function setDefinition(int $id): void
    {
        $this->definitionId = $id;

        $definition = resolve(ShopifyMetaobjectDefinitionRepository::class)->find($id);

        $this->fields = $definition?->fields ?? [];
        $this->typeLabels = MetaobjectFieldType::supportedTypes();
    }

    /**
     * Unused: prepare() is overridden to build the paginator from the definition's
     * in-memory JSON fields, so the SQL pipeline never runs. Required by the base contract.
     */
    public function prepareQueryBuilder()
    {
        return DB::table('wk_shopify_metaobject_definitions')->whereRaw('1 = 0');
    }

    public function prepareColumns(): void
    {
        $this->addColumn([
            'index'      => 'name',
            'label'      => trans('shopify::app.shopify.metaobject.field-name'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'type_label',
            'label'      => trans('shopify::app.shopify.metaobject.field-type'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => false,
            'sortable'   => true,
        ]);
    }

    public function prepareActions(): void
    {
        if (! bouncer()->hasPermission('shopify.metaobjects.edit')) {
            return;
        }

        $this->addAction([
            'index'  => 'edit',
            'icon'   => 'icon-edit',
            'title'  => trans('admin::app.catalog.attributes.index.datagrid.edit'),
            'method' => 'GET',
            'url'    => fn ($row): string => route('shopify.metaobject.field.get', [$this->definitionId, $row->key]),
        ]);

        $this->addAction([
            'index'  => 'delete',
            'icon'   => 'icon-delete',
            'title'  => trans('admin::app.catalog.attributes.index.datagrid.delete'),
            'method' => 'DELETE',
            'url'    => fn ($row): string => route('shopify.metaobject.field.destroy', [$this->definitionId, $row->key]),
        ]);
    }

    /**
     * Back the grid with the definition's in-memory fields array; the SQL pipeline
     * (setQueryBuilder/processRequest) is intentionally skipped since fields are JSON.
     */
    public function prepare(): void
    {
        $this->prepareColumns();
        $this->prepareActions();
        $this->prepareMassActions();

        $rows = collect($this->fields)->map(fn ($field): object => (object) [
            'key'        => $field['key'] ?? '',
            'name'       => $field['name'] ?? '',
            'type_label' => $this->typeLabels[$field['preset'] ?? ($field['type'] ?? '')] ?? ($field['type'] ?? ''),
        ]);

        foreach ((array) request()->input('filters', []) as $column => $values) {
            $rows = $column === 'all'
                ? $this->applySearch($rows, (array) $values)
                : $this->applyColumnFilter($rows, (string) $column, (array) $values);
        }

        $sort = request()->input('sort', []);
        $column = in_array($sort['column'] ?? '', ['name', 'type_label'], true) ? $sort['column'] : 'name';

        $rows = ($sort['order'] ?? 'asc') === 'desc'
            ? $rows->sortByDesc($column)
            : $rows->sortBy($column);

        $rows = $rows->values();

        $perPage = (int) request()->input('pagination.per_page', $this->itemsPerPage);
        $page = (int) request()->input('pagination.page', 1);

        $this->paginator = new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            ['path' => request()->url()]
        );
    }

    /**
     * Match each search term against every searchable field column.
     */
    protected function applySearch(Collection $rows, array $terms): Collection
    {
        foreach ($terms as $term) {
            $rows = $rows->filter(fn ($row): bool => stripos($row->name, (string) $term) !== false
                || stripos($row->type_label, (string) $term) !== false);
        }

        return $rows;
    }

    /**
     * Apply one filterable column's condition (operator payload or plain values) to the rows.
     */
    protected function applyColumnFilter(Collection $rows, string $column, array $values): Collection
    {
        if (! in_array($column, ['name', 'type_label'], true)) {
            return $rows;
        }

        $condition = reset($values);

        if (is_array($condition) && ! empty($condition['operator'])) {
            $value = (string) ($condition['value'] ?? '');

            return $rows->filter(fn ($row): bool => match ($condition['operator']) {
                'eq'        => (string) $row->{$column} === $value,
                'neq'       => (string) $row->{$column} !== $value,
                'blank'     => (string) $row->{$column} === '',
                'not_blank' => (string) $row->{$column} !== '',
                default     => stripos((string) $row->{$column}, $value) !== false,
            });
        }

        foreach ($values as $value) {
            $rows = $rows->filter(fn ($row): bool => stripos((string) $row->{$column}, (string) $value) !== false);
        }

        return $rows;
    }
}
