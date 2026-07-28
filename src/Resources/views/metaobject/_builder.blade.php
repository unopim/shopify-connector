<script type="text/x-template" id="v-metaobject-builder-template">
    <div>
        <div class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
            <div class="mb-4 flex flex-col gap-1">
                <label class="required text-xs font-medium text-gray-600 dark:text-gray-300">@lang('shopify::app.shopify.metaobject.name')</label>
                <input v-model="name" type="text" class="max-w-md rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
            </div>

            <p class="mb-2 text-sm font-semibold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.fields')</p>

            <div v-for="(field, index) in fields" :key="index" class="mb-2 rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex items-start gap-2">
                    <input v-model="field.name" type="text" :placeholder="labels.fieldName" class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />

                    <div class="flex-1">
                        <v-multiselect
                            :options="compatibleTypeOptions(field)"
                            label="label"
                            track-by="id"
                            :model-value="typeOption(field)"
                            :allow-empty="false"
                            :show-labels="false"
                            :placeholder="labels.fieldType"
                            @select="option => onTypeSelect(field, option)"
                        ></v-multiselect>
                    </div>

                    <span class="icon-delete mt-2 cursor-pointer text-xl text-gray-400" @click="removeField(index)"></span>
                </div>

                <div v-if="field.type === 'metaobject_reference'" class="mt-2 flex items-center gap-2">
                    <div class="flex-1">
                        <v-multiselect
                            :options="definitionOptions"
                            label="label"
                            track-by="id"
                            :model-value="childOption(field)"
                            :allow-empty="false"
                            :show-labels="false"
                            :disabled="typeLocked(field)"
                            :placeholder="labels.fieldChild"
                            @select="option => onChildSelect(field, option)"
                        ></v-multiselect>
                    </div>

                    <button v-if="! typeLocked(field)" type="button" class="secondary-button !py-1 !text-xs" @click="openNested(index)">@lang('shopify::app.shopify.metaobject.create-new')</button>
                </div>

                <div class="mt-2 flex flex-wrap items-center gap-3">
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

                    <template v-else-if="hasMinMax(field)">
                        <input type="number" v-model="field.validations.min" :placeholder="labels.min" class="w-28 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                        <input type="number" v-model="field.validations.max" :placeholder="labels.max" class="w-28 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                    </template>

                    <input v-if="field.type === 'number_decimal'" type="number" min="0" max="9" v-model="field.validations.max_precision" :placeholder="labels.precision" class="w-32 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />

                    <input v-if="hasRegex(field)" type="text" v-model="field.validations.regex" :placeholder="labels.regex" class="min-w-40 flex-1 rounded-md border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-cherry-900" />

                    <div v-if="hasUnit(field)" class="w-40">
                        <v-multiselect
                            :options="unitsFor(field)"
                            :model-value="field.validations.unit || null"
                            :show-labels="false"
                            :placeholder="labels.unit"
                            @select="value => field.validations.unit = value"
                            @remove="() => field.validations.unit = ''"
                        ></v-multiselect>
                    </div>

                    <div v-if="field.type === 'file_reference'" class="w-40">
                        <v-multiselect
                            :options="contentTypeOptions"
                            label="label"
                            track-by="id"
                            :model-value="contentTypeOption(field)"
                            :show-labels="false"
                            :placeholder="labels.contentType"
                            @select="option => field.content_type = option.id"
                            @remove="() => field.content_type = ''"
                        ></v-multiselect>
                    </div>
                </div>

                <div class="mt-3 flex items-center gap-6" :class="typeLocked(field) ? 'pointer-events-none opacity-60' : ''">
                    <span class="inline-flex cursor-pointer items-center gap-1 text-sm text-gray-600 dark:text-gray-300" @click="field.list = false">
                        <span class="text-2xl" :class="! field.list ? 'icon-radio-selected text-violet-700' : 'icon-radio-normal'"></span>
                        @lang('shopify::app.shopify.metaobject.field-single')
                    </span>
                    <span class="inline-flex cursor-pointer items-center gap-1 text-sm text-gray-600 dark:text-gray-300" @click="field.list = true">
                        <span class="text-2xl" :class="field.list ? 'icon-radio-selected text-violet-700' : 'icon-radio-normal'"></span>
                        @lang('shopify::app.shopify.metaobject.field-list')
                    </span>
                </div>
            </div>

            <div class="mt-3">
                <button type="button" class="secondary-button !py-1 !text-xs" @click="addField">@lang('shopify::app.shopify.metaobject.add-field')</button>
            </div>
        </div>

        <div class="mt-3 flex items-center justify-end gap-2.5">
            <button type="button" class="secondary-button" @click="cancel">@lang('shopify::app.shopify.metaobject.cancel')</button>
            <button type="button" class="primary-button" :disabled="saving" @click="save">@lang('shopify::app.shopify.metaobject.save')</button>
        </div>

        <teleport to="body">
        <x-admin::modal ref="nestedModal" type="medium">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.create')</p>
            </x-slot>

            <x-slot:content>
                <v-metaobject-builder
                    v-if="nestedOpen"
                    mode="create"
                    :is-nested="true"
                    :field-types="fieldTypes"
                    @saved="onNestedSaved"
                    @cancel="closeNested"
                ></v-metaobject-builder>
            </x-slot>
        </x-admin::modal>
        </teleport>
    </div>
