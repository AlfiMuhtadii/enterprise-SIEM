<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Soak Run Detail</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Advisory evidence only — no promotion action taken here.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        {{-- Run header --}}
        <div class="glass-card p-4 flex flex-wrap gap-6">
            <div>
                <div class="text-xs text-gray-400 mb-1">Run ID</div>
                <div class="font-mono text-cyan-300 text-sm">{{ $run->run_id }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 mb-1">Domain</div>
                <div class="text-gray-200">{{ $run->domain }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 mb-1">Status</div>
                <div class="text-gray-200">{{ $run->status }}</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 mb-1">Evidence</div>
                <div class="{{ $run->evidence_pass ? 'text-green-400' : 'text-red-400' }}">
                    {{ $run->status === 'completed' ? ($run->evidence_pass ? 'PASS' : 'FAIL') : '—' }}
                </div>
            </div>
            <div>
                <div class="text-xs text-gray-400 mb-1">Window</div>
                <div class="text-gray-200">{{ $run->window_hours }}h</div>
            </div>
            <div>
                <div class="text-xs text-gray-400 mb-1">Started</div>
                <div class="text-gray-200">{{ $run->started_at?->format('Y-m-d H:i:s') }}</div>
            </div>
        </div>

        @if($run->failure_reason)
        <div class="rounded border border-red-400/30 bg-red-900/10 px-4 py-3 text-sm text-red-300">
            Failure: {{ $run->failure_reason }}
        </div>
        @endif

        {{-- Evidence snapshot --}}
        @if($snapshot)
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Evidence Snapshot</h3>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <div class="text-center">
                    <div class="text-xs text-gray-400 mb-1">Total Findings</div>
                    <div class="text-xl font-bold text-cyan-300">{{ $snapshot->total_findings }}</div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-gray-400 mb-1">High Conf</div>
                    <div class="text-xl font-bold text-cyan-300">{{ $snapshot->high_confidence_findings }}</div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-gray-400 mb-1">High Conf Rate</div>
                    <div class="text-xl font-bold {{ $snapshot->high_confidence_rate >= 0.50 ? 'text-green-400' : 'text-amber-400' }}">
                        {{ number_format($snapshot->high_confidence_rate * 100, 1) }}%
                    </div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-gray-400 mb-1">Suppressed</div>
                    <div class="text-xl font-bold text-gray-300">{{ $snapshot->suppressed_findings }}</div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-gray-400 mb-1">Suppression Rate</div>
                    <div class="text-xl font-bold {{ $snapshot->suppression_rate <= 0.40 ? 'text-green-400' : 'text-red-400' }}">
                        {{ number_format($snapshot->suppression_rate * 100, 1) }}%
                    </div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-gray-400 mb-1">Rule Coverage</div>
                    <div class="text-xl font-bold text-cyan-300">{{ number_format($snapshot->rule_coverage_rate * 100, 1) }}%</div>
                </div>
            </div>
        </div>
        @endif

        {{-- Gate checks --}}
        @if($gates->count())
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Gate Checks</h3>
            <table class="w-full text-xs text-gray-300">
                <thead class="text-gray-400 border-b border-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left">Gate</th>
                        <th class="px-3 py-2 text-left">Result</th>
                        <th class="px-3 py-2 text-left">Actual</th>
                        <th class="px-3 py-2 text-left">Threshold</th>
                        <th class="px-3 py-2 text-left">Note</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach($gates as $gate)
                    <tr>
                        <td class="px-3 py-2">{{ $gate->gate_name }}</td>
                        <td class="px-3 py-2">
                            <span class="{{ $gate->result === 'pass' ? 'text-green-400' : 'text-red-400' }}">
                                {{ strtoupper($gate->result) }}
                            </span>
                        </td>
                        <td class="px-3 py-2">{{ $gate->actual_value }}</td>
                        <td class="px-3 py-2">{{ $gate->comparison }} {{ $gate->threshold_value }}</td>
                        <td class="px-3 py-2 text-gray-400">{{ $gate->note }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        {{-- Assessment --}}
        @if($assessment)
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Assessment</h3>
            <div class="text-sm text-gray-300 mb-2">
                {{ $assessment->recommendation_note }}
            </div>
            <div class="text-xs text-amber-300/80">
                Promotion to active requires a domain-specific 6h soak PASS and explicit operator decision.
                This assessment does not trigger any cutover.
            </div>
        </div>
        @endif

        {{-- Audit trail --}}
        @if($audit->count())
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Audit Events</h3>
            <table class="w-full text-xs text-gray-300">
                <thead class="text-gray-400 border-b border-gray-700">
                    <tr>
                        <th class="px-3 py-2 text-left">Time</th>
                        <th class="px-3 py-2 text-left">Event</th>
                        <th class="px-3 py-2 text-left">Actor</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @foreach($audit as $event)
                    <tr>
                        <td class="px-3 py-2">{{ $event->created_at?->format('H:i:s') }}</td>
                        <td class="px-3 py-2 font-mono">{{ $event->event_type }}</td>
                        <td class="px-3 py-2 text-gray-400">{{ $event->actor ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @endif

        <div class="text-right">
            <a href="{{ route('shadow-soak.index') }}" class="text-cyan-400 hover:underline text-sm">← All runs</a>
        </div>

    </div>
</x-app-layout>
