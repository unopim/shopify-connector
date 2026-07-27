@php
    $binding = isset($attribute) ? app(\Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository::class)->bindingFor($attribute->id) : null;
@endphp

<div v-if="selectedAttributeType == 'shopify_metaobject'" class="mb-4">
    <v-metaobject-binding
        initial-definition="{{ $binding->definition_id ?? '' }}"
    ></v-metaobject-binding>
</div>

@pushOnce('scripts')
<script type="text/x-template" id="v-metaobject-binding-template">
    <div class="flex flex-col gap-4">
        <div class="flex flex-col gap-1">
            <label class="required text-xs font-medium text-gray-600 dark:text-gray-300">@lang('shopify::app.shopify.attribute.metaobject-binding')</label>
            <v-multiselect
                :options="definitions"
                label="name"
                track-by="id"
                :model-value="selected"
                :allow-empty="false"
                :show-labels="false"
                :placeholder="labels.select"
                @select="option => definitionId = option.id"
            ></v-multiselect>
            <input type="hidden" name="metaobject_definition" :value="definitionId" />
        </div>
    </div>
</script>

<script type="module">
    app.component('v-metaobject-binding', {
        template: '#v-metaobject-binding-template',

        props: {
            initialDefinition: { type: [String, Number], default: '' },
        },

        data() {
            return {
                definitionId: this.initialDefinition ? Number(this.initialDefinition) : '',
                definitions: [],
                labels: { select: "@lang('shopify::app.shopify.attribute.metaobject-binding')" },
            };
        },

        computed: {
            selected() {
                return this.definitions.find(definition => definition.id === this.definitionId) || null;
            },
        },

        mounted() {
            this.$axios.get("{{ route('shopify.metaobject.local-definitions') }}")
                .then(res => { this.definitions = res.data.options ?? []; });

            this.$nextTick(() => this.moveBelowType());
        },

        methods: {
            moveBelowType() {
                const wrapper = this.$el.parentElement;
                const typeGroup = document.getElementById('type')?.closest('.mb-4');

                if (wrapper && typeGroup && typeGroup.parentNode) {
                    typeGroup.parentNode.insertBefore(wrapper, typeGroup.nextSibling);
                }
            },
        },
    });
</script>
@endPushOnce
