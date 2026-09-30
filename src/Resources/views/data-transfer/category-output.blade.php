{{--
    The children toggle of the category export. Core places the output fields it
    knows by name, and this one is the connector's own, so it is rendered under
    them here. The field set renders only the fields the chosen export declares,
    so every other export shows nothing.
--}}
@if (request()->routeIs('admin.settings.data_transfer.exports.edit'))
    @php
        $categoryExport = \Webkul\DataTransfer\Models\JobInstancesProxy::query()->find(request()->route('id'));
    @endphp

    @if ($categoryExport?->entity_type === 'shopifyCategories')
        <x-admin::data-transfer.filter-fields
            :entity-type="$categoryExport->entity_type"
            :values="$categoryExport->filters ?? []"
            :exporter-config="config('exporters')"
            only="with_children"
        />
    @endif
@else
    <x-admin::data-transfer.filter-fields
        ::entity-type="entityType"
        :exporter-config="config('exporters')"
        only="with_children"
    />
@endif
