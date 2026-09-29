<x-admin::layouts>
    <x-slot:title>
        @lang('shopify::app.shopify.pro.comparison.title')
    </x-slot>

    <x-admin::page-header
        :title="trans('shopify::app.shopify.pro.comparison.title')"
        :subtitle="trans('shopify::app.shopify.pro.comparison.intro')"
        :breadcrumb="false"
    >
        <x-slot:actions>
            <x-shopify::pro-cta variant="store" />
        </x-slot>
    </x-admin::page-header>

    {{--
        One table rather than a column per edition, so a long feature list stays
        one scan down the page; the narrow screens scroll it sideways instead of
        stacking every row into its own card.
    --}}
    <div class="mt-5 overflow-x-auto rounded-lg box-shadow bg-white dark:bg-cherry-900">
        <x-admin::table class="min-w-[36rem]">
            <x-admin::table.thead>
                <x-admin::table.thead.tr>
                    <x-admin::table.th>
                        @lang('shopify::app.shopify.pro.comparison.feature')
                    </x-admin::table.th>

                    <x-admin::table.th class="w-32 !text-center">
                        @lang('shopify::app.shopify.pro.comparison.community')
                    </x-admin::table.th>

                    <x-admin::table.th class="w-32 !text-center">
                        @lang('shopify::app.shopify.pro.comparison.pro')
                    </x-admin::table.th>
                </x-admin::table.thead.tr>
            </x-admin::table.thead>

            <x-admin::table.tbody>
                @foreach ($groups as $group)
                    <x-admin::table.tbody.tr class="bg-gray-50 dark:bg-cherry-800">
                        <x-admin::table.td colspan="3" class="!py-2 text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            @lang($group['title'])
                        </x-admin::table.td>
                    </x-admin::table.tbody.tr>

                    @foreach ($group['features'] as $feature)
                        <x-admin::table.tbody.tr>
                            <x-admin::table.td>
                                <p class="font-medium text-gray-800 dark:text-slate-50">
                                    @lang($feature['name'])
                                </p>

                                @isset($feature['note'])
                                    <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                        @lang($feature['note'])
                                    </p>
                                @endisset
                            </x-admin::table.td>

                            <x-admin::table.td class="text-center">
                                @include('shopify::pro._availability', ['available' => $feature['ce']])
                            </x-admin::table.td>

                            <x-admin::table.td class="text-center">
                                @include('shopify::pro._availability', ['available' => true])
                            </x-admin::table.td>
                        </x-admin::table.tbody.tr>
                    @endforeach
                @endforeach
            </x-admin::table.tbody>
        </x-admin::table>
    </div>
</x-admin::layouts>
