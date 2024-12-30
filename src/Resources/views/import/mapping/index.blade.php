<x-admin::layouts.with-history>
    <x-slot:entityName>
        shopify_exportmapping
    </x-slot>
    <x-slot:title>
        @lang('shopify::app.shopify.import.mapping.title')
    </x-slot>
    <v-create-attributes-mappings></v-create-attributes-mappings>
    @pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-create-attributes-mapping-template"
    >
        <x-admin::form
            :action="route('shopify.import-mappings.create')" enctype="multipart/form-data"
        >
        @method('POST')
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('shopify::app.shopify.import.mapping.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <!-- Cancel Button -->
                    <a
                        href="{{ route('shopify.credentials.index') }}"
                        class="transparent-button"
                    >
                        @lang('admin::app.catalog.attribute-groups.create.back-btn')
                    </a>

                    <!-- Save Button -->
                    <button
                        type="submit"
                        class="primary-button"
                    >
                        @lang('shopify::app.shopify.import.mapping.save')
                    </button>
                </div>
            </div>
            <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">
                
                <div class="flex flex-col gap-2 flex-1 max-xl:flex-auto">

                    <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words font-bold">@lang('shopify::app.shopify.import.mapping.filed-shopify')</p>
                            <p class="break-words font-bold">@lang('shopify::app.shopify.import.mapping.attribute')</p>
                        </div>
                        @php
                            $importMapping = $formattedShopifyMapping;
                            $currentLocal = core()->getRequestedLocaleCode();
                        @endphp

                        @foreach ($mappingFields as $field)
                            @php 
                                $fields = $field['name'];
                                $selecttype = 'select';
                                if ($field['name'] == 'productType') {
                                    $field['types'] = ['text'];
                                }
                                if ($field['name'] == 'tags') {
                                    $field['types'] = ['textarea'];
                                }
                                $value = $importMapping[$fields] ?? '';
                                $importMapping[$fields] = old($field['name']) ?? $value;
                                $defaultValue = $defaultMapping[$fields] ?? null;
                            @endphp

                            <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                                <p class="break-words">@lang($field['label']) {{ ' ['.$field['name'].']' }}</p>
                                <x-admin::form.control-group class="!mb-0">
                                    <x-admin::form.control-group.control
                                        :type="$selecttype"
                                        :name="$field['name']"
                                        :value="old($field['name']) ?? $value"
                                        :label="trans($field['label'])"
                                        :placeholder="trans($field['label'])"
                                        track-by="code"
                                        label-by="label"
                                        @open="openedSelect"
                                        :entityName="json_encode($field['types'])"
                                        ::queryParams="[notInclude, '{{ $field['name'] }}']"
                                        async=true
                                        :list-route="route('admin.shopify.get-attribute')"
                                        formnovalidate
                                        @select-option="handleOpenedSelect($event, '{{ $field['name'] }}')"
                                    />
                                    <x-admin::form.control-group.control
                                        type="hidden"
                                        :name="'default_' . $field['name']"
                                        :value="old('default_' . $field['name']) ?? $value"
                                        :label="trans($field['label'])"
                                        :placeholder="trans($field['label'])"
                                        v-model="notInclude['{{ $field['name'] }}']"
                                        track-by="code"
                                        label-by="label"
                                    />
                                    <x-admin::form.control-group.error :control-name="'default_' . $field['name']" />
                                </x-admin::form.control-group>
                            </div>
                        @endforeach
                    </div>
                    <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="text-base text-gray-800 dark:text-white font-semibold">
                            @lang('shopify::app.shopify.import.mapping.other')
                            </p>
                        </div>
                        @php 
                            $imageData = '';
                            if (isset($importMapping['images']) && !empty($importMapping['images'])) {
                                $imageData = $importMapping['images'];
                            }
                            $variantImage = '';
                            if (isset($importMapping['variantimages']) && !empty($importMapping['variantimages'])) {
                                $variantImage = $importMapping['variantimages'];
                            }
                            $family = '';
                            if (isset($importMapping['family_variant']) && !empty($importMapping['family_variant'])) {
                                $family = $importMapping['family_variant'];
                            }
                        @endphp
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words"> @lang('shopify::app.shopify.import.mapping.image')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="multiselect"
                                    track-by="code"
                                    label-by="label"
                                    :value="$imageData"
                                    async=true
                                    name="images"
                                    :list-route="route('admin.shopify.get-image-attribute')"
                                />
                                <x-admin::form.control-group.error control-name="{{ $field['name'] }}" />
                            </x-admin::form.control-group>
                        </div>
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words"> @lang('shopify::app.shopify.import.mapping.variantimage')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    track-by="code"
                                    label-by="label"
                                    :value="$variantImage"
                                    async=true
                                    name="variantimages"
                                    :list-route="route('admin.shopify.get-image-attribute')"
                                />
                                <x-admin::form.control-group.error control-name="variantimages" />
                            </x-admin::form.control-group>
                        </div>
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words required"> @lang('shopify::app.shopify.import.mapping.family')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    track-by="id"
                                    label-by="label"
                                    rules="required"
                                    :value="$family"
                                    async=true
                                    name="family_variant"
                                    :list-route="route('admin.shopify.get-all-family-variants')"
                                />
                                <x-admin::form.control-group.error control-name="family_variant" />
                            </x-admin::form.control-group>
                        </div>
                    </div>
                </div>
            </div>
        </x-admin::form>
    </script>
    <script type="module">
        app.component('v-create-attributes-mappings', {
            template: '#v-create-attributes-mapping-template',
            data() {
                return {
                    form: {
                        vendosr: '',
                        errors: {},
                    },
                    notInclude: @json($importMapping ?? null),
                    fieldName: 'meta_fields', 
                };
            },
             
            methods: {
                handleOpenedSelect(event, fieldName) {
                    const values = Object.values(this.notInclude);
                    var json = JSON.parse(event);
                    this.notInclude[fieldName] = json?.code;
                },
            },
        });
    </script>
    @endPushOnce
</x-admin::layouts.with-history>
