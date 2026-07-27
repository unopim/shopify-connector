<x-admin::layouts>
    <x-slot:title>@lang('shopify::app.shopify.metaobject.title')</x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center justify-between">
            <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.title')</p>

            @if (bouncer()->hasPermission('shopify.metaobjects.store'))
                <a href="{{ route('shopify.metaobject.create') }}" class="primary-button">
                    @lang('shopify::app.shopify.metaobject.create')
                </a>
            @endif
        </div>

        <x-admin::datagrid :src="route('shopify.metaobject.index')" />
    </div>
</x-admin::layouts>
