<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Platform</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Entity Graph</h2>
            </div>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/50">Investigation Pivoting</span>
        </div>
    </x-slot>

    <div class="space-y-5">

        {{-- Search form --}}
        <div class="glass-card p-5">
            <form method="GET" action="{{ route('entity.index') }}" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex-1">
                    <label class="mb-1.5 block text-xs text-cyan-200/60">Search entity</label>
                    <input type="text" name="q" value="{{ $q }}"
                           placeholder="e.g. user@example.com, 192.0.2.1, hostname, alert-id..."
                           class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 placeholder-cyan-200/30 focus:border-cyan-400/40 focus:outline-none mono-ui" />
                </div>
                <div class="w-44">
                    <label class="mb-1.5 block text-xs text-cyan-200/60">Filter by type</label>
                    <select name="type"
                            class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-400/40 focus:outline-none">
                        <option value="">— All types —</option>
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit"
                        class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-5 py-2 text-sm font-medium text-cyan-200 hover:bg-cyan-500/25 transition">
                    Search
                </button>
            </form>
        </div>

        {{-- Results --}}
        @if ($q !== '' && $entities->isEmpty())
            <div class="glass-card p-8 text-center text-sm text-cyan-200/40">
                No entities found for <span class="mono-ui text-cyan-200/60">{{ $q }}</span>
                @if ($type)
                    with type <span class="mono-ui text-cyan-200/60">{{ $type }}</span>
                @endif
            </div>

        @elseif ($entities->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">
                        {{ $entities->count() }} entit{{ $entities->count() === 1 ? 'y' : 'ies' }} found
                    </span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.1em] text-cyan-200/40">
                            <th class="px-5 py-3">Type</th>
                            <th class="px-4 py-3">Entity Key</th>
                            <th class="px-4 py-3">Display Name</th>
                            <th class="px-4 py-3 text-center">Observations</th>
                            <th class="px-4 py-3 text-center">Risk</th>
                            <th class="px-4 py-3">First Seen</th>
                            <th class="px-4 py-3">Last Seen</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($entities as $e)
                            @php
                                $typeColor = match($e->entity_type) {
                                    'user'      => 'text-cyan-300 border-cyan-400/40 bg-cyan-500/10',
                                    'host'      => 'text-blue-300 border-blue-400/40 bg-blue-500/10',
                                    'process'   => 'text-purple-300 border-purple-400/40 bg-purple-500/10',
                                    'ip'        => 'text-orange-300 border-orange-400/40 bg-orange-500/10',
                                    'domain'    => 'text-green-300 border-green-400/40 bg-green-500/10',
                                    'file_hash' => 'text-red-300 border-red-400/40 bg-red-500/10',
                                    'alert'     => 'text-yellow-300 border-yellow-400/40 bg-yellow-500/10',
                                    'incident'  => 'text-pink-300 border-pink-400/40 bg-pink-500/10',
                                    'trace'     => 'text-gray-300 border-gray-400/40 bg-gray-500/10',
                                    default     => 'text-cyan-100/50 border-cyan-200/20 bg-black/20',
                                };
                                $risk = $e->risk_score ?? 0;
                                $riskColor = $risk >= 7 ? 'text-red-300' : ($risk >= 5 ? 'text-orange-300' : ($risk >= 3 ? 'text-yellow-300' : 'text-cyan-200/40'));
                            @endphp
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-3">
                                    <span class="rounded border px-1.5 py-0.5 text-xs {{ $typeColor }}">
                                        {{ $e->entity_type }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="mono-ui text-xs text-cyan-200/80 break-all">{{ Str::limit($e->entity_key, 48) }}</span>
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/60">
                                    {{ Str::limit($e->display_name ?? '—', 40) }}
                                </td>
                                <td class="px-4 py-3 text-center text-xs text-cyan-200/70">
                                    {{ $e->observation_count }}
                                </td>
                                <td class="px-4 py-3 text-center text-xs font-semibold {{ $riskColor }}">
                                    {{ $e->risk_score !== null ? number_format($e->risk_score, 1) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/50">
                                    {{ $e->first_seen_at ? \Carbon\Carbon::parse($e->first_seen_at)->format('Y-m-d H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-cyan-200/50">
                                    {{ $e->last_seen_at ? \Carbon\Carbon::parse($e->last_seen_at)->format('Y-m-d H:i') : '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <a href="{{ route('entity.show', $e->id) }}"
                                       class="text-xs text-cyan-400 hover:text-cyan-200 transition">
                                        Investigate →
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

        @else
            <div class="glass-card p-10 text-center">
                <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full border border-cyan-200/20 bg-cyan-100/5">
                    <svg class="h-6 w-6 text-cyan-200/40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                    </svg>
                </div>
                <p class="text-sm text-cyan-200/50">Search for users, hosts, IPs, domains, processes, file hashes, alerts, incidents, or traces.</p>
                <p class="mt-1 text-xs text-cyan-200/30">Entity projection is built from pipeline data — security_alerts, security_incidents, and endpoint telemetry.</p>
            </div>
        @endif

    </div>
</x-app-layout>
