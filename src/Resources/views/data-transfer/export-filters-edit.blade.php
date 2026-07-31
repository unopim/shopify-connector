@php
    $shopifyExport = app(\Webkul\DataTransfer\Repositories\JobInstancesRepository::class)->find(request()->route('id'));
    $shopifyEntityType = $shopifyExport?->entity_type;
    $shopifyExportFilters = $shopifyExport?->filters ?? [];
    $shopifyFilterNames = collect(config('exporters')[$shopifyEntityType]['filters']['fields'] ?? [])->pluck('name');
@endphp

@if ($shopifyEntityType && $shopifyFilterNames->intersect(['credentials', 'channel', 'currency', 'productfilter', 'productstatus'])->isNotEmpty())
    <div class="shopify-export-filters p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
        <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
            @lang('admin::app.settings.data-transfer.exports.create.filters')
        </p>

        <x-admin::data-transfer.filter-fields
            :entity-type="$shopifyEntityType"
            :values="$shopifyExportFilters"
            :exporter-config="config('exporters')"
            only="credentials,channel,currency,productfilter,productstatus"
            grid-class="grid grid-cols-1"
        />
    </div>
@endif
