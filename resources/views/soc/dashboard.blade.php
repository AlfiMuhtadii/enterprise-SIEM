<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
            <div>
                <p class="brand-chip">SOC Operations</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Telemetry Incident Dashboard</h2>
            </div>
            <form method="GET" action="{{ route('soc.dashboard') }}" class="grid gap-2 sm:grid-cols-5">
                <input name="q" value="{{ $q }}" placeholder="Search incident, title, analyst" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50 placeholder:text-cyan-100/40">
                <select name="status" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <option value="">All status</option>
                    @foreach (['open','triaged','investigating','resolved','false_positive'] as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
                    @endforeach
                </select>
                <select name="severity" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    <option value="">All severity</option>
                    @foreach (['critical','high','medium','low'] as $s)
                        <option value="{{ $s }}" @selected($severity === $s)>{{ $s }}</option>
                    @endforeach
                </select>
                <select name="minutes" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                    @foreach ([60, 240, 1440, 10080] as $m)
                        <option value="{{ $m }}" @selected($minutes === $m)>{{ $m }}m</option>
                    @endforeach
                </select>
                <button class="rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm font-medium text-cyan-50 hover:bg-cyan-100/20">Filter</button>
            </form>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mb-4 rounded-lg border border-red-300/30 bg-red-500/10 p-3 text-sm text-red-100">{{ $errors->first() }}</div>
    @endif

    <div class="grid gap-4 md:grid-cols-4">
        <div class="metric-card"><p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Incidents</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['incidents'] }}</p></div>
        <div class="metric-card"><p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Recent Alerts</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['alerts'] }}</p></div>
        <div class="metric-card"><p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Critical</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['critical'] }}</p></div>
        <div class="metric-card"><p class="text-sm uppercase tracking-[0.14em] text-cyan-200/75">Open</p><p class="mt-2 text-3xl font-semibold text-main-ui">{{ $totals['open'] }}</p></div>
    </div>

    <section class="glass-card mt-4 p-5">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-main-ui">Operational Summary</h3>
            @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
                <a href="{{ route('soc.hunts') }}" class="mr-3 text-sm text-cyan-200 underline decoration-cyan-300/40">Threat hunting</a>
                <a href="{{ route('soc.tuning') }}" class="mr-3 text-sm text-cyan-200 underline decoration-cyan-300/40">Detection tuning</a>
                <a href="{{ route('soc.threat-intel') }}" class="mr-3 text-sm text-cyan-200 underline decoration-cyan-300/40">Threat intel</a>
                <a href="{{ route('soc.knowledge') }}" class="mr-3 text-sm text-cyan-200 underline decoration-cyan-300/40">Knowledge base</a>
                <a href="{{ route('soc.reports') }}" class="mr-3 text-sm text-cyan-200 underline decoration-cyan-300/40">Reports</a>
                <a href="{{ route('soc.agents') }}" class="text-sm text-cyan-200 underline decoration-cyan-300/40">Manage agents</a>
            @endif
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-6">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Queue</p><p class="mono-ui mt-1 text-cyan-50">{{ $operationalSummary['queue_connection'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Failed Jobs</p><p class="mono-ui mt-1 text-cyan-50">{{ $operationalSummary['failed_jobs'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Ingestion Lag</p><p class="mono-ui mt-1 text-cyan-50">{{ $operationalSummary['telemetry_lag_seconds'] ?? '-' }}s</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Notification Failures</p><p class="mono-ui mt-1 text-cyan-50">{{ $operationalSummary['notification_failures_24h'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Overdue Incidents</p><p class="mono-ui mt-1 text-cyan-50">{{ $operationalSummary['overdue_incidents'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Agents Online</p><p class="mono-ui mt-1 text-cyan-50">{{ $operationalSummary['agents_online'] }}/{{ $operationalSummary['agents_total'] }}</p></div>
        </div>
    </section>

    <section class="glass-card mt-4 p-5">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-main-ui">XDR Cross-Domain Visibility</h3>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/60">email / identity / cloud / SaaS / proxy</span>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-6">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">XDR Incidents</p><p class="mono-ui mt-1 text-cyan-50">{{ $xdrSummary['cross_domain_incidents'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Identity Risk</p><p class="mono-ui mt-1 text-cyan-50">{{ $xdrSummary['identity_risk'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Cloud Risk</p><p class="mono-ui mt-1 text-cyan-50">{{ $xdrSummary['cloud_risk'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Email Threats</p><p class="mono-ui mt-1 text-cyan-50">{{ $xdrSummary['email_threats'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">SaaS Activity</p><p class="mono-ui mt-1 text-cyan-50">{{ $xdrSummary['saas_activity'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Proxy Anomalies</p><p class="mono-ui mt-1 text-cyan-50">{{ $xdrSummary['proxy_anomalies'] }}</p></div>
        </div>
        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Domain Telemetry Mix</h4>
                <div class="mt-2 space-y-2">
                    @forelse ($xdrDomainBreakdown as $row)
                        <div class="flex items-center justify-between text-xs text-cyan-100"><span>{{ $row->telemetry_type }}</span><span class="mono-ui">{{ $row->total }}</span></div>
                    @empty
                        <p class="text-xs text-muted-ui">No XDR telemetry in this window.</p>
                    @endforelse
                </div>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Recent XDR Evidence Timeline</h4>
                <div class="mt-2 space-y-2">
                    @forelse ($xdrRecent as $alert)
                        @php $xdrEvidence = json_decode($alert->evidence ?: '{}', true) ?: []; @endphp
                        <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2 text-xs text-cyan-100">
                            <p class="mono-ui text-cyan-50">{{ $alert->alert_type }} | {{ $alert->severity }}</p>
                            <p class="text-cyan-100/60">{{ $alert->detected_at }} | {{ $alert->actor_key }} | incident={{ $alert->incident_id ?: '-' }}</p>
                            <p class="mt-1 text-cyan-100/70">domains={{ implode(',', $xdrEvidence['xdr_domains'] ?? []) }}</p>
                        </div>
                    @empty
                        <p class="text-xs text-muted-ui">No XDR correlations yet.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="mt-4 grid gap-4 lg:grid-cols-3">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Service Separation</h4>
                <div class="mt-2 space-y-2">
                    @foreach ($xdrDistributed['services'] as $service)
                        <div class="text-xs text-cyan-100"><span class="mono-ui text-cyan-50">{{ $service['service'] }}</span> | {{ $service['status'] }}</div>
                    @endforeach
                </div>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Streaming Backbone</h4>
                <p class="mt-2 text-xs text-cyan-100">topics={{ count($xdrDistributed['streams']['topics_configured']) }} lag={{ $xdrDistributed['streams']['total_lag'] }} dlq={{ $xdrDistributed['streams']['dlq_total'] }}</p>
                <p class="mt-1 text-xs text-cyan-100/70">avg latency={{ $xdrDistributed['streams']['avg_latency_ms'] }}ms</p>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Specialized Storage</h4>
                <div class="mt-2 space-y-2">
                    @foreach ($xdrDistributed['storage'] as $store)
                        <div class="text-xs text-cyan-100"><span class="mono-ui text-cyan-50">{{ $store['store'] }}</span> | {{ $store['driver'] }} | {{ $store['status'] }}</div>
                    @endforeach
                </div>
            </div>
        </div>
        @if ($xdrDistributed['latestValidation'])
            @php $quality = json_decode($xdrDistributed['latestValidation']->quality_metrics ?: '{}', true) ?: []; @endphp
            <div class="mt-4 rounded-lg border border-cyan-200/15 bg-black/20 p-3 text-xs text-cyan-100">
                Latest XDR validation: {{ $xdrDistributed['latestValidation']->dataset_name }} |
                correlation={{ $quality['correlation_accuracy'] ?? '-' }} |
                replay={{ $quality['replay_stability'] ?? '-' }} |
                FP={{ $quality['estimated_false_positive'] ?? '-' }} |
                FN={{ $quality['estimated_false_negative'] ?? '-' }}
            </div>
        @endif
        @php $soak = $xdrDistributed['correlationSoak']; @endphp
        <div class="mt-4 rounded-lg border border-cyan-200/15 bg-black/20 p-3">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h4 class="text-sm font-semibold text-cyan-50">Go Correlation Soak Gate</h4>
                    <p class="mt-1 text-xs text-cyan-100/70">identity/cloud/SaaS only | decision={{ $soak['decision'] }} | reason={{ $soak['reason'] }}</p>
                </div>
                <span class="rounded-full border border-cyan-200/20 px-3 py-1 text-xs text-cyan-100">{{ $soak['status'] }}</span>
            </div>
            <div class="mt-3 grid gap-2 md:grid-cols-7">
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2"><p class="text-[11px] text-cyan-100/60">p95</p><p class="mono-ui text-xs text-cyan-50">{{ $soak['metrics']['p95_latency_ms'] ?? '-' }}ms</p></div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2"><p class="text-[11px] text-cyan-100/60">Memory Growth</p><p class="mono-ui text-xs text-cyan-50">{{ $soak['metrics']['memory_growth_mb'] ?? '-' }}MB</p></div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2"><p class="text-[11px] text-cyan-100/60">Goroutine Growth</p><p class="mono-ui text-xs text-cyan-50">{{ $soak['metrics']['goroutine_growth'] ?? '-' }}</p></div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2"><p class="text-[11px] text-cyan-100/60">Fallback</p><p class="mono-ui text-xs text-cyan-50">{{ $soak['metrics']['fallback_count'] ?? '-' }}</p></div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2"><p class="text-[11px] text-cyan-100/60">Failures</p><p class="mono-ui text-xs text-cyan-50">{{ $soak['metrics']['failure_count'] ?? '-' }}</p></div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2"><p class="text-[11px] text-cyan-100/60">Latency Drift</p><p class="mono-ui text-xs text-cyan-50">{{ $soak['metrics']['latency_drift_ms'] ?? '-' }}ms</p></div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2"><p class="text-[11px] text-cyan-100/60">Report</p><p class="mono-ui text-xs text-cyan-50">{{ $soak['report_path'] ?? '-' }}</p></div>
            </div>
        </div>
        @php $separated = $xdrDistributed['separatedServices']; @endphp
        <div class="mt-4 rounded-lg border border-cyan-200/15 bg-black/20 p-3">
            <h4 class="text-sm font-semibold text-cyan-50">Separated Service Migration</h4>
            <div class="mt-3 grid gap-2 md:grid-cols-4">
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2">
                    <p class="text-[11px] text-cyan-100/60">Alert Writer</p>
                    <p class="mono-ui text-xs text-cyan-50">{{ $separated['alert_writer']['status'] ?? '-' }}</p>
                    <p class="mt-1 text-[11px] text-cyan-100/70">latency={{ $separated['alert_writer']['metrics']['write_latency_ms_last'] ?? '-' }}ms dlq={{ $separated['alert_writer']['metrics']['dlq_count'] ?? '-' }} dedup={{ $separated['alert_writer']['metrics']['duplicates'] ?? '-' }}</p>
                </div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2">
                    <p class="text-[11px] text-cyan-100/60">Incident Builder</p>
                    <p class="mono-ui text-xs text-cyan-50">{{ $separated['incident_builder']['status'] ?? '-' }}</p>
                    <p class="mt-1 text-[11px] text-cyan-100/70">latency={{ $separated['incident_builder']['metrics']['latency_ms_last'] ?? '-' }}ms dlq={{ $separated['incident_builder']['metrics']['dlq_count'] ?? '-' }} built={{ $separated['incident_builder']['metrics']['incidents_built'] ?? '-' }}</p>
                </div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2">
                    <p class="text-[11px] text-cyan-100/60">AI/RAG Service</p>
                    <p class="mono-ui text-xs text-cyan-50">{{ $separated['ai_rag']['status'] ?? '-' }}</p>
                    <p class="mt-1 text-[11px] text-cyan-100/70">analysis={{ $separated['ai_rag']['metrics']['analysis_requests'] ?? '-' }} retrieval={{ $separated['ai_rag']['metrics']['retrieval_requests'] ?? '-' }}</p>
                </div>
                <div class="rounded border border-cyan-200/10 bg-slate-950/60 p-2">
                    <p class="text-[11px] text-cyan-100/60">Endpoint/DNS/Proxy Shadow</p>
                    <p class="mono-ui text-xs text-cyan-50">{{ $separated['endpoint_dns_proxy_shadow']['status'] ?? '-' }}</p>
                    <p class="mt-1 text-[11px] text-cyan-100/70">alerts={{ $separated['endpoint_dns_proxy_shadow']['alert_count'] ?? '-' }} p95={{ $separated['endpoint_dns_proxy_shadow']['p95_latency_ms'] ?? '-' }}ms cutover=no</p>
                </div>
            </div>
        </div>
        @php $maturity = $xdrDistributed['maturity']; @endphp
        <div class="mt-4 grid gap-4 lg:grid-cols-5">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Stream Maturity</h4>
                <p class="mt-2 text-xs text-cyan-100">warning topics={{ $maturity['stream']['warning_topics'] }} lag={{ $maturity['stream']['max_partition_lag'] }}</p>
                <p class="text-xs text-cyan-100/70">saturation={{ $maturity['stream']['max_saturation'] }}</p>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Rule Lifecycle</h4>
                <p class="mt-2 text-xs text-cyan-100">rules={{ $maturity['rules']['rules_total'] }} quality={{ $maturity['rules']['avg_quality_score'] }}</p>
                <p class="text-xs text-cyan-100/70">drift warnings={{ $maturity['rules']['drift_warnings'] }}</p>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Identity Risk</h4>
                <p class="mt-2 text-xs text-cyan-100">users={{ $maturity['identity']['tracked_users'] }} high={{ $maturity['identity']['high_risk_users'] }}</p>
                <p class="text-xs text-cyan-100/70">max risk={{ $maturity['identity']['max_risk_score'] }}</p>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Recovery / Attack Flow</h4>
                <p class="mt-2 text-xs text-cyan-100">campaigns={{ $maturity['attack_reconstruction']['campaigns_24h'] }} confidence={{ $maturity['attack_reconstruction']['avg_chain_confidence'] }}</p>
                <p class="text-xs text-cyan-100/70">recovery={{ $maturity['recovery']['latest_status'] ?: '-' }}</p>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Service Extraction</h4>
                <p class="mt-2 text-xs text-cyan-100">extracted={{ $maturity['service_extraction']['extracted_services'] }} healthy={{ $maturity['service_extraction']['healthy_extracted_services'] }}</p>
                <p class="text-xs text-cyan-100/70">Laravel remains SOC control plane</p>
            </div>
        </div>
    </section>

    <section class="glass-card mt-4 p-5">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-main-ui">AI & Detection Maturity</h3>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/60">AI provider: {{ config('soc.ai_provider', 'local') }}</span>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-6">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">AI Generated 24h</p><p class="mono-ui mt-1 text-cyan-50">{{ $aiSummary['generated_24h'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">AI Accepted 7d</p><p class="mono-ui mt-1 text-cyan-50">{{ $aiSummary['accepted_7d'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">AI Rejected 7d</p><p class="mono-ui mt-1 text-cyan-50">{{ $aiSummary['rejected_7d'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">KB Entries</p><p class="mono-ui mt-1 text-cyan-50">{{ $aiSummary['kb_entries'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Avg AI Latency</p><p class="mono-ui mt-1 text-cyan-50">{{ $aiSummary['avg_latency_ms_24h'] }}ms</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Guardrail Events</p><p class="mono-ui mt-1 text-cyan-50">{{ $aiSummary['guardrail_events_7d'] }}</p></div>
        </div>
        <div class="mt-4 grid gap-4 lg:grid-cols-4">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Recent AI Suggestions</h4>
                <div class="mt-2 space-y-2">@forelse ($aiSuggestions as $row)<div class="text-xs text-cyan-100">{{ $row->suggestion_type }} | {{ $row->target_id }} | {{ $row->status }}</div>@empty<div class="text-xs text-muted-ui">No AI suggestions yet.</div>@endforelse</div>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Provider / Model Usage</h4>
                <div class="mt-2 space-y-2">@forelse ($aiProviderUsage as $row)<div class="text-xs text-cyan-100">{{ $row->provider }}: {{ $row->total }}</div>@empty<div class="text-xs text-muted-ui">No provider usage.</div>@endforelse</div>
                <div class="mt-3 space-y-2">@foreach ($aiModelUsage as $row)<div class="text-xs text-cyan-100/70">{{ $row->model ?: '-' }}: {{ $row->total }}</div>@endforeach</div>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">Confidence / Retrieval</h4>
                <div class="mt-2 space-y-2">@forelse ($aiConfidenceDistribution as $row)<div class="text-xs text-cyan-100">{{ $row->confidence_label }}: {{ $row->total }}</div>@empty<div class="text-xs text-muted-ui">No confidence data.</div>@endforelse</div>
                <p class="mt-3 text-xs text-cyan-100/70">Citation-backed outputs 24h: {{ $aiSummary['retrieval_citations_24h'] }}</p>
            </div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3">
                <h4 class="text-sm font-semibold text-cyan-50">AI Evaluation</h4>
                <div class="mt-2 space-y-2">
                    @forelse ($aiEvalRuns as $run)
                        @php $metrics = json_decode($run->metrics ?: '{}', true) ?: []; @endphp
                        <div class="text-xs text-cyan-100">{{ $run->scope }} | acceptance={{ $metrics['analyst_acceptance_rate'] ?? '-' }} citation={{ $metrics['citation_coverage'] ?? '-' }} hallucination={{ $metrics['hallucination_rate'] ?? '-' }}</div>
                    @empty
                        <div class="text-xs text-muted-ui">No AI evaluation yet.</div>
                    @endforelse
                </div>
            </div>
        </div>
    </section>

    <section class="glass-card mt-4 p-5">
        <div class="flex items-center justify-between">
            <h3 class="text-lg font-semibold text-main-ui">Investigation Visibility</h3>
            <span class="text-xs uppercase tracking-[0.14em] text-cyan-100/60">last 24h / 1h spike window</span>
        </div>
        <div class="mt-4 grid gap-3 md:grid-cols-6">
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Active Hunts</p><p class="mono-ui mt-1 text-cyan-50">{{ $investigationSummary['active_hunts_24h'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Hunt Matches</p><p class="mono-ui mt-1 text-cyan-50">{{ $investigationSummary['hunt_matches_24h'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Endpoint Sessions</p><p class="mono-ui mt-1 text-cyan-50">{{ $investigationSummary['endpoint_sessions_24h'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Forensic Pending</p><p class="mono-ui mt-1 text-cyan-50">{{ $investigationSummary['forensic_pending'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Timeline Spikes</p><p class="mono-ui mt-1 text-cyan-50">{{ $investigationSummary['timeline_spike_hosts_1h'] }}</p></div>
            <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-3"><p class="text-xs text-cyan-100/70">Integrity Warnings</p><p class="mono-ui mt-1 text-cyan-50">{{ $investigationSummary['agent_integrity_warnings'] }}</p></div>
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Endpoint Agents</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">Agent</th><th class="px-4 py-3">Host</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Version</th><th class="px-4 py-3">Events</th><th class="px-4 py-3">Errors</th><th class="px-4 py-3">Last Seen</th></tr></thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @forelse ($agentRows as $agent)
                        <tr>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-50">{{ $agent->agent_id }}</td>
                            <td class="px-4 py-3 text-cyan-100">{{ $agent->host_id }}<p class="text-xs text-cyan-100/50">{{ $agent->os_family ?: '-' }}</p></td>
                            <td class="px-4 py-3"><span class="rounded-full border border-cyan-200/20 px-2 py-1 text-xs {{ $agent->computed_status === 'online' ? 'text-emerald-200' : 'text-amber-200' }}">{{ $agent->computed_status }}</span></td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $agent->agent_version }}</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $agent->event_count_total }} <span class="text-cyan-100/40">(+{{ $agent->last_batch_event_count }})</span></td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $agent->error_count_total }}</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $agent->last_seen_at ?: '-' }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-muted-ui" colspan="7">No endpoint agents enrolled yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="mt-4 grid gap-4 xl:grid-cols-4">
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Severity Summary</h3>
            <div class="mt-4 space-y-3">
                @forelse ($severitySummary as $row)
                    <div class="flex items-center justify-between rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2"><span class="capitalize text-cyan-50">{{ $row->severity }}</span><span class="mono-ui text-cyan-100">{{ $row->total }}</span></div>
                @empty
                    <p class="text-sm text-muted-ui">No severity data in this window.</p>
                @endforelse
            </div>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Incident Status</h3>
            <div class="mt-4 space-y-3">
                @forelse ($statusSummary as $row)
                    <div class="flex items-center justify-between rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2"><span class="text-cyan-50">{{ $row->status }}</span><span class="mono-ui text-cyan-100">{{ $row->total }}</span></div>
                @empty
                    <p class="text-sm text-muted-ui">No incident status data.</p>
                @endforelse
            </div>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">MITRE Overview</h3>
            <div class="mt-4 space-y-2">
                @forelse ($mitreOverview as $technique => $total)
                    <div class="flex items-center justify-between rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2"><span class="mono-ui text-cyan-50">{{ $technique }}</span><span class="mono-ui text-cyan-100">{{ $total }}</span></div>
                @empty
                    <p class="text-sm text-muted-ui">No MITRE data.</p>
                @endforelse
            </div>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Top Affected Entities</h3>
            <div class="mt-4 space-y-2">
                @forelse ($topEntities as $entity => $total)
                    <div class="rounded-lg border border-cyan-200/15 bg-black/20 px-3 py-2"><p class="mono-ui break-all text-xs text-cyan-50">{{ $entity }}</p><p class="mt-1 text-xs text-cyan-100">{{ $total }} incidents</p></div>
                @empty
                    <p class="text-sm text-muted-ui">No entity data.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Alert Trend</h3>
            <div class="mt-4 flex h-40 items-end gap-1">
                @php $maxAlert = max(1, (int) ($alertTrend->max('total') ?? 1)); @endphp
                @forelse ($alertTrend as $row)
                    <div title="{{ $row->bucket }}: {{ $row->total }}" class="min-w-3 flex-1 rounded-t bg-cyan-300/70" style="height: {{ max(4, ($row->total / $maxAlert) * 100) }}%"></div>
                @empty
                    <div class="flex h-full w-full items-center justify-center text-sm text-muted-ui">No alert volume yet.</div>
                @endforelse
            </div>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Incident Trend</h3>
            <div class="mt-4 flex h-40 items-end gap-1">
                @php $maxIncident = max(1, (int) ($incidentTrend->max('total') ?? 1)); @endphp
                @forelse ($incidentTrend as $row)
                    <div title="{{ $row->bucket }}: {{ $row->total }}" class="min-w-3 flex-1 rounded-t bg-amber-300/80" style="height: {{ max(4, ($row->total / $maxIncident) * 100) }}%"></div>
                @empty
                    <div class="flex h-full w-full items-center justify-center text-sm text-muted-ui">No incident trend yet.</div>
                @endforelse
            </div>
        </section>
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Exports</h3>
            <div class="mt-4 grid gap-2">
                @if (in_array(Auth::user()?->role, ['admin', 'analyst'], true))
                    <a class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50" href="{{ route('soc.exports.download', 'jsonl') }}">Download JSONL</a>
                    <a class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50" href="{{ route('soc.exports.download', 'siem') }}">Download SIEM JSONL</a>
                    <a class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50" href="{{ route('soc.exports.download', 'stix') }}">Download STIX-like JSON</a>
                    @foreach (['webhook' => 'Webhook test', 'slack' => 'Slack test', 'discord' => 'Discord test'] as $target => $label)
                        <form method="POST" action="{{ route('soc.exports.test', $target) }}" class="mt-2 flex gap-2">
                            @csrf
                            <input name="url" placeholder="{{ $label }} URL" class="min-w-0 flex-1 rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-xs text-cyan-50">
                            <button class="rounded-lg border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-xs text-cyan-50">{{ $label }}</button>
                        </form>
                    @endforeach
                @else
                    <p class="text-sm text-muted-ui">Export actions require analyst or admin role.</p>
                @endif
            </div>
        </section>
    </div>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Incident List</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">Incident</th><th class="px-4 py-3">Severity</th><th class="px-4 py-3">Status</th><th class="px-4 py-3">Analyst</th><th class="px-4 py-3">SLA</th><th class="px-4 py-3">Last Seen</th></tr></thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @forelse ($incidents as $incident)
                        <tr class="hover:bg-cyan-100/5">
                            <td class="px-4 py-3"><a class="mono-ui text-cyan-50 underline decoration-cyan-300/40" href="{{ route('soc.incidents.show', $incident->incident_id) }}">{{ $incident->incident_id }}</a><p class="max-w-xl truncate text-xs text-cyan-100/70">{{ $incident->title }}</p></td>
                            <td class="px-4 py-3 text-cyan-100">{{ $incident->severity }}</td>
                            <td class="px-4 py-3 text-cyan-100">{{ $incident->status }}</td>
                            <td class="px-4 py-3 text-cyan-100/80">{{ $incident->assigned_analyst ?: '-' }}</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $incident->sla_due_at ?: '-' }}</td>
                            <td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $incident->last_seen_at }}</td>
                        </tr>
                    @empty
                        <tr><td class="px-4 py-6 text-muted-ui" colspan="6">No incidents match the current filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-cyan-100/15 px-5 py-4">{{ $incidents->links() }}</div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Recent Alerts & Evidence</h3></div>
        <div class="grid gap-3 p-5 lg:grid-cols-2">
            @forelse ($recentAlerts as $alert)
                @php $ev = json_decode($alert->evidence ?: '{}', true) ?: []; @endphp
                <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-4">
                    <div class="flex items-center justify-between gap-3"><p class="mono-ui text-sm text-cyan-50">{{ $alert->alert_type }}</p><span class="text-xs text-cyan-100">{{ $alert->severity }}</span></div>
                    <p class="mt-1 mono-ui text-xs text-cyan-100/70">{{ $alert->detected_at }} | {{ $alert->actor_key ?: $alert->ip }}</p>
                    <p class="mt-3 text-xs text-cyan-100/75">Incident: {{ $alert->incident_id ?: '-' }}</p>
                    <details class="mt-3">
                        <summary class="cursor-pointer text-xs text-cyan-200">Evidence chain</summary>
                        <pre class="mt-2 max-h-56 overflow-auto rounded bg-slate-950/70 p-3 text-xs text-cyan-50">{{ json_encode($ev['evidence_chain'] ?? $ev, JSON_PRETTY_PRINT) }}</pre>
                    </details>
                </div>
            @empty
                <div class="rounded-lg border border-cyan-200/15 bg-black/20 p-4 text-sm text-muted-ui">No recent alerts in this window.</div>
            @endforelse
        </div>
    </section>

    <section class="glass-card mt-4 overflow-hidden">
        <div class="border-b border-cyan-100/15 px-5 py-4"><h3 class="text-lg font-semibold text-main-ui">Detection Quality History</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-cyan-100/10 text-sm">
                <thead class="bg-black/20 text-left text-xs uppercase tracking-[0.12em] text-cyan-200/70"><tr><th class="px-4 py-3">Measured</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Precision</th><th class="px-4 py-3">Recall</th><th class="px-4 py-3">FPR</th><th class="px-4 py-3">Latency</th><th class="px-4 py-3">Alerts</th><th class="px-4 py-3">Incidents</th></tr></thead>
                <tbody class="divide-y divide-cyan-100/10">
                    @foreach ($qualityHistory as $row)
                        <tr><td class="px-4 py-3 mono-ui text-xs text-cyan-100/80">{{ $row->measured_at }}</td><td class="px-4 py-3 text-cyan-100">{{ $row->metric_type }}</td><td class="px-4 py-3">{{ $row->precision }}</td><td class="px-4 py-3">{{ $row->recall }}</td><td class="px-4 py-3">{{ $row->false_positive_rate }}</td><td class="px-4 py-3">{{ $row->avg_detection_latency_sec }}</td><td class="px-4 py-3">{{ $row->alert_volume }}</td><td class="px-4 py-3">{{ $row->incident_volume }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</x-app-layout>
