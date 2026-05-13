<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div><p class="brand-chip">Detection Engineering</p><h2 class="mt-2 text-2xl font-semibold text-main-ui">Detection Tuning</h2></div>
            <a href="{{ route('soc.dashboard') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to SOC</a>
        </div>
    </x-slot>

    @if (session('status'))<div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>@endif

    <div class="grid gap-4 md:grid-cols-3">
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Feedback Summary</h3>
            <div class="mt-3 space-y-2">@foreach ($feedbackSummary as $row)<div class="flex justify-between rounded bg-black/20 p-2 text-sm text-cyan-100"><span>{{ $row->verdict }}</span><span>{{ $row->total }}</span></div>@endforeach</div>
        </section>
        <section class="glass-card p-5 md:col-span-2">
            <h3 class="text-lg font-semibold text-main-ui">Create Suppression</h3>
            <form method="POST" action="{{ route('soc.tuning.suppress') }}" class="mt-3 grid gap-2 md:grid-cols-6">
                @csrf
                <select name="scope" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50"><option>alert_type</option><option>actor_key</option><option>ip</option><option>rule_id</option></select>
                <input name="match_value" placeholder="match value" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50">
                <input name="rule_id" placeholder="rule_id optional" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50">
                <input name="expires_in_hours" type="number" value="24" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50">
                <input name="reason" placeholder="reason" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50">
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Create</button>
            </form>
            <form method="POST" action="{{ route('soc.tuning.suppress.apply') }}" class="mt-3">@csrf<button class="rounded border border-amber-200/20 bg-amber-100/10 px-3 py-2 text-sm text-amber-50">Apply Active Suppressions</button></form>
        </section>
    </div>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Recent Alerts Feedback</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">Alert</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Entity</th><th class="px-4 py-3">Feedback</th></tr></thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @foreach ($alerts as $alert)
                        <tr>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100">{{ $alert->alert_id }}<p>{{ $alert->detected_at }}</p></td>
                            <td class="px-4 py-3 text-cyan-100">{{ $alert->alert_type }}<p class="text-xs text-cyan-100/50">{{ $alert->detector_name ?? '-' }}</p></td>
                            <td class="px-4 py-3 text-cyan-100">{{ $alert->actor_key ?: $alert->ip }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('soc.tuning.mark', $alert->alert_id) }}" class="grid gap-2 md:grid-cols-4">
                                    @csrf
                                    <select name="verdict" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50"><option>true_positive</option><option>false_positive</option><option>benign</option><option>needs_review</option></select>
                                    <input name="rule_id" value="{{ $alert->detector_name ?? $alert->alert_type }}" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                    <input name="notes" placeholder="notes" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-1 text-xs text-cyan-50">
                                    <button class="rounded border border-cyan-200/20 px-2 py-1 text-xs text-cyan-50">Mark</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-3">
        <div class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">Rule Effectiveness</h3><div class="mt-3 space-y-2">@foreach ($ruleMetrics as $row)<div class="rounded bg-black/20 p-3 text-sm text-cyan-100"><p class="mono-ui text-cyan-50">{{ $row->rule_key }}</p><p>alerts={{ $row->alerts }} tp={{ $row->true_positive }} fp={{ $row->false_positive }} effectiveness={{ $row->effectiveness ?? '-' }}</p><p class="text-xs text-amber-100">{{ $row->suggestion }}</p></div>@endforeach</div></div>
        <div class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">Suppression History</h3><div class="mt-3 space-y-2">@foreach ($suppressionHistory as $row)<div class="rounded bg-black/20 p-2 text-xs text-cyan-100">{{ $row->alert_id }} | {{ $row->suppressed_by }} | {{ $row->suppressed_at }}</div>@endforeach</div></div>
        <div class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">Rule Tuning Notes</h3><form method="POST" action="{{ route('soc.tuning.notes') }}" class="mt-3 grid gap-2">@csrf<input name="rule_id" placeholder="rule_id" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50"><input name="suggestion_type" placeholder="suggestion_type" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50"><textarea name="note" placeholder="note" class="rounded border border-cyan-200/30 bg-slate-950 px-2 py-2 text-sm text-cyan-50"></textarea><button class="rounded border border-cyan-200/20 px-3 py-2 text-sm text-cyan-50">Save Note</button></form><div class="mt-3 space-y-2">@foreach ($notes as $note)<div class="rounded bg-black/20 p-2 text-xs text-cyan-100">{{ $note->rule_id }} | {{ $note->analyst }}<p>{{ $note->note }}</p></div>@endforeach</div></div>
    </section>
</x-app-layout>
