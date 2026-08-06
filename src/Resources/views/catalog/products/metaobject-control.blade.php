@php
    $value ??= '';
    $fieldName ??= '';
    $binding = app(\Webkul\Shopify\Repositories\ShopifyMetaobjectAttributeRepository::class)->bindingFor($field->id);
@endphp

<v-metaobject-entry-field
    name="{{ $fieldName }}"
    value="{{ $value }}"
    attribute-id="{{ $field->id }}"
    :multiple="{{ ($binding->is_list ?? false) ? 'true' : 'false' }}"
></v-metaobject-entry-field>

@pushOnce('scripts')
<script type="text/x-template" id="v-metaobject-entry-field-template">
    <div class="w-full">
        <input type="hidden" :name="name" :value="hiddenValue" />

        <v-multiselect
            :options="entries"
            label="code"
            track-by="code"
            :multiple="multiple"
            :model-value="selection"
            :allow-empty="true"
            :show-labels="false"
            :placeholder="labels.select"
            @select="onSelect"
            @remove="onRemove"
        ></v-multiselect>
    </div>
</script>

<script type="module">
    app.component('v-metaobject-entry-field', {
        template: '#v-metaobject-entry-field-template',

        props: {
            name: { type: String, default: '' },
            value: { type: String, default: '' },
            attributeId: { type: [String, Number], default: '' },
            multiple: { type: Boolean, default: false },
        },

        data() {
            return {
                entries: [],
                codes: this.value ? this.value.split(',').map(code => code.trim()).filter(Boolean) : [],
                labels: { select: "@lang('shopify::app.shopify.metaobject.entry-code')" },
            };
        },

        computed: {
            hiddenValue() {
                return this.codes.join(',');
            },

            selection() {
                if (this.multiple) {
                    return this.entries.filter(entry => this.codes.includes(entry.code));
                }

                return this.entries.find(entry => entry.code === this.codes[0]) || null;
            },
        },

        mounted() {
            this.$axios.get("{{ route('shopify.metaobject.for-attribute') }}", { params: { attribute_id: this.attributeId } })
                .then(res => { this.entries = res.data.entries ?? []; });
        },

        methods: {
            onSelect(option) {
                this.codes = this.multiple ? [...this.codes, option.code] : [option.code];
            },

            onRemove(option) {
                this.codes = this.multiple ? this.codes.filter(code => code !== option.code) : [];
            },
        },
    });
</script>
@endPushOnce
