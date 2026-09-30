{{--
    The category selection of the category export. Core has no card for a field
    of this name, so it sits under the scope card. Without Pro it is shown but
    locked, like every other Pro filter.
--}}
@php
    $proFeatures = resolve(\Webkul\Shopify\Support\ProFeatures::class);
    $isEdit = request()->routeIs('admin.settings.data_transfer.exports.edit');
    $categoryExport = $isEdit ? \Webkul\DataTransfer\Models\JobInstancesProxy::query()->find(request()->route('id')) : null;
@endphp

@if (! $isEdit || $categoryExport?->entity_type === 'shopifyCategories')
    <div
        @unless ($isEdit) v-if="filterFields.some(field => field.name === 'export_categories')" @endunless
        class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow"
        data-shopify-category-selection
        @unless ($proFeatures->isInstalled()) data-shopify-pro-locked @endunless
    >
        <p class="flex items-center gap-2 text-base text-gray-800 dark:text-white font-semibold mb-4">
            @lang('shopify::app.shopify.export.category-filters.title')

            <x-shopify::pro-cta variant="badge" />
        </p>

        @if ($isEdit)
            <x-admin::data-transfer.filter-fields
                :entity-type="$categoryExport->entity_type"
                :values="$categoryExport->filters ?? []"
                :exporter-config="config('exporters')"
                only="export_categories"
            />
        @else
            <x-admin::data-transfer.filter-fields
                ::entity-type="entityType"
                :exporter-config="config('exporters')"
                only="export_categories"
            />
        @endif
    </div>
@endif
