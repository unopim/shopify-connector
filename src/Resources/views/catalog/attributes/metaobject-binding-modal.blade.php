<div id="metaobject-binding-modal-holder" class="hidden">
    <v-metaobject-binding-modal
        store-path="{{ parse_url(route('admin.catalog.attributes.store'), PHP_URL_PATH) }}"
        definitions-url="{{ route('shopify.metaobject.local-definitions') }}"
    ></v-metaobject-binding-modal>
</div>

@pushOnce('scripts')
<script type="text/x-template" id="v-metaobject-binding-modal-template">
    <div v-show="visible" class="mb-4 flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <label class="required text-xs font-medium text-gray-600 dark:text-gray-300">@lang('shopify::app.shopify.attribute.metaobject-binding')</label>

            <v-multiselect
                :options="definitions"
                label="name"
                track-by="id"
                :model-value="selected"
                :allow-empty="false"
                :searchable="true"
                :show-labels="false"
                :placeholder="labels.select"
                @select="option => definitionId = option.id"
            ></v-multiselect>

            <input type="hidden" name="metaobject_definition" :value="definitionId" />
        </div>

        <div class="flex items-center gap-6">
            <span class="inline-flex cursor-pointer items-center gap-1 text-sm text-gray-600 dark:text-gray-300" @click="multiple = false">
                <span class="text-2xl" :class="! multiple ? 'icon-radio-selected text-primary' : 'icon-radio-normal'"></span>
                @lang('shopify::app.shopify.metaobject.field-single')
            </span>

            <span class="inline-flex cursor-pointer items-center gap-1 text-sm text-gray-600 dark:text-gray-300" @click="multiple = true">
                <span class="text-2xl" :class="multiple ? 'icon-radio-selected text-primary' : 'icon-radio-normal'"></span>
                @lang('shopify::app.shopify.metaobject.field-list')
            </span>
        </div>

        <input type="hidden" name="metaobject_multiple" :value="multiple ? 1 : ''" />
    </div>
</script>

<script type="module">
    app.component('v-metaobject-binding-modal', {
        template: '#v-metaobject-binding-modal-template',

        props: {
            storePath: { type: String, required: true },
            definitionsUrl: { type: String, required: true },
        },

        data() {
            return {
                visible: false,
                definitionId: '',
                multiple: false,
                definitions: [],
                labels: { select: "@lang('shopify::app.shopify.metaobject.select')" },
                observer: null,
            };
        },

        computed: {
            selected() {
                return this.definitions.find(definition => definition.id === this.definitionId) || null;
            },
        },

        mounted() {
            this.$axios.get(this.definitionsUrl)
                .then(response => { this.definitions = response.data.options ?? []; });

            this.observer = new MutationObserver(() => this.reposition());
            this.observer.observe(document.body, { childList: true, subtree: true });

            this.reposition();
        },

        beforeUnmount() {
            this.observer?.disconnect();
        },

        methods: {
            typeInputs() {
                return Array.from(document.querySelectorAll('input[name="type"]')).filter(input => {
                    const form = input.closest('form');

                    return form && new URL(form.action, window.location.origin).pathname === this.storePath;
                });
            },

            reposition() {
                const inputs = this.typeInputs();

                if (inputs.some(input => input.value === 'shopify_metaobject')) {
                    const group = inputs[0].closest('[data-control-group]');

                    if (group && this.$el.parentNode !== group.parentNode) {
                        group.parentNode.insertBefore(this.$el, group.nextSibling);
                    }

                    this.visible = true;

                    return;
                }

                const holder = document.getElementById('metaobject-binding-modal-holder');

                if (holder && this.$el.parentNode !== holder) {
                    holder.appendChild(this.$el);
                }

                this.visible = false;
                this.definitionId = '';
                this.multiple = false;
            },
        },
    });
</script>
@endPushOnce
