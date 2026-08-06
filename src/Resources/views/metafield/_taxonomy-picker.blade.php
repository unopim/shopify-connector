<script type="text/x-template" id="v-taxonomy-category-picker-template">
    <div>
        <input type="hidden" name="taxonomy_category" :value="JSON.stringify(selectedIds)" />

        <div class="flex flex-wrap items-center gap-2">
            <span
                v-for="id in chipIds"
                :key="id"
                class="rounded-full bg-gray-100 dark:bg-gray-800 px-2.5 py-1 text-xs text-gray-700 dark:text-gray-300"
                v-text="labelCache[id] || shortOf(id)"
            ></span>

            <span v-if="extraCount > 0" class="text-xs text-gray-500 dark:text-gray-400" v-text="'+' + extraCount"></span>

            <button
                type="button"
                class="secondary-button"
                @click="openModal"
                v-text="selectedIds.length ? assignLabels.edit : assignLabels.assign"
            ></button>
        </div>

        <teleport to="body">
        <x-admin::modal ref="taxonomyModal" type="medium">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white" v-text="assignLabels.assign"></p>
            </x-slot>

            <x-slot:content>
                <input
                    type="text"
                    v-model="query"
                    @input="onSearch"
                    :placeholder="assignLabels.search"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-900"
                />

                <div v-if="!query && stack.length" class="flex flex-wrap items-center gap-1 py-2 text-sm text-gray-500">
                    <span class="cursor-pointer hover:underline" @click="goTo(-1)" v-text="assignLabels.root"></span>
                    <template v-for="(node, i) in stack" :key="node.id">
                        <span>/</span>
                        <span class="cursor-pointer hover:underline" @click="goTo(i)" v-text="node.name"></span>
                    </template>
                </div>

                <div style="height: 60vh; overflow-y: auto;">
                    <div v-if="loading" class="py-6 text-gray-500" v-text="assignLabels.loading"></div>

                    <div
                        v-for="row in rows"
                        :key="row.id"
                        class="flex items-center gap-3 border-b py-2.5 dark:border-gray-800"
                    >
                        <span
                            v-if="row.hasChildren && !query"
                            class="icon-chevron-right cursor-pointer text-xl text-gray-400"
                            @click="drill(row)"
                        ></span>
                        <span v-else class="w-5 shrink-0"></span>

                        <span
                            class="cursor-pointer text-2xl shrink-0"
                            :class="isChecked(row.id) ? 'icon-checkbox-check text-violet-700' : (isIndeterminate(row.id) ? 'icon-checkbox-partial text-violet-700' : 'icon-checkbox-normal')"
                            @click="toggleNode(row)"
                        ></span>

                        <span class="flex-1 text-sm text-gray-700 dark:text-gray-300" v-text="row.name"></span>
                    </div>
                </div>
            </x-slot>

            <x-slot:footer>
                <div class="flex w-full items-center justify-between">
                    <span class="text-sm text-gray-600 dark:text-gray-300" v-text="selectedIds.length + ' ' + assignLabels.selected"></span>

                    <div class="flex gap-2.5">
                        <button type="button" class="secondary-button" @click="closeModal" v-text="assignLabels.cancel"></button>
                        <button type="button" class="primary-button" @click="closeModal" v-text="assignLabels.done"></button>
                    </div>
                </div>
            </x-slot>
        </x-admin::modal>
        </teleport>
    </div>
</script>

