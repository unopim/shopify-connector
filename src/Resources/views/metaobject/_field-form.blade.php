<script type="text/x-template" id="v-metaobject-field-form-template">
    <div class="flex flex-col gap-3">
        <div class="flex items-start gap-2">
            <input v-model="field.name" type="text" :placeholder="labels.fieldName" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />

            <div class="flex-1">
                <v-multiselect
                    :options="compatibleTypeOptions"
                    label="label"
                    track-by="id"
                    :model-value="typeOption"
                    :allow-empty="false"
                    :show-labels="false"
                    :searchable="true"
                    :placeholder="labels.fieldType"
                    @select="onTypeSelect"
                ></v-multiselect>
            </div>
        </div>

        <div v-if="field.type === 'metaobject_reference'" class="flex items-center gap-2">
            <div class="flex-1">
                <v-multiselect
                    :options="definitionOptions"
                    label="label"
                    track-by="id"
                    :model-value="childOption"
                    :allow-empty="false"
                    :show-labels="false"
                    :searchable="true"
                    :disabled="isLocked"
                    :placeholder="labels.fieldChild"
                    @select="option => field.child = option.id"
                ></v-multiselect>
            </div>

            <button v-if="! isLocked" type="button" class="secondary-button !py-1 !text-xs" @click="$emit('open-nested')">@lang('shopify::app.shopify.metaobject.create-new')</button>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <template v-if="field.type === 'date'">
                <x-admin::flat-picker.date>
                    <input :value="field.validations.min" @change="field.validations.min = $event.target.value" :placeholder="labels.min" autocomplete="off" class="w-40 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                </x-admin::flat-picker.date>
                <x-admin::flat-picker.date>
                    <input :value="field.validations.max" @change="field.validations.max = $event.target.value" :placeholder="labels.max" autocomplete="off" class="w-40 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                </x-admin::flat-picker.date>
            </template>

            <template v-else-if="field.type === 'date_time'">
                <x-admin::flat-picker.datetime>
                    <input :value="field.validations.min" @change="field.validations.min = $event.target.value" :placeholder="labels.min" autocomplete="off" class="w-48 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                </x-admin::flat-picker.datetime>
                <x-admin::flat-picker.datetime>
                    <input :value="field.validations.max" @change="field.validations.max = $event.target.value" :placeholder="labels.max" autocomplete="off" class="w-48 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                </x-admin::flat-picker.datetime>
            </template>

            <template v-else-if="hasMinMax">
                <input type="number" v-model="field.validations.min" :placeholder="labels.min" class="w-28 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                <input type="number" v-model="field.validations.max" :placeholder="labels.max" class="w-28 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
            </template>

            <input v-if="field.type === 'number_decimal'" type="number" min="0" max="9" v-model="field.validations.max_precision" :placeholder="labels.precision" class="w-32 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />

            <div v-if="field.type === 'id'" class="w-52">
                <v-multiselect
                    :options="regexPresetOptions"
                    label="label"
                    track-by="id"
                    :model-value="regexPresetOption"
                    :show-labels="false"
                    :searchable="false"
                    :placeholder="labels.regexPreset"
                    @select="option => field.validations.regex = option.id"
                    @remove="() => field.validations.regex = ''"
                ></v-multiselect>
            </div>

            <input v-if="field.type === 'id'" type="text" v-model="field.validations.regex" :placeholder="labels.regex" class="min-w-40 flex-1 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />

            <input v-if="field.type === 'choice_list'" type="text" v-model="field.validations.choices" :placeholder="labels.choices" class="min-w-40 flex-1 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />

            <div v-if="hasUnit" class="w-40">
                <v-multiselect
                    :options="unitsFor"
                    :model-value="field.validations.unit || null"
                    :show-labels="false"
                    :placeholder="labels.unit"
                    @select="value => field.validations.unit = value"
                    @remove="() => field.validations.unit = ''"
                ></v-multiselect>
            </div>

            <div v-if="field.type === 'file_reference'" class="w-40">
                <v-multiselect
                    :options="fileModeOptions"
                    label="label"
                    track-by="id"
                    :model-value="fileModeOption"
                    :show-labels="false"
                    :placeholder="labels.contentType"
                    @select="option => field.validations.file_mode = option.id"
                ></v-multiselect>
            </div>
        </div>

        <div class="flex items-center gap-6" :class="isLocked ? 'pointer-events-none opacity-60' : ''">
            <span class="inline-flex cursor-pointer items-center gap-1 text-sm text-gray-600 dark:text-gray-300" @click="field.list = false">
                <span class="text-2xl" :class="! field.list ? 'icon-radio-selected text-primary' : 'icon-radio-normal'"></span>
                @lang('shopify::app.shopify.metaobject.field-single')
            </span>
            <span class="inline-flex cursor-pointer items-center gap-1 text-sm text-gray-600 dark:text-gray-300" @click="field.list = true">
                <span class="text-2xl" :class="field.list ? 'icon-radio-selected text-primary' : 'icon-radio-normal'"></span>
                @lang('shopify::app.shopify.metaobject.field-list')
            </span>
        </div>
    </div>
