<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Network Analytics Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Network analytics are shadow-only and advisory. No blocking or containment is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            DNS/proxy/firewall analytics are advisory-only. No firewall rule push. No IP/domain blocking. No DPI inspection.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach([
                ['DNS Events (24h)', $summary['dns_events_24h'], 'text-blue-300'],
                ['Proxy Events (24h)', $summary['proxy_events_24h'], 'text-purple-300'],
                ['Firewall Events (24h)', $summary['firewall_events_24h'], 'text-orange-300'],
                ['Network Findings (24h)', $summary['findings_24h'], 'text-amber-300'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ number_format($val) }}</div>
            </div>
            @endforeach
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">NXDOMAIN (24h)</div>
                <div class="text-xl font-bold text-red-400">{{ number_format($summary['nxdomain_24h']) }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Denied Firewall Flows (24h)</div>
                <div class="text-xl font-bold text-red-400">{{ number_format($summary['denied_flows_24h']) }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Denied Proxy Requests (24h)</div>
                <div class="text-xl font-bold text-amber-400">{{ number_format($summary['denied_proxy_24h']) }}</div>
            </div>
        </div>
        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Network Behavioral Findings</h3>
            @forelse($findings as $f)
            <div class="flex gap-3 items-center py-1 border-b border-gray-800 text-xs">
                <span class="px-1.5 py-0.5 rounded {{ $f->source_domain === 'dns' ? 'bg-blue-900/40 text-blue-300' : ($f->source_domain === 'proxy' ? 'bg-purple-900/40 text-purple-300' : 'bg-orange-900/40 text-orange-300') }}">
                    {{ $f->source_domain }}
                </span>
                <span class="text-gray-300">{{ str_replace('_', ' ', $f->finding_type) }}</span>
                <span class="font-mono text-gray-400">{{ $f->host_id }}</span>
                <span class="ml-auto text-gray-500">{{ $f->created_at?->format('H:i:s') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No network behavioral findings yet.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
