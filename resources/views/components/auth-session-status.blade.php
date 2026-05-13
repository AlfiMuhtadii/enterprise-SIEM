@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'rounded-lg border border-emerald-200/30 bg-emerald-200/10 px-3 py-2 text-sm font-medium text-emerald-200']) }}>
        {{ $status }}
    </div>
@endif
