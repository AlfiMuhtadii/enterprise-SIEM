<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div><p class="brand-chip">Threat Intelligence</p><h2 class="mt-2 text-2xl font-semibold text-main-ui">IOC Watchlists</h2></div>
            <a href="{{ route('soc.dashboard') }}" class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Back to SOC</a>
        </div>
    </x-slot>
    @if (session('status'))<div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>@endif

    <div class="grid gap-4 xl:grid-cols-3">
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Manual IOC Entry</h3>
            <form method="POST" action="{{ route('soc.threat-intel.iocs.store') }}" class="mt-3 grid gap-2">
                @csrf
                <select name="ioc_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option>ip</option><option>domain</option><option>hash</option><option>url</option></select>
                <input name="ioc_value" placeholder="value" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input name="source" placeholder="source" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <select name="reputation" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option>suspicious</option><option>malicious</option><option>unknown</option><option>benign</option></select>
                <input name="threat_label" placeholder="threat label" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input name="expires_in_days" type="number" value="30" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Save IOC</button>
            </form>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">IOC Import</h3>
            <form method="POST" action="{{ route('soc.threat-intel.import') }}" class="mt-3 grid gap-2">
                @csrf
                <select name="feed_format" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option>jsonl</option><option>csv</option></select>
                <input name="source" placeholder="feed source" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <textarea name="feed_body" rows="8" placeholder='{"ioc_type":"ip","ioc_value":"203.0.113.10","reputation":"malicious"}' class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-xs text-cyan-50"></textarea>
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Import Feed</button>
            </form>
            <form method="POST" action="{{ route('soc.threat-intel.enrich') }}" class="mt-3">@csrf<button class="rounded border border-amber-200/20 bg-amber-100/10 px-3 py-2 text-sm text-amber-50">Run Alert Enrichment</button></form>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">IOC Summary</h3>
            <div class="mt-3 space-y-2">@foreach ($summary as $row)<div class="flex justify-between rounded bg-black/20 p-2 text-sm text-cyan-100"><span>{{ $row->ioc_type }}</span><span>{{ $row->total }}</span></div>@endforeach</div>
        </section>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">External Threat Intel Lookup</h3>
            <form method="POST" action="{{ route('soc.threat-intel.lookup') }}" class="mt-3 grid gap-2 md:grid-cols-4">
                @csrf
                <select name="provider" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option>virustotal</option><option>abuseipdb</option><option>webhook</option><option>local</option></select>
                <select name="indicator_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option>ip</option><option>domain</option><option>hash</option><option>url</option></select>
                <input name="indicator_value" placeholder="indicator value" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Lookup</button>
            </form>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">MISP / OpenCTI Feed Import</h3>
            <form method="POST" action="{{ route('soc.threat-intel.external-feed') }}" class="mt-3 grid gap-2">
                @csrf
                <select name="feed_type" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"><option>jsonl</option><option>misp-json</option><option>opencti-json</option></select>
                <input name="source" placeholder="feed name or source URL" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <textarea name="feed_body" rows="5" placeholder='[{"type":"ip","value":"203.0.113.9","reputation":"malicious"}]' class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-xs text-cyan-50"></textarea>
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Import External Feed</button>
            </form>
        </section>
    </div>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Local Watchlist / Blocklist</h3></div>
        <div class="overflow-x-auto">
        <div class="overflow-x-auto"><table class="min-w-full divide-y divide-cyan-100/10 text-sm"><thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">IOC</th><th class="px-4 py-3">Reputation</th><th class="px-4 py-3">Source</th><th class="px-4 py-3">Expires</th></tr></thead><tbody class="divide-y divide-cyan-100/10">@foreach ($iocs as $ioc)<tr><td class="px-4 py-3 mono-ui text-xs text-cyan-50">{{ $ioc->ioc_type }}:{{ $ioc->ioc_value }}</td><td class="px-4 py-3 text-cyan-100">{{ $ioc->reputation }}<p class="text-xs text-cyan-100/50">{{ $ioc->threat_label }}</p></td><td class="px-4 py-3 text-cyan-100">{{ $ioc->source }}</td><td class="px-4 py-3 mono-ui text-xs text-cyan-100">{{ $ioc->expires_at ?: '-' }}</td></tr>@endforeach</tbody></table></div>
        <div class="border-t border-cyan-100/15 px-5 py-4">{{ $iocs->links() }}</div>
    </section>

    <section class="glass-card mt-4 p-5">
        <h3 class="text-lg font-semibold text-main-ui">Recent IOC Hits</h3>
        <div class="mt-3 grid gap-2 md:grid-cols-2">@foreach ($hits as $hit)<div class="rounded bg-black/20 p-3 text-xs text-cyan-100">{{ $hit->ioc_id }} -> {{ $hit->alert_id ?: $hit->telemetry_event_id }}<p>{{ $hit->matched_field }}={{ $hit->matched_value }} | {{ $hit->matched_at }}</p></div>@endforeach</div>
    </section>

    <section class="mt-4 grid gap-4 xl:grid-cols-2">
        <div class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">External Lookup History</h3><div class="mt-3 space-y-2">@foreach ($lookups as $lookup)<div class="rounded bg-black/20 p-2 text-xs text-cyan-100">{{ $lookup->provider }} | {{ $lookup->indicator_type }}:{{ $lookup->indicator_value }} | {{ $lookup->reputation }} | {{ $lookup->latency_ms }}ms</div>@endforeach</div></div>
        <div class="glass-card p-5"><h3 class="text-lg font-semibold text-main-ui">External Feed History</h3><div class="mt-3 space-y-2">@foreach ($feeds as $feed)<div class="rounded bg-black/20 p-2 text-xs text-cyan-100">{{ $feed->feed_type }} | {{ $feed->name }} | imported={{ $feed->last_import_count }} | {{ $feed->last_imported_at ?: '-' }}</div>@endforeach</div></div>
    </section>
</x-app-layout>

        </div>