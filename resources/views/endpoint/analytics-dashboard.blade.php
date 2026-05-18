<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Behavioral Findings Dashboard</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Behavioral analytics are advisory-only and shadow-mode. No active containment.</p>
                <p class="text-xs text-cyan-400/50 mt-0.5">{{ $agent->hostname ?? $agent->agent_id }}</p>
            </div>
            <div class="flex gap-2">
                <a href="{{ route('endpoint.analytics.chains', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Chains</a>
                <a href="{{ route('endpoint.analytics.beacon', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">Beacon</a>
                <a href="{{ route('endpoint.behavioral.activity', $agent->agent_id) }}"
                   class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Activity</a>
            </div>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Summary counts --}}
            @php
                $bySeverity = collect($findings)->groupBy('severity');
                $byType = collect($findings)->groupBy('finding_type');
                $severityColors = ['critical' => 'text-red-300', 'high' => 'text-orange-300', 'medium' => 'text-yellow-300', 'low' => 'text-cyan-300'];
            @endphp

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                @foreach (['critical', 'high', 'medium', 'low'] as $sev)
                <div class="glass-card p-4 text-center">
                    <div class="text-2xl font-bold {{ $severityColors[$sev] ?? 'text-cyan-200' }}">{{ $bySeverity->get($sev, collect())->count() }}</div>
                    <div class="text-xs text-cyan-400/60 mt-1 uppercase">{{ $sev }}</div>
                </div>
                @endforeach
            </div>

            {{-- Findings list --}}
            <div class="glass-card p-6">
                <h3 class="text-sm font-semibold text-cyan-200 mb-4">Recent Behavioral Findings ({{ count($findings) }})</h3>
                @if (empty($findings))
                    <p class="text-cyan-400/50 text-sm">No behavioral findings yet. Findings are generated when a behavioral snapshot is processed.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-cyan-200/80">
                            <thead>
                                <tr class="text-left text-xs text-cyan-400/60 border-b border-cyan-200/10">
                                    <th class="pb-2 pr-4">Finding ID</th>
                                    <th class="pb-2 pr-4">Type</th>
                                    <th class="pb-2 pr-4">Title</th>
                                    <th class="pb-2 pr-4">Severity</th>
                                    <th class="pb-2 pr-4">Confidence</th>
                                    <th class="pb-2">Detected</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($findings as $finding)
                                <tr class="border-b border-cyan-200/5 hover:bg-cyan-100/5">
                                    <td class="py-2 pr-4 font-mono text-xs">{{ $finding['finding_id'] }}</td>
                                    <td class="py-2 pr-4 font-mono text-xs text-cyan-400/70">{{ str_replace('_', ' ', $finding['finding_type']) }}</td>
                                    <td class="py-2 pr-4 text-xs">{{ $finding['title'] }}</td>
                                    <td class="py-2 pr-4">
                                        <span class="px-2 py-0.5 rounded text-xs font-medium
                                            @if($finding['severity'] === 'critical') bg-red-900/40 text-red-300
                                            @elseif($finding['severity'] === 'high') bg-orange-900/40 text-orange-300
                                            @elseif($finding['severity'] === 'medium') bg-yellow-900/40 text-yellow-300
                                            @else bg-cyan-900/40 text-cyan-300 @endif">
                                            {{ $finding['severity'] }}
                                        </span>
                                    </td>
                                    <td class="py-2 pr-4 text-xs">{{ number_format($finding['confidence'] * 100) }}%</td>
                                    <td class="py-2 text-xs text-cyan-400/60">{{ $finding['detected_at'] ? \Carbon\Carbon::parse($finding['detected_at'])->diffForHumans() : '—' }}</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Behavioral analytics are advisory-only and shadow-mode. All findings are for investigation enrichment only.
                    No active containment, process termination, isolation, or enforcement is implemented.
                    Findings represent behavioral anomalies that require analyst review before any action.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
