{{--
    Shopify credential selector on the export edit page.

    Rendered in the right-hand column, directly below core's Output card. This is
    Core connector UI: always visible, never gated on a Pro-contributed field.
--}}
@php
    $shopifyExport = app(\Webkul\DataTransfer\Repositories\JobInstancesRepository::class)->find(request()->route('id'));
    $shopifyEntityType = $shopifyExport?->entity_type;
    $shopifyExportFilters = $shopifyExport?->filters ?? [];
    $shopifyFilterNames = collect(config('exporters')[$shopifyEntityType]['filters']['fields'] ?? [])->pluck('name');
@endphp

@if ($shopifyEntityType && str_starts_with($shopifyEntityType, 'shopify') && $shopifyFilterNames->contains('credentials'))
    <div class="shopify-export-credentials p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
        <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
            @lang('shopify::app.shopify.export.filters.shopify')
        </p>

        <x-admin::data-transfer.filter-fields
            :entity-type="$shopifyEntityType"
            :values="$shopifyExportFilters"
            :exporter-config="config('exporters')"
            only="credentials"
        />
    </div>
@endif
