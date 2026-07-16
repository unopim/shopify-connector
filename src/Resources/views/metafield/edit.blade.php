<x-admin::layouts.with-history>
    <x-slot:entityName>
        shopify_meta_fields
    </x-slot>

    <x-slot:title>
        @lang('shopify::app.shopify.metafield.index.title')
    </x-slot>
    
    <x-admin::form  
        :action="route('shopify.metafield.update', ['id' => $metaField->id])"
    >
        @method('PUT')

        <div class="flex justify-between items-center">
            <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                @lang('shopify::app.shopify.metafield.edit.title')
            </p>

            <div class="flex gap-x-2.5 items-center">
                <a
                    href="{{ route('shopify.metafield.index') }}"
                    class="transparent-button"
                >
                    @lang('shopify::app.shopify.metafield.edit.back-btn')
                </a>

                <button 
                    type="submit" 
                    class="primary-button"
                    aria-lebel="Submit"
                >
                    @lang('shopify::app.shopify.metafield.edit.save')
                </button>
            </div>
        </div>

        <!-- body content -->
        <div class="flex gap-2.5 mt-3.5 max-xl:flex-wrap">
            <!-- Left Section -->
            <div class="flex flex-col gap-2 flex-1 max-xl:flex-auto">
            <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                <v-metafield></v-metafield>
            </div>
            </div>
        </div>
    </x-admin::form>
    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-metafield-template"
        >
                            <!-- Definition Type -->
                <x-admin::form.control-group class="w-[525px]">
                    <x-admin::form.control-group.label class="required">
                        @lang('shopify::app.shopify.metafield.index.definitiontype')
                    </x-admin::form.control-group.label>
                        @php
                        $storefronts = $metaField?->storefronts ? 'Read' : 'No access';
                        $typeofminmx = $metaField?->type == 'date' ? 'date' : 'text';
                        $listValue = $metaFieldTypeInShopify[$metaField?->type]['list'] ?? null;
                        $width = $metaFieldTypeInShopify[$metaField?->type]['unitoptions'] ?? null;
                        $smartCollectionCondition = $metaFieldTypeInShopify[$metaField?->type]['smartCollectionCondition'] ?? null;
                        if (isset($metaFieldTypeInShopify[$metaField?->type]['listvalue']['smartCollectionCondition'])) {
                            $smartCollectionCondition = $metaFieldTypeInShopify[$metaField?->type]['listvalue']['smartCollectionCondition'] ? null : $metaFieldTypeInShopify[$metaField?->type]['listvalue']['smartCollectionCondition'];
                        }

                        if (in_array($metaField?->type, ['rating', 'number_decimal', 'number_integer']) && !$metaField->listvalue) {
                            $smartCollectionCondition = true;
                        }
                        
                        $adminFilterable = (($metaFieldTypeInShopify[$metaField?->type]['adminFilterable'] ?? null) && $metaField?->ownerType == 'PRODUCT') ? true : null;
                        
                        $minvalueLabel = $metaFieldTypeInShopify[$metaField?->type]['validation']['min'] ?? null;
                        $maxvalueLabel = $metaFieldTypeInShopify[$metaField?->type]['validation']['max'] ?? null;
                        $options = null;
                        if ($metaField?->options) {
                            $options = json_decode($metaField->options);
                        }
                        $validations = null;
                        if ($metaField?->validations) {
                            $validations = json_decode($metaField->validations);
                        }
                        
                        $metaType = [
                            [
                                'id' => 'PRODUCT',
                                'name' => 'Products',
                            ], [
                                'id' => 'PRODUCTVARIANT',
                                'name' => 'Variants',
                            ],
                        ];
                        $metaType = json_encode($metaType, true);
                        $attributeType = ['text', 'textarea', 'boolean', 'select', 'multiselect', 'date', 'image'];

                        $isReference = in_array($metaField?->type, ['product_reference', 'variant_reference', 'collection_reference', 'metaobject_reference']);
                        $refSource = $validations?->reference_source ?? 'association';
                        $refAssoc = $validations?->association_type ?? 'related_products';
                        $refAs = $validations?->reference_as ?? 'product';
                        $refMetaobjectDefinition = ($validations?->metaobject_type ?? null) && ($validations?->metaobject_definition_id ?? null)
                            ? $validations->metaobject_type.'|'.$validations->metaobject_definition_id
                            : null;
                        $referenceSourceOptions = json_encode([
                            ['id' => 'association', 'name' => trans('shopify::app.shopify.metafield.index.association')],
                            ['id' => 'categories', 'name' => trans('shopify::app.shopify.metafield.index.categories')],
                            ['id' => 'metaobject', 'name' => trans('shopify::app.shopify.metafield.index.metaobject')],
                        ]);
                        $associationTypeOptions = json_encode([
                            ['id' => 'related_products', 'name' => trans('shopify::app.shopify.metafield.index.related')],
                            ['id' => 'up_sells', 'name' => trans('shopify::app.shopify.metafield.index.up-sells')],
                            ['id' => 'cross_sells', 'name' => trans('shopify::app.shopify.metafield.index.cross-sells')],
                        ]);
                        $referenceAsOptions = json_encode([
                            ['id' => 'product', 'name' => trans('shopify::app.shopify.metafield.index.as-product')],
                            ['id' => 'variant', 'name' => trans('shopify::app.shopify.metafield.index.as-variant')],
                        ]);
                        $referenceCollectionOptions = json_encode([
                            ['id' => 'collection', 'name' => trans('shopify::app.shopify.metafield.index.as-collection')],
                        ]);

                        $one = false;
                        $list = true;
                        if (!$metaField->listvalue){
                            $one = true;
                            $list = false;
                        }
                         
                        @endphp
                    <x-admin::form.control-group.control
                        type="select"
                        id="ownerType"
                        name="ownerType"
                        rules="required"
                        disabled="disabled"
                        :value="$metaField?->ownerType"
                        :options="$metaType"
                        :label="trans('shopify::app.shopify.metafield.index.title')"
                        :placeholder="trans('shopify::app.shopify.metafield.index.title')"
                        track-by="id"
                        label-by="name"
                    />

                    <x-admin::form.control-group.error control-name="ownerType"/>
                </x-admin::form.control-group>

                @if (! $isReference)
                <!-- Unopim Attribute -->
                <x-admin::form.control-group class="w-[525px]">
                    <x-admin::form.control-group.label class="required">
                        @lang('shopify::app.shopify.metafield.index.attribute')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control class="metafieldCode"
                        type="text"
                        id="code"
                        name="code"
                        rules="required"
                        :value="$metaField?->code"
                        readonly="readonly"
                        :label="trans('shopify::app.shopify.metafield.index.attribute')" 
                        :entityName="json_encode($attributeType)"
                        :placeholder="trans('shopify::app.shopify.metafield.index.attribute')"
                    />

                    <x-admin::form.control-group.error control-name="code" />
                </x-admin::form.control-group>


                <!-- Content Type -->
                <x-admin::form.control-group class="w-[525px]">
                    <x-admin::form.control-group.label class="required">
                        @lang('shopify::app.shopify.metafield.index.ContentTypeName')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="text"
                        id="ContentTypeName"
                        name="ContentTypeName"
                        disabled="disabled"
                        :value="!empty($metaField?->ContentTypeName) ? $metaField?->ContentTypeName : $metaField?->type"
                        :label="trans('shopify::app.shopify.metafield.index.ContentTypeName')"
                        :placeholder="trans('shopify::app.shopify.metafield.index.ContentTypeName')"
                    />

                    <x-admin::form.control-group.error control-name="type"/>

                    <x-admin::form.control-group.control
                        type="hidden"
                        id="type"
                        name="type"
                        :value="$metaField?->type"
                    />
                </x-admin::form.control-group>
                @endif

                @if ($isReference)
                    <input type="hidden" name="reference_mode" value="1" />

                    <x-admin::form.control-group class="w-[525px]">
                        <x-admin::form.control-group.label class="required">
                            @lang('shopify::app.shopify.metafield.index.reference-source')
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="select"
                            name="reference_source"
                            :options="$referenceSourceOptions"
                            :value="$refSource"
                            track-by="id"
                            label-by="name"
                            disabled="disabled"
                            @input="handleReferenceSelect($event, 'referenceSource')"
                        />
                    </x-admin::form.control-group>

                    <template v-if="referenceSource === 'association'">
                        <x-admin::form.control-group class="w-[525px]">
                            <x-admin::form.control-group.label class="required">
                                @lang('shopify::app.shopify.metafield.index.association-type')
                            </x-admin::form.control-group.label>
                            <x-admin::form.control-group.control
                                type="select"
                                name="association_type"
                                :options="$associationTypeOptions"
                                :value="$refAssoc"
                                track-by="id"
                                label-by="name"
                                @input="handleReferenceSelect($event, 'associationType')"
                            />
                        </x-admin::form.control-group>
                    </template>

                    <x-admin::form.control-group class="w-[525px]" v-if="referenceSource === 'association'">
                        <x-admin::form.control-group.label class="required">
                            @lang('shopify::app.shopify.metafield.index.resolved-type')
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="select"
                            name="reference_as"
                            :options="$referenceAsOptions"
                            :value="$refAs"
                            track-by="id"
                            label-by="name"
                            disabled="disabled"
                            @input="handleReferenceSelect($event, 'referenceAs')"
                        />
                    </x-admin::form.control-group>

                    <x-admin::form.control-group class="w-[525px]" v-if="referenceSource === 'categories'">
                        <x-admin::form.control-group.label class="required">
                            @lang('shopify::app.shopify.metafield.index.resolved-type')
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="select"
                            name="reference_as"
                            :options="$referenceCollectionOptions"
                            value="collection"
                            track-by="id"
                            label-by="name"
                            disabled="disabled"
                            @input="handleReferenceSelect($event, 'referenceAs')"
                        />
                    </x-admin::form.control-group>

                    <template v-if="referenceSource === 'metaobject'">
                        <x-admin::form.control-group class="w-[525px]">
                            <x-admin::form.control-group.label class="required">
                                @lang('shopify::app.shopify.metafield.index.metaobject-definition')
                            </x-admin::form.control-group.label>
                            <input type="hidden" name="metaobject_definition" value="{{ $refMetaobjectDefinition }}" />

                            <input
                                type="text"
                                readonly
                                value="{{ $metaobjectLabel ?: ($validations->metaobject_type ?? '') }}"
                                class="w-full cursor-not-allowed rounded-md border bg-gray-100 px-3 py-2.5 text-sm text-gray-600 dark:border-gray-600 dark:bg-cherry-800 dark:text-gray-300"
                            />
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="w-[525px]">
                            <div class="flex items-center gap-2">
                                <v-metaobject-mapper :definition="metaobjectDefinition"></v-metaobject-mapper>
                            </div>
                        </x-admin::form.control-group>
                    </template>

                    <input type="hidden" name="type" :value="referenceType" />

                    <x-admin::form.control-group class="w-[525px]" v-if="referenceSource !== 'metaobject'">
                        <div class="flex items-center gap-4">
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="radio"
                                    id="ref_listvalue_one"
                                    name="ref_listvalue"
                                    value="one"
                                    for="ref_listvalue_one"
                                    disabled="disabled"
                                    ::checked="referenceListChoice === 'one'"
                                />
                                <x-admin::form.control-group.label for="ref_listvalue_one">
                                    @lang('shopify::app.shopify.metafield.index.onevalue')
                                </x-admin::form.control-group.label>
                            </x-admin::form.control-group>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.control
                                    type="radio"
                                    id="ref_listvalue_list"
                                    name="ref_listvalue"
                                    value="list"
                                    for="ref_listvalue_list"
                                    disabled="disabled"
                                    ::checked="referenceListChoice === 'list'"
                                />
                                <x-admin::form.control-group.label for="ref_listvalue_list">
                                    @lang('shopify::app.shopify.metafield.index.listvalue')
                                </x-admin::form.control-group.label>
                            </x-admin::form.control-group>
                        </div>
                    </x-admin::form.control-group>
                @endif

                @if ($metaField?->type == 'url')
                    <x-admin::form.control-group>
                        <x-admin::form.control-group.label>
                            @lang('shopify::app.shopify.metafield.index.urlvalidation')
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.label>
                                <span class="icon-information text-lg"></span> <p class="break-words text-xs text-gray-500 dark:text-gray-400"> @lang('shopify::app.shopify.metafield.index.urlvalidationdata')</p>
                        </x-admin::form.control-group.label>
                    </x-admin::form.control-group>
                @endif
                @if ($listValue && ! $isReference)
                    <div class="flex items-center gap-4">
                        <x-admin::form.control-group class="{{ !(bool) $one ? 'opacity-25' : '' }}">
                            <x-admin::form.control-group.control
                                type="radio"
                                id="is_unique_onevalue"
                                name="is_unique"
                                value="0"
                                for="is_unique_onevalue"
                                :checked="(boolean) $one"
                                disabled="disabled"
                            />

                            <x-admin::form.control-group.label
                                for="is_unique_onevalue"
                            >
                                One value
                            </x-admin::form.control-group.label>
                        </x-admin::form.control-group>

                        <x-admin::form.control-group class="{{ !(bool) $list ? 'opacity-25' : '' }}">
                            <x-admin::form.control-group.control
                                type="radio"
                                id="is_unique_listvalue"
                                name="is_unique"
                                value="1"
                                for="is_unique_listvalue"
                                @change="toggleOneValue($event)"
                                :checked="(boolean) $list"
                                disabled="disabled"
                            />

                            <x-admin::form.control-group.label
                                for="is_unique_listvalue"
                            >
                                List of values
                            </x-admin::form.control-group.label>
                        </x-admin::form.control-group>
                    </div>
                @endif

                <x-admin::form.control-group class="w-[525px]" v-if="referenceSource !== 'metaobject'">
                    <x-admin::form.control-group.label class="required">
                        @lang('shopify::app.shopify.metafield.index.attributes')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="text"
                        id="attribute"
                        name="attribute"
                        rules="required"
                        v-model="attribute"
                        :label="trans('shopify::app.shopify.metafield.index.attribute')"
                        :placeholder="trans('shopify::app.shopify.metafield.index.attribute')"
                    />

                    <x-admin::form.control-group.error control-name="attribute"/>
                </x-admin::form.control-group>

                <input type="hidden" name="attribute" :value="attribute" v-if="referenceSource === 'metaobject'" />
                <input type="hidden" name="name_space_key" :value="name_space_key" />

                <x-admin::form.control-group class="w-[525px]" v-if="referenceSource !== 'metaobject'">
                    <x-admin::form.control-group.label class="required">
                        @lang('shopify::app.shopify.metafield.index.name_space_key')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="text"
                        id="name_space_key_display"
                        name="name_space_key_display"
                        v-model="name_space_key"
                        disabled="disabled"
                        :label="trans('shopify::app.shopify.metafield.index.name_space_key')"
                        :placeholder="trans('shopify::app.shopify.metafield.index.name_space_key')"
                    />

                    <x-admin::form.control-group.error control-name="name_space_key"/>
                </x-admin::form.control-group>
                @if ($metaField->type === 'link')
                    <x-admin::form.control-group class="w-[525px]">
                        <x-admin::form.control-group.label>
                            @lang('shopify::app.shopify.metafield.index.anchor-text')
                        </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="select"
                            id="link_text_attribute"
                            name="link_text_attribute"
                            track-by="code"
                            label-by="label"
                            :value="$linkTextAttribute ?? ''"
                            :label="trans('shopify::app.shopify.metafield.index.anchor-text')"
                            async=true
                            :entityName="json_encode(['text', 'textarea', 'boolean', 'select', 'multiselect', 'date', 'image', 'file', 'asset'])"
                            :placeholder="trans('shopify::app.shopify.metafield.index.anchor-text')"
                            :list-route="route('admin.shopify.get-attribute')"
                        />
                    </x-admin::form.control-group>
                @endif
                @if ($metaField?->ownerType === 'PRODUCT')
                    <x-admin::form.control-group class="w-[525px]">
                        <x-admin::form.control-group.label>
                            @lang('shopify::app.shopify.metafield.index.taxonomy-category')
                        </x-admin::form.control-group.label>

                        <v-taxonomy-category-picker :initial='@json($taxonomyOptions)' @count-change="taxonomyCount = $event"></v-taxonomy-category-picker>
                    </x-admin::form.control-group>
                @endif
                <x-admin::form.control-group class="w-[525px]">
                    <x-admin::form.control-group.label>
                        @lang('shopify::app.shopify.metafield.index.description')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="text"
                        id="description"
                        name="description"
                        :value="old('description') ?? $metaField->description"
                        :label="trans('shopify::app.shopify.metafield.index.description')"
                        :placeholder="trans('shopify::app.shopify.metafield.index.description')"
                    />

                    <x-admin::form.control-group.error control-name="description"/>
                </x-admin::form.control-group>
                @if (! $isReference && $typeofminmx == 'text' && $minvalueLabel)
                <div>
                    <div :class="{ 'flex items-center gap-2':  width != null }">
                    @php
                        $widthClass = $width != null ? 'w-[360px]' : 'w-[525px]';
                    @endphp
                        <x-admin::form.control-group class="{{ $widthClass }}">
                            <x-admin::form.control-group.label>
                                    @lang($minvalueLabel)
                            </x-admin::form.control-group.label>
                                <x-admin::form.control-group.control
                                    type="text"
                                    id="minvalue"
                                    name="minvalue"
                                    :label="$minvalueLabel"
                                    :placeholder="$minvalueLabel"
                                    :value="old('minvalue') ?? $validations?->min ?? null"
                                />

                            <x-admin::form.control-group.error control-name="minvalue"/>
                        </x-admin::form.control-group>
                        @if ($width != null)
                        <x-admin::form.control-group class="w-[170px] mt-5">
                            <x-admin::form.control-group.control
                                type="select"
                                id="minunit"
                                name="minunit"
                                track-by="id"
                                label-by="name"
                                :value="old('minunit') ?? $validations?->minunit ?? null"
                                :options="json_encode($width, true)"
                                label="min unit"
                                placeholder="min unit"
                            />
                            <x-admin::form.control-group.error control-name="minunit"/>
                        </x-admin::form.control-group>
                        @endif
                    </div>
                    <div :class="{ 'flex items-center gap-2':  width != null }">
                        <x-admin::form.control-group class="{{ $widthClass }}">
                            <x-admin::form.control-group.label>
                                @lang($maxvalueLabel)
                            </x-admin::form.control-group.label>
                                <x-admin::form.control-group.control
                                    type="text"
                                    id="maxvalue"
                                    name="maxvalue"
                                    :label="$maxvalueLabel"
                                    :placeholder="$maxvalueLabel"
                                    :value="old('maxvalue') ?? $validations?->max ?? null"
                                />

                                <x-admin::form.control-group.error control-name="maxvalue"/>
                        </x-admin::form.control-group>
                        @if ($width != null)
                        <x-admin::form.control-group class="w-[170px] mt-5">
                            <x-admin::form.control-group.control
                                type="select"
                                id="maxunit"
                                name="maxunit"
                                track-by="id"
                                label-by="name"
                                :options="json_encode($width, true)"
                                :value="old('maxunit') ?? $validations?->maxunit ?? null"
                                label="max unit"
                                placeholder="max unit"
                            />
                            <x-admin::form.control-group.error control-name="maxunit"/>
                        </x-admin::form.control-group>
                        @endif
                    </div>
                </div>
                @endif
                @if (! $isReference && $typeofminmx == 'date' && $minvalueLabel)
                <div>
                    <x-admin::form.control-group class="w-[525px]">
                    <x-admin::form.control-group.label>
                        @lang($minvalueLabel)
                    </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="date"
                            id="minvalue"
                            name="minvalue"
                            :label="$minvalueLabel"
                            :placeholder="$minvalueLabel"
                            :value="old('minvalue') ?? $validations?->min ?? null"
                        />

                        <x-admin::form.control-group.error control-name="minvalue"/>
                    </x-admin::form.control-group>
                    <x-admin::form.control-group class="w-[525px]">
                    <x-admin::form.control-group.label>
                            @lang($maxvalueLabel)
                    </x-admin::form.control-group.label>
                        <x-admin::form.control-group.control
                            type="date"
                            id="maxvalue"
                            name="maxvalue"
                            :label="$maxvalueLabel"
                            :placeholder="$maxvalueLabel"
                            :value="old('maxvalue') ?? $validations?->max ?? null"
                        />

                        <x-admin::form.control-group.error control-name="maxvalue"/>
                    </x-admin::form.control-group>
                </div>
                @endif
                <input type="hidden" name="pin" value="0" />
                <x-admin::form.control-group class="w-[525px]" v-if="taxonomyCount === 0">
                    <x-admin::form.control-group.label>
                        @lang('shopify::app.shopify.metafield.datagrid.pin')
                    </x-admin::form.control-group.label>
                    <x-admin::form.control-group.control
                        type="switch"
                        name="pin"
                        value="1"
                        :checked="(boolean) $metaField->pin"
                    />

                    <x-admin::form.control-group.error control-name="pin"/>
                </x-admin::form.control-group>

                @if ($adminFilterable)
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('shopify::app.shopify.metafield.index.adminFilterable')
                    </x-admin::form.control-group.label>
                    <input 
                        type="hidden"
                        name="adminFilterable"
                        value="0"
                    />
                    <x-admin::form.control-group.control
                        type="switch"
                        name="adminFilterable"
                        value="1"
                        :checked="(boolean) $options?->adminFilterable"
                    />
                </x-admin::form.control-group>
                @endif
                @if ($smartCollectionCondition)
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('shopify::app.shopify.metafield.index.smartCollectionCondition')
                    </x-admin::form.control-group.label>
                    <input 
                        type="hidden"
                        name="smartCollectionCondition"
                        value="0"
                    />
                    <x-admin::form.control-group.control
                        type="switch"
                        name="smartCollectionCondition"
                        value="1"
                        :checked="(boolean) $options?->smartCollectionCondition"
                    />
                </x-admin::form.control-group>
                @endif
                <x-admin::form.control-group class="w-[525px]">
                    <x-admin::form.control-group.label>
                        @lang('shopify::app.shopify.metafield.index.storefronts')
                    </x-admin::form.control-group.label>
                    <x-admin::form.control-group.label v-text="storefronts">
                    </x-admin::form.control-group.label>
                    <input 
                        type="hidden"
                        name="storefronts"
                        value="0"
                    />
                    <x-admin::form.control-group.control
                        type="switch"
                        name="storefronts"
                        value="1"
                        v-model="enableStorefronts"
                        @change="togglenableStorefronts"
                        :checked="(boolean) $metaField?->storefronts"
                    />
                </x-admin::form.control-group>
        </script>
        <script type="module">
            app.component('v-metafield', {
                template: '#v-metafield-template',
                data() {
                    return {
                        storefronts: @json($storefronts ?? null),
                        width: @json($width ?? null),
                        referenceSource: @json($refSource ?? 'association'),
                        associationType: @json($refAssoc ?? 'related_products'),
                        referenceAs: @json($refAs ?? 'product'),
                        referenceListChoice: @json($metaField->listvalue ? 'list' : 'one'),
                        metaobjectDefinition: @json($refMetaobjectDefinition ?? ''),
                        attribute: @json($metaField->attribute ?? ''),
                        name_space_key: @json($metaField->name_space_key ?? ''),
                        taxonomyCount: {{ count($taxonomyOptions ?? []) }},
                    };
                },
                computed: {
                    referenceTypeBase() {
                        if (this.referenceSource === 'metaobject') {
                            return 'metaobject_reference';
                        }

                        return this.referenceSource === 'categories'
                            ? 'collection_reference'
                            : (this.referenceAs === 'variant' ? 'variant_reference' : 'product_reference');
                    },
                    referenceTypeLabel() {
                        const map = {
                            product_reference: 'Product Reference',
                            variant_reference: 'Variant Reference',
                            collection_reference: 'Collection Reference',
                        };
                        return map[this.referenceTypeBase] || '';
                    },
                    referenceType() {
                        return this.referenceListChoice === 'list' ? 'list.' + this.referenceTypeBase : this.referenceTypeBase;
                    },
                },
                methods: {
                    togglenableStorefronts() {
                        this.storefronts = this.enableStorefronts ? 'Read' : 'No access';
                    },

                    handleReferenceSelect(event, field) {
                        if (event && (typeof event === 'string' || event instanceof String)) {
                            this[field] = JSON.parse(event)?.id;

                            if (field === 'referenceSource') {
                                this.referenceAs = this.referenceSource === 'categories' ? 'collection' : 'product';
                            }

                            this.applyReferenceNaming();
                        }
                    },

                    applyReferenceNaming() {
                        if (! this.referenceSource || this.referenceSource === 'metaobject') {
                            return;
                        }
                        let key, name;
                        if (this.referenceSource === 'categories') {
                            key = 'collections';
                            name = 'Collections';
                        } else {
                            const labels = { related_products: 'Related products', up_sells: 'Up-sells', cross_sells: 'Cross-sells' };
                            key = this.associationType + (this.referenceAs === 'variant' ? '_variant' : '');
                            name = (labels[this.associationType] ?? this.associationType) + (this.referenceAs === 'variant' ? ' (Variant)' : '');
                        }
                        this.name_space_key = 'custom.' + key;
                        this.attribute = name;
                    },
                }
            })
        </script>

        @include('shopify::metafield._taxonomy-picker')

        @include('shopify::metafield._metaobject-mapper')
    @endPushOnce
</x-admin::layouts.with-history>
