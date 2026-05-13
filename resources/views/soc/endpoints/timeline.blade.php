<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div><p class="brand-chip">Endpoint Investigation</p><h2 class="mt-2 text-2xl font-semibold text-main-ui">{{ $hostId }}</h2><p class="mono-ui text-xs text-cyan-100/60">Session {{ $sessionId }}</p></div>
            <a href="{{ route('soc.hunts') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Threat Hunt</a>
        </div>
    </x-slot>
    <form method="GET" class="glass-card p-4 grid gap-3 md:grid-cols-3">
        <select name="minutes" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">@foreach ([60,240,1440,10080] as $m)<option value="{{ $m }}" @selected($minutes===$m)>{{ $m }}m</option>@endforeach</select>
        <input name="type" value="{{ $type }}" placeholder="event_type filter" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
        <button class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Filter Timeline</button>
    </form>
    <section class="mt-4 grid gap-4 xl:grid-cols-3">
        <div class="glass-card p-5"><h3 class="font-semibold text-main-ui">Process/File/Network Timeline</h3><div class="mt-3 space-y-2">@foreach ($telemetry as $row)<div class="rounded border border-cyan-200/15 bg-black/20 p-2 text-sm text-cyan-100"><span class="mono-ui text-xs">{{ $row->ts }}</span><p>{{ $row->event_type }} | {{ $row->process_name }}</p><p class="text-xs text-cyan-100/60">{{ $row->src_ip }} -> {{ $row->dst_ip }}:{{ $row->dst_port }}</p></div>@endforeach</div></div>
        <div class="glass-card p-5"><h3 class="font-semibold text-main-ui">Alert Timeline</h3><div class="mt-3 space-y-2">@foreach ($alerts as $alert)<div class="rounded border border-cyan-200/15 bg-black/20 p-2 text-sm {{ in_array($alert->severity, ['critical','high']) ? 'text-red-100' : 'text-cyan-100' }}"><span class="mono-ui text-xs">{{ $alert->detected_at }}</span><p>{{ $alert->alert_type }} | {{ $alert->severity }}</p><details><summary class="text-xs">Evidence</summary><pre class="text-xs overflow-auto">{{ json_encode(json_decode($alert->evidence ?: '{}', true), JSON_PRETTY_PRINT) }}</pre></details></div>@endforeach</div></div>
        <div class="glass-card p-5"><h3 class="font-semibold text-main-ui">Incident / Response Timeline</h3><div class="mt-3 space-y-2">@foreach ($incidents as $incident)<div class="rounded border border-cyan-200/15 bg-black/20 p-2 text-sm text-cyan-100"><a class="underline" href="{{ route('soc.incidents.show', $incident->incident_id) }}">{{ $incident->incident_id }}</a><p>{{ $incident->severity }} | {{ $incident->status }}</p><p class="text-xs">{{ $incident->last_seen_at }}</p></div>@endforeach @foreach ($responses as $response)<div class="rounded border border-cyan-200/15 bg-black/20 p-2 text-sm text-amber-100">{{ $response->action_type }} | {{ $response->status }}<p class="text-xs">{{ $response->created_at }}</p></div>@endforeach</div></div>
    </section>
</x-app-layout>
