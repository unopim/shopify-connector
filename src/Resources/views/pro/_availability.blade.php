{{--
    One cell of the comparison: whether the edition ships the feature. The mark
    carries the wording for a reader who cannot see it.
--}}
@props(['available' => false])

@if ($available)
    <span class="icon-done text-xl text-green-600 dark:text-green-400" role="img" aria-label="{{ trans('shopify::app.shopify.pro.comparison.included') }}"></span>
@else
    <span class="icon-cancel text-xl text-red-600 dark:text-red-400" role="img" aria-label="{{ trans('shopify::app.shopify.pro.comparison.not-included') }}"></span>
@endif
