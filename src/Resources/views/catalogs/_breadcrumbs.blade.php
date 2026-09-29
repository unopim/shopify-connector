@props(['crumbs' => [], 'leaf' => null])

<nav
    aria-label="{{ trans('admin::app.components.layouts.breadcrumbs.label') }}"
    class="flex flex-wrap items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400"
>
    @foreach ($crumbs as $crumb)
        <a
            href="{{ $crumb['url'] }}"
            class="text-gray-600 hover:text-unopim-primary hover:text-primary-700 dark:text-gray-300 dark:hover:text-primary-400"
        >
            {{ $crumb['label'] }}
        </a>

        <span class="text-gray-300 dark:text-gray-600">/</span>
    @endforeach

    @if ($leaf)
        <span class="text-gray-400 dark:text-gray-500">{{ $leaf }}</span>
    @endif
</nav>
