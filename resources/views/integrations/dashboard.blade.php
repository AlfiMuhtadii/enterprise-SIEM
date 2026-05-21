<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Enterprise Integrations Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory-only. No autonomous account actions. No bidirectional destructive sync.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Integration events are ingested read-only. Risk scoring is advisory. No account suspension or automatic ticket closure is performed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-4">
            @foreach([
                ['Total Integrations', $stats['total'], 'text-cyan-300'],
                ['Active', $stats['active'], 'text-green-400'],
                ['IdP Events', $stats['idp_events'], 'text-blue-300'],
                ['SaaS Audit Events', $stats['saas_events'], 'text-purple-300'],
                ['Notifications', $stats['notifications'], 'text-amber-300'],
            ] as [$label, $val, $color])
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase tracking-wider mb-1">{{ $label }}</div>
                <div class="text-2xl font-bold {{ $color }}">{{ number_format($val) }}</div>
            </div>
            @endforeach
        </div>
        <div class="grid grid-cols-2 gap-6">
            <div class="glass-card p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-3">Integration Registry</h3>
                @forelse($integrations as $int)
                <div class="flex justify-between items-center py-1 border-b border-gray-800 text-xs">
                    <span class="text-gray-200">{{ $int->name }}</span>
                    <span class="text-gray-400">{{ $int->provider }}</span>
                    <span class="px-2 py-0.5 rounded text-xs font-medium {{ $int->status === 'active' ? 'bg-green-900/40 text-green-300' : 'bg-gray-700 text-gray-400' }}">
                        {{ $int->status }}
                    </span>
                </div>
                @empty
                <p class="text-gray-500 text-xs">No integrations registered.</p>
                @endforelse
            </div>
            <div class="glass-card p-4">
                <h3 class="text-sm font-semibold text-cyan-200 mb-3">Recent Sync Events</h3>
                @forelse($recentSyncs as $sync)
                <div class="flex justify-between items-center py-1 border-b border-gray-800 text-xs">
                    <span class="text-gray-400">{{ $sync->sync_type }}</span>
                    <span class="text-gray-500">{{ $sync->direction }}</span>
                    <span class="px-2 py-0.5 rounded text-xs font-medium {{ $sync->status === 'completed' ? 'bg-green-900/40 text-green-300' : ($sync->status === 'failed' ? 'bg-red-900/40 text-red-300' : 'bg-gray-700 text-gray-400') }}">
                        {{ $sync->status }}
                    </span>
                    <span class="text-gray-600">{{ $sync->created_at?->diffForHumans() }}</span>
                </div>
                @empty
                <p class="text-gray-500 text-xs">No sync events.</p>
                @endforelse
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
            @foreach([
                ['IdP Event Feed', route('integrations.idp-feed'), 'Identity provider login & MFA events'],
                ['SaaS Audit Feed', route('integrations.saas-audit'), 'Office 365, GSuite, GitHub audit logs'],
                ['Notification Center', route('integrations.notifications'), 'Outbound alerts & PagerDuty events'],
                ['Case Links', route('integrations.case-links'), 'Jira/ServiceNow ticket linkage'],
                ['Sync History', route('integrations.sync-history'), 'Full audit trail of sync runs'],
                ['Registry', route('integrations.registry'), 'Configure & manage integrations'],
            ] as [$label, $url, $desc])
            <a href="{{ $url }}" class="glass-card p-4 hover:border-cyan-700 transition-colors">
                <div class="text-sm font-medium text-cyan-200 mb-1">{{ $label }}</div>
                <div class="text-xs text-gray-500">{{ $desc }}</div>
            </a>
            @endforeach
        </div>
    </div>
</x-app-layout>
