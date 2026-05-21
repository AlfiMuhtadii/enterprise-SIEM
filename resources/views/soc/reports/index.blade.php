<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div><p class="brand-chip">Executive Reporting</p><h2 class="mt-2 text-2xl font-semibold text-main-ui">Security Reports</h2></div>
            <a href="{{ route('soc.dashboard') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to SOC</a>
        </div>
    </x-slot>
    <section class="glass-card p-5">
        <h3 class="text-lg font-semibold text-main-ui">Generate Report</h3>
        <form method="POST" action="{{ route('soc.reports.generate') }}" class="mt-3 flex gap-2">
            @csrf
            <select name="period" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option>weekly</option><option>monthly</option></select>
            <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Generate HTML</button>
            <button formaction="{{ route('soc.reports.generate', ['format' => 'json']) }}" class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Generate JSON</button>
        </form>
    </section>
    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Report History</h3></div>
        <div class="overflow-x-auto">
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-cyan-100/10 text-sm"><thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">Report</th><th class="px-4 py-3">Period</th><th class="px-4 py-3">Generated</th><th class="px-4 py-3">Export</th></tr></thead><tbody class="divide-y divide-cyan-100/10">@foreach ($reports as $report)<tr><td class="px-4 py-3"><a href="{{ route('soc.reports.show', $report->report_id) }}" class="mono-ui text-cyan-50 underline">{{ $report->report_id }}</a></td><td class="px-4 py-3 text-cyan-100">{{ $report->period }}<p class="text-xs text-cyan-100/50">{{ $report->period_start }} - {{ $report->period_end }}</p></td><td class="px-4 py-3 text-cyan-100">{{ $report->generated_by }} | {{ $report->created_at }}</td><td class="px-4 py-3"><a href="{{ route('soc.reports.json', $report->report_id) }}" class="text-cyan-200 underline">JSON</a></td></tr>@endforeach</tbody></table></div>
    </section>
</x-app-layout>

        </div>