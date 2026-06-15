@php
    $currentTab = request('history') !== null ? 'history' : request('tab', 'general');
@endphp

<x-admin::layouts.with-history :active-tab="$currentTab === 'taxonomy' ? 'taxonomy' : 'general'">
    <x-slot:entityName>
        shopify_exportmapping
    </x-slot>
    <x-slot:title>
        @lang('shopify::app.shopify.export.mapping.title')
    </x-slot>

    {{-- Add the Category Taxonomy link into the layout's own tab strip --}}
    <x-slot:tabs>
        <a href="?tab=taxonomy">
            <div class="{{ $currentTab === 'taxonomy' ? '-mb-px border-violet-700 border-b-2 transition' : '' }} pb-3.5 px-2.5 text-base font-medium text-gray-600 dark:text-gray-300 cursor-pointer">
                @lang('shopify::app.shopify.export.mapping.tabs.taxonomy')
            </div>
        </a>
    </x-slot:tabs>

    {{-- General tab content (rendered by the layout when activeTab === 'general') --}}
    <v-create-attributes-mappings></v-create-attributes-mappings>

    {{-- Category Taxonomy tab content --}}
    <x-slot:tabContents>
        @if ($currentTab === 'taxonomy')
            <v-shopify-category-taxonomy-mapping
                :initial-rows='@json($taxonomyMappings)'
                save-url="{{ route('shopify.category-taxonomy-mappings.create') }}"
            ></v-shopify-category-taxonomy-mapping>
        @endif
    </x-slot:tabContents>

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
                                <div>
                                    <p class="break-words"><span @class(['required' => $field['name'] === 'title'])>@lang($field['label']) {{ ' ['.$field['name'].']' }}</span>
                                    @if(isset($field['tooltip']))
                                    <div class="flex gap-1 items-center mt-1"> <span class="icon-information text-lg"></span> <p class="break-words text-xs text-gray-500 dark:text-gray-400"> @lang($field['tooltip'])</p> </div>
                                     </p>
                                    @endif
                                </div>
                                
                                
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

                        <!----- Product status: static dropdown in the attribute column, fixed value always disabled ---->
                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <div>
                                <p class="break-words"><span class="required">@lang('shopify::app.shopify.export.mapping.status.label') {{ ' [status]' }}</span>
                                <div class="flex gap-1 items-center mt-1"> <span class="icon-information text-lg"></span> <p class="break-words text-xs text-gray-500 dark:text-gray-400"> @lang('shopify::app.shopify.export.mapping.status.tooltip')</p> </div>
                                </p>
                            </div>

                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    name="status"
                                    rules="required"
                                    track-by="id"
                                    label-by="name"
                                    :value="old('status') ?? ($exportMapping['status'] ?? '')"
                                    :options="json_encode($statusOptions, true)"
                                    :label="trans('shopify::app.shopify.export.mapping.status.label')"
                                    :placeholder="trans('shopify::app.shopify.export.mapping.status.placeholder')"
                                />
                                <x-admin::form.control-group.error control-name="status" />
                            </x-admin::form.control-group>

                            <div></div>
                        </div>
     
                        
                    </div>

                    <!----- Unit price mapping ---->
                    @php
                        $unitPrice = $unitPriceMapping ?? [];
                        $referenceUnitOptions = array_merge(
                            [['id' => 'AUTO', 'name' => trans('shopify::app.shopify.export.mapping.unit_price.auto')]],
                            $unitPriceUnitOptions
                        );
                    @endphp
                    <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="text-base text-gray-800 dark:text-white font-semibold">
                                @lang('shopify::app.shopify.export.mapping.unit_price.title')
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words">@lang('shopify::app.shopify.export.mapping.unit_price.quantity_value')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    name="unit_price_quantity_value"
                                    track-by="code"
                                    label-by="label"
                                    :value="$unitPrice['quantityValueAttr'] ?? ''"
                                    :entityName="json_encode(['number','decimal'])"
                                    async=true
                                    :list-route="route('admin.shopify.get-attribute')"
                                />
                            </x-admin::form.control-group>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words">@lang('shopify::app.shopify.export.mapping.unit_price.quantity_unit')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    name="unit_price_quantity_unit"
                                    track-by="code"
                                    label-by="label"
                                    :value="$unitPrice['quantityUnitAttr'] ?? ''"
                                    :entityName="json_encode(['select','text'])"
                                    async=true
                                    :list-route="route('admin.shopify.get-attribute')"
                                />
                            </x-admin::form.control-group>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words">@lang('shopify::app.shopify.export.mapping.unit_price.reference_value')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="text"
                                    name="unit_price_reference_value"
                                    :value="$unitPrice['referenceValue'] ?? 100"
                                />
                            </x-admin::form.control-group>
                        </div>

                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="break-words">@lang('shopify::app.shopify.export.mapping.unit_price.reference_unit')</p>
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    name="unit_price_reference_unit"
                                    track-by="id"
                                    label-by="name"
                                    :value="$unitPrice['referenceUnit'] ?? 'AUTO'"
                                    :options="json_encode($referenceUnitOptions, true)"
                                />
                            </x-admin::form.control-group>
                        </div>
                    </div>

                    <!----- Image mappings ---->
                    <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="text-base text-gray-800 dark:text-white font-semibold">
                            @lang('shopify::app.shopify.export.mapping.images.title')
                            </p>
                        </div>


                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                           
                        @php
                        $mediaAttributes = '';
                        $mediaType  = '';
                        $selectType = 'select';
                        if (isset($mediaMapping['mediaAttributes']) && !empty($mediaMapping['mediaAttributes'])) {
                            $mediaAttributes = $mediaMapping['mediaAttributes'];
                            $mediaType = $mediaMapping['mediaType'];
                            $selectType = 'gallery' === $mediaType ? 'select' : 'multiselect';
            
                        }

                        $supportedTypes = ['image', 'gallery'];

                        $attributeTypes = [];

                        foreach($supportedTypes as $type) {
                            $attributeTypes[] = [
                                'id'    => $type,
                                'label' => trans('admin::app.catalog.attributes.create.'. $type)
                            ];
                        }

                        $attributeTypesJson = json_encode($attributeTypes);

                    @endphp
                        <x-admin::form.control-group>
                            <p class="break-words py-3"> @lang('shopify::app.shopify.export.mapping.images.label.type')</p>
                            <x-admin::form.control-group.control
                                type="select"
                                id="mediaType"
                                class="cursor-pointer"
                                name="mediaType"
                                v-model="attributeType"
                                :label="trans('shopify::app.shopify.export.mapping.images.label.type')"
                                :options="$attributeTypesJson"
                                track-by="id"
                                label-by="label"
                                @input="handleDependentChange('mediaType', 'mediaAttributes')"
                                ref="mediaType"
                                v-model="selectedAttributeType"
                            >
                            </x-admin::form.control-group.control>

                            <x-admin::form.control-group.error control-name="mediaType" />
                        </x-admin::form.control-group>
                        
                        <x-admin::form.control-group>
                        <p class="break-words py-3"> @lang('shopify::app.shopify.export.mapping.images.label.attribute')</p>
                                <x-admin::form.control-group.control
                                    type="multiselect"
                                    track-by="code"
                                    label-by="label"
                                    :value="$mediaAttributes"
                                    async=true
                                    name="mediaAttributes"
                                    :list-route="route('admin.shopify.get-image-attribute')"
                                    @input="handleDependentChange('mediaType', 'mediaAttributes')"
                                    ref="mediaAttributes"
                                    ::disabled="isDisabled()"
                                />
                                <x-admin::form.control-group.error control-name="mediaAttributes" />
                            </x-admin::form.control-group>
                            </div>
                    </div>

                    <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                        <div class="grid grid-cols-2 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                            <p class="text-base text-gray-800 dark:text-white font-semibold">
                            @lang('shopify::app.shopify.export.mapping.unit.title')
                            </p>
                        </div>


                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                           
                        @php
                            $weightunit = $metaFieldTypeInShopify['weight']['unitoptions'] ?? null;
                            $weightUnitValue = $shopifyMapping?->mapping['unit']['weight'] ?? null;
                        @endphp
                        <x-admin::form.control-group class="!mb-0">
                            <p class="break-words py-3"> @lang('shopify::app.shopify.export.mapping.unit.weight')</p>
                        </x-admin::form.control-group>
                        <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    track-by="id"
                                    label-by="name"
                                    :value="old('weightunit') ?? $weightUnitValue"
                                    :options="json_encode($weightunit, true)"
                                    :label="trans('shopify::app.shopify.export.mapping.unit.weight')"
                                    :placeholder="trans('shopify::app.shopify.export.mapping.unit.weight')"
                                    name="weightunit"
                                    rules="required"
                                />
                                <x-admin::form.control-group.error control-name="weightunit" />
                        </x-admin::form.control-group>
                        </div>

                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                           
                        @php
                            $volume = $metaFieldTypeInShopify['volume']['unitoptions'] ?? null;
                            $volumeUnitValue = $shopifyMapping?->mapping['unit']['volume'] ?? null;
                        @endphp
                        <x-admin::form.control-group class="!mb-0">
                            <p class="break-words py-3"> @lang('shopify::app.shopify.export.mapping.unit.volume')</p>
                        </x-admin::form.control-group>
                        <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    track-by="id"
                                    label-by="name"
                                    :value="old('volumeunit') ?? $volumeUnitValue"
                                    :label="trans('shopify::app.shopify.export.mapping.unit.volume')"
                                    :placeholder="trans('shopify::app.shopify.export.mapping.unit.volume')"
                                    :options="json_encode($volume, true)"
                                    name="volumeunit"
                                    rules="required"
                                />
                                <x-admin::form.control-group.error control-name="volumeunit" />
                        </x-admin::form.control-group>
                        </div>

                        <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300 transition-all hover:bg-violet-50 hover:bg-opacity-30 dark:hover:bg-cherry-800">
                           
                        @php
                            $dimension = $metaFieldTypeInShopify['dimension']['unitoptions'] ?? null;
                            $dimensionunit = $shopifyMapping?->mapping['unit']['dimension'] ?? null;
                        @endphp
                        <x-admin::form.control-group class="!mb-0">
                            <p class="break-words py-3"> @lang('shopify::app.shopify.export.mapping.unit.dimension')</p>
                        </x-admin::form.control-group>
                        <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.control
                                    type="select"
                                    track-by="id"
                                    label-by="name"
                                    :value="old('dimensionunit') ?? $dimensionunit"
                                    :options="json_encode($dimension, true)"
                                    :label="trans('shopify::app.shopify.export.mapping.unit.dimension')"
                                    :placeholder="trans('shopify::app.shopify.export.mapping.unit.dimension')"
                                    name="dimensionunit"
                                    rules="required"
                                />
                                <x-admin::form.control-group.error control-name="dimensionunit" />
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
                    selectedAttributeType: @json($mediaType ?? null),
                };
            },
            watch: {
                selectedAttributeType(value) {
                    this.$refs.mediaAttributes.selectedValue = [];
                }
            },
            methods: {
                isDisabled(){
                   
                    if (this.$refs['mediaType'] && !this.$refs['mediaType'].selectedValue) 
                    {
                     this.$refs['mediaAttributes'].selectedValue = null;
                     
                     return true
                    }

                    return false
                },
                handleDependentChange(fieldName, dependentFieldName) {
                    let value = this.$refs[fieldName].selectedOption;
                    this.$refs[dependentFieldName].params[fieldName] = value;
                    console.log(fieldName, value);
                    this.$refs[dependentFieldName].optionsList = '';
                },
                
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

    <script type="text/x-template" id="v-shopify-category-taxonomy-mapping-template">
        <div>
            <div class="flex justify-between items-center mb-5">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('shopify::app.shopify.export.mapping.taxonomy.title')
                </p>
                <button type="button" class="primary-button" @click="save" :disabled="saving">
                    @lang('shopify::app.shopify.export.mapping.taxonomy.save_btn')
                </button>
            </div>

            <div class="bg-white dark:bg-cherry-900 rounded box-shadow">
                <div class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 font-bold text-gray-600 dark:text-gray-300">
                    <p>@lang('shopify::app.shopify.export.mapping.taxonomy.header_category')</p>
                    <p>@lang('shopify::app.shopify.export.mapping.taxonomy.header_taxonomy')</p>
                    <p></p>
                </div>

                <div v-if="!rows.length" class="px-4 py-6 text-gray-500 dark:text-gray-400">
                    @lang('shopify::app.shopify.export.mapping.taxonomy.empty')
                </div>

                <div
                    v-for="(row, index) in rows"
                    :key="row.category_id"
                    class="grid grid-cols-3 gap-2.5 items-center px-4 py-4 border-b dark:border-cherry-800 text-gray-600 dark:text-gray-300"
                >
                    <p class="break-words" v-text="row.category_label"></p>
                    <p class="break-words" v-text="row.taxonomy_path"></p>
                    <span class="icon-delete text-2xl cursor-pointer" @click="removeRow(index)"></span>
                </div>

                <div v-if="showPicker" class="flex gap-2.5 items-center px-4 py-4">
                    <div class="flex-1">
                        <x-admin::form.control-group class="!mb-0">
                            <x-admin::form.control-group.control
                                type="select"
                                name="taxonomy_category_picker"
                                track-by="id"
                                label-by="label"
                                async="true"
                                :list-route="route('admin.shopify.get-categories')"
                                ::query-params="{ exclude_ids: mappedCategoryIds }"
                                v-on:select-option="onCategoryPicked"
                                :placeholder="trans('shopify::app.shopify.export.mapping.taxonomy.category_placeholder')"
                            />
                        </x-admin::form.control-group>
                    </div>

                    <div class="flex-1">
                        <x-admin::form.control-group class="!mb-0">
                            <x-admin::form.control-group.control
                                type="select"
                                name="taxonomy_node_picker"
                                track-by="id"
                                label-by="label"
                                async="true"
                                :list-route="route('admin.shopify.get-taxonomy-nodes')"
                                v-on:select-option="onTaxonomyPicked"
                                :placeholder="trans('shopify::app.shopify.export.mapping.taxonomy.taxonomy_placeholder')"
                            />
                        </x-admin::form.control-group>
                    </div>

                    <button
                        type="button"
                        class="secondary-button shrink-0 disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="!canAdd"
                        @click="addRow"
                    >
                        @lang('shopify::app.shopify.export.mapping.taxonomy.add_btn')
                    </button>
                </div>
            </div>
        </div>
    </script>

    <script type="module">
        app.component('v-shopify-category-taxonomy-mapping', {
            template: '#v-shopify-category-taxonomy-mapping-template',

            props: ['initialRows', 'saveUrl'],

            data() {
                return {
                    rows: Array.isArray(this.initialRows) ? [...this.initialRows] : [],
                    pickedCategory: null,
                    pickedTaxonomy: null,
                    showPicker: true,
                    saving: false,
                };
            },

            computed: {
                canAdd() {
                    return !! (this.pickedCategory && this.pickedTaxonomy);
                },

                mappedCategoryIds() {
                    return this.rows.map(r => r.category_id);
                },
            },

            methods: {
                extract(event) {
                    const val = event?.target?.value ?? event;
                    if (! val) return null;
                    if (typeof val === 'string') {
                        try { return JSON.parse(val); } catch (e) { return null; }
                    }
                    return val;
                },

                onCategoryPicked(event) {
                    this.pickedCategory = this.extract(event);
                },

                onTaxonomyPicked(event) {
                    this.pickedTaxonomy = this.extract(event);
                },

                addRow() {
                    if (! this.canAdd) return;

                    const catId = this.pickedCategory.id;

                    if (this.rows.some(r => String(r.category_id) === String(catId))) {
                        this.$emitter.emit('add-flash', {
                            type: 'warning',
                            message: "@lang('shopify::app.shopify.export.mapping.taxonomy.already_mapped')",
                        });

                        return;
                    }

                    this.rows.push({
                        category_id: catId,
                        category_label: this.pickedCategory.label,
                        taxonomy_id: this.pickedTaxonomy.id,
                        taxonomy_path: this.pickedTaxonomy.label,
                    });

                    this.pickedCategory = null;
                    this.pickedTaxonomy = null;
                    this.remountPicker();
                },

                removeRow(index) {
                    this.rows.splice(index, 1);
                    this.remountPicker();
                },

                remountPicker() {
                    // Destroy + recreate the picker block so both selects (and their
                    // VeeValidate field state) reset and the category exclude list refreshes.
                    this.showPicker = false;
                    this.$nextTick(() => { this.showPicker = true; });
                },

                save() {
                    if (this.saving) return;
                    this.saving = true;

                    const mappings = {};
                    this.rows.forEach(r => { mappings[r.category_id] = r.taxonomy_id; });

                    this.$axios.post(this.saveUrl, { mappings })
                        .then(response => {
                            this.$emitter.emit('add-flash', {
                                type: 'success',
                                message: response.data.message,
                            });
                        })
                        .catch(() => {
                            this.$emitter.emit('add-flash', {
                                type: 'error',
                                message: "@lang('shopify::app.shopify.export.mapping.taxonomy.save_failed')",
                            });
                        })
                        .finally(() => { this.saving = false; });
                },
            },
        });
    </script>
    @endPushOnce
</x-admin::layouts.with-history>
