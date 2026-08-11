<div
    v-if="filterFields.some(field => (field.list_route || '').includes('/shopify/')) && filterFields.some(field => ['credentials', 'channel', 'currency', 'family', 'locale'].includes(field.name))"
    class="shopify-export-filters p-4 bg-white dark:bg-cherry-900 rounded box-shadow"
>
    <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
        @lang('shopify::app.shopify.export.filters.shopify')
    </p>

    <x-admin::data-transfer.filter-fields
        ::entity-type="entityType"
        :exporter-config="config('exporters')"
        only="credentials,channel,currency,family,locale"
        grid-class="grid grid-cols-1"
    />
</div>

<div
    v-if="filterFields.some(field => (field.list_route || '').includes('/shopify/')) && filterFields.some(field => ['productfilter', 'productstatus'].includes(field.name))"
    class="shopify-data-filters p-4 bg-white dark:bg-cherry-900 rounded box-shadow"
>
    <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
        @lang('admin::app.settings.data-transfer.exports.create.product-filters')
    </p>

    <x-admin::data-transfer.filter-fields
        ::entity-type="entityType"
        :exporter-config="config('exporters')"
        only="productfilter,productstatus"
        grid-class="grid grid-cols-1"
    />
</div>
