@php
    $value ??= '';
    $fieldName ??= '';
@endphp

<v-metaobject-entry-field
    name="{{ $fieldName }}"
    value="{{ $value }}"
    attribute-id="{{ $field->id }}"
></v-metaobject-entry-field>

@pushOnce('scripts')
<script type="text/x-template" id="v-metaobject-entry-field-template">
    <div class="w-full">
        <input type="hidden" :name="name" :value="code" />

        <v-multiselect
            :options="entries"
            label="code"
            track-by="code"
            :model-value="selection"
            :allow-empty="true"
            :show-labels="false"
            :placeholder="labels.select"
            @select="option => code = option.code"
            @remove="code = ''"
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
        },

        data() {
            return {
                entries: [],
                code: this.value,
                labels: { select: "@lang('shopify::app.shopify.metaobject.entry-code')" },
            };
        },

        computed: {
            selection() {
                return this.entries.find(entry => entry.code === this.code) || null;
            },
        },

        mounted() {
            this.$axios.get("{{ route('shopify.metaobject.for-attribute') }}", { params: { attribute_id: this.attributeId } })
                .then(res => { this.entries = res.data.entries ?? []; });
        },
    });
</script>
@endPushOnce
