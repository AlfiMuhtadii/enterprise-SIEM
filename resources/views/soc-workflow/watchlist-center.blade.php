<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Watchlist Center</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-5">
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Add to Watchlist</h3>
            <form method="POST" action="{{ route('soc.workflow.watchlist.create') }}" class="flex gap-3 items-end flex-wrap">
                @csrf
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Type</label>
                    <select name="watch_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                        @foreach($watchTypes as $t)
                        <option value="{{ $t }}">{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1">
                    <label class="text-xs text-gray-400 block mb-1">Watch Key</label>
                    <input name="watch_key" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-full" placeholder="user@corp.com, 192.168.1.1, evil.example.com">
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Expires At</label>
                    <input name="expires_at" type="datetime-local" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                </div>
                <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Watch</button>
            </form>
        </div>

        <div>
            <div class="flex gap-3 mb-3">
                @foreach(array_merge([''], $watchTypes) as $t)
                <a href="{{ route('soc.workflow.watchlist', $t ? ['watch_type' => $t] : []) }}"
                   class="text-xs px-2 py-0.5 rounded {{ $watchType === $t ? 'bg-cyan-700/40 text-cyan-200' : 'text-gray-400 hover:text-cyan-300' }}">
                    {{ $t ?: 'All' }}
                </a>
                @endforeach
            </div>
            @forelse($watchlists as $w)
            <div class="flex gap-3 items-center py-1.5 border-b border-gray-800 text-xs @if(!$w->active) opacity-50 @endif">
                <span class="px-1.5 py-0.5 rounded bg-gray-700 text-gray-300">{{ $w->watch_type }}</span>
                <span class="font-mono text-cyan-300">{{ $w->watch_key }}</span>
                <span class="text-gray-500">{{ $w->analyst?->name }}</span>
                @if($w->expires_at)<span class="text-amber-400/70">exp: {{ $w->expires_at?->format('M d') }}</span>@endif
                @if(!$w->active)<span class="text-red-400/70">inactive</span>@endif
                <span class="ml-auto text-gray-600">{{ $w->created_at?->format('M d H:i') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No watchlist entries.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
