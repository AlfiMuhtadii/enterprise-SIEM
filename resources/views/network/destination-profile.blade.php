<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Network Destination Profile</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Network analytics are shadow-only and advisory. No blocking or containment is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3">
            <input name="key" value="{{ $key }}" placeholder="Domain or IP address"
                   class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-72">
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Lookup</button>
        </form>

        @if($profile)
        <div class="glass-card p-5 space-y-3">
            <div class="flex items-center gap-3">
                <span class="text-lg font-mono text-cyan-300">{{ $profile->destination_key }}</span>
                <span class="px-1.5 py-0.5 rounded text-xs {{ $profile->is_rare ? 'bg-amber-900/40 text-amber-300' : 'bg-gray-700 text-gray-400' }}">
                    {{ $profile->is_rare ? 'RARE' : 'known' }}
                </span>
            </div>
            <div class="grid grid-cols-3 gap-4 text-xs">
                <div><div class="text-gray-400">Type</div><div class="text-gray-200">{{ $profile->destination_type }}</div></div>
                <div><div class="text-gray-400">Contacts</div><div class="text-gray-200">{{ $profile->contact_count }}</div></div>
                <div><div class="text-gray-400">Hosts</div><div class="text-gray-200">{{ $profile->host_count }}</div></div>
                <div><div class="text-gray-400">TLD</div><div class="text-gray-200">{{ $profile->tld }}</div></div>
                <div><div class="text-gray-400">Entropy</div><div class="text-gray-200 @if($profile->entropy_score > 3.5) text-amber-400 @endif">{{ $profile->entropy_score ? number_format($profile->entropy_score, 2) : '-' }}</div></div>
                <div><div class="text-gray-400">First Seen</div><div class="text-gray-200">{{ $profile->first_seen_at?->format('Y-m-d') }}</div></div>
            </div>
        </div>
        @elseif($key)
        <p class="text-sm text-gray-500">No profile found for "{{ $key }}".</p>
        @endif

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Rare Destinations</h3>
            @forelse($rare as $r)
            <div class="flex items-center justify-between py-1 border-b border-gray-800 text-xs">
                <a href="{{ route('network.destination', ['key' => $r->destination_key]) }}" class="font-mono text-cyan-400 hover:underline">{{ $r->destination_key }}</a>
                <span class="text-gray-400">{{ $r->contact_count }} contact(s)</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No rare destinations profiled yet.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
