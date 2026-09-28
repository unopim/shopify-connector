{{--
    The schedule card on the export create screen. The entity type is still
    being picked there, so the card follows the live selection.
--}}
@php($shopifyScheduleFields = implode(',', array_column(config('shopify_schedule.fields', []), 'name')))

<div
    v-if="filterFields.some(field => field.name === 'schedule_cron_preset')"
    class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow"
>
    <x-shopify::pro-notice
        class="shopify-pro-notice--bare"
        :title="trans('shopify::app.export.schedule.title')"
        :note="trans('shopify::app.shopify.pro.schedule-note')"
    />

    <fieldset @disabled(! $shopifyProInstalled)>
        <x-admin::data-transfer.filter-fields
            ::entity-type="entityType"
            :exporter-config="config('exporters')"
            :only="$shopifyScheduleFields"
            grid-class="grid grid-cols-1"
        />
    </fieldset>
</div>
