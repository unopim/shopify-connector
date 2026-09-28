{{--
    When a Shopify export may run unattended, the schedule it keeps. The Pro
    package owns what a schedule holds; here it is only offered, and stays read
    only without it.
--}}
@php
    $shopifyExport = app(\Webkul\DataTransfer\Repositories\JobInstancesRepository::class)->find(request()->route('id'));
    $shopifyEntityType = $shopifyExport?->entity_type;

    $shopifySchedule = collect(config("exporters.{$shopifyEntityType}.filters.fields", []))
        ->pluck('name')
        ->intersect(array_column(config('shopify_schedule.fields', []), 'name'))
        ->values();
@endphp

@if ($shopifySchedule->isNotEmpty())
    <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
        <x-shopify::pro-notice
            class="shopify-pro-notice--bare"
            :title="trans('shopify::app.export.schedule.title')"
            :note="trans('shopify::app.shopify.pro.schedule-note')"
        />

        <fieldset @disabled(! $shopifyProInstalled)>
            <x-admin::data-transfer.filter-fields
                :entity-type="$shopifyEntityType"
                :values="$shopifyExport?->filters ?? []"
                :exporter-config="config('exporters')"
                :only="$shopifySchedule->implode(',')"
                grid-class="grid grid-cols-1"
            />
        </fieldset>
    </div>
@endif
