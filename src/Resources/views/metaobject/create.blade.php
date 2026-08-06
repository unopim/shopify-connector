<x-admin::layouts>
    <x-slot:title>@lang('shopify::app.shopify.metaobject.create')</x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('shopify.metaobject.index') }}" class="icon-arrow-left cursor-pointer text-2xl"></a>
            <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.create')</p>
        </div>

        <v-metaobject-builder
            mode="create"
            :field-types='@json($fieldTypes)'
        ></v-metaobject-builder>
    </div>

    @pushOnce('scripts')
        @include('shopify::metaobject._builder')
    @endPushOnce
</x-admin::layouts>
