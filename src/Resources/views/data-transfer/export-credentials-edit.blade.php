{{--
    Shopify credential selector on the export edit screen.

    Sits at the top of core's Output card when the export has one, and in an
    Output card of its own when core renders none, so it is never left out.
--}}
@php
    $insideOutput ??= false;

    $shopifyExport = app(\Webkul\DataTransfer\Repositories\JobInstancesRepository::class)->find(request()->route('id'));
    $shopifyEntityType = $shopifyExport?->entity_type;
    $shopifyExportFilters = $shopifyExport?->filters ?? [];
    $shopifyFilterNames = collect(config('exporters')[$shopifyEntityType]['filters']['fields'] ?? [])->pluck('name');

    $hasCoreOutput = $shopifyFilterNames->intersect(['file_format', 'with_media', 'with_associations', 'header_row', 'use_labels', 'date_format', 'file_path'])->isNotEmpty();

    $rendersHere = $shopifyEntityType
        && str_starts_with($shopifyEntityType, 'shopify')
        && $shopifyFilterNames->contains('credentials')
        && $hasCoreOutput === $insideOutput;
@endphp

@if ($rendersHere)
    <div class="{{ $insideOutput ? 'shopify-export-credentials' : 'shopify-export-output p-4 bg-white dark:bg-cherry-900 rounded box-shadow' }}">
        @unless ($insideOutput)
            <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
                @lang('admin::app.settings.data-transfer.exports.create.output')
            </p>
        @endunless

        <x-admin::data-transfer.filter-fields
            :entity-type="$shopifyEntityType"
            :values="$shopifyExportFilters"
            :exporter-config="config('exporters')"
            only="credentials"
        />
    </div>
@endif
