<div
    v-if="filterFields.some(field => ['credentials', 'channel', 'currency', 'productfilter'].includes(field.name))"
    class="shopify-export-filters p-4 bg-white dark:bg-cherry-900 rounded box-shadow"
>
    <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
        @lang('admin::app.settings.data-transfer.exports.create.filters')
    </p>

    <x-admin::data-transfer.filter-fields
        ::entity-type="entityType"
        :exporter-config="config('exporters')"
        only="credentials,channel,currency,productfilter,productstatus"
        grid-class="grid grid-cols-1"
    />
</div>
