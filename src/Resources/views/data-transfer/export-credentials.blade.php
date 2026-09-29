{{--
    Shopify credential selector.

    Rendered in the right-hand column, directly below core's Output card. This is
    Core connector UI: always visible, never gated on a Pro-contributed field.
--}}
<div
    v-if="filterFields.some(field => field.name === 'credentials' && (field.list_route || '').includes('/shopify/'))"
    class="shopify-export-credentials p-4 bg-white dark:bg-cherry-900 rounded box-shadow"
>
    <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
        @lang('shopify::app.shopify.export.filters.shopify')
    </p>

    <x-admin::data-transfer.filter-fields
        ::entity-type="entityType"
        :exporter-config="config('exporters')"
        only="credentials"
    />
</div>
