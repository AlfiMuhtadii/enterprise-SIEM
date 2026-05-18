<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">
            Security Hardening
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            {{-- Secrets Validation --}}
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Secrets Validation</h3>
                @if (!$validation['ok'])
                    <div class="mb-3 p-3 rounded bg-red-900/30 border border-red-500/40 text-red-300 text-sm">
                        <strong>{{ count($validation['errors']) }} error(s)</strong> detected — remediation required.
                    </div>
                    @foreach ($validation['errors'] as $error)
                        <div class="flex items-start gap-2 mb-2 text-sm text-red-300">
                            <span class="font-mono font-bold">[ERROR]</span>
                            <span>{{ $error }}</span>
                        </div>
                    @endforeach
                @endif
                @if (!empty($validation['warnings']))
                    <div class="mb-3 p-3 rounded bg-yellow-900/30 border border-yellow-500/40 text-yellow-300 text-sm">
                        <strong>{{ count($validation['warnings']) }} warning(s)</strong> — review before production deployment.
                    </div>
                    @foreach ($validation['warnings'] as $warning)
                        <div class="flex items-start gap-2 mb-2 text-sm text-yellow-300">
                            <span class="font-mono font-bold">[WARN]</span>
                            <span>{{ $warning }}</span>
                        </div>
                    @endforeach
                @endif
                @if ($validation['ok'] && empty($validation['warnings']))
                    <div class="p-3 rounded bg-green-900/30 border border-green-500/40 text-green-300 text-sm">
                        All secrets validated — no issues found.
                    </div>
                @endif
                <p class="text-xs text-cyan-400/60 mt-3">Checked at: {{ $validation['checked_at'] }}</p>
                <p class="text-xs text-cyan-400/50 mt-1">Run <code class="font-mono bg-cyan-900/30 px-1 rounded">php artisan security:validate-secrets</code> for CLI output.</p>
            </div>

            {{-- Service Auth Status --}}
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Internal Service Auth Status</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Service</th>
                                <th class="pb-2 text-left">Auth Type</th>
                                <th class="pb-2 text-center">Configured</th>
                                <th class="pb-2 text-center">Dev Default</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($serviceAuthStatus as $svc)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 font-mono text-xs">{{ $svc['service'] }}</td>
                                <td class="py-2 text-xs text-cyan-300/70">{{ $svc['auth_type'] }}</td>
                                <td class="py-2 text-center">
                                    @if ($svc['configured'])
                                        <span class="text-green-400">Yes</span>
                                    @else
                                        <span class="text-yellow-400">No</span>
                                    @endif
                                </td>
                                <td class="py-2 text-center">
                                    @if ($svc['dev_default'])
                                        <span class="text-red-400 font-bold">YES</span>
                                    @else
                                        <span class="text-cyan-500">—</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Audit Integrity Status --}}
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Audit Append-Only Integrity</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Table</th>
                                <th class="pb-2 text-left">Purpose</th>
                                <th class="pb-2 text-center">Present</th>
                                <th class="pb-2 text-right">Record Count</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($auditIntegrity as $row)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 font-mono text-xs">{{ $row['table'] }}</td>
                                <td class="py-2 text-xs text-cyan-300/70">{{ $row['label'] }}</td>
                                <td class="py-2 text-center">
                                    @if ($row['ok'])
                                        <span class="text-green-400">Yes</span>
                                    @else
                                        <span class="text-yellow-400">Pending migration</span>
                                    @endif
                                </td>
                                <td class="py-2 text-right font-mono text-xs">{{ number_format($row['count']) }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-cyan-400/50 mt-3">
                    Append-only guarantees are enforced at the service layer.
                    No UPDATE or DELETE is issued against audit tables by any platform service.
                </p>
            </div>

            {{-- Event Counts --}}
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Security Event Summary</h3>
                @if (empty($eventCounts))
                    <p class="text-cyan-400/60 text-sm">No security hardening events recorded.</p>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-4">
                        @foreach ($eventCounts as $type => $count)
                        <div class="p-3 rounded bg-cyan-900/20 border border-cyan-100/10">
                            <div class="text-2xl font-bold text-cyan-100">{{ number_format($count) }}</div>
                            <div class="text-xs text-cyan-400/70 font-mono mt-1">{{ $type }}</div>
                        </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Recent Events --}}
            @if ($recentEvents->isNotEmpty())
            <div class="glass-card p-6">
                <h3 class="text-lg font-semibold text-cyan-100 mb-4">Recent Security Events (last 50)</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-cyan-200">
                        <thead>
                            <tr class="border-b border-cyan-100/20 text-cyan-400 text-xs uppercase">
                                <th class="pb-2 text-left">Time</th>
                                <th class="pb-2 text-left">Type</th>
                                <th class="pb-2 text-left">Service</th>
                                <th class="pb-2 text-left">Actor</th>
                                <th class="pb-2 text-left">Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-cyan-100/10">
                            @foreach ($recentEvents as $event)
                            <tr class="hover:bg-cyan-900/20">
                                <td class="py-2 text-xs text-cyan-400/70 whitespace-nowrap">
                                    {{ $event->occurred_at->format('Y-m-d H:i:s') }}
                                </td>
                                <td class="py-2">
                                    <span class="inline-block px-2 py-0.5 rounded text-xs font-mono
                                        @if(str_contains($event->event_type, 'failure'))
                                            bg-red-900/40 text-red-300
                                        @elseif(str_contains($event->event_type, 'warning'))
                                            bg-yellow-900/40 text-yellow-300
                                        @else
                                            bg-cyan-900/40 text-cyan-300
                                        @endif
                                    ">{{ $event->event_type }}</span>
                                </td>
                                <td class="py-2 font-mono text-xs">{{ $event->service_name ?? '—' }}</td>
                                <td class="py-2 text-xs text-cyan-300/70">{{ $event->actor ?? '—' }}</td>
                                <td class="py-2 text-xs text-cyan-400/60 truncate max-w-xs">
                                    @if ($event->details)
                                        {{ collect($event->details)->map(fn($v, $k) => "$k: $v")->implode(', ') }}
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
            @endif

        </div>
    </div>
</x-app-layout>
