<x-admin::layouts.with-history
    :history-id="$credential->id"
    active-tab="catalogs"
    :history-url="route('shopify.credentials.edit', $credential->id).'?history=1'"
    :tab-items="$tabItems ?? []"
>
    <x-slot:entityName>
        shopify_credentials
    </x-slot>

    <x-slot:title>
        @lang('shopify::app.shopify.catalogs.breadcrumb')
    </x-slot>

    <x-slot:pageHeader>
        <div class="flex min-h-10 items-center justify-between gap-4 max-sm:flex-wrap">
            <div class="flex flex-col gap-1.5">
                @include('shopify::catalogs._breadcrumbs', [
                    'crumbs' => [
                        ['label' => trans('shopify::app.components.layouts.sidebar.shopify'), 'url' => route('shopify.credentials.index')],
                        ['label' => trans('shopify::app.shopify.credential.index.title'), 'url' => route('shopify.credentials.edit', $credential->id)],
                    ],
                    'leaf' => trans('shopify::app.shopify.catalogs.breadcrumb'),
                ])

                <div class="flex items-center gap-2">
                    <x-admin::heading
                        :title="trans('shopify::app.shopify.catalogs.breadcrumb')"
                        as="h1"
                        size="xl"
                    />

                    <x-shopify::pro-cta variant="badge" />
                </div>
            </div>

            {{--
                Two guards, for two states: the flag covers a store that holds
                the package without it being on, the include covers a store
                that does not hold it at all.
            --}}
            @if ($shopifyProInstalled)
                @includeIf('shopify_pro::catalogs._create-button')
            @endif
        </div>
    </x-slot>

    <x-slot:tabContents>
        @if (request('history') === null)
            @if ($shopifyProInstalled)
                <x-admin::datagrid :src="route('shopify.credentials.catalogs.index', $credential->id)" />
            @else
                <x-shopify::pro-notice variant="page" />
            @endif
        @endif
    </x-slot>

    @if ($shopifyProInstalled)
        @includeIf('shopify_pro::catalogs._create-form')
    @endif
</x-admin::layouts.with-history>
