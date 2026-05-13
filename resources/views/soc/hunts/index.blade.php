<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">Threat Hunting</p>
                <h2 class="mt-2 text-2xl font-semibold text-main-ui">Telemetry Hunt Workbench</h2>
            </div>
            <a href="{{ route('soc.dashboard') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to SOC</a>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    <section class="glass-card p-5">
        <h3 class="text-lg font-semibold text-main-ui">Telemetry Search</h3>
        <form method="GET" action="{{ route('soc.hunts') }}" class="mt-4 grid gap-3 md:grid-cols-5">
            @foreach (['host_id','user','process','ip','domain','event_type'] as $field)
                <input name="{{ $field }}" value="{{ $filters[$field] }}" placeholder="{{ $field }}" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            @endforeach
            <select name="minutes" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                @foreach ([60,240,1440,10080] as $m)<option value="{{ $m }}" @selected($filters['minutes']===$m)>{{ $m }}m</option>@endforeach
            </select>
            <button name="run" value="1" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Run Hunt</button>
            <a class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50" href="{{ route('soc.hunts.export', request()->query()) }}">Export JSONL</a>
        </form>
        <form method="POST" action="{{ route('soc.hunts.save') }}" class="mt-3 flex gap-2">
            @csrf
            @foreach ($filters as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            <input name="name" placeholder="Saved hunt name" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
            <select name="template_key" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <option value="">No template</option>
                @foreach ($templates as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            <button class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Save Hunt</button>
        </form>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-3">
        <div class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Hunt Templates</h3>
            <div class="mt-3 space-y-2">@foreach ($templates as $key => $label)<div class="rounded border border-cyan-200/15 bg-black/20 p-2 text-sm text-cyan-100">{{ $key }}<p class="text-xs text-cyan-100/60">{{ $label }}</p></div>@endforeach</div>
        </div>
        <div class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Saved Hunts</h3>
            <div class="mt-3 space-y-2">@forelse ($savedHunts as $hunt)<div class="rounded border border-cyan-200/15 bg-black/20 p-2 text-sm text-cyan-100">{{ $hunt->name }}<p class="text-xs text-cyan-100/60">{{ $hunt->created_by }} | {{ $hunt->last_result_count }} matches</p></div>@empty<p class="text-sm text-muted-ui">No saved hunts.</p>@endforelse</div>
        </div>
        <div class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Hunt History</h3>
            <div class="mt-3 space-y-2">@forelse ($huntRuns as $run)<div class="rounded border border-cyan-200/15 bg-black/20 p-2 text-sm text-cyan-100">{{ $run->run_id }}<p class="text-xs text-cyan-100/60">{{ $run->executed_by }} | {{ $run->result_count }} matches</p></div>@empty<p class="text-sm text-muted-ui">No hunt runs.</p>@endforelse</div>
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Correlation-Aware Results</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">Time</th><th class="px-4 py-3">Host</th><th class="px-4 py-3">Event</th><th class="px-4 py-3">Process</th><th class="px-4 py-3">Network</th><th class="px-4 py-3">Correlated Alerts</th></tr></thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @forelse ($results as $row)
                        <tr>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100">{{ $row->ts }}</td>
                            <td class="px-4 py-3"><a href="{{ route('soc.endpoints.timeline', $row->host_id) }}" class="text-cyan-50 underline">{{ $row->host_id }}</a></td>
                            <td class="px-4 py-3 text-cyan-100">{{ $row->event_type }}</td>
                            <td class="px-4 py-3 text-cyan-100">{{ $row->process_name }}</td>
                            <td class="px-4 py-3 text-cyan-100">{{ $row->src_ip }} -> {{ $row->dst_ip }}:{{ $row->dst_port }}</td>
                            <td class="px-4 py-3 text-cyan-100">
                                @forelse (($row->correlated_alerts ?? []) as $alert)
                                    <span class="mb-1 inline-block rounded border border-cyan-200/20 bg-cyan-100/10 px-2 py-1 text-xs">{{ $alert->alert_type }}:{{ $alert->severity }}</span>
                                @empty
                                    <span class="text-cyan-100/50">0</span>
                                @endforelse
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-4 py-6 text-muted-ui">Run a hunt to see results.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</x-app-layout>
