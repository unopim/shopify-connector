@include('shopify::metaobject._field-form')

<script type="text/x-template" id="v-metaobject-builder-template">
    <div class="flex flex-col gap-2">
        <div class="rounded bg-white p-4 box-shadow dark:bg-cherry-900">
            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.general')</p>

            <x-admin::form.control-group class="!mb-0 max-w-md">
                <x-admin::form.control-group.label class="required">@lang('shopify::app.shopify.metaobject.name')</x-admin::form.control-group.label>

                <input v-model="name" type="text" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
            </x-admin::form.control-group>
        </div>

        <div class="rounded bg-white p-4 box-shadow dark:bg-cherry-900">
            <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.fields')</p>

            <div v-for="(field, index) in fields" :key="index" class="mb-2 rounded-md border border-gray-200 p-3 dark:border-gray-700">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex-1">
                        <v-metaobject-field-form
                            :field="field"
                            :field-types="fieldTypes"
                            :definition-options="definitionOptions"
                            @open-nested="openNested(index)"
                        ></v-metaobject-field-form>
                    </div>

                    <span class="icon-delete mt-1 cursor-pointer text-xl text-gray-400" @click="removeField(index)"></span>
                </div>
            </div>

            <div class="mt-3">
                <button type="button" class="secondary-button" @click="addField">@lang('shopify::app.shopify.metaobject.add-field')</button>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2.5">
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
            isNested: { type: Boolean, default: false },
            fieldTypes: { type: Object, default: () => ({}) },
        },

        emits: ['saved', 'cancel'],

        data() {
            return {
                name: '',
                fields: [this.blankField()],
                definitionOptions: [],
                saving: false,
                nestedOpen: false,
                nestedIndex: null,
            };
        },

        mounted() {
            this.$axios.get("{{ route('shopify.metaobject.local-definitions') }}")
                .then(res => { this.definitionOptions = (res.data.options ?? []).map(definition => ({ id: definition.code, label: definition.name })); });
        },

        methods: {
            blankField() {
                return { name: '', type: '', child: '', list: false, content_type: '', existing: false, validations: this.blankValidations() };
            },

            blankValidations() {
                return { min: '', max: '', unit: '', max_precision: '', regex: '', choices: '' };
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
