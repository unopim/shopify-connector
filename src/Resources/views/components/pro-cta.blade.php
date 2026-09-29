{{--
    The marks a Pro feature wears: the badge that names it, the button that
    leads to the comparison, and the one that leaves for the store selling Pro,
    in the wording the spot calls for.
--}}
@props(['variant' => 'button'])

@php
    $proFeatures = resolve(\Webkul\Shopify\Support\ProFeatures::class);
    $installed = $proFeatures->isInstalled();
    $isStore = $variant === 'store';

    $labels = [
        'button' => 'shopify::app.shopify.pro.upgrade',
        'unlock' => 'shopify::app.shopify.pro.unlock',
        'store'  => 'shopify::app.shopify.pro.upgrade',
    ];

    $classes = [
        'unlock' => 'shopify-pro-unlock',
        'store'  => 'shopify-pro-cta',
        'button' => 'shopify-pro-cta',
    ];
@endphp

@if ($variant === 'badge')
    <span {{ $attributes->merge(['class' => 'shopify-pro-badge shrink-0']) }}>
        {{ trans('shopify::app.shopify.pro.badge') }}
    </span>
@elseif ($isStore || ! $installed)
    <a
        href="{{ $isStore ? $proFeatures->storeUrl() : $proFeatures->upgradeUrl() }}"
        target="_blank"
        rel="noopener noreferrer"
        {{ $attributes->merge(['class' => 'shrink-0 '.($classes[$variant] ?? $classes['button'])]) }}
    >
        {{ trans($labels[$variant] ?? $labels['button']) }}
    </a>
@endif
