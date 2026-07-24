@php
    $moAttributeType = $attributeType ?? ['text', 'textarea', 'boolean', 'select', 'multiselect', 'date', 'image', 'file', 'asset'];
    $moContentTypes = $metaFieldType ?? [];
@endphp

<style>
    .mo-toggle { position: relative; display: inline-block; width: 36px; height: 20px; flex: none; }
    .mo-toggle input { opacity: 0; width: 0; height: 0; }
    .mo-toggle .mo-slider { position: absolute; inset: 0; cursor: pointer; background: #cbd5e1; border-radius: 9999px; transition: .2s; }
    .mo-toggle .mo-slider::before { content: ''; position: absolute; height: 16px; width: 16px; left: 2px; top: 2px; background: #fff; border-radius: 50%; transition: .2s; }
    .mo-toggle input:checked + .mo-slider { background: #7c3aed; }
    .mo-toggle input:checked + .mo-slider::before { transform: translateX(16px); }
</style>

<script type="text/x-template" id="v-metaobject-builder-template">
    <span>
        <button type="button" class="secondary-button" @click="openModal">
            @lang('shopify::app.shopify.metafield.index.metaobject-create')
        </button>

        <teleport to="body">
        <x-admin::modal ref="metaobjectBuilderModal" type="medium">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metafield.index.metaobject-create')</p>
            </x-slot>

            <x-slot:content>
                <div v-if="errors.length" class="mb-3 rounded-md bg-red-50 p-2 text-sm text-red-600 dark:bg-red-950">
                    <p v-for="e in errors" :key="e" v-text="e"></p>
                </div>

                <label class="required block text-sm font-medium text-gray-800 dark:text-white">
                    @lang('shopify::app.shopify.metafield.index.metaobject-definition-name')
                </label>
                <input
                    v-model="name"
                    type="text"
                    class="mb-4 w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900"
                />

                <div
                    v-for="(field, index) in fields"
                    :key="renderKey + '-' + index"
                    class="mb-3 rounded-md border border-gray-200 p-3 dark:border-gray-700"
                >
                    <div class="flex items-start gap-2">
                        <input
                            v-model="field.name"
                            placeholder="@lang('shopify::app.shopify.metafield.index.metaobject-field-label')"
                            class="mt-1 w-1/3 rounded-md border border-gray-300 px-2 py-2 text-sm dark:border-gray-600 dark:bg-gray-900"
                        />

                        <div v-if="! field.reference" class="flex-1">
                            <v-async-select-handler
                                track-by="code"
                                label-by="label"
                                :entity-name="JSON.stringify(attributeTypes)"
                                list-route="{{ route('admin.shopify.get-attribute') }}"
                                placeholder="@lang('shopify::app.shopify.metafield.index.attribute')"
                                @input="onAttrSelect(field, $event)"
                            ></v-async-select-handler>
                        </div>

                        <div v-else class="flex-1">
                            <v-select-handler
                                track-by="id"
                                label-by="label"
                                :options="referenceSourceOptions"
                                :value="field.source"
                                placeholder="@lang('shopify::app.shopify.metafield.index.metaobject-source')"
                                @input="onSourceSelect(field, $event)"
                            ></v-select-handler>
                        </div>

                        <span class="icon-delete mt-2 cursor-pointer text-xl text-gray-400" @click="removeField(index)"></span>
                    </div>

                    <div class="mt-2 flex items-center gap-4">
                        <span class="inline-flex cursor-pointer items-center gap-1 text-xs text-gray-600 dark:text-gray-300" @click="field.required = ! field.required">
                            <span class="text-2xl" :class="field.required ? 'icon-checkbox-check text-violet-700' : 'icon-checkbox-normal'"></span>
                            required
                        </span>
                        <span class="inline-flex cursor-pointer items-center gap-1 text-xs text-gray-600 dark:text-gray-300" @click="field.list = ! field.list">
                            <span class="text-2xl" :class="field.list ? 'icon-checkbox-check text-violet-700' : 'icon-checkbox-normal'"></span>
                            list of values
                        </span>
                        <span class="inline-flex cursor-pointer items-center gap-1 text-xs text-gray-600 dark:text-gray-300" @click="field.reference = ! field.reference; onReferenceToggle(field)">
                            <span class="text-2xl" :class="field.reference ? 'icon-checkbox-check text-violet-700' : 'icon-checkbox-normal'"></span>
                            @lang('shopify::app.shopify.metafield.index.reference')
                        </span>
                    </div>

                    <div v-if="! field.reference && field.attribute_code" class="mt-2 w-1/2">
                        <v-select-handler
                            :key="field.attribute_type"
                            track-by="id"
                            label-by="name"
                            :options="contentTypeOptions(field)"
                            :value="field.shopify_type"
                            placeholder="@lang('shopify::app.shopify.metafield.index.ContentTypeName')"
                            @input="onContentTypeSelect(field, $event)"
                        ></v-select-handler>
                    </div>

                    <div v-if="field.reference && field.source === 'association'" class="mt-2 flex items-start gap-2">
                        <div class="flex-1">
                            <v-select-handler
                                track-by="id"
                                label-by="label"
                                :options="associationTypeOptions"
                                :value="field.assoc_type"
                                placeholder="@lang('shopify::app.shopify.metafield.index.association-type')"
                                @input="onAssocTypeSelect(field, $event)"
                            ></v-select-handler>
                        </div>
                        <div class="flex-1">
                            <v-select-handler
                                track-by="id"
                                label-by="label"
                                :options="asOptions"
                                :value="field.as"
                                placeholder="@lang('shopify::app.shopify.metafield.index.resolved-type')"
                                @input="onAsSelect(field, $event)"
                            ></v-select-handler>
                        </div>
                    </div>

                    <div v-if="field.reference && field.source === 'metaobject'" class="mt-2 flex items-start gap-2">
                        <div class="flex-1">
                            <v-select-handler
                                :key="'child-' + index + '-' + field.child"
                                track-by="id"
                                label-by="label"
                                :options="definitions"
                                :value="field.child"
                                placeholder="@lang('shopify::app.shopify.metafield.index.metaobject-definition')"
                                @input="onChildSelect(field, $event)"
                            ></v-select-handler>
                        </div>
                        <v-metaobject-builder :credential-id="credentialId" @created="onNestedCreated(index, $event)"></v-metaobject-builder>
                    </div>
                </div>

                <button type="button" class="secondary-button" @click="addField">
                    @lang('shopify::app.shopify.metafield.index.metaobject-add-field')
                </button>

                <div class="mt-4 rounded-md border border-gray-200 p-3 dark:border-gray-700">
                    <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metafield.index.metaobject-options')</p>

                    <label v-for="opt in optionToggles" :key="opt.key" class="flex items-center justify-between py-1.5">
                        <span class="text-sm text-gray-700 dark:text-gray-300" v-text="opt.label"></span>
                        <span class="mo-toggle">
                            <input type="checkbox" v-model="options[opt.key]" />
                            <span class="mo-slider"></span>
                        </span>
                    </label>
                </div>
            </x-slot>

            <x-slot:footer>
                <div class="flex w-full justify-end gap-2.5">
                    <button type="button" class="secondary-button" @click="closeModal">Cancel</button>
                    <button type="button" class="primary-button" :disabled="saving" @click="save">Save</button>
                </div>
            </x-slot>
        </x-admin::modal>
        </teleport>
    </span>
</script>

<script type="module">
    app.component('v-metaobject-builder', {
        template: '#v-metaobject-builder-template',

        props: {
            credentialId: { type: [String, Number], default: '' },
        },

        emits: ['created'],

        data() {
            return {
                name: '',
                fields: [this.blankField()],
                renderKey: 0,
                options: this.defaultOptions(),
                optionToggles: [
                    { key: 'publishable', label: '@lang('shopify::app.shopify.metafield.index.metaobject-active-draft')' },
                    { key: 'translatable', label: '@lang('shopify::app.shopify.metafield.index.metaobject-translations')' },
                    { key: 'onlineStore', label: '@lang('shopify::app.shopify.metafield.index.metaobject-online-store')' },
                    { key: 'storefront', label: '@lang('shopify::app.shopify.metafield.index.metaobject-storefront-access')' },
                    { key: 'customerAccount', label: '@lang('shopify::app.shopify.metafield.index.metaobject-customer-access')' },
                ],
                attributeTypes: @json($moAttributeType),
                contentTypes: @json($moContentTypes),
                definitions: [],
                referenceSourceOptions: [
                    { id: 'association', label: '@lang('shopify::app.shopify.metafield.index.association')' },
                    { id: 'categories', label: '@lang('shopify::app.shopify.metafield.index.categories')' },
                    { id: 'metaobject', label: '@lang('shopify::app.shopify.metafield.index.metaobject')' },
                ],
                associationTypeOptions: [
                    { id: 'related_products', label: '@lang('shopify::app.shopify.metafield.index.related')' },
                    { id: 'up_sells', label: '@lang('shopify::app.shopify.metafield.index.up-sells')' },
                    { id: 'cross_sells', label: '@lang('shopify::app.shopify.metafield.index.cross-sells')' },
                ],
                asOptions: [
                    { id: 'product', label: '@lang('shopify::app.shopify.metafield.index.as-product')' },
                    { id: 'variant', label: '@lang('shopify::app.shopify.metafield.index.as-variant')' },
                ],
                saving: false,
                errors: [],
            };
        },

        methods: {
            blankField() {
                return { name: '', reference: false, source: '', attribute_code: '', attribute_type: '', shopify_type: '', assoc_type: 'related_products', as: 'product', child: '', list: false, required: false };
            },

            defaultOptions() {
                return { publishable: true, translatable: true, onlineStore: false, storefront: true, customerAccount: false };
            },

            optionId(event, fallback) {
                if (! event || (typeof event !== 'string' && ! (event instanceof String))) {
                    return fallback;
                }
                return JSON.parse(event)?.id ?? fallback;
            },

            onReferenceToggle(field) {
                field.source = '';
                field.attribute_code = '';
                field.attribute_type = '';
                field.shopify_type = '';
                field.child = '';
                field.list = false;
            },

            onAttrSelect(field, event) {
                if (! event || (typeof event !== 'string' && ! (event instanceof String))) {
                    field.attribute_code = '';
                    field.attribute_type = '';
                    field.shopify_type = '';
                    return;
                }
                const parsed = JSON.parse(event);
                field.attribute_code = parsed?.code ?? '';
                field.attribute_type = parsed?.validation || parsed?.type || '';
                const options = this.contentTypeOptions(field);
                field.shopify_type = options.length ? options[0].id : '';
            },

            onSourceSelect(field, event) {
                field.source = this.optionId(event, '');
            },

            onContentTypeSelect(field, event) {
                field.shopify_type = this.optionId(event, '');
            },

            onAssocTypeSelect(field, event) {
                field.assoc_type = this.optionId(event, 'related_products');
            },

            onAsSelect(field, event) {
                field.as = this.optionId(event, 'product');
            },

            onChildSelect(field, event) {
                field.child = this.optionId(event, '');
            },

            contentTypeOptions(field) {
                return this.contentTypes[field.attribute_type] || [];
            },

            openModal() {
                this.reset();
                this.renderKey++;

                this.$axios.get("{{ route('shopify.metaobject.definitions.fetch-all') }}", { params: { credential_id: this.credentialId } })
                    .then(res => { this.definitions = res.data.options || []; });
                this.$refs.metaobjectBuilderModal.open();
                this.$nextTick(() => this.raiseModal('metaobjectBuilderModal'));
            },

            raiseModal(ref) {
                const root = this.$refs[ref] && this.$refs[ref].$el;

                if (! root) {
                    return;
                }

                window.moModalZIndex = (window.moModalZIndex || 10050) + 10;
                const base = window.moModalZIndex;

                root.querySelectorAll('.fixed.inset-0').forEach(el => {
                    const isOverlay = el.classList.contains('bg-gray-500');
                    el.style.zIndex = isOverlay ? String(base) : String(base + 1);

                    if (isOverlay) {
                        el.style.backdropFilter = 'blur(4px)';
                    }
                });
            },

            closeModal() {
                this.$refs.metaobjectBuilderModal.close();
            },

            addField() {
                this.fields.push(this.blankField());
            },

            removeField(index) {
                this.fields.splice(index, 1);
            },

            onNestedCreated(index, definition) {
                this.definitions.push(definition);
                this.fields[index].child = definition.id;
            },

            payloadFields() {
                return this.fields.map(field => {
                    const base = { name: field.name, list: field.list, required: field.required };

                    if (! field.reference) {
                        return { ...base, source: 'attribute', attribute_code: field.attribute_code, shopify_type: field.shopify_type };
                    }

                    if (field.source === 'association') {
                        return { ...base, source: 'association', assoc_type: field.assoc_type, as: field.as };
                    }

                    if (field.source === 'categories') {
                        return { ...base, source: 'categories' };
                    }

                    if (field.source === 'metaobject') {
                        return { ...base, source: 'metaobject', child_type: (field.child.split('|')[0] || ''), child_definition_id: (field.child.split('|')[1] || '') };
                    }

                    return base;
                });
            },

            save() {
                this.saving = true;
                this.errors = [];
                this.$axios.post("{{ route('shopify.metaobject.definition.store') }}", {
                    name: this.name,
                    options: this.options,
                    fields: this.payloadFields(),
                    credential_id: this.credentialId,
                })
                    .then(res => {
                        this.$emit('created', res.data.definition);
                        this.reset();
                        this.closeModal();
                    })
                    .catch(err => {
                        this.errors = Object.values(err.response?.data?.errors || {}).flat();
                    })
                    .finally(() => { this.saving = false; });
            },

            reset() {
                this.name = '';
                this.options = this.defaultOptions();
                this.fields = [this.blankField()];
                this.errors = [];
            },
        },
    });
</script>
