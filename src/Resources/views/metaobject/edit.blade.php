<x-admin::layouts>
    <x-slot:title>@lang('shopify::app.shopify.metaobject.title')</x-slot>

    <div class="flex flex-col gap-4">
        <div class="flex items-center gap-3">
            <a href="{{ route('shopify.metaobject.index') }}" class="icon-arrow-left cursor-pointer text-2xl"></a>
            <p class="text-xl font-bold text-gray-800 dark:text-white">{{ $definition->name }}</p>
        </div>

        <v-metaobject-builder
            mode="edit"
            :initial='@json($definitionData)'
            :field-types='@json($fieldTypes)'
        ></v-metaobject-builder>

        <v-metaobject-entries
            type="{{ $definition->code }}"
            :fields='@json($formFields)'
        ></v-metaobject-entries>
    </div>

    @pushOnce('scripts')
        @include('shopify::metaobject._builder')

        <script type="text/x-template" id="v-metaobject-entries-template">
            <div class="rounded-md border border-gray-200 p-4 dark:border-gray-700">
                <div class="mb-3 flex items-center justify-between">
                    <p class="text-sm font-semibold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.entries')</p>
                    <button type="button" class="primary-button !py-1 !text-xs" @click="openCreate">@lang('shopify::app.shopify.metaobject.add-entry')</button>
                </div>

                <p v-if="! entries.length" class="text-sm text-gray-500 dark:text-gray-400">@lang('shopify::app.shopify.metaobject.entry-empty')</p>
                <div v-for="entry in entries" :key="entry.id" class="flex items-center justify-between border-b border-gray-100 py-2 text-sm last:border-0 dark:border-gray-700">
                    <span class="font-medium text-gray-700 dark:text-gray-300" v-text="entry.code"></span>
                    <span class="flex items-center gap-4">
                        <span class="icon-edit cursor-pointer text-2xl text-gray-600 transition-all hover:text-gray-900 dark:text-gray-300" :title="'@lang('shopify::app.shopify.metaobject.edit-entry')'" @click="openEdit(entry)"></span>
                        <span class="icon-delete cursor-pointer text-2xl text-gray-600 transition-all hover:text-red-600 dark:text-gray-300" :title="'@lang('shopify::app.shopify.metaobject.delete')'" @click="remove(entry)"></span>
                    </span>
                </div>

                <teleport to="body">
                <x-admin::modal ref="entryModal" type="medium">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white" v-text="form.id ? '@lang('shopify::app.shopify.metaobject.edit-entry')' : '@lang('shopify::app.shopify.metaobject.add-entry')'"></p>
                    </x-slot>

                    <x-slot:content>
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col gap-1">
                                <label class="text-xs font-medium text-gray-600 dark:text-gray-300">@lang('shopify::app.shopify.metaobject.entry-code') <span class="required"></span></label>
                                <input type="text" v-model="form.code" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                            </div>

                            <div v-for="field in formFields" :key="field.key" class="flex flex-col gap-1">
                                <label class="text-xs font-medium text-gray-600 dark:text-gray-300" v-text="field.name"></label>

                                <textarea v-if="isTextarea(field)" v-model="form.values[field.key]" rows="3" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900"></textarea>

                                <label v-else-if="field.shopify_type === 'boolean'" class="relative inline-flex cursor-pointer items-center">
                                    <input type="checkbox" v-model="form.values[field.key]" class="peer sr-only" />
                                    <span class="h-5 w-9 rounded-full bg-gray-200 after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:bg-white after:transition-all peer-checked:bg-violet-700 peer-checked:after:translate-x-full"></span>
                                </label>

                                <input v-else-if="field.shopify_type === 'color'" type="color" v-model="form.values[field.key]" class="h-9 w-16 rounded-md border border-gray-300 dark:border-gray-600" />

                                <template v-else-if="field.shopify_type === 'file_reference'">
                                    <input type="file" :accept="acceptFor(field)" @change="onFile($event, field.key)" class="text-sm text-gray-600 dark:text-gray-300" />
                                    <span v-if="form.values[field.key]" class="text-xs text-gray-500 dark:text-gray-400" v-text="form.values[field.key]"></span>
                                </template>

                                <div v-else-if="field.source === 'metaobject'">
                                    <v-multiselect
                                        :options="childOptions(field)"
                                        label="code"
                                        track-by="code"
                                        :multiple="field.list"
                                        :model-value="childSelection(field)"
                                        :allow-empty="true"
                                        :show-labels="false"
                                        :placeholder="labels.select"
                                        @select="option => onChildPick(field, option)"
                                        @remove="option => onChildUnpick(field, option)"
                                    ></v-multiselect>
                                </div>

                                <template v-else-if="field.shopify_type === 'link'">
                                    <input type="text" :value="linkValue(field, 'text')" @input="setLink(field, 'text', $event.target.value)" :placeholder="labels.linkText" class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                                    <input type="url" :value="linkValue(field, 'url')" @input="setLink(field, 'url', $event.target.value)" :placeholder="labels.linkUrl" class="mt-1 rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                                </template>

                                <x-admin::flat-picker.date v-else-if="field.shopify_type === 'date'">
                                    <input :value="form.values[field.key]" @change="form.values[field.key] = $event.target.value" :required="field.required" autocomplete="off" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                                </x-admin::flat-picker.date>

                                <x-admin::flat-picker.datetime v-else-if="field.shopify_type === 'date_time'">
                                    <input :value="form.values[field.key]" @change="form.values[field.key] = $event.target.value" :required="field.required" autocomplete="off" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                                </x-admin::flat-picker.datetime>

                                <input v-else :type="inputType(field)"
                                    v-model="form.values[field.key]"
                                    :required="field.required"
                                    :min="numericBound(field, 'min')"
                                    :max="numericBound(field, 'max')"
                                    :maxlength="textMax(field)"
                                    :step="stepFor(field)"
                                    :pattern="field.validations?.regex || null"
                                    class="rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                            </div>
                        </div>
                    </x-slot>

                    <x-slot:footer>
                        <div class="flex w-full justify-end gap-2.5">
                            <button type="button" class="secondary-button" @click="closeModal">@lang('shopify::app.shopify.metaobject.cancel')</button>
                            <button type="button" class="primary-button" :disabled="saving" @click="save">@lang('shopify::app.shopify.metaobject.save')</button>
                        </div>
                    </x-slot>
                </x-admin::modal>
                </teleport>
            </div>
        </script>

        <script type="module">
            app.component('v-metaobject-entries', {
                template: '#v-metaobject-entries-template',

                props: {
                    type: { type: String, default: '' },
                    fields: { type: Array, default: () => [] },
                },

                data() {
                    return {
                        entries: [],
                        childEntries: {},
                        pickedFiles: {},
                        saving: false,
                        form: { id: null, code: '', values: {} },
                        labels: {
                            select: "@lang('shopify::app.shopify.metaobject.field-child')",
                            linkText: "@lang('shopify::app.shopify.metaobject.link-text')",
                            linkUrl: "@lang('shopify::app.shopify.metaobject.link-url')",
                        },
                    };
                },

                computed: {
                    formFields() {
                        return this.fields.filter(field => this.supported(field));
                    },
                },

                mounted() {
                    this.refresh();
                },

                methods: {
                    refresh() {
                        if (! this.type) {
                            return;
                        }

                        this.loadChildren();

                        this.$axios.get("{{ route('shopify.metaobject.entry.list') }}", { params: { type: this.type } })
                            .then(res => { this.entries = res.data.entries ?? []; });
                    },

                    loadChildren() {
                        this.fields.filter(field => field.source === 'metaobject' && field.child_type).forEach(field => {
                            if (this.childEntries[field.child_type]) {
                                return;
                            }

                            this.$axios.get("{{ route('shopify.metaobject.entry.list') }}", { params: { type: field.child_type } })
                                .then(res => { this.childEntries[field.child_type] = res.data.entries ?? []; });
                        });
                    },

                    childOptions(field) {
                        return this.childEntries[field.child_type] || [];
                    },

                    childSelection(field) {
                        const value = this.form.values[field.key];
                        const options = this.childOptions(field);

                        if (field.list) {
                            const codes = Array.isArray(value) ? value : (value ? [value] : []);

                            return options.filter(option => codes.includes(option.code));
                        }

                        return options.find(option => option.code === value) || null;
                    },

                    onChildPick(field, option) {
                        if (field.list) {
                            const current = Array.isArray(this.form.values[field.key]) ? this.form.values[field.key] : [];
                            this.form.values[field.key] = [...current, option.code];

                            return;
                        }

                        this.form.values[field.key] = option.code;
                    },

                    onChildUnpick(field, option) {
                        if (field.list) {
                            const current = Array.isArray(this.form.values[field.key]) ? this.form.values[field.key] : [];
                            this.form.values[field.key] = current.filter(code => code !== option.code);
                        }
                    },

                    supported(field) {
                        return field.source === 'attribute' || field.shopify_type === 'file_reference' || (field.source === 'metaobject' && field.child_type);
                    },

                    isTextarea(field) {
                        return ['multi_line_text_field', 'rich_text_field', 'json'].includes(field.shopify_type);
                    },

                    inputType(field) {
                        if (field.preset === 'email') {
                            return 'email';
                        }

                        if (['number_integer', 'number_decimal', 'rating', 'money', 'weight', 'volume', 'dimension'].includes(field.shopify_type)) {
                            return 'number';
                        }

                        if (field.shopify_type === 'url') {
                            return 'url';
                        }

                        return 'text';
                    },

                    linkValue(field, part) {
                        const raw = this.form.values[field.key];
                        const obj = (raw && typeof raw === 'object') ? raw : {};

                        return obj[part] ?? '';
                    },

                    setLink(field, part, value) {
                        const raw = this.form.values[field.key];
                        const obj = (raw && typeof raw === 'object') ? { ...raw } : {};
                        obj[part] = value;
                        this.form.values[field.key] = obj;
                    },

                    numericBound(field, bound) {
                        const numeric = ['number_integer', 'number_decimal', 'rating', 'money', 'weight', 'volume', 'dimension', 'date', 'date_time'];

                        return numeric.includes(field.shopify_type) ? (field.validations?.[bound] || null) : null;
                    },

                    textMax(field) {
                        return ['single_line_text_field', 'multi_line_text_field', 'id'].includes(field.shopify_type) ? (field.validations?.max || null) : null;
                    },

                    stepFor(field) {
                        if (field.shopify_type === 'number_integer') {
                            return '1';
                        }

                        if (field.shopify_type === 'number_decimal' && field.validations?.max_precision) {
                            return (1 / Math.pow(10, Number(field.validations.max_precision))).toString();
                        }

                        return null;
                    },

                    acceptFor(field) {
                        if (field.content_type === 'IMAGE') {
                            return 'image/*';
                        }

                        if (field.content_type === 'VIDEO') {
                            return 'video/*';
                        }

                        return null;
                    },

                    openCreate() {
                        this.form = { id: null, code: '', values: {} };
                        this.pickedFiles = {};
                        this.$refs.entryModal.open();
                    },

                    openEdit(entry) {
                        this.form = { id: entry.id, code: entry.code, values: { ...entry.values } };
                        this.pickedFiles = {};
                        this.$refs.entryModal.open();
                    },

                    closeModal() {
                        this.$refs.entryModal.close();
                    },

                    onFile(event, key) {
                        this.pickedFiles[key] = event.target.files[0];
                    },

                    save() {
                        if (! this.form.code.trim()) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.entry-code-required')" });

                            return;
                        }

                        this.saving = true;

                        const payload = new FormData();
                        payload.append('type', this.type);
                        payload.append('code', this.form.code);
                        payload.append('values', JSON.stringify(this.form.values));

                        if (this.form.id) {
                            payload.append('id', this.form.id);
                        }

                        Object.keys(this.pickedFiles).forEach(key => payload.append(`files[${key}]`, this.pickedFiles[key]));

                        this.$axios.post("{{ route('shopify.metaobject.entry.store') }}", payload)
                            .then(() => {
                                this.$emitter.emit('add-flash', { type: 'success', message: "@lang('shopify::app.shopify.metaobject.entry-saved')" });
                                this.closeModal();
                                this.refresh();
                            }).finally(() => { this.saving = false; });
                    },

                    remove(entry) {
                        if (! window.confirm("@lang('shopify::app.shopify.metaobject.entry-delete-confirm')")) {
                            return;
                        }

                        this.$axios.delete("{{ route('shopify.metaobject.entry.delete', ':id') }}".replace(':id', entry.id))
                            .then(() => {
                                this.$emitter.emit('add-flash', { type: 'success', message: "@lang('shopify::app.shopify.metaobject.entry-deleted')" });
                                this.refresh();
                            });
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