<script type="module">
    app.component('v-taxonomy-category-picker', {
        template: '#v-taxonomy-category-picker-template',

        props: {
            initial: {
                type: Array,
                default: () => [],
            },
        },

        emits: ['count-change'],

        data() {
            return {
                loading: false,
                query: '',
                rows: [],
                stack: [],
                selected: {},
                labelCache: {},
                searchTimer: null,
                assignLabels: {
                    assign: "@lang('shopify::app.shopify.metafield.index.taxonomy-assign')",
                    edit: "@lang('shopify::app.shopify.metafield.index.taxonomy-edit')",
                    search: "@lang('shopify::app.shopify.metafield.index.taxonomy-search')",
                    root: "@lang('shopify::app.shopify.metafield.index.taxonomy-root')",
                    loading: "@lang('shopify::app.shopify.metafield.index.taxonomy-loading')",
                    selected: "@lang('shopify::app.shopify.metafield.index.taxonomy-selected')",
                    cancel: "@lang('shopify::app.shopify.metafield.index.taxonomy-cancel')",
                    done: "@lang('shopify::app.shopify.metafield.index.taxonomy-done')",
                },
            };
        },

        computed: {
            selectedIds() {
                return Object.keys(this.selected);
            },

            chipIds() {
                return this.selectedIds.slice(0, 3);
            },

            extraCount() {
                return Math.max(0, this.selectedIds.length - 3);
            },
        },

        watch: {
            selectedIds(ids) {
                this.$emit('count-change', ids.length);
            },
        },

        created() {
            this.initial.forEach(opt => {
                this.selected[opt.id] = true;
                this.labelCache[opt.id] = opt.label;
            });
        },

        mounted() {
            this.$emit('count-change', this.selectedIds.length);
        },

        methods: {
            shortOf(gid) {
                return gid.substring(gid.lastIndexOf('/') + 1);
            },

            openModal() {
                this.query = '';
                this.stack = [];
                this.loadLevel('');
                this.refreshChipLabels();
                this.$refs.taxonomyModal.open();
                this.$nextTick(() => this.raiseModal(true));
            },

            closeModal() {
                this.raiseModal(false);
                this.$refs.taxonomyModal.close();
            },

            raiseModal(on) {
                const root = this.$refs.taxonomyModal?.$el;
                if (! root) {
                    return;
                }

                const overlay = root.querySelector('.bg-gray-500');
                const content = root.querySelector('[class*="10002"]');

                if (overlay) {
                    overlay.style.zIndex = on ? '10005' : '';
                    overlay.style.backdropFilter = on ? 'blur(4px)' : '';
                    overlay.style.webkitBackdropFilter = on ? 'blur(4px)' : '';
                }

                if (content) {
                    content.style.zIndex = on ? '10006' : '';
                }
            },

            loadLevel(parent) {
                this.loading = true;
                this.$axios.get("{{ route('admin.shopify.get-taxonomy-tree') }}", { params: { parent } })
                    .then(res => { this.rows = res.data.options; })
                    .finally(() => { this.loading = false; });
            },

            drill(row) {
                this.stack.push({ id: row.id, name: row.name });
                this.loadLevel(row.id);
            },

            goTo(index) {
                this.stack = this.stack.slice(0, index + 1);
                const parent = index < 0 ? '' : this.stack[index].id;
                this.loadLevel(parent);
            },

            onSearch() {
                clearTimeout(this.searchTimer);
                this.searchTimer = setTimeout(() => {
                    if (! this.query.trim()) {
                        this.stack = [];
                        this.loadLevel('');
                        return;
                    }
                    this.loading = true;
                    this.$axios.get("{{ route('admin.shopify.get-taxonomy-tree') }}", { params: { query: this.query } })
                        .then(res => { this.rows = res.data.options; })
                        .finally(() => { this.loading = false; });
                }, 300);
            },

            isChecked(id) {
                return !! this.selected[id];
            },

            isIndeterminate(id) {
                if (this.selected[id]) {
                    return false;
                }
                const prefix = this.shortOf(id) + '-';
                return this.selectedIds.some(s => this.shortOf(s).startsWith(prefix));
            },

            toggleNode(row) {
                this.$axios.get("{{ route('admin.shopify.get-taxonomy-descendants') }}", { params: { id: row.id } })
                    .then(res => {
                        const ids = res.data.ids;
                        if (this.isChecked(row.id)) {
                            ids.forEach(id => { delete this.selected[id]; });
                        } else {
                            ids.forEach(id => { this.selected[id] = true; });
                            this.labelCache[row.id] = row.name.includes(' > ') ? row.name.split(' > ').pop() : row.name;
                        }
                        this.selected = { ...this.selected };
                        this.refreshChipLabels();
                    });
            },

            refreshChipLabels() {
                const missing = this.chipIds.filter(id => ! this.labelCache[id]);
                if (! missing.length) {
                    return;
                }
                this.$axios.get("{{ route('admin.shopify.get-taxonomy-names') }}", { params: { ids: missing } })
                    .then(res => { this.labelCache = { ...this.labelCache, ...res.data.names }; });
            },
        },
    });
</script>
