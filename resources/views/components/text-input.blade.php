@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'w-full rounded-xl border border-cyan-100/35 bg-cyan-100/5 px-3 py-2 text-sm text-cyan-50 placeholder-cyan-200/50 shadow-sm focus:border-cyan-300 focus:ring-cyan-300 disabled:opacity-60']) !!}>
