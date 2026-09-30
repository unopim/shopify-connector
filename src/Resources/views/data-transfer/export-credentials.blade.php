{{--
    Shopify credential selector on the export create screen.

    Sits at the top of core's Output card when the picked export has one, and in
    an Output card of its own when core renders none, so it is never left out.
    The entity type is still being picked here, so both follow the live pick.
--}}
@php
    $insideOutput ??= false;

    $coreOutputFields = "['file_format', 'with_media', 'with_associations', 'header_row', 'use_labels', 'date_format', 'file_path']";

    $outputCheck = $insideOutput ? '' : '! ';
@endphp

<div
    v-if="filterFields.some(field => field.name === 'credentials' && (field.list_route || '').includes('/shopify/')) && {{ $outputCheck }}filterFields.some(field => {{ $coreOutputFields }}.includes(field.name))"
    class="{{ $insideOutput ? 'shopify-export-credentials' : 'shopify-export-output p-4 bg-white dark:bg-cherry-900 rounded box-shadow' }}"
>
    @unless ($insideOutput)
        <p class="text-base text-gray-800 dark:text-white font-semibold mb-4">
            @lang('admin::app.settings.data-transfer.exports.create.output')
        </p>
    @endunless

    <x-admin::data-transfer.filter-fields
        ::entity-type="entityType"
        :exporter-config="config('exporters')"
        only="credentials"
    />
</div>
