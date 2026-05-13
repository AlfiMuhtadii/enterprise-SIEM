@props(['value'])

<label {{ $attributes->merge(['class' => 'block text-sm font-medium text-cyan-100']) }}>
    {{ $value ?? $slot }}
</label>
