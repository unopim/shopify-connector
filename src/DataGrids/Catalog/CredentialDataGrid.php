<?php

namespace Webkul\Shopify\DataGrids\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class CredentialDataGrid extends DataGrid
{
    protected bool $hasSaasCredential;

    public function __construct()
    {
        $this->hasSaasCredential = DB::table('wk_shopify_credentials_config')
            ->whereJsonContains('extras->saas', true)
            ->exists();
    }

    /**
     * Prepare query builder.
     *
     * @return Builder
     */
    public function prepareQueryBuilder()
    {
        $queryBuilder = DB::table('wk_shopify_credentials_config')
            ->select(
                'id',
                'shopUrl',
                'apiVersion',
                'active',
                'extras'
            );

        return $queryBuilder;
    }

    /**
     * Add columns.
     *
     * @return void
     */
    public function prepareColumns()
    {
        $this->addColumn([
            'index'      => 'shopUrl',
            'label'      => trans('shopify::app.shopify.credential.datagrid.shopUrl'),
            'type'       => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
        ]);

        if (! $this->hasSaasCredential) {
            $this->addColumn([
                'index'      => 'apiVersion',
                'label'      => trans('shopify::app.shopify.credential.datagrid.apiVersion'),
                'type'       => 'string',
                'searchable' => true,
                'filterable' => true,
                'sortable'   => true,
            ]);
        }

        $this->addColumn([
            'index'      => 'active',
            'label'      => trans('shopify::app.shopify.credential.datagrid.enabled'),
            'type'       => 'boolean',
            'searchable' => true,
            'filterable' => true,
            'sortable'   => true,
            'closure'    => fn ($row) => $row->active ? '<span class="label-active">'.trans('admin::app.common.yes').'</span>' : '<span class="label-info">'.trans('admin::app.common.no').'</span>',
        ]);
    }

    /**
     * Prepare actions.
     *
     * @return void
     */
    public function prepareActions()
    {
        if (bouncer()->hasPermission('shopify.credentials.edit')) {
            $this->addAction([
                'index'  => 'edit',
                'icon'   => 'icon-edit',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.edit'),
                'method' => 'GET',
                'url'    => fn ($row) => route('shopify.credentials.edit', $row->id),
            ]);
        }

        $this->addAction([
            'index'  => 'sync',
            'icon'   => 'icon-data-transfer',
            'title'  => trans('shopify::app.shopify.credential.datagrid.sync'),
            'method' => 'POST',
            'url'    => fn ($row) => route('shopify.credentials.sync', $row->id),
        ]);

        $this->addAction([
            'index'  => 'revoke',
            'icon'   => 'icon-cancel',
            'title'  => trans('shopify::app.shopify.credential.datagrid.revoke'),
            'method' => 'POST',
            'url'    => fn ($row) => route('shopify.credentials.revoke', $row->id),
        ]);

        if (bouncer()->hasPermission('shopify.credentials.delete')) {
            $this->addAction([
                'index'  => 'delete',
                'icon'   => 'icon-delete',
                'title'  => trans('admin::app.catalog.attributes.index.datagrid.delete'),
                'method' => 'DELETE',
                'url'    => fn ($row) => route('shopify.credentials.delete', $row->id),
            ]);
        }
    }

    /**
     * Filter per-row actions:
     *   SaaS row → edit + sync + revoke (delete hidden; SaaS uses revoke)
     *   non-SaaS row → edit + delete
     */
    public function formatData(): array
    {
        $data = parent::formatData();

        foreach ($data['records'] as $record) {
            if (! isset($record->actions) || ! is_array($record->actions)) {
                continue;
            }

            $isSaasRow = ! empty($this->decodeExtras($record)['saas']);

            $record->actions = array_values(array_filter(
                $record->actions,
                function ($action) use ($isSaasRow) {
                    $index = $action['index'] ?? '';

                    if ($isSaasRow) {
                        return $index !== 'delete';
                    }

                    return $index !== 'sync' && $index !== 'revoke';
                }
            ));
        }

        return $data;
    }

    /**
     * Decode the row's `extras` JSON column into an array.
     */
    protected function decodeExtras(object $record): array
    {
        $extras = $record->extras ?? null;

        if (is_string($extras)) {
            $extras = json_decode($extras, true);
        }

        return is_array($extras) ? $extras : [];
    }
}
