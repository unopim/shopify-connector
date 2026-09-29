<x-admin::layouts.with-history
    :history-id="$credential->id"
    active-tab="realtime"
    :history-url="route('shopify.credentials.edit', $credential->id).'?history=1'"
    :tab-items="$tabItems ?? []"
>
    @php($realtimeAvailable = $shopifyProInstalled && \Illuminate\Support\Facades\Route::has('shopify.credentials.realtime.toggle'))

    <x-slot:entityName>
        shopify_credentials
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
                        ['label' => trans('shopify::app.shopify.credential.index.title'), 'url' => route('shopify.credentials.edit', $credential->id)],
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

            @if ($realtimeAvailable)
                <button
                    type="submit"
                    form="realtime-credential-form"
                    class="primary-button"
                >
                    @lang('shopify::app.shopify.credential.edit.save')
                </button>
            @endif
        </div>
    </x-slot>

    <x-slot:tabContents>
        @if (request('history') === null)
        <x-shopify::pro-notice variant="page" />

        <x-admin::form
            id="realtime-credential-form"
            :action="$realtimeAvailable ? route('shopify.credentials.realtime.toggle', $credential->id) : '#'"
            method="PUT"
            :ajax="true"
        >
            <fieldset @disabled(! $realtimeAvailable) class="contents">
            <div class="p-4 bg-white dark:bg-cherry-900 rounded box-shadow">
                {{--
                    The tracker appends its badge to the control group, so the row
                    is the group itself and the parts are ordered around it: the
                    badge follows the heading, the rest drops to its own line.
                --}}
                <x-admin::form.control-group class="realtime-toggle !mb-0">
                    <p class="realtime-toggle__title text-base text-gray-800 dark:text-white font-semibold">
                        @lang('shopify::app.shopify.realtime.enable')
                    </p>

                    <div class="realtime-toggle__switch">
                        <x-admin::form.control-group.control
                            type="switch"
                            name="enabled"
                            value="1"
                            :checked="$enabled"
                            :disabled="$blocking && ! $enabled"
                            :label="trans('shopify::app.shopify.realtime.enable')"
                        />
                    </div>

                    <p class="realtime-toggle__note text-xs text-gray-500 dark:text-gray-300">
                        @lang('shopify::app.shopify.realtime.enable-info')
                    </p>

                    @if ($blocker)
                        <p @class([
                            'realtime-toggle__note text-xs',
                            'text-red-600' => $blocking,
                            'text-amber-600 dark:text-amber-400' => ! $blocking,
                        ])>
                            {{ trans($blocker) }}
                        </p>
                    @endif
                </x-admin::form.control-group>
            </div>
            </fieldset>
        </x-admin::form>
        @endif
    </x-slot>
</x-admin::layouts.with-history>
