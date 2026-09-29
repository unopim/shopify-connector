{{--
    Everything a Pro feature says for itself. As a section it is the card's own
    heading, naming the feature and offering to unlock it; as a page it is the
    banner that sums up what the screen is holding back. With Pro installed a
    section is just its title and a page says nothing at all.
--}}
@props(['variant' => 'section', 'title' => null, 'note' => null])

@php
    $proFeatures = resolve(\Webkul\Shopify\Support\ProFeatures::class);
    $installed = $proFeatures->isInstalled();
    $isPage = $variant === 'page';
@endphp

@unless ($installed && $isPage)
    <div {{ $attributes->class([
        'shopify-pro-notice',
        'shopify-pro-notice--page' => $isPage,
        'shopify-pro-notice--locked' => ! $installed,
    ]) }}>
        @unless ($installed)
            <span class="shopify-pro-notice__lock">
                <svg width="{{ $isPage ? 15 : 14 }}" height="{{ $isPage ? 15 : 14 }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                    <rect x="4" y="11" width="16" height="10" rx="2"></rect>
                    <path d="M8 11V7a4 4 0 0 1 8 0v4"></path>
                </svg>
            </span>
        @endunless

        <div class="shopify-pro-notice__lead">
            <p class="shopify-pro-notice__title">{{ $isPage ? trans('shopify::app.shopify.pro.summary') : $title }}</p>

            @unless ($isPage)
                <x-shopify::pro-cta variant="badge" />
            @endunless

            @if ($isPage || $note)
                <p class="shopify-pro-notice__note">{{ $isPage ? trans('shopify::app.shopify.pro.tagline') : $note }}</p>
            @endif
        </div>

        @unless ($installed)
            <div class="shopify-pro-notice__actions">
                @if ($isPage)
                    <a
                        href="{{ $proFeatures->upgradeUrl() }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="shopify-pro-link"
                    >
                        {{ trans('shopify::app.shopify.pro.compare') }}
                    </a>

                    <x-shopify::pro-cta />
                @else
                    <x-shopify::pro-cta variant="unlock" />
                @endif
            </div>
        @endunless
    </div>
@endunless
