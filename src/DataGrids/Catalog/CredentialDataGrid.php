<?php

namespace Webkul\Shopify\DataGrids\Catalog;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webkul\DataGrid\DataGrid;

class CredentialDataGrid extends DataGrid
{
    protected bool $hasSaasCredential;

    /**
     * OAuth client_ids registered against the currently logged-in admin via
     * api_keys.oauth_client_id. A SaaS row's extras.unopim_client_id must
     * appear in this set for sync/revoke to be exposed on that row.
     */
    protected array $loggedInClientIds = [];

    public function __construct()
    {
        $this->hasSaasCredential = DB::table('wk_shopify_credentials_config')
            ->whereJsonContains('extras->saas', true)
            ->exists();

        $adminId = Auth::guard('admin')->id() ?? Auth::id();

        if ($adminId) {
            $this->loggedInClientIds = DB::table('api_keys')
                ->where('admin_id', $adminId)
                ->whereNotNull('oauth_client_id')
                ->pluck('oauth_client_id')
                ->map(fn ($id) => (string) $id)
                ->all();
        }
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
            'index' => 'shopUrl',
            'label' => trans('shopify::app.shopify.credential.datagrid.shopUrl'),
            'type' => 'string',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
        ]);

        if (! $this->hasSaasCredential) {
            $this->addColumn([
                'index' => 'apiVersion',
                'label' => trans('shopify::app.shopify.credential.datagrid.apiVersion'),
                'type' => 'string',
                'searchable' => true,
                'filterable' => true,
                'sortable' => true,
            ]);
        }

        $this->addColumn([
            'index' => 'active',
            'label' => trans('shopify::app.shopify.credential.datagrid.enabled'),
            'type' => 'boolean',
            'searchable' => true,
            'filterable' => true,
            'sortable' => true,
            'closure' => fn ($row) => $row->active ? '<span class="label-active">'.trans('admin::app.common.yes').'</span>' : '<span class="label-info">'.trans('admin::app.common.no').'</span>',
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
                'index' => 'edit',
                'icon' => 'icon-edit',
                'title' => trans('admin::app.catalog.attributes.index.datagrid.edit'),
                'method' => 'GET',
                'url' => fn ($row) => route('shopify.credentials.edit', $row->id),
            ]);
        }

        $this->addAction([
            'index' => 'sync',
            'icon' => 'icon-data-transfer',
            'title' => trans('shopify::app.shopify.credential.datagrid.sync'),
            'method' => 'POST',
            'url' => fn ($row) => route('shopify.credentials.sync', $row->id),
        ]);

        $this->addAction([
            'index' => 'revoke',
            'icon' => 'icon-cancel',
            'title' => trans('shopify::app.shopify.credential.datagrid.revoke'),
            'method' => 'POST',
            'url' => fn ($row) => route('shopify.credentials.revoke', $row->id),
        ]);

        if (bouncer()->hasPermission('shopify.credentials.delete')) {
            $this->addAction([
                'index' => 'delete',
                'icon' => 'icon-delete',
                'title' => trans('admin::app.catalog.attributes.index.datagrid.delete'),
                'method' => 'DELETE',
                'url' => fn ($row) => route('shopify.credentials.delete', $row->id),
            ]);
        }
    }

    /**
     * Filter per-row actions:
     *   SaaS row → edit + (sync + revoke when current admin owns the row)
     *   non-SaaS row → edit + delete (previous behavior)
     */
    public function formatData(): array
    {
        $data = parent::formatData();

        foreach ($data['records'] as $record) {
            if (! isset($record->actions) || ! is_array($record->actions)) {
                continue;
            }

            $extras = $this->decodeExtras($record);
            $isSaasRow = ! empty($extras['saas']);
            $ownsRow = $isSaasRow && $this->ownsSaasRow($extras);

            $record->actions = array_values(array_filter(
                $record->actions,
                function ($action) use ($isSaasRow, $ownsRow) {
                    $index = $action['index'] ?? '';

                    if ($isSaasRow) {
                        if ($index === 'delete') {
                            return false;
                        }

                        if ($index === 'sync' || $index === 'revoke') {
                            return $ownsRow;
                        }

                        return true;
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

    /**
     * The SaaS row is "owned" by the logged-in admin when its
     * extras.unopim_client_id matches one of the admin's api_keys client ids.
     */
    protected function ownsSaasRow(array $extras): bool
    {
        $rowClientId = $extras['unopim_client_id'] ?? null;

        if (! $rowClientId || empty($this->loggedInClientIds)) {
            return false;
        }

        return in_array((string) $rowClientId, $this->loggedInClientIds, true);
    }
}
