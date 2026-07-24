<x-admin::layouts>
    <x-slot:title>
        @lang('shopify::app.shopify.metafield.index.title')
    </x-slot>

    <v-metafield>
        <div class="flex justify-between items-center">
            <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                @lang('shopify::app.shopify.metafield.index.title')
            </p>

            <div class="flex gap-x-2.5 items-center">
                <!-- Create User Button -->
                @if (bouncer()->hasPermission('shopify.meta-fields.create'))
                    <button
                        type="button"
                        class="primary-button"
                    >
                        @lang('shopify::app.shopify.metafield.index.create')
                    </button>
                @endif
            </div>
        </div>

        <!-- DataGrid Shimmer -->
        <x-admin::shimmer.datagrid />
    </v-metafield>
    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-metafield-template"
        >
            <div class="flex justify-between items-center">
                <p class="text-xl text-gray-800 dark:text-slate-50 font-bold">
                    @lang('shopify::app.shopify.metafield.index.title')
                </p>

                <div class="flex gap-x-2.5 items-center">
                    <!-- User Create Button -->
                    @if (bouncer()->hasPermission('shopify.meta-fields.create'))
                        <button
                            type="button"
                            class="primary-button"
                            @click="openCreate()"
                        >
                            @lang('shopify::app.shopify.metafield.index.create')
                        </button>
                    @endif
                </div>
            </div>
            <!-- Datagrid -->
            <x-admin::datagrid :src="route('shopify.metafield.index')" ref="datagrid" class="mb-8"/>
            <!-- Modal Form -->
            <x-admin::form
                v-slot="{ meta, errors, handleSubmit }"
                as="div"
                ref="modalForm"
            >
                <form
                    @submit="handleSubmit($event, create)"
                    ref="metafieldCreateForm"
                >
                    <!-- User Create Modal -->
                    <x-admin::modal ref="metafieldCreateModal">
                        <!-- Modal Header -->
                        <x-slot:header>
                             <p class="text-lg text-gray-800 dark:text-white font-bold">
                                @lang('shopify::app.shopify.metafield.index.create')
                            </p>
                        </x-slot>

                        <!-- Modal Content -->
                        <x-slot:content>
                            <!-- Definition Type -->
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.metafield.index.definitiontype')
                                </x-admin::form.control-group.label>
                                    @php
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
                                        $attributeType = ['text', 'textarea', 'boolean', 'select', 'multiselect', 'date', 'datetime', 'image', 'gallery', 'file', 'asset'];
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
                                    @endphp
                                <x-admin::form.control-group.control
                                    type="select"
                                    id="ownerType"
                                    name="ownerType"
                                    rules="required"
                                    :options="$metaType"
                                    :label="trans('shopify::app.shopify.metafield.index.title')"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.title')"
                                    @input="handleownerTypeChnage($event, 'code')"
                                    track-by="id"
                                    label-by="name"
                                />

                                <x-admin::form.control-group.error control-name="ownerType"/>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>
                                    @lang('shopify::app.shopify.metafield.index.reference')
                                </x-admin::form.control-group.label>
                                <input type="hidden" name="reference_mode" value="0" />
                                <x-admin::form.control-group.control
                                    type="switch"
                                    name="reference_mode"
                                    value="1"
                                    v-model="referenceMode"
                                />
                            </x-admin::form.control-group>

                            <template v-if="referenceMode">
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label class="required">
                                        @lang('shopify::app.shopify.metafield.index.reference-source')
                                    </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="select"
                                        name="reference_source"
                                        :options="$referenceSourceOptions"
                                        :placeholder="trans('shopify::app.shopify.metafield.index.reference-source')"
                                        track-by="id"
                                        label-by="name"
                                        @input="handleReferenceSelect($event, 'referenceSource')"
                                    />
                                </x-admin::form.control-group>

                                <template v-if="referenceSource === 'association'">
                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            @lang('shopify::app.shopify.metafield.index.association-type')
                                        </x-admin::form.control-group.label>
                                        <x-admin::form.control-group.control
                                            type="select"
                                            name="association_type"
                                            :options="$associationTypeOptions"
                                            :value="json_encode(['id' => 'related_products', 'name' => trans('shopify::app.shopify.metafield.index.related')])"
                                            :placeholder="trans('shopify::app.shopify.metafield.index.association-type')"
                                            track-by="id"
                                            label-by="name"
                                            @input="handleReferenceSelect($event, 'associationType')"
                                        />
                                    </x-admin::form.control-group>
                                </template>

                                {{-- Type: Product/Variant when source is association, Collection when categories. --}}
                                <x-admin::form.control-group v-if="referenceSource === 'association'">
                                    <x-admin::form.control-group.label class="required">
                                        @lang('shopify::app.shopify.metafield.index.resolved-type')
                                    </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="select"
                                        name="reference_as"
                                        :options="$referenceAsOptions"
                                        :value="json_encode(['id' => 'product', 'name' => trans('shopify::app.shopify.metafield.index.as-product')])"
                                        :placeholder="trans('shopify::app.shopify.metafield.index.resolved-type')"
                                        track-by="id"
                                        label-by="name"
                                        @input="handleReferenceSelect($event, 'referenceAs')"
                                    />
                                </x-admin::form.control-group>

                                <x-admin::form.control-group v-if="referenceSource === 'categories'">
                                    <x-admin::form.control-group.label class="required">
                                        @lang('shopify::app.shopify.metafield.index.resolved-type')
                                    </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="select"
                                        name="reference_as"
                                        :options="$referenceCollectionOptions"
                                        :value="json_encode(['id' => 'collection', 'name' => trans('shopify::app.shopify.metafield.index.as-collection')])"
                                        :placeholder="trans('shopify::app.shopify.metafield.index.resolved-type')"
                                        track-by="id"
                                        label-by="name"
                                        @input="handleReferenceSelect($event, 'referenceAs')"
                                    />
                                </x-admin::form.control-group>

                                <template v-if="referenceSource === 'metaobject'">
                                    <x-admin::form.control-group v-if="shopifyCredentials.length > 1">
                                        <x-admin::form.control-group.label class="required">
                                            @lang('shopify::app.shopify.metafield.index.metaobject-credential')
                                        </x-admin::form.control-group.label>
                                        <v-select-handler
                                            track-by="id"
                                            label-by="label"
                                            :options="shopifyCredentials"
                                            :value="selectedCredential"
                                            placeholder="@lang('shopify::app.shopify.metafield.index.metaobject-credential')"
                                            @input="handleCredentialSelect($event)"
                                        ></v-select-handler>
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group>
                                        <x-admin::form.control-group.label class="required">
                                            @lang('shopify::app.shopify.metafield.index.metaobject-definition')
                                        </x-admin::form.control-group.label>
                                        <x-admin::form.control-group.control
                                            type="select"
                                            name="metaobject_definition"
                                            ref="metaobjectDefinition"
                                            async=true
                                            track-by="id"
                                            label-by="label"
                                            ::key="'mo-def-' + selectedCredential + '-' + metaobjectListKey"
                                            ::query-params="{ credential_id: selectedCredential }"
                                            :label="trans('shopify::app.shopify.metafield.index.metaobject-definition')"
                                            :placeholder="trans('shopify::app.shopify.metafield.index.metaobject-definition')"
                                            :list-route="route('shopify.metaobject.definitions.fetch-all')"
                                            @input="handleMetaobjectDefinition($event)"
                                        />
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group>
                                        <div class="flex items-center gap-2">
                                            <v-metaobject-builder :credential-id="selectedCredential" @created="onMetaobjectDefinitionCreated"></v-metaobject-builder>
                                            <v-metaobject-mapper :definition="metaobjectDefinition" :credential-id="selectedCredential"></v-metaobject-mapper>
                                            <v-metaobject-sync v-if="shopifyCredentials.length > 1" :definition="metaobjectDefinition" :credential-id="selectedCredential"></v-metaobject-sync>
                                        </div>
                                    </x-admin::form.control-group>
                                </template>

                                <input type="hidden" name="type" :value="referenceType" v-if="referenceSource" />

                                <x-admin::form.control-group v-if="referenceSource && referenceSource !== 'metaobject'">
                                    <div class="flex items-center gap-4">
                                        <x-admin::form.control-group>
                                            <x-admin::form.control-group.control
                                                type="radio"
                                                id="ref_listvalue_one"
                                                name="ref_listvalue"
                                                value="one"
                                                for="ref_listvalue_one"
                                                @change="referenceListChoice = 'one'"
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
                                                @change="referenceListChoice = 'list'"
                                                ::checked="referenceListChoice === 'list'"
                                            />
                                            <x-admin::form.control-group.label for="ref_listvalue_list">
                                                @lang('shopify::app.shopify.metafield.index.listvalue')
                                            </x-admin::form.control-group.label>
                                        </x-admin::form.control-group>
                                    </div>
                                </x-admin::form.control-group>
                            </template>

                            <!-- Unopim Attribute -->
                            <x-admin::form.control-group v-if="!referenceMode">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.metafield.index.attribute')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="select"
                                    id="code"
                                    name="code"
                                    rules="required"
                                    track-by="code"
                                    label-by="label"
                                    :label="trans('shopify::app.shopify.metafield.index.attribute')"
                                    async=true
                                    :entityName="json_encode($attributeType)"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.attribute')"
                                    :list-route="route('admin.shopify.get-attribute')"
                                    @input="handleSelectChange($event, 'code')"
                                />

                                <x-admin::form.control-group.error control-name="code" />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group v-if="ownerType === 'PRODUCT'">
                                <x-admin::form.control-group.label>
                                    @lang('shopify::app.shopify.metafield.index.taxonomy-category')
                                </x-admin::form.control-group.label>

                                <v-taxonomy-category-picker :initial="[]" @count-change="taxonomyCount = $event"></v-taxonomy-category-picker>
                            </x-admin::form.control-group>

                            <!-- Content Type -->
                            <x-admin::form.control-group v-if="!referenceMode">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.metafield.index.ContentTypeName')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="select"
                                    id="type"
                                    name="type"
                                    rules="required"
                                    track-by="id"
                                    label-by="name"
                                    v-model="selectedType"
                                    ::options="contentTypeOptions"
                                    @input="handleContentTypeChnage($event, 'code')"
                                    :label="trans('shopify::app.shopify.metafield.index.ContentTypeName')"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.ContentTypeName')"
                                    ref="mediaAttributes"
                                />

                                <x-admin::form.control-group.error control-name="type"/>
                            </x-admin::form.control-group>

                            <input
                                type="hidden"
                                name="content_type"
                                :value="contentType"
                                v-if="!referenceMode && key === 'file_reference'"
                            />

                            <x-admin::form.control-group v-if="!referenceMode && key === 'file_reference' && contentType === 'FILE'">
                                <x-admin::form.control-group.label>
                                    @lang('shopify::app.shopify.metafield.index.accepted-file-types')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="select"
                                    id="file_types"
                                    name="file_types"
                                    track-by="id"
                                    label-by="name"
                                    v-model="fileTypeMode"
                                    ::options="fileTypeOptions"
                                    @input="handleFileTypeChange($event)"
                                    :label="trans('shopify::app.shopify.metafield.index.accepted-file-types')"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.accepted-file-types')"
                                />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group v-if="!referenceMode && key === 'link'">
                                <x-admin::form.control-group.label>
                                    @lang('shopify::app.shopify.metafield.index.anchor-text')
                                </x-admin::form.control-group.label>
                                <x-admin::form.control-group.control
                                    type="select"
                                    id="link_text_attribute"
                                    name="link_text_attribute"
                                    track-by="code"
                                    label-by="label"
                                    :label="trans('shopify::app.shopify.metafield.index.anchor-text')"
                                    async=true
                                    :entityName="json_encode($attributeType)"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.anchor-text')"
                                    :list-route="route('admin.shopify.get-attribute')"
                                />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group v-if="!referenceMode && urlvalidation == true">
                                <x-admin::form.control-group.label>
                                    @lang('shopify::app.shopify.metafield.index.urlvalidation')
                                </x-admin::form.control-group.label>
                                <x-admin::form.control-group.label>
                                     <span class="icon-information text-lg"></span> <p class="break-words text-xs text-gray-500 dark:text-gray-400"> @lang('shopify::app.shopify.metafield.index.urlvalidationdata')</p>
                                </x-admin::form.control-group.label>
                            </x-admin::form.control-group>

                            <div class="flex items-center gap-4" v-if=" (contenttypeSelect == 1) && !referenceMode">
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.control
                                        type="radio"
                                        id="is_unique_onevalue"
                                        name="listvalue"
                                        value="0"
                                        for="is_unique_onevalue"
                                        @change="toggleOneValue($event)"
                                        checked
                                    />

                                    <x-admin::form.control-group.label
                                        for="is_unique_onevalue"
                                    >
                                        One value
                                    </x-admin::form.control-group.label>
                                </x-admin::form.control-group>

                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.control
                                        type="radio"
                                        id="is_unique_listvalue"
                                        name="listvalue"
                                        value="1"
                                        for="is_unique_listvalue"
                                        @change="toggleOneValue($event)"
                                    />

                                    <x-admin::form.control-group.label
                                        for="is_unique_listvalue"
                                    >
                                        List of values
                                    </x-admin::form.control-group.label>
                                </x-admin::form.control-group>
                            </div>
                            <x-admin::form.control-group v-if="referenceSource !== 'metaobject'">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.metafield.index.attributes')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    id="attribute"
                                    name="attribute"
                                    rules="required"
                                    v-model="attribute"
                                    :label="trans('shopify::app.shopify.metafield.index.attributes')"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.attributes')"
                                />

                                <x-admin::form.control-group.error control-name="attribute"/>
                            </x-admin::form.control-group>
                            <x-admin::form.control-group v-if="referenceSource !== 'metaobject'">
                                <x-admin::form.control-group.label class="required">
                                    @lang('shopify::app.shopify.metafield.index.name_space_key')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    id="name_space_key"
                                    name="name_space_key"
                                    rules="required"
                                    v-model="name_space_key"
                                    :label="trans('shopify::app.shopify.metafield.index.name_space_key')"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.name_space_key')"
                                />

                                <x-admin::form.control-group.error control-name="name_space_key"/>
                            </x-admin::form.control-group>

                            <template v-if="referenceSource === 'metaobject'">
                                <input type="hidden" name="attribute" :value="attribute" />
                                <input type="hidden" name="name_space_key" :value="name_space_key" />
                            </template>
                            <x-admin::form.control-group>
                                <x-admin::form.control-group.label>
                                    @lang('shopify::app.shopify.metafield.index.description')
                                </x-admin::form.control-group.label>

                                <x-admin::form.control-group.control
                                    type="text"
                                    id="description"
                                    name="description"
                                    :label="trans('shopify::app.shopify.metafield.index.description')"
                                    :placeholder="trans('shopify::app.shopify.metafield.index.description')"
                                />

                                <x-admin::form.control-group.error control-name="description"/>
                            </x-admin::form.control-group>
                            <div v-if="!referenceMode && typeofminmx == 'text'">
                                <div :class="{ 'flex items-center gap-2':  width != null }">
                                    <x-admin::form.control-group ::class="width">
                                        <x-admin::form.control-group.label v-text="minvalueLabel">
                                        </x-admin::form.control-group.label>
                                        <x-admin::form.control-group.control
                                            type="text"
                                            id="minvalue"
                                            name="minvalue"
                                            ::label="minvalueLabel"
                                            ::placeholder="minvalueLabel"
                                            value=""
                                        />
                                        <x-admin::form.control-group.error control-name="minvalue"/>
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group v-if=" (width != null)" class="w-[170px] mt-5">
                                        <x-admin::form.control-group.control
                                            type="select"
                                            id="minunit"
                                            name="minunit"
                                            track-by="id"
                                            label-by="name"
                                            ::options="unitTypeOptions"
                                            label="min unit"
                                            placeholder="min unit"
                                            value=""
                                            ref="minunitvalue"
                                        />
                                        <x-admin::form.control-group.error control-name="minunit"/>
                                    </x-admin::form.control-group>
                                </div>
                                <div :class="{ 'flex items-center gap-2':  width != null }">
                                    <x-admin::form.control-group ::class="width">
                                        <x-admin::form.control-group.label v-text="maxvalueLabel">
                                        </x-admin::form.control-group.label>
                                        <x-admin::form.control-group.control
                                            type="text"
                                            id="maxvalue"
                                            name="maxvalue"
                                            ::label="maxvalueLabel"
                                            ::placeholder="maxvalueLabel"
                                            value=""
                                        />

                                        <x-admin::form.control-group.error control-name="maxvalue"/>
                                    </x-admin::form.control-group>

                                    <x-admin::form.control-group v-if=" (width != null)" class="w-[170px] mt-5">
                                        <x-admin::form.control-group.control
                                            type="select"
                                            id="maxunit"
                                            name="maxunit"
                                            track-by="id"
                                            label-by="name"
                                            ::options="unitTypeOptions"
                                            label="max unit"
                                            placeholder="max unit"
                                            value=""
                                            ref="maxunitvalue"
                                        />
                                        <x-admin::form.control-group.error control-name="maxunit"/>
                                    </x-admin::form.control-group>
                                </div>
                            </div>
                            <div v-if="!referenceMode && key === 'id'">
                                @php
                                    $regexPresetOptions = json_encode([
                                        ['id' => '^[a-zA-Z]+$', 'name' => trans('shopify::app.shopify.metafield.index.regex-alphabetical')],
                                        ['id' => '^[a-zA-Z0-9]+$', 'name' => trans('shopify::app.shopify.metafield.index.regex-alphanumeric')],
                                        ['id' => '^[0-9]+$', 'name' => trans('shopify::app.shopify.metafield.index.regex-numeric')],
                                        ['id' => '^[a-zA-Z0-9_-]{3,20}$', 'name' => trans('shopify::app.shopify.metafield.index.regex-sku')],
                                    ]);
                                @endphp
                                <x-admin::form.control-group>
                                    <x-admin::form.control-group.label>
                                        @lang('shopify::app.shopify.metafield.index.regex')
                                    </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="select"
                                        name="regex_preset"
                                        :options="$regexPresetOptions"
                                        track-by="id"
                                        label-by="name"
                                        :placeholder="trans('shopify::app.shopify.metafield.index.regex-preset')"
                                        @input="handleRegexPreset($event)"
                                    />
                                    <x-admin::form.control-group.control
                                        type="text"
                                        id="regex"
                                        name="regex"
                                        v-model="regexValue"
                                        class="mt-2"
                                        :label="trans('shopify::app.shopify.metafield.index.regex')"
                                        :placeholder="trans('shopify::app.shopify.metafield.index.regex-placeholder')"
                                    />
                                    <x-admin::form.control-group.error control-name="regex"/>
                                </x-admin::form.control-group>
                            </div>
                            <div v-if="!referenceMode && typeofminmx == 'date'">
                                <x-admin::form.control-group>
                                <x-admin::form.control-group.label v-text="minvalueLabel">
                                </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="date"
                                        id="minvalue"
                                        name="minvalue"
                                        ::label="minvalueLabel"
                                        ::placeholder="minvalueLabel"
                                        value=""
                                    />

                                    <x-admin::form.control-group.error control-name="minvalue"/>
                                </x-admin::form.control-group>
                                <x-admin::form.control-group>
                                <x-admin::form.control-group.label v-text="maxvalueLabel">
                                </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="date"
                                        id="maxvalue"
                                        name="maxvalue"
                                        ::label="maxvalueLabel"
                                        ::placeholder="maxvalueLabel"
                                        ref="maxvalueref"
                                        value=""
                                    />

                                    <x-admin::form.control-group.error control-name="maxvalue"/>
                                </x-admin::form.control-group>
                            </div>

                            <div v-if="!referenceMode && typeofminmx == 'datetime'">
                                <x-admin::form.control-group>
                                <x-admin::form.control-group.label v-text="minvalueLabel">
                                </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="datetime"
                                        id="minvalue"
                                        name="minvalue"
                                        ::label="minvalueLabel"
                                        ::placeholder="minvalueLabel"
                                        value=""
                                    />

                                    <x-admin::form.control-group.error control-name="minvalue"/>
                                </x-admin::form.control-group>
                                <x-admin::form.control-group>
                                <x-admin::form.control-group.label v-text="maxvalueLabel">
                                </x-admin::form.control-group.label>
                                    <x-admin::form.control-group.control
                                        type="datetime"
                                        id="maxvalue"
                                        name="maxvalue"
                                        ::label="maxvalueLabel"
                                        ::placeholder="maxvalueLabel"
                                        value=""
                                    />

                                    <x-admin::form.control-group.error control-name="maxvalue"/>
                                </x-admin::form.control-group>
                            </div>

                            <input type="hidden" name="pin" value="0" />
                            <x-admin::form.control-group v-if="taxonomyCount === 0">
                                <x-admin::form.control-group.label>
                                    @lang('shopify::app.shopify.metafield.datagrid.pin')
                                </x-admin::form.control-group.label>
                                <x-admin::form.control-group.control
                                    type="switch"
                                    name="pin"
                                    value="1"
                                    :checked="true"
                                />
                                <x-admin::form.control-group.error control-name="pin"/>
                            </x-admin::form.control-group>

                            <x-admin::form.control-group v-if="!referenceMode && adminFilterable == 1">
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
                                />
                            </x-admin::form.control-group>
                            <x-admin::form.control-group v-if="!referenceMode && smartCollectionCondition == 1">
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
                                />
                            </x-admin::form.control-group>

                            <x-admin::form.control-group>
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
                                    :checked="true"
                                />
                            </x-admin::form.control-group>
                        </x-slot>

                        <!-- Modal Footer -->
                        <x-slot:footer>
                            <div class="flex gap-x-2.5 items-center">
                                <button
                                    type="submit"
                                    class="primary-button"
                                >
                                    @lang('shopify::app.shopify.credential.index.save')
                                </button>
                            </div>
                        </x-slot>
                    </x-admin::modal>
                </form>
            </x-admin::form>
        </script>

        <script type="module">
            app.component('v-metafield', {
                template: '#v-metafield-template',
                data() {
                    return {
                        contenttypeSelect: null,
                        ownerType: '',
                        attribute: "",
                        name_space_key: "",
                        contentTypeOptions:  [],
                        unitTypeOptions: [],
                        selectedType: "",
                        onevalue: 0,
                        taxonomyCount: 0,
                        metafieldType: @json($metaFieldType ?? null),
                        metaFieldTypeInShopify: @json($metaFieldTypeInShopify ?? null),
                        typeofminmx: null,
                        maxvalueLabel: null,
                        minvalueLabel: null,
                        adminFilterable: null,
                        smartCollectionCondition: null,
                        storefronts: 'Read',
                        contentTypeName: null,
                        contentType: '',
                        key: null,
                        urlvalidation: false,
                        width: null,
                        fileTypeMode: 'any',
                        regexValue: '',
                        fileTypeOptions: [
                            { id: 'any', name: "{{ trans('shopify::app.shopify.metafield.index.any-file-type') }}" },
                            { id: 'media', name: "{{ trans('shopify::app.shopify.metafield.index.media-file-only') }}" },
                        ],
                        referenceMode: false,
                        referenceSource: '',
                        associationType: 'related_products',
                        referenceAs: 'product',
                        referenceListChoice: 'one',
                        metaobjectDefinition: '',
                        shopifyCredentials: @json($shopifyCredentials ?? []),
                        selectedCredential: @json($shopifyCredentials[0]['id'] ?? ''),
                        metaobjectListKey: 0,
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
                watch: {
                    referenceMode(on) {
                        if (on) {
                            this.adminFilterable = null;
                            this.smartCollectionCondition = null;
                            this.fileTypeMode = 'any';
                            this.applyReferenceNaming();
                        }
                    },
                },
                methods: {
                    create(params, { setErrors }) {
                        let formData = new FormData(this.$refs.metafieldCreateForm);
                        var minCharslen = formData.get("minvalue") ?? null;
                        var maxCharslen = formData.get("maxvalue") ?? null;
                        if (this.contentTypeName) {
                            formData.set("ContentTypeName", this.contentTypeName);
                        }
                        if (!this.referenceMode && this.key) {
                            formData.set("type", this.key);
                        }
                        if (((minCharslen && minCharslen.trim().length > 0) || (maxCharslen && maxCharslen.trim().length > 0)) &&
                        this.typeofminmx != 'date' && this.typeofminmx != 'datetime'
                    )   {
                            var jsErrors = {};
                            const decimalKeys = ['number_decimal', 'rating', 'dimension', 'volume', 'weight'];
                            const isDecimal = decimalKeys.includes(this.key);
                            const numberRe = isDecimal ? /^\d+(\.\d+)?$/ : /^\d+$/;
                            const limit = this.contentTypeName == 'Rating' ? 9999999999999 : 9007199254740991;

                            if (minCharslen && !numberRe.test(minCharslen)) {
                                jsErrors['minvalue'] = 'Only Number Allowed';
                            } else if (minCharslen && (isDecimal ? Number(minCharslen) : BigInt(minCharslen)) >= limit) {
                                jsErrors['minvalue'] = `Validation value for min can't exceed ${limit}`;
                            }

                            if (maxCharslen && !numberRe.test(maxCharslen)) {
                                jsErrors['maxvalue'] = 'Only Number Allowed';
                            } else if (maxCharslen && (isDecimal ? Number(maxCharslen) : BigInt(maxCharslen)) >= limit) {
                                jsErrors['maxvalue'] = `Validation value for max can't exceed ${limit}`;
                            }

                            if (jsErrors && Object.keys(jsErrors).length > 0) {
                                setErrors(jsErrors);
                                return;
                            }
                        }
                        this.$axios.post("{{ route('shopify.metafield.store') }}", formData)
                            .then((response) => {
                                window.location.href = response.data.redirect_url;
                            })
                            .catch(error => {
                                if (error.response.status == 422) {
                                    setErrors(error.response.data.errors);
                                }
                            });
                    },

                    openCreate() {
                        this.referenceMode = false;
                        this.referenceSource = '';
                        this.associationType = 'related_products';
                        this.referenceAs = 'product';
                        this.referenceListChoice = 'one';
                        this.metaobjectDefinition = '';
                        this.$refs.metafieldCreateModal.open();
                    },

                    handleownerTypeChnage(event) {
                        if (event && typeof event === 'string' || event instanceof String) {
                            this.ownerType = JSON.parse(event)?.id;
                            this.adminFilterable = this.ownerType == 'PRODUCT' ? 1 : null;
                        }
                    },

                    handleFileTypeChange(event) {
                        if (event && typeof event === 'string') {
                            this.fileTypeMode = JSON.parse(event)?.id ?? 'any';
                        }
                    },

                    handleRegexPreset(event) {
                        if (event && typeof event === 'string') {
                            this.regexValue = JSON.parse(event)?.id ?? this.regexValue;
                        }
                    },

                    handleCredentialSelect(event) {
                        if (event && (typeof event === 'string' || event instanceof String)) {
                            this.selectedCredential = JSON.parse(event)?.id ?? this.selectedCredential;
                            this.metaobjectDefinition = '';
                            if (this.$refs.metaobjectDefinition) {
                                this.$refs.metaobjectDefinition.selectedValue = [];
                            }
                        }
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

                    handleMetaobjectDefinition(event) {
                        const definition = (event && (typeof event === 'string' || event instanceof String)) ? JSON.parse(event) : null;
                        this.applyMetaobjectNaming(definition);
                    },

                    onMetaobjectDefinitionCreated(definition) {
                        this.metaobjectListKey++;
                        this.$nextTick(() => {
                            if (this.$refs.metaobjectDefinition) {
                                this.$refs.metaobjectDefinition.selectedValue = definition;
                            }
                            this.applyMetaobjectNaming(definition);
                        });
                    },

                    applyMetaobjectNaming(definition) {
                        const type = String(definition?.id ?? '').split('|')[0] || '';
                        this.metaobjectDefinition = definition?.id ?? '';
                        this.attribute = definition?.label ?? type;
                        this.name_space_key = type ? 'custom.' + type : '';
                    },

                    applyReferenceNaming() {
                        if (! this.referenceMode || ! this.referenceSource || this.referenceSource === 'metaobject') {
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

                    handleSelectChange(event, FieldType) {
                        if (event && typeof event === 'string' || event instanceof String) {
                            var parsedEvent = JSON.parse(event);
                            var attrType = (parsedEvent?.validation && attrType !== '' ) ? parsedEvent?.validation : parsedEvent?.type;
                            if ((parsedEvent?.type === 'select' || parsedEvent?.type === 'multiselect') && parsedEvent?.swatch_type === 'color') {
                                attrType = 'color_swatch';
                            }
                            this.contentTypeOptions = this.metafieldType[attrType] ?? [];
                            this.attribute = parsedEvent?.label ?? parsedEvent?.code;
                            this.name_space_key = 'custom.'+parsedEvent?.code;
                            this.selectedType = "";
                            this.$refs.mediaAttributes.selectedValue = [];
                        }
                    },
                    handleContentTypeChnage(event, FieldType) {
                        if (event && typeof event === 'string' && !event.includes('[]')) {
                            var parsedEvent = JSON.parse(event);
                            this.enableTagsAttribute = 0;
                            var key = parsedEvent?.type ?? parsedEvent?.id;
                            this.key = key;
                            this.contentTypeName = parsedEvent?.name;
                            this.contentType = parsedEvent?.content_type ?? '';
                            this.fileTypeMode = 'any';
                            this.regexValue = '';
                            var contentTypeData = this.metaFieldTypeInShopify[key];
                            this.contenttypeSelect = contentTypeData?.list ? 1 : 0;
                            if (parsedEvent?.id === 'single_line_text_field' || parsedEvent?.id === 'id' || parsedEvent?.id === 'number_integer' || parsedEvent?.id == 'number_decimal' || parsedEvent?.id === 'multi_line_text_field' || parsedEvent?.id === 'rating' || parsedEvent?.id == 'dimension' || parsedEvent?.id == 'volume' || parsedEvent?.id == 'weight') {
                                this.typeofminmx = "text";
                            } else if (parsedEvent?.id === 'date') {
                                this.typeofminmx = "date";
                            } else if (parsedEvent?.id === 'date_time') {
                                this.typeofminmx = "datetime";
                            } else {
                                this.typeofminmx = null;
                            }

                            if (contentTypeData?.unitoptions) {
                                this.width = 'w-[360px]';
                                this.unitTypeOptions = contentTypeData?.unitoptions;
                                if (this.$refs.minunitvalue) {
                                    this.$refs.minunitvalue.selectedValue = [];
                                }
                                if (this.$refs.maxunitvalue) {
                                    this.$refs.maxunitvalue.selectedValue = [];
                                }

                            } else {
                                this.width = null;
                                this.unitTypeOptions = [];
                            }

                            this.urlvalidation = parsedEvent?.id === 'url' ? true : false;

                            this.minvalueLabel = contentTypeData?.validation?.min ?? null;
                            this.maxvalueLabel = contentTypeData?.validation?.max ?? null;
                            this.adminFilterable = (contentTypeData?.adminFilterable && this.ownerType == 'PRODUCT') ? 1 : null;
                            this.smartCollectionCondition = contentTypeData?.smartCollectionCondition ? 1 : null;
                            if (this.metaFieldTypeInShopify[this.key]?.listvalue?.smartCollectionCondition != undefined) {
                                this.smartCollectionCondition = this.onevalue == 0 ? 1 : null
                            }
                        }
                    },

                    toggleOneValue(event) {
                        if (this.metaFieldTypeInShopify[this.key]?.listvalue?.smartCollectionCondition != undefined) {
                            this.onevalue = event.target.value;
                            var check = (this.metaFieldTypeInShopify[this.key]?.listvalue?.smartCollectionCondition == true && event.target.value == 1) ? false : true;
                            
                            this.smartCollectionCondition = check;
                        }
                    },
                    togglenableStorefronts() {
                        this.storefronts = this.enableStorefronts ? 'Read' : 'No access';
                    },
                    toggleUnique(event) {
                        console.log(event.target.value);
                    },
                }
            })
        </script>

        @include('shopify::metafield._taxonomy-picker')

        @include('shopify::metafield._metaobject-builder')

        @include('shopify::metafield._metaobject-mapper')

        @include('shopify::metafield._metaobject-sync')
    @endPushOnce
</x-admin::layouts>
