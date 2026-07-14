@props(['active', 'icon' => null])

@php
$classes = ($active ?? false)
    ? 'sidebar-nav-item sidebar-nav-item--active'
    : 'sidebar-nav-item sidebar-nav-item--default';
@endphp

<a {{ $attributes->merge(['class' => $classes, 'aria-current' => ($active ?? false) ? 'page' : null]) }}>
    @if (isset($icon))
        <span class="shrink-0 text-current opacity-80" aria-hidden="true">{{ $icon }}</span>
    @else
        <span class="shrink-0 h-4 w-4 rounded-full border border-current opacity-40" aria-hidden="true"></span>
    @endif
    <span class="truncate">{{ $slot }}</span>
</a>