</script>

<script type="module">
    app.component('v-metaobject-builder', {
        template: '#v-metaobject-builder-template',

        props: {
            mode: { type: String, default: 'create' },
            isNested: { type: Boolean, default: false },
            initial: { type: Object, default: () => ({}) },
            fieldTypes: { type: Object, default: () => ({}) },
        },

        emits: ['saved', 'cancel'],

        data() {
            const initialFields = (this.initial.fields ?? []).map(field => ({
                ...this.blankField(),
                ...field,
                type: field.preset ?? field.type,
                existing: true,
                validations: { ...this.blankValidations(), ...(field.validations ?? {}) },
            }));

            return {
                name: this.initial.name ?? '',
                fields: initialFields.length ? initialFields : [this.blankField()],
                definitionOptions: [],
                typeOptions: Object.entries(this.fieldTypes).map(([id, label]) => ({ id, label })),
                contentTypeOptions: [
                    { id: 'IMAGE', label: 'Image' },
                    { id: 'VIDEO', label: 'Video' },
                    { id: 'FILE', label: 'File' },
                ],
                saving: false,
                nestedOpen: false,
                nestedIndex: null,
                labels: {
                    fieldName: "@lang('shopify::app.shopify.metaobject.field-name')",
                    fieldType: "@lang('shopify::app.shopify.metaobject.field-type')",
                    fieldChild: "@lang('shopify::app.shopify.metaobject.field-child')",
                    min: "@lang('shopify::app.shopify.metaobject.field-min')",
                    max: "@lang('shopify::app.shopify.metaobject.field-max')",
                    precision: "@lang('shopify::app.shopify.metaobject.field-precision')",
                    regex: "@lang('shopify::app.shopify.metaobject.field-regex')",
                    unit: "@lang('shopify::app.shopify.metaobject.field-unit')",
                    contentType: "@lang('shopify::app.shopify.metaobject.field-content-type')",
                },
            };
        },

        mounted() {
            this.$axios.get("{{ route('shopify.metaobject.local-definitions') }}", { params: { exclude: this.initial.id } })
                .then(res => { this.definitionOptions = (res.data.options ?? []).map(definition => ({ id: definition.code, label: definition.name })); });
        },

        methods: {
            blankField() {
                return { name: '', type: 'single_line_text_field', child: '', list: false, content_type: '', existing: false, validations: this.blankValidations() };
            },

            blankValidations() {
                return { min: '', max: '', unit: '', max_precision: '', regex: '' };
            },

            typeLocked(field) {
                return this.mode === 'edit' && !! field.existing;
            },

            compatibleTypeOptions(field) {
                if (! this.typeLocked(field)) {
                    return this.typeOptions;
                }

                const groups = {
                    single_line_text_field: ['single_line_text_field', 'email'],
                    email: ['single_line_text_field', 'email'],
                    image: ['image', 'file_reference'],
                    file_reference: ['image', 'file_reference'],
                };
                const allowed = groups[field.type] || [field.type];

                return this.typeOptions.filter(option => allowed.includes(option.id));
            },

            hasMinMax(field) {
                return ['single_line_text_field', 'multi_line_text_field', 'email', 'number_integer', 'number_decimal', 'date', 'date_time', 'rating', 'dimension', 'volume', 'weight', 'id'].includes(field.type);
            },

            hasRegex(field) {
                return ['single_line_text_field', 'email', 'id'].includes(field.type);
            },

            hasUnit(field) {
                return ['dimension', 'volume', 'weight'].includes(field.type);
            },

            unitsFor(field) {
                const map = {
                    dimension: ['mm', 'cm', 'm', 'in', 'ft', 'yd'],
                    weight: ['g', 'kg', 'oz', 'lb'],
                    volume: ['ml', 'cl', 'l', 'm3', 'fl_oz', 'pt', 'qt', 'gal'],
                };

                return map[field.type] || [];
            },

            typeOption(field) {
                return this.typeOptions.find(option => option.id === field.type) || null;
            },

            childOption(field) {
                return this.definitionOptions.find(option => option.id === field.child) || null;
            },

            contentTypeOption(field) {
                return this.contentTypeOptions.find(option => option.id === field.content_type) || null;
            },

            onTypeSelect(field, option) {
                field.type = option.id;

                if (field.type !== 'metaobject_reference') {
                    field.child = '';
                }
            },

            onChildSelect(field, option) {
                field.child = option.id;
            },

            addField() {
                this.fields.push(this.blankField());
            },

            removeField(index) {
                this.fields.splice(index, 1);
            },

            openNested(index) {
                this.nestedIndex = index;
                this.nestedOpen = true;
                this.$refs.nestedModal.open();
                this.$nextTick(() => this.raiseModal());
            },

            onNestedSaved(data) {
                this.definitionOptions.push({ id: data.code, label: data.name });

                if (this.nestedIndex !== null) {
                    this.fields[this.nestedIndex].child = data.code;
                }

                this.closeNested();
            },

            closeNested() {
                this.nestedOpen = false;
                this.nestedIndex = null;
                this.$refs.nestedModal.close();
            },

            raiseModal() {
                const root = this.$refs.nestedModal && this.$refs.nestedModal.$el;

                if (! root) {
                    return;
                }

                window.moModalZIndex = (window.moModalZIndex || 10050) + 10;
                const base = window.moModalZIndex;

                root.querySelectorAll('.fixed.inset-0').forEach(el => {
                    const isOverlay = el.classList.contains('bg-gray-500');
                    el.style.zIndex = isOverlay ? String(base) : String(base + 1);
                });
            },

            cancel() {
                if (this.isNested) {
                    this.$emit('cancel');

                    return;
                }

                window.location.href = "{{ route('shopify.metaobject.index') }}";
            },

            showError(error) {
                const messages = Object.values(error.response?.data?.errors || {}).flat();

                this.$emitter.emit('add-flash', { type: 'error', message: messages[0] ?? "@lang('shopify::app.shopify.metaobject.fields-required')" });
                this.saving = false;
            },

            save() {
                if (! this.name.trim()) {
                    this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.name-required')" });

                    return;
                }

                if (! this.fields.some(field => (field.name || '').trim() && field.type)) {
                    this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.fields-required')" });

                    return;
                }

                this.saving = true;

                if (this.mode === 'edit') {
                    this.$axios.put("{{ route('shopify.metaobject.update', ':id') }}".replace(':id', this.initial.id), { name: this.name, fields: this.fields })
                        .then(() => {
                            this.$emitter.emit('add-flash', { type: 'success', message: "@lang('shopify::app.shopify.metaobject.saved')" });
                            window.location.reload();
                        })
                        .catch(error => this.showError(error));

                    return;
                }

                this.$axios.post("{{ route('shopify.metaobject.store') }}", { name: this.name, fields: this.fields })
                    .then(res => {
                        if (this.isNested) {
                            this.$emit('saved', res.data);
                            this.saving = false;

                            return;
                        }

                        window.location.href = "{{ route('shopify.metaobject.edit', ':id') }}".replace(':id', res.data.id);
                    })
                    .catch(error => this.showError(error));
            },
        },
    });
</script>
