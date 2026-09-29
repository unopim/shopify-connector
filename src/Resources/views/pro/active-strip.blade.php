{{--
    Tells the admin, on any Shopify screen, that the Pro package is the one
    answering. Without it the screens say so themselves, each in its own card.
--}}
@if ($shopifyProInstalled)
    <div class="flex items-center gap-2 mb-3.5 rounded bg-white p-3 box-shadow dark:bg-cherry-900">
        <x-shopify::pro-cta variant="badge" />

        <p class="text-sm text-gray-600 dark:text-gray-300">
            @lang('shopify::app.shopify.pro.active')
        </p>
    </div>
@endif
