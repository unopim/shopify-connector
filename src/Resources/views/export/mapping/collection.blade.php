<x-admin::layouts.with-history>
    <x-slot:entityName>
        shopify_exportmapping
    </x-slot>
    <x-slot:title>
        @lang('shopify::app.shopify.export.mapping.collection.title')
    </x-slot>
    <v-create-collection-mappings></v-create-collection-mappings>
    @pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-create-collection-mapping-template"
    >
        <x-admin::form
            :action="route('shopify.collection-mappings.create')" enctype="multipart/form-data"
        >
        @method('POST')
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('shopify::app.shopify.export.mapping.collection.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <a
                        href="{{ route('shopify.credentials.index') }}"
                        class="transparent-button"
                    >
                        @lang('shopify::app.shopify.export.mapping.collection.back-btn')
                    </a>

                    <button
                        type="submit"
                        class="primary-button"
                    >
                        @lang('shopify::app.shopify.export.mapping.collection.save')
                    </button>
                </div>
            </div>

            <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">
                <div class="flex flex-col gap-2 flex-1 max-xl:flex-auto">

                    <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300">
                            <p class="break-words font-bold">@lang('shopify::app.shopify.export.mapping.filed-shopify')</p>
                            <p class="break-words font-bold">@lang('shopify::app.shopify.export.mapping.attribute')</p>
                        </div>

                        @foreach ($collectionFields as $field)
                            <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300">
                                <div>
                                    <p class="break-words"><span @class(['required' => $field['name'] === 'title'])>@lang($field['label']) {{ ' ['.$field['name'].']' }}</span>
                                    @if (isset($field['tooltip']))
                                        <div class="flex gap-1 items-center mt-1"> <span class="icon-information text-lg"></span> <p class="break-words text-xs text-gray-500 dark:text-gray-400"> @lang($field['tooltip'])</p> </div>
                                    @endif
                                    </p>
                                </div>

                                <x-admin::form.control-group class="!mb-0">
                                    <x-admin::form.control-group.control
                                        type="select"
                                        :name="$field['name']"
                                        :value="$collectionMapping[$field['name']] ?? ''"
                                        :label="trans($field['label'])"
                                        :placeholder="trans($field['label'])"
                                        track-by="code"
                                        label-by="label"
                                        :entityName="json_encode($field['types'])"
                                        async=true
                                        :list-route="route('admin.shopify.get-category-field')"
                                        :rules="$field['name'] === 'title' ? 'required' : ''"
                                    />
                                    <x-admin::form.control-group.error control-name="{{ $field['name'] }}" />
                                </x-admin::form.control-group>
                            </div>
                        @endforeach

                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300">
                            <div>
                                <p class="break-words">@lang('shopify::app.shopify.export.mapping.collection.sort_order.label') {{ ' [sortOrder]' }}
                                <div class="flex gap-1 items-center mt-1"> <span class="icon-information text-lg"></span> <p class="break-words text-xs text-gray-500 dark:text-gray-400"> @lang('shopify::app.shopify.export.mapping.collection.sort_order.tooltip')</p> </div>
                                </p>
                            </div>

                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    name="sort_order"
                                    track-by="id"
                                    label-by="name"
                                    :value="$sortOrder"
                                    :options="json_encode($sortOrderOptions, true)"
                                    :label="trans('shopify::app.shopify.export.mapping.collection.sort_order.label')"
                                    :placeholder="trans('shopify::app.shopify.export.mapping.collection.sort_order.placeholder')"
                                />
                                <x-admin::form.control-group.error control-name="sort_order" />
                            </x-admin::form.control-group>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300">
                            <p class="text-base text-gray-800 dark:text-white font-semibold">
                                @lang('shopify::app.shopify.export.mapping.collection.images.title')
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300">
                            <p class="break-words py-3">@lang('shopify::app.shopify.export.mapping.collection.images.label')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    name="mediaAttributes"
                                    track-by="code"
                                    label-by="label"
                                    :value="$mediaMapping['mediaAttributes'] ?? ''"
                                    :entityName="json_encode(['image', 'file'])"
                                    async=true
                                    :list-route="route('admin.shopify.get-category-field')"
                                />
                                <x-admin::form.control-group.error control-name="mediaAttributes" />
                            </x-admin::form.control-group>
                        </div>
                    </div>
                </div>
            </div>
        </x-admin::form>
    </script>
    <script type="module">
        app.component('v-create-collection-mappings', {
            template: '#v-create-collection-mapping-template',
        });
    </script>
    @endPushOnce
</x-admin::layouts.with-history>
