<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div><p class="brand-chip">Security Report</p><h2 class="mt-2 text-2xl font-semibold text-main-ui">{{ $report['report_id'] ?? $report->report_id }}</h2></div>
            <a href="{{ route('soc.reports') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to Reports</a>
        </div>
    </x-slot>
    @php $summary = $report['summary'] ?? []; @endphp
    <div class="grid gap-4 md:grid-cols-4">
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Period</p><p class="mt-2 text-2xl text-main-ui">{{ $report['period'] ?? $report->period }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">Incidents</p><p class="mt-2 text-2xl text-main-ui">{{ $summary['incident_statistics']['total'] ?? 0 }}</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">MTTA</p><p class="mt-2 text-2xl text-main-ui">{{ $summary['mtta_seconds'] ?? '-' }}s</p></div>
        <div class="metric-card"><p class="text-sm text-cyan-200/75">MTTR</p><p class="mt-2 text-2xl text-main-ui">{{ $summary['mttr_seconds'] ?? '-' }}s</p></div>
    </div>
    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        @foreach (['top_threats' => 'Top Threats', 'rule_performance' => 'Rule Performance', 'false_positive_trends' => 'False Positive Trends', 'analyst_activity' => 'Analyst Activity', 'severity_distribution' => 'Severity Distribution'] as $key => $label)
            <section class="glass-card p-5">
                <h3 class="text-lg font-semibold text-main-ui">{{ $label }}</h3>
                <div class="mt-3 space-y-2">
                    @foreach (($summary[$key] ?? []) as $name => $total)
                        <div class="flex justify-between rounded bg-black/20 p-2 text-sm text-cyan-100"><span class="break-all">{{ $name }}</span><span>{{ $total }}</span></div>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
    <section class="glass-card mt-4 p-5">
        <h3 class="text-lg font-semibold text-main-ui">Raw JSON</h3>
        <pre class="mt-3 max-h-[32rem] overflow-auto rounded bg-slate-950/70 p-3 text-xs text-cyan-50">{{ json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
    </section>
</x-app-layout>
