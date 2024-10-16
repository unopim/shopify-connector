<x-admin::layouts.with-history>
    <x-slot:entityName>
        shopify_exportmapping
    </x-slot>
    <x-slot:title>
        @lang('shopify::app.shopify.export.mapping.title')
    </x-slot>
    <v-create-attributes-mappings></v-create-attributes-mappings>
    @pushOnce('scripts')
    <script
        type="text/x-template"
        id="v-create-attributes-mapping-template"
    >
        <x-admin::form  
            :action="route('shopify.export-mappings.create')" enctype="multipart/form-data"
        >
        @method('POST')
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('shopify::app.shopify.export.mapping.title')
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
                        @lang('shopify::app.shopify.export.mapping.save')
                    </button>
                </div>
            </div>
            <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">
                
                <div class="flex flex-col gap-2 flex-1 max-xl:flex-auto">

                    <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words font-bold">@lang('shopify::app.shopify.export.mapping.filed-shopify')</p>
                            <p class="break-words font-bold">@lang('shopify::app.shopify.export.mapping.attribute')</p>
                            <p class="break-words font-bold">@lang('shopify::app.shopify.export.mapping.fixed-value')</p>
                        </div>
                        @php
                            $exportMapping = $formattedShopifyMapping;
                            
                            $defaultMapping = $shopifyDefaultMapping;
                            $currentLocal = core()->getRequestedLocaleCode();
                            
                        @endphp
                        @foreach ($mappingFields as $field)
                            @php 
                                $fields = $field['name'];
                                $selecttype = $fields == 'tags' ? 'multiselect' : 'select';
                                
                                $value = $exportMapping[$fields] ?? '';

                                $defaultValue = $defaultMapping[$fields] ?? null;
                            @endphp

                            <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                                <p class="break-words">@lang($field['label']) {{ ' ['.$field['name'].']' }}</p>
                                <x-admin::form.control-group class="!mb-0">
                                    <x-admin::form.control-group.control
                                        :type="$selecttype"
                                        :name="$field['name']"
                                        :value="$value"
                                        :label="trans($field['label'])"
                                        :placeholder="trans($field['label'])"
                                        track-by="code"
                                        label-by="label"
                                        :entityName="json_encode($field['types'])"
                                        async=true
                                        :list-route="route('admin.shopify.get-attribute')"
                                        @input="handleSelectChange($event, '{{ $field['name'] }}')"
                                    />
                                    <x-admin::form.control-group.error control-name="{{ $field['name'] }}" />
                                </x-admin::form.control-group>
                                <x-admin::form.control-group class="!mb-0">
                                    <x-admin::form.control-group.control
                                        type="text"
                                        :name="'default_' . $field['name']"
                                        :id="'default_' . $field['name']"
                                        :value="old('default_' . $field['name']) ?? $defaultValue"
                                        :placeholder="trans($field['label'])"
                                        ::asc="isFieldDisabled('{{ $value }}', 'default_' + '{{ $field['name'] }}')"
                                        ::disabled="disabledFields['default_' + '{{ $field['name'] }}']"
                                                
                                    />
                                    
                                    <x-admin::form.control-group.error :control-name="'default_' . $field['name']" />
                                </x-admin::form.control-group>
                            </div>
                        @endforeach
                        @php 
                            $imageData = '';
                            if (isset($exportMapping['images']) && !empty($exportMapping['images'])) {
                                $imageData = $exportMapping['images'];
                            }
                        @endphp
                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words"> @lang('shopify::app.shopify.export.mapping.image')</p>
                            <x-admin::form.control-group>
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
                    </div>
                    @php 
                        $otherMapping = $formattedOtherMapping;

                        $otherMapingresult = [
                            'meta_fields_string'  => [],
                            'meta_fields_integer' => [],
                            'meta_fields_json'    => [],
                        ];
                        if (!empty($otherMapping)) {
                            foreach ($otherMapping as $key => $values) {
                                $otherMapingresult[$key] = $values;
                            }
                        }
                         
                    @endphp
                    <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words"></p>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="multiselect"
                                    track-by="code"
                                    label-by="label"
                                    async=true
                                    :entityName="json_encode(['text', 'select'])"
                                    :value="json_encode($otherMapingresult['meta_fields_string'])"
                                    name="meta_fields_string"
                                    :list-route="route('admin.shopify.get-attribute')"
                                />
                                <x-admin::form.control-group.error control-name="meta_fields_string" />
                            </x-admin::form.control-group>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="text"
                                    track-by="code"
                                    label-by="label"
                                    async=true
                                    name="metsaaa_fields_string"
                                    :placeholder="trans('admin::app.shopify.mapping.input_meta_fields')"
                                    :disabled=true
                                    value="Single line text"
                                />
                            </x-admin::form.control-group>
                            <p class="break-words">@lang('shopify::app.shopify.export.mapping.metafields')</p>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="multiselect"
                                    track-by="code"
                                    label-by="label"
                                    :entityName="json_encode(['number'])"
                                    async=true
                                    :value="json_encode($otherMapingresult['meta_fields_integer'])"
                                    name="meta_fields_integer"
                                    :list-route="route('admin.shopify.get-attribute')"
                                />
                                <x-admin::form.control-group.error control-name="meta_fields_integer" />
                            </x-admin::form.control-group>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="text"
                                    track-by="code"
                                    label-by="label"
                                    async=true
                                    name="meta_fielsds_string"
                                    :placeholder="trans('admin::app.shopify.mapping.input_meta_fields')"
                                    :disabled=true
                                    value="Integer"
                                />
                            </x-admin::form.control-group>
                            <p class="break-words"></p>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="multiselect"
                                    track-by="code"
                                    label-by="label"
                                    async=true
                                    name="meta_fields_json"
                                    :entityName="json_encode(['textarea'])"
                                    :value="json_encode($otherMapingresult['meta_fields_json'])"
                                    :list-route="route('admin.shopify.get-attribute')"
                                />
                                <x-admin::form.control-group.error control-name="meta_fields_json" />
                            </x-admin::form.control-group>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="text"
                                    track-by="code"
                                    label-by="label"
                                    async=true
                                    name="meta_fields_straing"
                                    :placeholder="trans('admin::app.shopify.mapping.input_meta_fields')"
                                    :disabled=true
                                    value="Json"
                                />
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
                    disabledFields: {},
                    onchange: {},
                };
            },
            methods: {
                handleSelectChange(event, fieldName) {
                    var defaultFieldName = 'default_' + fieldName;

                    if (!event) {
                        this.onchange[defaultFieldName] = false;
                    } else {
                        this.onchange[defaultFieldName] = true;
                    }
                },

                isFieldDisabled(value, defaultFieldName) {  
                    this.disabledFields[defaultFieldName] = true;

                    if (value == 'null' || value == '' || !value) {
                        this.disabledFields[defaultFieldName] = false;  
                    }

                    if (Object.keys(this.onchange).length != 0) {
                        Object.keys(this.onchange).forEach(key => {
                            this.disabledFields[key] = this.onchange[key];
                        });
                    }    
                }
            }
        });
    </script>
    @endPushOnce
</x-admin::layouts.with-history>
