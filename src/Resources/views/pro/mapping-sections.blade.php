{{--
    The mapping screen's two Pro cards travel into place together, so they are
    grouped here and the page's own notice stays at the top where it belongs.
--}}
<div class="shopify-pro-sections flex flex-col gap-4">
    @include('shopify::external-media.section')

    @include('shopify::association-mappings.section')
</div>
