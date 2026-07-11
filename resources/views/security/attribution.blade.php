<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <p class="brand-chip">Detection Center</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Alert Attribution Context</h2>
                <p class="mt-1 text-sm text-cyan-100/60">Advisory OSINT enrichment — offline fixture only, no active response.</p>
            </div>
            <form method="GET" action="{{ route('security.attribution') }}" class="flex items-center gap-2">
                <label for="limit" class="text-sm text-cyan-100/75">Limit</label>
                <select id="limit" name="limit" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50 focus:border-cyan-200 focus:ring-cyan-200">
                    @foreach ([25, 50, 100, 200] as $opt)
                        <option value="{{ $opt }}" @selected($limit === $opt)>{{ $opt }}</option>
                    @endforeach
                </select>
                <button class="rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm font-medium text-cyan-50 hover:bg-cyan-100/20">Apply</button>
            </form>
        </div>
    </x-slot>

    <div class="grid gap-4 md:grid-cols-3">
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Attribution Records</p>
            <p class="mt-2 text-3xl font-semibold text-main-ui">{{ $records->count() }}</p>
            <p class="mt-2 text-sm text-muted-ui">Showing latest {{ $limit }}.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Source</p>
            <p class="mt-2 text-lg font-semibold text-main-ui">offline_fixture</p>
            <p class="mt-2 text-sm text-muted-ui">No live external API calls.</p>
        </div>
        <div class="metric-card">
            <p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Advisory Only</p>
            <p class="mt-2 text-lg font-semibold text-cyan-300">True</p>
            <p class="mt-2 text-sm text-muted-ui">Never triggers automated response.</p>
        </div>
    </div>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="flex items-center justify-between border-b border-cyan-100/15 px-5 py-4">
            <h3 class="text-lg font-semibold text-main-ui">Attribution Records</h3>
            <p class="mono-ui text-xs text-cyan-200/70">limit {{ $limit }}</p>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70">
                    <tr>
                        <th class="px-4 py-3">Alert ID</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3">IP</th>
                        <th class="px-4 py-3">Country</th>
                        <th class="px-4 py-3">ASN Org</th>
                        <th class="px-4 py-3">IP Type</th>
                        <th class="px-4 py-3">Reputation Hint</th>
                        <th class="px-4 py-3">Confidence</th>
                        <th class="px-4 py-3">Enriched At</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @forelse ($records as $r)
                        <tr class="hover:bg-cyan-100/5">
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/70">{{ substr($r->alert_id, 0, 12) }}…</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-50">{{ $r->alert_type }}</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/85">{{ $r->ip ?: '—' }}</td>
                            <td class="px-4 py-3 text-xs text-cyan-100">
                                @if ($r->country_code)
                                    <span class="mono-ui">{{ $r->country_code }}</span>
                                    <span class="block text-cyan-100/80">{{ $r->country_name }}</span>
                                @else
                                    <span class="text-cyan-100/30">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-cyan-100/75">{{ $r->asn_org ?: '—' }}</td>
                            <td class="px-4 py-3 text-xs">
                                @if ($r->ip_type === 'private')
                                    <span class="rounded-full bg-cyan-100/10 px-2 py-0.5 text-cyan-200">private</span>
                                @elseif ($r->ip_type === 'loopback')
                                    <span class="rounded-full bg-slate-100/10 px-2 py-0.5 text-slate-300">loopback</span>
                                @elseif ($r->ip_type === 'public')
                                    <span class="rounded-full bg-amber-500/10 px-2 py-0.5 text-amber-300">public</span>
                                @else
                                    <span class="text-cyan-100/40">{{ $r->ip_type ?: '—' }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs text-cyan-100/60">{{ $r->ip_reputation_hint ?: '—' }}</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ number_format((float) $r->confidence, 2) }}</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/60">{{ $r->created_at }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-5 text-muted-ui" colspan="9">No attribution records found. Run <code class="mono-ui text-cyan-300">php artisan alerts:enrich-attribution</code> to populate.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-app-layout>
