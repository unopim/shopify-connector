<x-admin::layouts>
    <x-slot:title>@lang('shopify::app.shopify.metaobject.title')</x-slot>

    <div class="mb-2.5">
        <x-admin::breadcrumbs />
    </div>

    <v-metaobject-list>
        <x-admin::shimmer.datagrid />
    </v-metaobject-list>

    @pushOnce('scripts')
        <script
            type="text/x-template"
            id="v-metaobject-list-template"
        >
            <div class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <p class="text-xl font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.title')</p>

                    @if (bouncer()->hasPermission('shopify.metaobjects.store'))
                        <button
                            type="button"
                            class="primary-button"
                            @click="openCreate()"
                        >
                            @lang('shopify::app.shopify.metaobject.create')
                        </button>
                    @endif
                </div>

                <x-admin::datagrid :src="route('shopify.metaobject.index')" />

                <teleport to="body">
                    <x-admin::modal ref="createModal" type="medium">
                        <x-slot:header>
                            <p class="text-lg font-bold text-gray-800 dark:text-white">@lang('shopify::app.shopify.metaobject.create')</p>
                        </x-slot>

                        <x-slot:content>
                            <v-metaobject-builder
                                v-if="createOpen"
                                mode="create"
                                :is-nested="true"
                                :field-types='@json($fieldTypes)'
                                @saved="onSaved"
                                @cancel="closeCreate"
                            ></v-metaobject-builder>
                        </x-slot>
                    </x-admin::modal>
                </teleport>
            </div>
        </script>

        @include('shopify::metaobject._builder')

        <script type="module">
            app.component('v-metaobject-list', {
                template: '#v-metaobject-list-template',

                data() {
                    return {
                        createOpen: false,
                    };
                },

                methods: {
                    openCreate() {
                        this.createOpen = true;
                        this.$refs.createModal.open();
                    },

                    closeCreate() {
                        this.createOpen = false;
                        this.$refs.createModal.close();
                    },

                    onSaved(data) {
                        window.location.href = "{{ route('shopify.metaobject.edit', ':id') }}".replace(':id', data.id);
                    },
                },
            });
        </script>
    @endPushOnce
</x-admin::layouts>
