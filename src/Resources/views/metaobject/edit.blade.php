<x-admin::layouts.with-history>
    <x-slot:entityName>
        shopify_metaobject
    </x-slot>

    <x-slot:title>
        @lang('shopify::app.shopify.metaobject.edit-title')
    </x-slot>

    <x-slot:pageHeader>
        <x-admin::layouts.edit-page-header
            :title="trans('shopify::app.shopify.metaobject.edit-title').' | '.$definition->name"
            :back-url="route('shopify.metaobject.index')"
            :back-label="trans('shopify::app.shopify.metaobject.back')"
        />
    </x-slot>

    <div class="flex flex-col gap-4">
        <x-admin::form method="PATCH" :action="route('shopify.metaobject.general', $definition->id)" ajax>
            <div class="flex flex-col gap-4">
                <div class="rounded bg-white p-4 box-shadow dark:bg-cherry-900">
                    <p class="mb-4 text-base font-semibold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.general')</p>

                    <x-admin::form.control-group class="!mb-0 max-w-md">
                        <x-admin::form.control-group.label class="required">@lang('shopify::app.shopify.metaobject.name')</x-admin::form.control-group.label>

                        <x-admin::form.control-group.control
                            type="text"
                            name="name"
                            rules="required"
                            :value="$definition->name"
                            :label="trans('shopify::app.shopify.metaobject.name')"
                        />

                        <x-admin::form.control-group.error control-name="name" />
                    </x-admin::form.control-group>
                </div>

                <v-metaobject-fields
                    :definition-id="{{ $definition->id }}"
                    :field-types='@json($fieldTypes)'
                >
                    <x-admin::shimmer.datagrid />
                </v-metaobject-fields>

                <div class="rounded bg-white p-4 box-shadow dark:bg-cherry-900">
                    <p class="mb-2 text-base font-semibold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.options')</p>

                    @foreach ([
                        'active_draft'            => 'opt-active-draft',
                        'translations'            => 'opt-translations',
                        'web_pages'               => 'opt-web-pages',
                        'storefront_access'       => 'opt-storefront',
                        'customer_account_access' => 'opt-customer-account',
                    ] as $optionKey => $optionLabel)
                        <div class="flex items-center justify-between border-b py-3 last:border-b-0 dark:border-cherry-800">
                            <label for="{{ $optionKey }}" class="cursor-pointer text-sm text-gray-700 dark:text-gray-300">@lang('shopify::app.shopify.metaobject.'.$optionLabel)</label>

                            <x-admin::form.control-group.control
                                type="switch"
                                :id="$optionKey"
                                :name="$optionKey"
                                value="1"
                                :checked="(bool) $options[$optionKey]"
                                :for="$optionKey"
                            />
                        </div>
                    @endforeach
                </div>
            </div>
        </x-admin::form>

        <div class="rounded bg-white p-4 box-shadow dark:bg-cherry-900">
            <v-metaobject-entries
                type="{{ $definition->code }}"
                :fields='@json($formFields)'
            >
                <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
                    <p class="text-xl font-bold text-gray-800 dark:text-slate-50">@lang('shopify::app.shopify.metaobject.entries')</p>
                </div>

                <x-admin::shimmer.datagrid />
            </v-metaobject-entries>
        </div>
    </div>

    @pushOnce('scripts')
        @include('shopify::metaobject._builder')

        <script type="text/x-template" id="v-metaobject-fields-template">
            <div class="flex flex-col gap-4">

                <div class="rounded bg-white p-4 box-shadow dark:bg-cherry-900">
                    <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
                        <p class="text-xl font-bold text-gray-800 dark:text-slate-50">@lang('shopify::app.shopify.metaobject.fields')</p>

                        @if (bouncer()->hasPermission('shopify.metaobjects.edit'))
                            <button type="button" class="primary-button" @click="openCreate">@lang('shopify::app.shopify.metaobject.add-field')</button>
                        @endif
                    </div>

                    <x-admin::datagrid
                        :src="route('shopify.metaobject.field.datagrid', $definition->id)"
                        ref="datagrid"
                    >
                        <template #body="{ records, performAction }">
                            <div
                                v-for="record in records"
                                :key="record.key"
                                class="row grid items-center gap-2.5 border-b px-4 py-4 text-gray-600 transition-all hover:bg-violet-50 dark:border-cherry-800 dark:text-gray-300 dark:hover:bg-cherry-800"
                                :style="`grid-template-columns: repeat(${gridsCount}, minmax(0, 1fr))`"
                            >
                                <template v-for="column in visibleColumns" :key="column.index">
                                    <p class="truncate" :title="record[column.index]" v-text="record[column.index]"></p>
                                </template>

                                <div class="flex justify-end gap-1">
                                    @if (bouncer()->hasPermission('shopify.metaobjects.edit'))
                                        <a
                                            v-if="record.actions.find(a => a.index === 'edit' && a.url)"
                                            @click="editModal(record.actions.find(a => a.index === 'edit').url)"
                                        >
                                            <span class="icon-edit cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-violet-100 dark:hover:bg-gray-800" title="@lang('shopify::app.shopify.metaobject.edit-field')"></span>
                                        </a>

                                        <a
                                            v-if="record.actions.find(a => a.index === 'delete' && a.url)"
                                            @click="deleteField(record.actions.find(a => a.index === 'delete').url)"
                                        >
                                            <span class="icon-delete cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-violet-100 dark:hover:bg-gray-800" title="@lang('shopify::app.shopify.metaobject.delete-field')"></span>
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </template>
                    </x-admin::datagrid>
                </div>

                <teleport to="body">
                <x-admin::modal ref="fieldModal" type="medium">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white" v-text="form.existing ? '@lang('shopify::app.shopify.metaobject.edit-field')' : '@lang('shopify::app.shopify.metaobject.add-field')'"></p>
                    </x-slot>

                    <x-slot:content>
                        <v-metaobject-field-form
                            :key="openToken"
                            :field="form"
                            :field-types="fieldTypes"
                            :definition-options="definitionOptions"
                            :locked="true"
                            @open-nested="openNested"
                        ></v-metaobject-field-form>
                    </x-slot>

                    <x-slot:footer>
                        <div class="flex w-full justify-end gap-2.5">
                            <button type="button" class="secondary-button" @click="closeModal">@lang('shopify::app.shopify.metaobject.cancel')</button>
                            <button type="button" class="primary-button" :disabled="savingField" @click="saveField">@lang('shopify::app.shopify.metaobject.save')</button>
                        </div>
                    </x-slot>
                </x-admin::modal>
                </teleport>

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
            app.component('v-metaobject-fields', {
                template: '#v-metaobject-fields-template',

                props: {
                    definitionId: { type: Number, required: true },
                    fieldTypes: { type: Object, default: () => ({}) },
                },

                data() {
                    return {
                        savingField: false,
                        gridColumns: [],
                        definitionOptions: [],
                        form: this.blankField(),
                        openToken: 0,
                        nestedOpen: false,
                    };
                },

                computed: {
                    visibleColumns() {
                        return this.gridColumns.filter(column => column.visible !== false);
                    },

                    gridsCount() {
                        let count = this.visibleColumns.length;

                        if (this.$refs.datagrid?.available?.actions?.length) {
                            ++count;
                        }

                        return count;
                    },
                },

                mounted() {
                    this.$axios.get("{{ route('shopify.metaobject.local-definitions') }}", { params: { exclude: this.definitionId } })
                        .then(res => { this.definitionOptions = (res.data.options ?? []).map(definition => ({ id: definition.code, label: definition.name })); });

                    this.$emitter.on('change-datagrid', payload => {
                        if (payload.available !== this.$refs.datagrid?.available) {
                            return;
                        }

                        this.gridColumns = payload.available.columns;
                    });
                },

                methods: {
                    blankField() {
                        return { name: '', type: '', child: '', list: false, content_type: '', existing: false, validations: { min: '', max: '', unit: '', max_precision: '', regex: '', choices: '' } };
                    },

                    openCreate() {
                        this.form = this.blankField();
                        this.openToken++;
                        this.$refs.fieldModal.open();
                    },

                    editModal(url) {
                        this.$axios.get(url).then(res => {
                            const field = res.data.field;

                            this.form = {
                                key: field.key,
                                name: field.name,
                                type: field.preset || field.shopify_type,
                                child: field.child_type || '',
                                list: !! field.list,
                                content_type: field.content_type || '',
                                existing: true,
                                validations: { min: '', max: '', unit: '', max_precision: '', regex: '', choices: '', ...(field.validations || {}) },
                            };

                            this.openToken++;
                            this.$refs.fieldModal.open();
                        });
                    },

                    closeModal() {
                        this.$refs.fieldModal.close();
                    },

                    saveField() {
                        if (! (this.form.name || '').trim() || ! this.form.type) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.fields-required')" });

                            return;
                        }

                        this.savingField = true;

                        const request = this.form.existing
                            ? this.$axios.put("{{ route('shopify.metaobject.field.update', [':id', ':key']) }}".replace(':id', this.definitionId).replace(':key', this.form.key), this.form)
                            : this.$axios.post("{{ route('shopify.metaobject.field.store', ':id') }}".replace(':id', this.definitionId), this.form);

                        request.then(() => {
                            window.location.reload();
                        }).catch(error => {
                            this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.errors?.name?.[0] ?? '' });
                            this.savingField = false;
                        });
                    },

                    deleteField(url) {
                        if ((this.$refs.datagrid?.available?.meta?.total ?? 0) <= 1) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.last-field')" });

                            return;
                        }

                        this.$emitter.emit('open-delete-modal', {
                            agree: () => {
                                this.$axios.delete(url)
                                    .then(() => {
                                        window.location.reload();
                                    })
                                    .catch(error => {
                                        this.$emitter.emit('add-flash', { type: 'error', message: error.response?.data?.message ?? '' });
                                    });
                            },
                        });
                    },

                    openNested() {
                        this.nestedOpen = true;
                        this.$refs.nestedModal.open();
                        this.$nextTick(() => this.raiseModal());
                    },

                    onNestedSaved(data) {
                        this.definitionOptions.push({ id: data.code, label: data.name });
                        this.form.child = data.code;
                        this.closeNested();
                    },

                    closeNested() {
                        this.nestedOpen = false;
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
                },
            });
        </script>

        <script type="text/x-template" id="v-metaobject-entries-template">
            <div>
                <div class="mb-4 flex items-center justify-between gap-4 max-sm:flex-wrap">
                    <p class="text-xl font-bold text-gray-800 dark:text-slate-50">@lang('shopify::app.shopify.metaobject.entries')</p>

                    @if (bouncer()->hasPermission('shopify.metaobjects.entry-save'))
                        <button type="button" class="primary-button" @click="openCreate">@lang('shopify::app.shopify.metaobject.add-entry')</button>
                    @endif
                </div>

                <x-admin::datagrid
                    :src="route('shopify.metaobject.entry.datagrid', $definition->code)"
                    ref="datagrid"
                >
                    <template #body="{ records, performAction }">
                        <div
                            v-for="record in records"
                            :key="record.id"
                            class="row grid items-center gap-2.5 border-b px-4 py-4 text-gray-600 transition-all hover:bg-violet-50 dark:border-cherry-800 dark:text-gray-300 dark:hover:bg-cherry-800"
                            :style="`grid-template-columns: repeat(${gridsCount}, minmax(0, 1fr))`"
                        >
                            <template v-for="column in visibleColumns" :key="column.index">
                                <img
                                    v-if="column.type === 'image'"
                                    :src="record[column.index] ? record[column.index] : '{{ unopim_asset('images/placeholder.svg') }}'"
                                    class="h-14 max-h-14 min-h-14 w-14 min-w-14 max-w-14 rounded-lg border border-gray-200 object-cover dark:border-cherry-800"
                                />

                                <p v-else class="truncate" :title="record[column.index]" v-html="record[column.index]"></p>
                            </template>

                            <div class="flex justify-end gap-1">
                                @if (bouncer()->hasPermission('shopify.metaobjects.entry-save'))
                                    <a
                                        v-if="record.actions.find(a => a.index === 'edit' && a.url)"
                                        @click="editModal(record.actions.find(a => a.index === 'edit').url)"
                                    >
                                        <span class="icon-edit cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-violet-100 dark:hover:bg-gray-800" title="@lang('shopify::app.shopify.metaobject.edit-entry')"></span>
                                    </a>
                                @endif

                                @if (bouncer()->hasPermission('shopify.metaobjects.entry-delete'))
                                    <a
                                        v-if="record.actions.find(a => a.index === 'delete' && a.url)"
                                        @click="performAction(record.actions.find(a => a.index === 'delete'))"
                                    >
                                        <span class="icon-delete cursor-pointer rounded-md p-1.5 text-2xl transition-all hover:bg-violet-100 dark:hover:bg-gray-800" title="@lang('shopify::app.shopify.metaobject.delete')"></span>
                                    </a>
                                @endif
                            </div>
                        </div>
                    </template>
                </x-admin::datagrid>

                <teleport to="body">
                <x-admin::modal ref="entryModal" type="medium">
                    <x-slot:header>
                        <p class="text-lg font-bold text-gray-800 dark:text-white" v-text="form.id ? '@lang('shopify::app.shopify.metaobject.edit-entry')' : '@lang('shopify::app.shopify.metaobject.add-entry')'"></p>
                    </x-slot>

                    <x-slot:content>
                        <div class="flex flex-col gap-4">
                            <x-admin::form.control-group class="!mb-0">
                                <x-admin::form.control-group.label class="required">@lang('shopify::app.shopify.metaobject.entry-code')</x-admin::form.control-group.label>

                                <input type="text" v-model="form.code" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                            </x-admin::form.control-group>

                            <div v-for="field in formFields" :key="field.key" class="!mb-0">
                                <x-admin::form.control-group.label><span v-text="field.name"></span></x-admin::form.control-group.label>

                                <textarea v-if="isTextarea(field)" v-model="form.values[field.key]" rows="3" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900"></textarea>

                                <label v-else-if="field.shopify_type === 'boolean'" class="relative inline-flex cursor-pointer items-center">
                                    <input type="checkbox" v-model="form.values[field.key]" class="peer sr-only" />
                                    <span class="h-5 w-9 rounded-full bg-gray-200 dark:bg-cherry-800 after:content-[''] after:absolute after:top-0.5 after:left-0.5 after:h-4 after:w-4 after:rounded-full after:border after:border-gray-300 after:bg-white after:transition-all peer-checked:bg-primary-700 peer-checked:after:translate-x-full peer-checked:after:border-white"></span>
                                </label>

                                <input v-else-if="field.shopify_type === 'color'" type="color" v-model="form.values[field.key]" class="h-9 w-16 rounded-md border border-gray-300 dark:border-gray-600" />

                                <div v-else-if="field.preset === 'choice_list' && choiceOptions(field).length">
                                    <v-multiselect
                                        :options="choiceOptions(field)"
                                        :model-value="form.values[field.key] || null"
                                        :allow-empty="true"
                                        :show-labels="false"
                                        :searchable="false"
                                        :placeholder="labels.select"
                                        @select="value => form.values[field.key] = value"
                                        @remove="() => form.values[field.key] = ''"
                                    ></v-multiselect>
                                </div>

                                <v-metaobject-file
                                    v-else-if="field.shopify_type === 'file_reference'"
                                    :key="field.key + '-' + openToken"
                                    :accept="acceptFor(field)"
                                    :content-type="field.content_type"
                                    :value="form.values[field.key] || ''"
                                    base-url="{{ Storage::disk('public')->url('') }}"
                                    @picked="onFilePicked(field.key, $event)"
                                    @cleared="onFileCleared(field.key)"
                                ></v-metaobject-file>

                                <div v-else-if="isCatalogReference(field)">
                                    <v-multiselect
                                        :options="referenceOptions(field)"
                                        label="label"
                                        track-by="id"
                                        :multiple="field.list"
                                        :model-value="referenceSelection(field)"
                                        :loading="referenceLoading[field.key]"
                                        :internal-search="false"
                                        :allow-empty="true"
                                        :show-labels="false"
                                        :placeholder="labels.select"
                                        @open="loadReferences(field)"
                                        @search-change="query => loadReferences(field, query)"
                                        @select="option => onReferencePick(field, option)"
                                        @remove="option => onReferenceUnpick(field, option)"
                                    ></v-multiselect>
                                </div>

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
                                    <input type="text" :value="linkValue(field, 'text')" @input="setLink(field, 'text', $event.target.value)" :placeholder="labels.linkText" class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
                                    <input type="url" :value="linkValue(field, 'url')" @input="setLink(field, 'url', $event.target.value)" :placeholder="labels.linkUrl" class="mt-1 w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
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
                                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900" />
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
                        gridColumns: [],
                        childEntries: {},
                        referenceCache: {},
                        referenceLoading: {},
                        pickedFiles: {},
                        saving: false,
                        openToken: 0,
                        form: { id: null, code: '', values: {} },
                        labels: {
                            select: "@lang('shopify::app.shopify.metaobject.select')",
                            linkText: "@lang('shopify::app.shopify.metaobject.link-text')",
                            linkUrl: "@lang('shopify::app.shopify.metaobject.link-url')",
                        },
                    };
                },

                computed: {
                    formFields() {
                        return this.fields.filter(field => this.supported(field));
                    },

                    visibleColumns() {
                        return this.gridColumns.filter(column => column.visible !== false);
                    },

                    gridsCount() {
                        let count = this.visibleColumns.length;

                        if (this.$refs.datagrid?.available?.actions?.length) {
                            ++count;
                        }

                        return count;
                    },
                },

                mounted() {
                    this.loadChildren();

                    this.$emitter.on('change-datagrid', payload => {
                        if (payload.available !== this.$refs.datagrid?.available) {
                            return;
                        }

                        this.gridColumns = payload.available.columns;
                    });
                },

                methods: {
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

                    isCatalogReference(field) {
                        return ['product_reference', 'variant_reference', 'collection_reference'].includes(field.shopify_type);
                    },

                    referenceOptions(field) {
                        return this.referenceCache[field.key] || [];
                    },

                    loadReferences(field, query = '') {
                        this.referenceLoading[field.key] = true;

                        this.$axios.get("{{ route('shopify.metaobject.reference-options') }}", {
                            params: { refType: field.shopify_type, query, page: 1 },
                        }).then(res => {
                            this.referenceCache[field.key] = res.data.options ?? [];
                        }).finally(() => {
                            this.referenceLoading[field.key] = false;
                        });
                    },

                    referenceSelection(field) {
                        const value = this.form.values[field.key];

                        if (field.list) {
                            const ids = Array.isArray(value) ? value : (value ? [value] : []);

                            return ids.map(id => ({ id, label: id }));
                        }

                        return value ? { id: value, label: value } : null;
                    },

                    onReferencePick(field, option) {
                        if (field.list) {
                            const current = Array.isArray(this.form.values[field.key]) ? this.form.values[field.key] : [];
                            this.form.values[field.key] = [...current, option.id];

                            return;
                        }

                        this.form.values[field.key] = option.id;
                    },

                    onReferenceUnpick(field, option) {
                        if (field.list) {
                            const current = Array.isArray(this.form.values[field.key]) ? this.form.values[field.key] : [];
                            this.form.values[field.key] = current.filter(id => id !== option.id);

                            return;
                        }

                        this.form.values[field.key] = '';
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

                    choiceOptions(field) {
                        return (field.validations?.choices || '').split(',').map(choice => choice.trim()).filter(Boolean);
                    },

                    acceptFor(field) {
                        if (field.content_type === 'IMAGE') {
                            return 'image/*';
                        }

                        if (field.content_type === 'VIDEO') {
                            return 'video/*';
                        }

                        if (field.content_type === 'FILE' && (field.validations?.file_mode || 'any') === 'media') {
                            return 'image/*,video/*';
                        }

                        return null;
                    },

                    openCreate() {
                        this.form = { id: null, code: '', values: {} };
                        this.pickedFiles = {};
                        this.openToken++;
                        this.$refs.entryModal.open();
                    },

                    editModal(url) {
                        this.$axios.get(url).then(res => {
                            this.form = { id: res.data.id, code: res.data.code, values: { ...(res.data.values ?? {}) } };
                            this.pickedFiles = {};
                            this.openToken++;
                            this.$refs.entryModal.open();
                        });
                    },

                    closeModal() {
                        this.$refs.entryModal.close();
                    },

                    onFilePicked(key, file) {
                        this.pickedFiles[key] = file;
                    },

                    onFileCleared(key) {
                        delete this.pickedFiles[key];
                        this.form.values[key] = '';
                    },

                    isValidUrl(value) {
                        try {
                            const url = new URL(String(value));

                            return url.protocol === 'http:' || url.protocol === 'https:';
                        } catch {
                            return false;
                        }
                    },

                    isValidEmail(value) {
                        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value));
                    },

                    passesFieldRules(field, list) {
                        const rules = field.validations || {};
                        const numeric = ['number_integer', 'number_decimal', 'rating', 'money', 'weight', 'volume', 'dimension'];
                        const text = ['single_line_text_field', 'multi_line_text_field', 'id'];
                        const dates = ['date', 'date_time'];
                        const has = key => rules[key] !== undefined && rules[key] !== null && rules[key] !== '';

                        for (const item of list) {
                            if (numeric.includes(field.shopify_type)) {
                                const num = Number(item);

                                if (Number.isNaN(num)) {
                                    return false;
                                }

                                if (has('min') && num < Number(rules.min)) {
                                    return false;
                                }

                                if (has('max') && num > Number(rules.max)) {
                                    return false;
                                }

                                if (field.shopify_type === 'number_decimal' && has('max_precision')) {
                                    const decimals = (String(item).split('.')[1] || '').length;

                                    if (decimals > Number(rules.max_precision)) {
                                        return false;
                                    }
                                }
                            } else if (text.includes(field.shopify_type)) {
                                const length = String(item).length;

                                if (has('min') && length < Number(rules.min)) {
                                    return false;
                                }

                                if (has('max') && length > Number(rules.max)) {
                                    return false;
                                }

                                if (field.shopify_type === 'id' && rules.regex) {
                                    try {
                                        if (! new RegExp(rules.regex).test(String(item))) {
                                            return false;
                                        }
                                    } catch (error) {
                                        return true;
                                    }
                                }
                            } else if (dates.includes(field.shopify_type)) {
                                if (has('min') && String(item) < String(rules.min)) {
                                    return false;
                                }

                                if (has('max') && String(item) > String(rules.max)) {
                                    return false;
                                }
                            }
                        }

                        return true;
                    },

                    save() {
                        if (! this.form.code.trim()) {
                            this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.entry-code-required')" });

                            return;
                        }

                        for (const field of this.formFields) {
                            const value = this.form.values[field.key];

                            if (value === undefined || value === null || value === '' || (Array.isArray(value) && ! value.length)) {
                                continue;
                            }

                            if (field.shopify_type === 'link') {
                                const url = (value && typeof value === 'object') ? (value.url || '') : '';

                                if (url && ! this.isValidUrl(url)) {
                                    this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.invalid-url')" });

                                    return;
                                }

                                continue;
                            }

                            const list = Array.isArray(value) ? value : [value];

                            if (field.shopify_type === 'url' && list.some(item => ! this.isValidUrl(item))) {
                                this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.invalid-url')" });

                                return;
                            }

                            if ((field.preset === 'email' || field.shopify_type === 'email') && list.some(item => ! this.isValidEmail(item))) {
                                this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.invalid-email')" });

                                return;
                            }

                            if (! this.passesFieldRules(field, list)) {
                                this.$emitter.emit('add-flash', { type: 'warning', message: "@lang('shopify::app.shopify.metaobject.entry-value-invalid')".replace(':field', field.name) });

                                return;
                            }
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
                                this.$refs.datagrid.get();
                            }).finally(() => { this.saving = false; });
                    },
                },
            });
        </script>

        <script type="text/x-template" id="v-metaobject-file-template">
            <div>
                <div v-if="hasSelection" class="group relative flex flex-col overflow-hidden rounded-lg border border-gray-200 bg-white shadow-sm dark:border-cherry-800 dark:bg-cherry-900" style="width:120px">
                    <div class="relative w-full" style="height:120px">
                        <img v-if="isImage && imageSrc" :src="imageSrc" class="h-full w-full bg-gray-100 object-cover dark:bg-cherry-800" />
                        <video v-else-if="isVideo && imageSrc" :src="imageSrc" class="h-full w-full bg-gray-100 object-cover dark:bg-cherry-800" muted playsinline></video>
                        <div v-else class="flex h-full w-full items-center justify-center bg-gray-50 dark:bg-cherry-800">
                            <span class="text-4xl text-gray-400" :class="isImage ? 'icon-image' : 'icon-file'"></span>
                        </div>

                        <div class="absolute inset-0 flex items-end justify-center gap-2 bg-gradient-to-t from-black/60 via-black/20 to-transparent p-2 opacity-0 transition-opacity group-hover:opacity-100">
                            <label class="icon-edit cursor-pointer rounded-md bg-white/10 p-1.5 text-xl text-white hover:bg-white/30" :for="inputId"></label>
                            <span class="icon-delete cursor-pointer rounded-md bg-white/10 p-1.5 text-xl text-white hover:bg-red-500/80" @click="clear"></span>
                        </div>
                    </div>

                    <p class="truncate px-2 py-1.5 text-center text-xs text-gray-700 dark:text-gray-300" :title="displayName" v-text="displayName"></p>
                </div>

                <label v-else class="group flex cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed border-gray-300 transition-all hover:border-violet-500 dark:border-cherry-500" style="width:120px;height:120px" :for="inputId">
                    <span class="text-3xl text-gray-400 transition-colors group-hover:text-violet-600" :class="isImage ? 'icon-image' : 'icon-file'"></span>
                    <p class="mt-2 text-sm font-semibold text-gray-700 dark:text-gray-200">
                        <template v-if="isImage">@lang('shopify::app.shopify.metaobject.add-image')</template>
                        <template v-else>@lang('shopify::app.shopify.metaobject.add-file')</template>
                    </p>
                </label>

                <input type="file" class="hidden" :id="inputId" :accept="accept" ref="input" @change="onChange" />
            </div>
        </script>

        <script type="module">
            app.component('v-metaobject-file', {
                template: '#v-metaobject-file-template',

                props: {
                    accept: { type: String, default: null },
                    contentType: { type: String, default: '' },
                    value: { type: String, default: '' },
                    baseUrl: { type: String, default: '' },
                },

                emits: ['picked', 'cleared'],

                data() {
                    return { file: null, previewUrl: '', existing: this.value };
                },

                computed: {
                    inputId() {
                        return 'mo_file_' + this.$.uid;
                    },

                    hasSelection() {
                        return !! this.file || !! this.existing;
                    },

                    imageSrc() {
                        if (this.previewUrl) {
                            return this.previewUrl;
                        }

                        if (! this.existing) {
                            return '';
                        }

                        return /^(https?:)?\/\//.test(this.existing) ? this.existing : this.baseUrl + this.existing;
                    },

                    isVideo() {
                        if (this.file) {
                            return (this.file.type || '').startsWith('video/');
                        }

                        if (this.contentType) {
                            return this.contentType === 'VIDEO';
                        }

                        return /\.(mp4|webm|mov|m4v|ogg)$/i.test(this.existing);
                    },

                    isImage() {
                        if (this.file) {
                            return (this.file.type || '').startsWith('image/');
                        }

                        if (this.contentType) {
                            return this.contentType === 'IMAGE';
                        }

                        return /\.(jpe?g|png|gif|webp|svg)$/i.test(this.existing);
                    },

                    displayName() {
                        if (this.file) {
                            return this.file.name;
                        }

                        return this.existing ? this.existing.split('/').pop() : '';
                    },
                },

                methods: {
                    onChange(event) {
                        const file = event.target.files[0];

                        if (! file) {
                            return;
                        }

                        this.file = file;
                        this.existing = '';
                        this.previewUrl = '';

                        if ((file.type || '').startsWith('image/')) {
                            const reader = new FileReader();
                            reader.onload = e => { this.previewUrl = e.target.result; };
                            reader.readAsDataURL(file);
                        } else if ((file.type || '').startsWith('video/')) {
                            this.previewUrl = URL.createObjectURL(file);
                        }

                        this.$emit('picked', file);
                    },

                    clear() {
                        this.file = null;
                        this.previewUrl = '';
                        this.existing = '';

                        if (this.$refs.input) {
                            this.$refs.input.value = '';
                        }

                        this.$emit('cleared');
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts.with-history>
