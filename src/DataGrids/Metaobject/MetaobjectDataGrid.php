<?php

namespace Webkul\Shopify\DataGrids\Metaobject;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class MetaobjectDataGrid extends DataGrid
{
    /**
     * @return Builder
     */
    public function prepareQueryBuilder()
    {
        $entriesCount = DB::table('wk_shopify_metaobject_entries')
            ->whereColumn('wk_shopify_metaobject_entries.type', 'wk_shopify_metaobject_definitions.code')
            ->selectRaw('count(*)');

        return DB::table('wk_shopify_metaobject_definitions')
            ->select('id', 'name', 'code', 'created_at')
            ->selectSub($entriesCount, 'entries_count');
    }

    /**
     * @return void
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'name',
            'label'      => trans('shopify::app.shopify.metaobject.datagrid.name'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'code',
            'label'      => trans('shopify::app.shopify.metaobject.datagrid.code'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        $this->addColumn([
            'index'      => 'entries_count',
            'label'      => trans('shopify::app.shopify.metaobject.datagrid.entries'),
            'type'       => 'string',
            'searchable' => false,
            'filterable' => false,
            'sortable'   => false,
        ]);
    }

    /**
     * @return void
     */
    public function prepareActions()
    {
        if (bouncer()->hasPermission('shopify.metaobjects.edit')) {
            $this->addAction([
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => function ($row) {
                    return route('shopify.metaobject.edit', $row->id);
                },
            ]);
        }

        if (bouncer()->hasPermission('shopify.metaobjects.delete')) {
            $this->addAction([
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => function ($row) {
                    return route('shopify.metaobject.destroy', $row->id);
                },
            ]);
        }
    }

    /**
     * @return void
     */
    public function prepareMassActions()
    {
        if (bouncer()->hasPermission('shopify.metaobjects.delete')) {
            $this->addMassAction([
                'title'   => trans('admin::app.catalog.attributes.index.datagrid.delete'),
                'url'     => route('shopify.metaobject.mass_delete'),
                'method'  => 'POST',
                'options' => ['actionType' => 'delete'],
            ]);
        }
    }
}
