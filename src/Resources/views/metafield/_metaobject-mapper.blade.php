@php
    $mapAttributeType = $attributeType ?? ['text', 'textarea', 'boolean', 'select', 'multiselect', 'date', 'image', 'file', 'asset'];
@endphp

<script type="text/x-template" id="v-metaobject-mapper-template">
    <span>
        <button type="button" class="secondary-button" :disabled="! definition" @click="openModal">
            @lang('shopify::app.shopify.metafield.index.metaobject-map-fields')
        </button>

        <teleport to="body">
        <x-admin::modal ref="metaobjectMapperModal" type="medium">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metafield.index.metaobject-map-title')</p>
            </x-slot>

            <x-slot:content>
                <div v-if="errors.length" class="mb-3 rounded-md bg-red-50 p-2 text-sm text-red-600 dark:bg-red-950">
                    <p v-for="e in errors" :key="e" v-text="e"></p>
                </div>

                <div v-for="(field, index) in fields" :key="renderKey + '-' + index" class="mb-3 rounded-md border border-gray-200 p-3 dark:border-gray-700">
                    <div class="mb-2 flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-800 dark:text-white" v-text="field.name"></span>
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800" v-text="(field.list ? 'list.' : '') + field.shopify_type"></span>
                    </div>

                    <div v-if="field.source === 'attribute'">
                        <v-async-select-handler
                            track-by="code"
                            label-by="label"
                            :entity-name="JSON.stringify(attributeTypes)"
                            :value="field.attribute_code ? JSON.stringify({ code: field.attribute_code, label: field.attribute_label || field.attribute_code }) : null"
                            list-route="{{ route('admin.shopify.get-attribute') }}"
                            placeholder="@lang('shopify::app.shopify.metafield.index.attribute')"
                            @input="onAttr(field, $event)"
                        ></v-async-select-handler>
                    </div>

                    <div v-else-if="field.source === 'association'">
                        <v-select-handler
                            track-by="id"
                            label-by="label"
                            :options="associationTypeOptions"
                            :value="field.assoc_type"
                            placeholder="@lang('shopify::app.shopify.metafield.index.association-type')"
                            @input="field.assoc_type = optionId($event, 'related_products')"
                        ></v-select-handler>
                    </div>

                    <div v-else-if="field.source === 'categories'" class="text-sm text-gray-500 dark:text-gray-400">
                        @lang('shopify::app.shopify.metafield.index.metaobject-product-categories')
                    </div>

                    <div v-else-if="field.source === 'metaobject'">
                        <v-select-handler
                            track-by="id"
                            label-by="label"
                            :options="definitions"
                            :value="field.child"
                            placeholder="@lang('shopify::app.shopify.metafield.index.metaobject-definition')"
                            @input="field.child = optionId($event, '')"
                        ></v-select-handler>
                    </div>
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
    app.component('v-metaobject-mapper', {
        template: '#v-metaobject-mapper-template',

        props: { definition: { type: String, default: '' } },

        data() {
            return {
                fields: [],
                name: '',
                renderKey: 0,
                definitions: [],
                attributeTypes: @json($mapAttributeType),
                associationTypeOptions: [
                    { id: 'related_products', label: '@lang('shopify::app.shopify.metafield.index.related')' },
                    { id: 'up_sells', label: '@lang('shopify::app.shopify.metafield.index.up-sells')' },
                    { id: 'cross_sells', label: '@lang('shopify::app.shopify.metafield.index.cross-sells')' },
                ],
                saving: false,
                errors: [],
            };
        },

        methods: {
            optionId(event, fallback) {
                if (! event || (typeof event !== 'string' && ! (event instanceof String))) {
                    return fallback;
                }
                return JSON.parse(event)?.id ?? fallback;
            },

            onAttr(field, event) {
                field.attribute_code = (event && (typeof event === 'string' || event instanceof String)) ? (JSON.parse(event)?.code ?? '') : '';
            },

            openModal() {
                this.errors = [];
                this.renderKey++;
                this.$axios.get("{{ route('shopify.metaobject.definition.fields') }}", { params: { definition: this.definition } })
                    .then(res => {
                        this.name = res.data.name || '';
                        this.fields = (res.data.fields || []).map(field => ({ ...field }));
                    });
                this.$axios.get("{{ route('shopify.metaobject.definitions.fetch-all') }}")
                    .then(res => { this.definitions = res.data.options || []; });
                this.$refs.metaobjectMapperModal.open();
                this.$nextTick(() => this.raiseModal('metaobjectMapperModal'));
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
                this.$refs.metaobjectMapperModal.close();
            },

            save() {
                this.saving = true;
                this.errors = [];
                this.$axios.post("{{ route('shopify.metaobject.definition.map') }}", {
                    definition: this.definition,
                    name: this.name,
                    fields: this.fields,
                })
                    .then(() => { this.closeModal(); })
                    .catch(err => { this.errors = Object.values(err.response?.data?.errors || {}).flat(); })
                    .finally(() => { this.saving = false; });
            },
        },
    });
</script>
