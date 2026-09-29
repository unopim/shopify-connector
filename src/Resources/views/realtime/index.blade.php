<x-admin::layouts.with-history
    active-tab="realtime"
    :history-id="\Webkul\Shopify\Support\ShopifyMapping::EXPORT_ID"
    :tab-items="$tabItems ?? []"
>
    @php($realtimeAvailable = $shopifyProInstalled && \Illuminate\Support\Facades\Route::has('shopify.realtime.store'))

    <x-slot:entityName>
        shopify_exportmapping
    </x-slot>

    <x-slot:title>
        @lang('shopify::app.shopify.realtime.title')
    </x-slot>

    <x-slot:pageHeader>
        <div class="flex min-h-10 items-center justify-between gap-4 max-sm:flex-wrap">
            <div class="flex flex-col gap-1.5">
                @include('shopify::catalogs._breadcrumbs', [
                    'crumbs' => [
                        ['label' => trans('shopify::app.components.layouts.sidebar.shopify'), 'url' => route('shopify.credentials.index')],
                        ['label' => trans('shopify::app.shopify.export.mapping.title'), 'url' => route('admin.shopify.export-mappings', \Webkul\Shopify\Support\ShopifyMapping::EXPORT_ID)],
                    ],
                    'leaf' => trans('shopify::app.shopify.realtime.title'),
                ])

                <div class="flex items-center gap-2">
                    <x-admin::heading
                        :title="trans('shopify::app.shopify.realtime.title')"
                        as="h1"
                        size="xl"
                    />

                    <x-shopify::pro-cta variant="badge" />
                </div>
            </div>

            @if ($realtimeAvailable && request('history') === null)
                <button
                    type="submit"
                    form="realtime-settings-form"
                    class="primary-button"
                >
                    @lang('shopify::app.shopify.export.mapping.save')
                </button>
            @endif
        </div>
    </x-slot>

    <x-slot:tabContents>
        @if (request('history') === null)
        <x-shopify::pro-notice variant="page" />

        <x-admin::form
            id="realtime-settings-form"
            :action="$realtimeAvailable ? route('shopify.realtime.store', \Webkul\Shopify\Support\ShopifyMapping::EXPORT_ID) : '#'"
            method="PUT"
            :ajax="true"
        >
            <fieldset @disabled(! $realtimeAvailable) class="contents">
            <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow grid grid-cols-2 max-sm:grid-cols-1 gap-x-5">
                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('shopify::app.shopify.realtime.channel')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="select"
                        name="channel"
                        :value="$realtimeSettings['channel']"
                        :label="trans('shopify::app.shopify.realtime.channel')"
                        async="true"
                        track-by="id"
                        label-by="label"
                        :list-route="route('shopify.channel.fetch-all')"
                    />
                </x-admin::form.control-group>

                <x-admin::form.control-group>
                    <x-admin::form.control-group.label>
                        @lang('shopify::app.shopify.realtime.currency')
                    </x-admin::form.control-group.label>

                    <x-admin::form.control-group.control
                        type="select"
                        name="currency"
                        :value="$realtimeSettings['currency']"
                        :label="trans('shopify::app.shopify.realtime.currency')"
                        async="true"
                        track-by="id"
                        label-by="label"
                        :list-route="route('shopify.currency.fetch-all')"
                    />
                </x-admin::form.control-group>
            </div>
            </fieldset>
        </x-admin::form>
        @endif
    </x-slot>
</x-admin::layouts.with-history>
