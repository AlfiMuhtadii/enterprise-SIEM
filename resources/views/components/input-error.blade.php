@props(['messages'])

@if ($messages)
    <ul role="alert" {{ $attributes->merge(['class' => 'space-y-1 text-sm text-rose-300']) }}>
        @foreach ((array) $messages as $message)
            <li>{{ $message }}</li>
        @endforeach
    </ul>
@endif
