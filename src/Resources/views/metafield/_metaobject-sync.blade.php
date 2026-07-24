<script type="text/x-template" id="v-metaobject-sync-template">
    <span>
        <button type="button" class="secondary-button" :disabled="! definition" @click="openModal">
            @lang('shopify::app.shopify.metafield.index.metaobject-sync')
        </button>

        <teleport to="body">
        <x-admin::modal ref="metaobjectSyncModal" type="medium">
            <x-slot:header>
                <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metafield.index.metaobject-sync-title')</p>
            </x-slot>

            <x-slot:content>
                <template v-if="phase === 'select'">
                    <p v-if="loading" class="text-sm text-gray-500 dark:text-gray-400">...</p>

                    <template v-else>
                        <p
                            class="mb-2 text-sm text-gray-600 dark:text-gray-300"
                            v-text="hasUnsynced
                                ? '@lang('shopify::app.shopify.metafield.index.metaobject-sync-select')'
                                : '@lang('shopify::app.shopify.metafield.index.metaobject-sync-all-done')'"
                        ></p>

                        <div
                            v-for="store in stores"
                            :key="store.id"
                            class="flex items-center justify-between gap-2 rounded-md border border-gray-200 px-3 py-2 mb-2 text-sm dark:border-gray-700"
                        >
                            <span v-if="! store.synced" class="inline-flex flex-1 cursor-pointer items-center gap-2" @click="toggleTarget(store.id)">
                                <span class="text-2xl" :class="targets.includes(store.id) ? 'icon-checkbox-check text-violet-700' : 'icon-checkbox-normal'"></span>
                                <span class="text-gray-700 dark:text-gray-300" v-text="store.label"></span>
                            </span>

                            <template v-else>
                                <span class="flex items-center gap-2 text-gray-700 dark:text-gray-300">
                                    <span class="icon-checkbox-check text-xl text-green-600"></span>
                                    <span v-text="store.label"></span>
                                </span>
                                <button type="button" class="secondary-button !py-1 !text-xs" @click="updateOne(store.id)">
                                    @lang('shopify::app.shopify.metafield.index.metaobject-sync-update')
                                </button>
                            </template>
                        </div>
                    </template>
                </template>

                <template v-else>
                    <p v-if="running" class="text-sm text-gray-500 dark:text-gray-400">...</p>
                    <ul v-else class="space-y-2">
                        <li v-for="result in results" :key="result.store" class="flex items-center justify-between gap-2 rounded-md border border-gray-200 p-2 text-sm dark:border-gray-700">
                            <span class="text-gray-700 dark:text-gray-300" v-text="result.store"></span>
                            <span
                                :class="result.status === 'synced' ? 'text-green-600' : 'text-red-600'"
                                v-text="result.status === 'synced'
                                    ? '@lang('shopify::app.shopify.metafield.index.metaobject-synced')'
                                    : '@lang('shopify::app.shopify.metafield.index.metaobject-sync-failed')' + (result.message ? ': ' + result.message : '')"
                            ></span>
                        </li>
                    </ul>
                </template>
            </x-slot>

            <x-slot:footer>
                <div class="flex w-full justify-end gap-2.5">
                    <button type="button" class="secondary-button" @click="closeModal">Cancel</button>
                    <button v-if="phase === 'select' && hasUnsynced" type="button" class="primary-button" :disabled="! targets.length || running" @click="run">
                        @lang('shopify::app.shopify.metafield.index.metaobject-sync')
                    </button>
                </div>
            </x-slot>
        </x-admin::modal>
        </teleport>
    </span>
</script>

<script type="module">
    app.component('v-metaobject-sync', {
        template: '#v-metaobject-sync-template',

        props: {
            definition: { type: String, default: '' },
            credentialId: { type: [String, Number], default: '' },
        },

        data() {
            return {
                phase: 'select',
                loading: false,
                targets: [],
                running: false,
                results: [],
                stores: [],
            };
        },

        computed: {
            hasUnsynced() {
                return this.stores.some(store => ! store.synced);
            },
        },

        methods: {
            openModal() {
                this.phase = 'select';
                this.targets = [];
                this.results = [];
                this.stores = [];
                this.loading = true;
                this.$refs.metaobjectSyncModal.open();
                this.$nextTick(() => this.raiseModal('metaobjectSyncModal'));

                this.$axios.get("{{ route('shopify.metaobject.definition.sync-status') }}", {
                    params: { definition: this.definition, credential_id: this.credentialId },
                })
                    .then(res => { this.stores = res.data.stores || []; })
                    .finally(() => { this.loading = false; });
            },

            toggleTarget(id) {
                const index = this.targets.indexOf(id);

                if (index === -1) {
                    this.targets.push(id);
                } else {
                    this.targets.splice(index, 1);
                }
            },

            updateOne(id) {
                this.targets = [id];
                this.run();
            },

            run() {
                this.running = true;
                this.phase = 'result';
                this.results = [];

                this.$axios.post("{{ route('shopify.metaobject.definition.sync') }}", {
                    definition: this.definition,
                    credential_id: this.credentialId,
                    targets: this.targets,
                })
                    .then(res => { this.results = res.data.results || []; })
                    .catch(err => {
                        this.results = [{
                            store: '',
                            status: 'failed',
                            message: Object.values(err.response?.data?.errors || {}).flat().join(', ') || 'error',
                        }];
                    })
                    .finally(() => { this.running = false; });
            },

            raiseModal(ref) {
                const root = this.$refs[ref] && this.$refs[ref].$el;

                if (! root) {
                    return;
                }

                window.moModalZIndex = (window.moModalZIndex || 10050) + 10;
                const base = window.moModalZIndex;

                root.querySelectorAll('.fixed.inset-0').forEach(el => {
                    const isOverlay = el.classList.contains('bg-gray-500');
                    el.style.zIndex = isOverlay ? String(base) : String(base + 1);

                    if (isOverlay) {
                        el.style.backdropFilter = 'blur(4px)';
                    }
                });
            },

            closeModal() {
                this.$refs.metaobjectSyncModal.close();
            },
        },
    });
</script>
