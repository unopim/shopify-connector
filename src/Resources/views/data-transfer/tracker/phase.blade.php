<p
    v-if="summary && summary.current_phase"
    class="mt-2 text-center text-xs font-semibold leading-tight text-blue-600 dark:text-blue-400"
>
    <span v-if="summary.current_phase === 'product'">@lang('shopify::app.tracker.phase.product')</span>
    <span v-else-if="summary.current_phase === 'publishing'">@lang('shopify::app.tracker.phase.publishing')</span>
    <span v-else-if="summary.current_phase === 'translations'">@lang('shopify::app.tracker.phase.translations')</span>
    <span v-else-if="summary.current_phase === 'media'">@lang('shopify::app.tracker.phase.media')</span>
    <span v-else>@{{ summary.current_phase }}</span>
</p>
