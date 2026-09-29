{{--
    What a Pro feature wears: the notice it carries, the look of the controls it
    holds back, and the sidebar entry that sells the package. Core renders them
    all; only their look lives here.
--}}
@php
    $menuPath = parse_url(route('shopify.upgrade'), PHP_URL_PATH);
    $storeUrl = config('shopify.pro.url');
@endphp

{{--
    The sidebar entry only exists without Pro, so its look is gated; the notice
    is worn either way and must be styled either way.
--}}
<style>
    #unopim-sidebar a[href$="{{ $menuPath }}"],
    #unopim-sidebar a[href="{{ $storeUrl }}"],
    #unopim-sidebar a.shopify-pro-menu-link {
        display: block;
        margin-block: 0.625rem 0.25rem;
        margin-inline-end: 1rem;
        padding: 0.5rem 0.75rem;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        line-height: 1.25rem;
        text-align: center;
    }

    /** The fly-out panel drops the menu's indent, so the button brings its own. */
    #unopim-sidebar [data-menu-item].inactive a[href$="{{ $menuPath }}"],
    #unopim-sidebar [data-menu-item].inactive a[href="{{ $storeUrl }}"],
    #unopim-sidebar [data-menu-item].inactive a.shopify-pro-menu-link,
    .sidebar-collapsed #unopim-sidebar a[href$="{{ $menuPath }}"],
    .sidebar-collapsed #unopim-sidebar a[href="{{ $storeUrl }}"],
    .sidebar-collapsed #unopim-sidebar a.shopify-pro-menu-link {
        margin-block: 0.5rem 0.75rem;
        margin-inline: 0.75rem;
    }

    #unopim-sidebar a[href$="{{ $menuPath }}"],
    #unopim-sidebar a[href="{{ $storeUrl }}"],
    #unopim-sidebar a.shopify-pro-menu-link,
    .shopify-pro-cta {
        background-color: #f6b81c;
        color: #2b2000;
        font-weight: 700;
        white-space: nowrap;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.08);
        transition: background-color 150ms ease-in-out;
    }

    #unopim-sidebar a[href$="{{ $menuPath }}"]:hover,
    #unopim-sidebar a[href="{{ $storeUrl }}"]:hover,
    #unopim-sidebar a.shopify-pro-menu-link:hover,
    .shopify-pro-cta:hover {
        background-color: #e9ab0c;
        color: #2b2000;
    }

    .shopify-pro-cta {
        display: inline-flex;
        align-items: center;
        padding: 0.5625rem 1rem;
        border-radius: 0.375rem;
        font-size: 0.8125rem;
        line-height: 1;
    }

    .shopify-pro-notice {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        flex-wrap: wrap;
        padding: 0.9375rem 1.25rem;
        border-bottom: 1px solid #f1eff8;
    }

    .dark .shopify-pro-notice {
        border-bottom-color: #37303e;
    }

    .shopify-pro-notice--locked:not(.shopify-pro-notice--page) {
        background-color: #fcfbff;
    }

    .dark .shopify-pro-notice--locked:not(.shopify-pro-notice--page) {
        background-color: rgba(255, 255, 255, 0.02);
    }

    /** A card that already frames itself only needs the row, not the box. */
    .shopify-pro-notice--bare {
        margin-bottom: 0.5rem;
        padding-block: 0 0.9375rem;
        padding-inline: 0;
        background-color: transparent;
    }

    .shopify-pro-notice--page {
        gap: 1rem;
        margin-bottom: 1.125rem;
        padding: 0.875rem 1.125rem;
        border: 1px solid #e7dcf7;
        border-radius: 0.625rem;
        background: linear-gradient(90deg, #f6f1ff, #fdfbff);
    }

    .dark .shopify-pro-notice--page {
        border-color: #3c2f5c;
        background: linear-gradient(90deg, rgba(109, 40, 217, 0.18), rgba(109, 40, 217, 0.06));
    }

    .shopify-pro-notice__lock {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: none;
        color: #a19bb8;
    }

    .shopify-pro-notice--page .shopify-pro-notice__lock {
        width: 2rem;
        height: 2rem;
        border-radius: 0.5rem;
        background-color: #ede4ff;
        color: #6d28d9;
    }

    .dark .shopify-pro-notice--page .shopify-pro-notice__lock {
        background-color: rgba(109, 40, 217, 0.35);
        color: #c4b5fd;
    }

    .shopify-pro-notice__lead {
        display: flex;
        align-items: center;
        gap: 0.625rem;
        flex-wrap: wrap;
        min-width: 0;
    }

    .shopify-pro-notice--page .shopify-pro-notice__lead {
        flex-direction: column;
        align-items: flex-start;
        gap: 0.125rem;
    }

    .shopify-pro-notice__title {
        margin: 0;
        font-size: 0.90625rem;
        font-weight: 700;
        color: #1e1b33;
    }

    .shopify-pro-notice--locked:not(.shopify-pro-notice--page) .shopify-pro-notice__title {
        color: #56526b;
    }

    .dark .shopify-pro-notice__title {
        color: #f1f5f9;
    }

    .shopify-pro-notice--page .shopify-pro-notice__title {
        font-size: 0.84375rem;
    }

    .shopify-pro-notice__note {
        margin: 0;
        font-size: 0.78125rem;
        color: #8d88a5;
    }

    .shopify-pro-notice:not(.shopify-pro-notice--page) .shopify-pro-notice__note {
        padding-inline-start: 0.625rem;
        border-inline-start: 1px solid #e6e3f2;
    }

    .dark .shopify-pro-notice__note {
        color: #b6b1c7;
    }

    .dark .shopify-pro-notice:not(.shopify-pro-notice--page) .shopify-pro-notice__note {
        border-inline-start-color: #3f3a4d;
    }

    /** The one Pro mark: the button's gold, worn the same on every screen. */
    .shopify-pro-badge {
        display: inline-flex;
        align-items: center;
        padding: 0.125rem 0.4375rem;
        border-radius: 0.25rem;
        background-color: #f6b81c;
        color: #2b2000;
        font-size: 0.625rem;
        font-weight: 800;
        line-height: 1.4;
        letter-spacing: 0.02em;
    }

    .shopify-pro-notice__actions {
        display: flex;
        align-items: center;
        gap: 0.875rem;
        margin-inline-start: auto;
        flex: none;
    }

    .shopify-pro-link {
        padding: 0.25rem 0.5rem;
        border-radius: 0.375rem;
        font-size: 0.78125rem;
        font-weight: 600;
        color: #6d28d9;
        white-space: nowrap;
        text-decoration: underline;
        text-decoration-color: transparent;
        text-underline-offset: 0.1875rem;
        transition: color 150ms ease-in-out, background-color 150ms ease-in-out, text-decoration-color 150ms ease-in-out;
    }

    .shopify-pro-link:hover {
        background-color: #efe7ff;
        color: #5b21b6;
        text-decoration-color: currentColor;
    }

    .dark .shopify-pro-link:hover {
        background-color: rgba(109, 40, 217, 0.28);
        color: #ddd0ff;
    }

    .dark .shopify-pro-link {
        color: #c4b5fd;
    }

    .shopify-pro-unlock {
        display: inline-flex;
        align-items: center;
        padding: 0.375rem 0.75rem;
        border: 1px solid #e0daf4;
        border-radius: 0.375rem;
        background-color: #ffffff;
        color: #6d28d9;
        font-size: 0.75rem;
        font-weight: 700;
        white-space: nowrap;
        transition: background-color 150ms ease-in-out;
    }

    .shopify-pro-unlock:hover {
        background-color: #f6f1ff;
    }

    .dark .shopify-pro-unlock {
        border-color: #4c3d75;
        background-color: transparent;
        color: #c4b5fd;
    }

    .dark .shopify-pro-unlock:hover {
        background-color: rgba(109, 40, 217, 0.2);
    }

    /** A locked section is out of reach as a whole. */
    .shopify-pro-notice--locked:not(.shopify-pro-notice--page) ~ *,
    [data-shopify-pro-locked] {
        user-select: none;
    }

    /**
     * Only what the merchant would have filled in reads as held back. Labels keep
     * their weight and the Pro mark keeps its colour, so the offer stays legible.
     */
    .shopify-pro-notice--locked:not(.shopify-pro-notice--page) ~ * :is(input, select, textarea, [data-control-group] > :not(label):not(:has(.shopify-pro-badge))),
    [data-shopify-pro-locked] > :not(label):not(:has(.shopify-pro-badge)),
    [data-shopify-pro-locked] :is(input, select, textarea, [data-control-group] > :not(label):not(:has(.shopify-pro-badge))) {
        opacity: 0.6;
        filter: saturate(0.55);
    }

    .shopify-pro-notice--locked:not(.shopify-pro-notice--page) ~ * .shopify-pro-badge,
    [data-shopify-pro-locked] .shopify-pro-badge {
        opacity: 1;
        filter: none;
    }

    [data-shopify-pro-locked] button[data-shopify-pro-disabled] {
        cursor: not-allowed;
        border-style: solid;
        border-color: #e5e7eb;
        background-color: #f7f7f8;
        color: #9ca3af;
    }

    .dark [data-shopify-pro-locked] button[data-shopify-pro-disabled] {
        border-color: #3f3a4d;
        background-color: rgba(255, 255, 255, 0.04);
        color: #6b7280;
    }


    /*
     * The real-time toggle row. The unsaved tracker appends its badge to the
     * control group, so the row is the group itself and the parts are ordered
     * around it: the badge follows the heading, the switch goes to the far
     * end, the notes drop to their own line.
     *
     * It lives here, in the head, rather than in a pushed stack on the page:
     * ajax navigation swaps only the content, so a stack pushed there never
     * reaches the head and the row would stay unstyled until a full reload.
     */
    .realtime-toggle {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.5rem;
    }

    .realtime-toggle__title {
        order: 1;
    }

    .realtime-toggle .unsaved-badge {
        order: 2;
    }

    .realtime-toggle__switch {
        display: flex;
        align-items: center;
        order: 3;
        margin-inline-start: auto;
    }

    .realtime-toggle__note {
        order: 4;
        flex-basis: 100%;
        margin: 0;
    }

    /*
     * A mapping row names its field on the left and holds the control in the
     * middle. The unsaved tracker appends its badge to the control group, which
     * pushes the row taller and narrows the control, so the injected badge is
     * put away and the row wears its own, right after the field name.
     */
    .shopify-map-row [data-control-group] > .unsaved-badge {
        display: none !important;
    }

    /* The badge is parked at the end of the name column, never over the control. */
    .shopify-map-row > :first-child {
        position: relative;
    }

    .shopify-map-row:has([data-control-group].unsaved-dirty) > :first-child > p.break-words > span:first-child::after,
    .shopify-map-row:has([data-control-group].unsaved-dirty) > :first-child > p.break-words:not(:has(> span))::after,
    .shopify-map-row:has([data-control-group].unsaved-dirty) > p.break-words::after {
        content: "{{ trans('admin::app.components.form.unsaved-changes.field-badge') }}";
        /* In flow, right where the name ends, so it covers nothing. */
        display: inline-flex;
        align-items: center;
        margin-inline-start: 0.375rem;
        padding: 0.05rem 0.4rem;
        border-radius: 0.25rem;
        font-size: 0.625rem;
        font-weight: 600;
        line-height: 1.2;
        white-space: nowrap;
        vertical-align: middle;
        color: #854d0e;
        background: #fef9c3;
    }

    .dark .shopify-map-row:has([data-control-group].unsaved-dirty) > :first-child > p.break-words > span:first-child::after,
    .dark .shopify-map-row:has([data-control-group].unsaved-dirty) > :first-child > p.break-words:not(:has(> span))::after,
    .dark .shopify-map-row:has([data-control-group].unsaved-dirty) > p.break-words::after {
        color: #fde68a;
        background: #713f12;
    }

</style>

<script>
    const configureShopifyProLinks = () => {
        const upgradeRoute = @json(route('shopify.upgrade'));
        const storeUrl = @json(config('shopify.pro.url'));

        document.querySelectorAll(`a[href="${upgradeRoute}"], a[href="${storeUrl}"]`).forEach((link) => {
            link.href = storeUrl;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.classList.add('shopify-pro-menu-link');
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', configureShopifyProLinks);
    } else {
        configureShopifyProLinks();
    }

    document.addEventListener('unopim:navigate:success', configureShopifyProLinks);
</script>
