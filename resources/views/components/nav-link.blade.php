@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center rounded-full border border-cyan-200/30 bg-cyan-100/10 px-3 py-1.5 text-sm font-medium leading-5 text-cyan-50 focus:outline-none transition duration-150 ease-in-out'
            : 'inline-flex items-center rounded-full border border-transparent px-3 py-1.5 text-sm font-medium leading-5 text-cyan-100/80 hover:border-cyan-200/20 hover:bg-cyan-100/5 hover:text-cyan-50 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
