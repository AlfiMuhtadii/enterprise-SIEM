<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">SaaS Audit Event Feed</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. SaaS audit events are ingested read-only. No account actions are performed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            SaaS audit logs from Office 365, GSuite, Salesforce, GitHub are ingested for correlation.
            Risk scoring is advisory-only. No user suspension, no permission revocation.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Total SaaS Events</div>
                <div class="text-2xl font-bold text-purple-300">{{ $events->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Suspicious Actions</div>
                <div class="text-2xl font-bold text-red-400">{{ $suspicious }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Posture</div>
                <div class="text-sm font-bold text-amber-300">Advisory-only / No enforcement</div>
            </div>
        </div>
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent SaaS Audit Events</h3>
            <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead><tr class="text-gray-400 uppercase text-left border-b border-gray-700">
                    <th class="pb-2 pr-4">Time</th>
                    <th class="pb-2 pr-4">Provider</th>
                    <th class="pb-2 pr-4">Actor</th>
                    <th class="pb-2 pr-4">Action</th>
                    <th class="pb-2 pr-4">Resource</th>
                    <th class="pb-2 pr-4">Country</th>
                    <th class="pb-2 pr-4">Risk</th>
                    <th class="pb-2">Flag</th>
                </tr></thead>
                <tbody>
                @forelse($events as $ev)
                <tr class="border-b border-gray-800 {{ $ev->is_suspicious ? 'bg-red-900/10' : '' }} hover:bg-gray-800/30">
                    <td class="py-1.5 pr-4 text-gray-500">{{ $ev->occurred_at?->format('H:i:s') }}</td>
                    <td class="py-1.5 pr-4 text-purple-300">{{ $ev->provider }}</td>
                    <td class="py-1.5 pr-4 text-gray-300">{{ $ev->actor_email ?? $ev->actor_id ?? '—' }}</td>
                    <td class="py-1.5 pr-4 text-gray-200">{{ $ev->action }}</td>
                    <td class="py-1.5 pr-4 text-gray-400">{{ $ev->resource_type ?? '—' }}</td>
                    <td class="py-1.5 pr-4 text-gray-400">{{ $ev->source_country ?? '—' }}</td>
                    <td class="py-1.5 pr-4 {{ $ev->risk_score >= 0.75 ? 'text-red-400' : 'text-gray-400' }}">
                        {{ number_format($ev->risk_score ?? 0, 2) }}
                    </td>
                    <td class="py-1.5">
                        @if($ev->is_suspicious)
                        <span class="px-2 py-0.5 rounded bg-red-900/40 text-red-300 text-xs">Suspicious</span>
                        @else
                        <span class="text-gray-600">—</span>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="py-4 text-center text-gray-500">No SaaS audit events ingested.</td></tr>
                @endforelse
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