</script>

<script type="module">
    app.component('v-metaobject-field-form', {
        template: '#v-metaobject-field-form-template',

        props: {
            field: { type: Object, required: true },
            fieldTypes: { type: Object, default: () => ({}) },
            definitionOptions: { type: Array, default: () => [] },
            locked: { type: Boolean, default: false },
        },

        emits: ['open-nested'],

        data() {
            return {
                typeOptions: Object.entries(this.fieldTypes).map(([id, label]) => ({ id, label })),
                fileModeOptions: [
                    { id: 'any', label: "@lang('shopify::app.shopify.metafield.index.any-file-type')" },
                    { id: 'media', label: "@lang('shopify::app.shopify.metafield.index.media-file-only')" },
                ],
                regexPresetOptions: [
                    { id: '^[a-zA-Z]+$', label: "@lang('shopify::app.shopify.metafield.index.regex-alphabetical')" },
                    { id: '^[a-zA-Z0-9]+$', label: "@lang('shopify::app.shopify.metafield.index.regex-alphanumeric')" },
                    { id: '^[0-9]+$', label: "@lang('shopify::app.shopify.metafield.index.regex-numeric')" },
                    { id: '^[a-zA-Z0-9_-]{3,20}$', label: "@lang('shopify::app.shopify.metafield.index.regex-sku')" },
                ],
                labels: {
                    fieldName: "@lang('shopify::app.shopify.metaobject.field-name')",
                    fieldType: "@lang('shopify::app.shopify.metaobject.field-type')",
                    fieldChild: "@lang('shopify::app.shopify.metaobject.field-child')",
                    min: "@lang('shopify::app.shopify.metaobject.field-min')",
                    max: "@lang('shopify::app.shopify.metaobject.field-max')",
                    precision: "@lang('shopify::app.shopify.metaobject.field-precision')",
                    regex: "@lang('shopify::app.shopify.metaobject.field-regex')",
                    choices: "@lang('shopify::app.shopify.metaobject.field-choices')",
                    regexPreset: "@lang('shopify::app.shopify.metaobject.field-regex-preset')",
                    unit: "@lang('shopify::app.shopify.metaobject.field-unit')",
                    contentType: "@lang('shopify::app.shopify.metaobject.field-content-type')",
                },
            };
        },

        computed: {
            isLocked() {
                return this.locked && !! this.field.existing;
            },

            compatibleTypeOptions() {
                if (! this.isLocked) {
                    return this.typeOptions;
                }

                const groups = {
                    single_line_text_field: ['single_line_text_field', 'email', 'choice_list'],
                    email: ['single_line_text_field', 'email', 'choice_list'],
                    choice_list: ['single_line_text_field', 'email', 'choice_list'],
                    image: ['image', 'video', 'file_reference'],
                    video: ['image', 'video', 'file_reference'],
                    file_reference: ['image', 'video', 'file_reference'],
                };
                const allowed = groups[this.field.type] || [this.field.type];

                return this.typeOptions.filter(option => allowed.includes(option.id));
            },

            typeOption() {
                return this.typeOptions.find(option => option.id === this.field.type) || null;
            },

            childOption() {
                return this.definitionOptions.find(option => option.id === this.field.child) || null;
            },

            fileModeOption() {
                return this.fileModeOptions.find(option => option.id === (this.field.validations.file_mode || 'any')) || null;
            },

            regexPresetOption() {
                return this.regexPresetOptions.find(option => option.id === this.field.validations.regex) || null;
            },

            hasMinMax() {
                return ['single_line_text_field', 'multi_line_text_field', 'email', 'number_integer', 'number_decimal', 'date', 'date_time', 'rating', 'dimension', 'volume', 'weight', 'id'].includes(this.field.type);
            },

            hasUnit() {
                return ['dimension', 'volume', 'weight'].includes(this.field.type);
            },

            unitsFor() {
                const map = {
                    dimension: ['mm', 'cm', 'm', 'in', 'ft', 'yd'],
                    weight: ['g', 'kg', 'oz', 'lb'],
                    volume: ['ml', 'cl', 'l', 'm3', 'fl_oz', 'pt', 'qt', 'gal'],
                };

                return map[this.field.type] || [];
            },
        },

        methods: {
            onTypeSelect(option) {
                this.field.type = option.id;

                if (this.field.type !== 'metaobject_reference') {
                    this.field.child = '';
                }
            },
        },
    });
</script>
