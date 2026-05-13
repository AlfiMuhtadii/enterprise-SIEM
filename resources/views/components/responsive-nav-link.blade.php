@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-start text-base font-medium text-cyan-50 focus:outline-none transition duration-150 ease-in-out'
            : 'block w-full rounded-lg border border-transparent px-3 py-2 text-start text-base font-medium text-cyan-100/75 hover:border-cyan-200/20 hover:bg-cyan-100/5 hover:text-cyan-50 focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
