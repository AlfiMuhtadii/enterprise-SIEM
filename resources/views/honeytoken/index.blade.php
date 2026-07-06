<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Honeytokens</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Purely detective — no offensive deployment, no active response. A hit means a seeded fake value was referenced somewhere it never legitimately should be.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Honeytokens are fake credentials/file paths/URLs/DNS names seeded intentionally. Any touch is a high-signal, near-zero-FP advisory hit — reviewed by an analyst, not auto-escalated.
        </div>

        @can('soc:honeytoken.manage')
        <div class="glass-card p-4 space-y-3">
            <h3 class="text-sm font-semibold text-purple-200">Seed a new honeytoken</h3>
            <form method="POST" action="{{ route('honeytoken.store') }}" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                @csrf
                <select name="token_type" class="rounded bg-gray-800 border-gray-700 text-xs text-gray-200" required>
                    <option value="credential">credential</option>
                    <option value="file_path">file_path</option>
                    <option value="url">url</option>
                    <option value="dns_name">dns_name</option>
                </select>
                <input type="text" name="token_value" placeholder="fake value" class="rounded bg-gray-800 border-gray-700 text-xs text-gray-200" required maxlength="512">
                <input type="text" name="label" placeholder="label (optional)" class="rounded bg-gray-800 border-gray-700 text-xs text-gray-200" maxlength="255">
                <button type="submit" class="rounded bg-cyan-700/40 hover:bg-cyan-700/60 text-cyan-100 text-xs px-3 py-2">Seed</button>
            </form>
        </div>
        @endcan

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-purple-200">Active honeytokens</h3>
            @forelse($honeytokens as $token)
            <div class="flex justify-between items-center text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span>
                    <span class="font-mono text-purple-300">{{ $token->token_type }}</span>
                    · {{ $token->label ?? $token->token_value }}
                    @unless($token->is_active) <span class="text-gray-500">(inactive)</span> @endunless
                </span>
                @can('soc:honeytoken.manage')
                @if($token->is_active)
                <form method="POST" action="{{ route('honeytoken.deactivate', $token->honeytoken_id) }}">
                    @csrf
                    <button type="submit" class="text-red-300 hover:text-red-200">Deactivate</button>
                </form>
                @endif
                @endcan
            </div>
            @empty
            <p class="text-xs text-gray-500">No honeytokens seeded yet.</p>
            @endforelse
        </div>

        <div class="glass-card p-4 space-y-2">
            <h3 class="text-sm font-semibold text-cyan-200">Recent hits</h3>
            @forelse($hits as $hit)
            <div class="flex justify-between text-xs text-gray-300 border-b border-gray-700/40 pb-1">
                <span class="font-mono text-purple-300">{{ $hit->honeytoken_id }}</span>
                <span>alert {{ $hit->alert_id }} · {{ $hit->matched_field }} · {{ $hit->matched_at }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No hits recorded — honeytoken:scan runs every 15 minutes.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
