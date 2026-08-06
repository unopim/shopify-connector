@php
    $value ??= '';
    $fieldName ??= 'shopify_taxonomy';
@endphp

<v-shopify-taxonomy-field
    name="{{ $fieldName }}"
    value="{{ $value }}"
></v-shopify-taxonomy-field>

@pushOnce('scripts')
<script type="text/x-template" id="v-shopify-taxonomy-field-template">
    <div class="relative w-full">
        <input type="hidden" :name="name" :value="selected" />

        <div
            class="flex items-center justify-between w-full rounded-md border border-gray-300 px-3 py-2.5 text-sm cursor-pointer dark:border-gray-600 dark:bg-cherry-900"
            @click="toggleOpen"
        >
            <span :class="selectedLabel ? 'text-gray-700 dark:text-gray-300' : 'text-gray-400'" v-text="selectedLabel || labels.placeholder"></span>
            <span class="icon-chevron-down text-2xl text-gray-400"></span>
        </div>

        <div
            v-if="open"
            class="absolute z-10 mt-1 w-full rounded-md border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-cherry-900"
            style="max-height: 340px; overflow: hidden; display: flex; flex-direction: column;"
        >
            <div class="p-2 border-b dark:border-gray-700">
                <input
                    type="text"
                    v-model="query"
                    @input="onSearch"
                    :placeholder="labels.search"
                    class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-cherry-900"
                />
            </div>

            <div v-if="!query && stack.length" class="flex flex-wrap items-center gap-1 px-3 py-1.5 text-xs text-gray-500">
                <span class="cursor-pointer hover:underline" @click="goTo(-1)" v-text="labels.root"></span>
                <template v-for="(node, i) in stack" :key="node.id">
                    <span>/</span>
                    <span class="cursor-pointer hover:underline" @click="goTo(i)" v-text="node.name"></span>
                </template>
            </div>

            <div style="overflow-y: auto;">
                <div v-if="loading" class="px-3 py-4 text-sm text-gray-500" v-text="labels.loading"></div>

                <div
                    v-for="row in rows"
                    :key="row.id"
                    class="flex items-center justify-between gap-2 px-3 py-2.5 text-sm border-b dark:border-gray-800 cursor-pointer hover:bg-gray-50 dark:hover:bg-cherry-800"
                    @click="rowClick(row)"
                >
                    <span class="flex-1 text-gray-700 dark:text-gray-300" v-text="row.name"></span>
                    <span
                        v-if="row.hasChildren && !query"
                        class="icon-chevron-right text-xl text-gray-400"
                    ></span>
                </div>
            </div>
        </div>
    </div>
</script>

<script type="module">
    app.component('v-shopify-taxonomy-field', {
        template: '#v-shopify-taxonomy-field-template',

        props: ['name', 'value'],

        data() {
            return {
                open: false,
                loading: false,
                query: '',
                rows: [],
                stack: [],
                selected: this.value || '',
                selectedLabel: '',
                searchTimer: null,
                labels: {
                    placeholder: "@lang('shopify::app.shopify.attribute.taxonomy-select-placeholder')",
                    search: "@lang('shopify::app.shopify.metafield.index.taxonomy-search')",
                    root: "@lang('shopify::app.shopify.metafield.index.taxonomy-root')",
                    loading: "@lang('shopify::app.shopify.metafield.index.taxonomy-loading')",
                },
            };
        },

        created() {
            if (this.selected) {
                this.$axios.get("{{ route('admin.shopify.get-taxonomy-names') }}", { params: { ids: [this.selected] } })
                    .then(res => { this.selectedLabel = res.data.names[this.selected] || this.selected; });
            }
        },

        methods: {
            toggleOpen() {
                this.open = ! this.open;
                if (this.open) {
                    this.query = '';
                    this.stack = [];
                    this.loadLevel('');
                }
            },

            loadLevel(parent) {
                this.loading = true;
                this.$axios.get("{{ route('admin.shopify.get-taxonomy-tree') }}", { params: { parent } })
                    .then(res => { this.rows = res.data.options; })
                    .finally(() => { this.loading = false; });
            },

            rowClick(row) {
                if (row.hasChildren && ! this.query) {
                    this.drill(row);
                } else {
                    this.choose(row);
                }
            },

            drill(row) {
                this.stack.push({ id: row.id, name: row.name });
                this.loadLevel(row.id);
            },

            goTo(index) {
                this.stack = this.stack.slice(0, index + 1);
                this.loadLevel(index < 0 ? '' : this.stack[index].id);
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

            choose(row) {
                this.selected = row.id;
                this.selectedLabel = row.name.includes(' > ') ? row.name.split(' > ').pop() : row.name;
                this.open = false;
            },
        },
    });
</script>
@endPushOnce
