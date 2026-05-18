<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">XDR Platform</p>
                <h2 class="mt-2 text-2xl font-semibold leading-tight text-main-ui">Export Center</h2>
            </div>
            <div class="flex items-center gap-3">
                <a href="{{ route('export.history') }}"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-2 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    Export History
                </a>
                <a href="{{ route('api.exports.history') }}" target="_blank" rel="noopener"
                   class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-2 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                    API
                </a>
            </div>
        </div>
    </x-slot>

    <div class="space-y-6">

        <div class="rounded-lg border border-cyan-400/20 bg-cyan-500/5 px-4 py-3 text-xs text-cyan-200/70">
            All exports apply <span class="mono-ui">TraceRedactor</span> — passwords, tokens, secrets, cookies, and authorization headers are replaced with <span class="mono-ui">[REDACTED]</span> before export.
            Every export creates an immutable audit log record.
        </div>

        {{-- Stat strip --}}
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            @foreach (\App\Models\ExportAuditLog::EXPORT_TYPES as $t)
                <div class="glass-card px-4 py-3">
                    <p class="text-[10px] uppercase tracking-[0.1em] text-cyan-200/50 truncate">{{ str_replace('_',' ',$t) }}</p>
                    <p class="mt-0.5 text-xl font-bold mono-ui text-cyan-50">{{ (int)($counts->get($t)?->count ?? 0) }}</p>
                </div>
            @endforeach
        </div>

        {{-- Export forms --}}
        <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-2">

            {{-- Investigation Export --}}
            <div class="glass-card p-5">
                <h3 class="text-sm font-semibold text-cyan-200 mb-1">Investigation Summary Report</h3>
                <p class="text-xs text-cyan-200/50 mb-4">Lifecycle state, assignment history, notes, linked alerts/incidents/entities/response plans.</p>
                <form method="POST" action="{{ route('export.investigation', ['id' => '__ID__']) }}" id="form-inv"
                      onsubmit="this.action=this.action.replace('__ID__',document.getElementById('inv-id').value)">
                    @csrf
                    <div class="space-y-2">
                        <input type="number" id="inv-id" placeholder="Investigation DB ID" required
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 mono-ui focus:border-cyan-400/40 focus:outline-none"/>
                        <div class="grid grid-cols-2 gap-2">
                            <select name="format" class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                <option value="json">JSON</option>
                                <option value="markdown">Markdown</option>
                                <option value="html">HTML</option>
                            </select>
                            <input type="text" name="reason" placeholder="Reason (optional)"
                                   class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none"/>
                        </div>
                        <button type="submit"
                                class="w-full rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                            Download Investigation Report
                        </button>
                    </div>
                </form>
            </div>

            {{-- Response Plan Export --}}
            <div class="glass-card p-5">
                <h3 class="text-sm font-semibold text-cyan-200 mb-1">Response Plan Report</h3>
                <p class="text-xs text-cyan-200/50 mb-4">Actions, approval chain, rejection history, advisory-only indicators, analyst notes.</p>
                <form method="POST" action="{{ route('export.response-plan', ['id' => '__ID__']) }}"
                      onsubmit="this.action=this.action.replace('__ID__',document.getElementById('rp-id').value)">
                    @csrf
                    <div class="space-y-2">
                        <input type="number" id="rp-id" placeholder="Response Plan DB ID" required
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 mono-ui focus:border-cyan-400/40 focus:outline-none"/>
                        <div class="grid grid-cols-2 gap-2">
                            <select name="format" class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                <option value="json">JSON</option>
                                <option value="markdown">Markdown</option>
                                <option value="html">HTML</option>
                            </select>
                            <input type="text" name="reason" placeholder="Reason (optional)"
                                   class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none"/>
                        </div>
                        <button type="submit"
                                class="w-full rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                            Download Response Plan Report
                        </button>
                    </div>
                </form>
            </div>

            {{-- Entity Risk Export --}}
            <div class="glass-card p-5">
                <h3 class="text-sm font-semibold text-cyan-200 mb-1">Entity Risk Report</h3>
                <p class="text-xs text-cyan-200/50 mb-4">Risk score, contributing factors, historical snapshots, linked alerts/traces.</p>
                <form method="POST" action="{{ route('export.entity-risk', ['id' => '__ID__']) }}"
                      onsubmit="this.action=this.action.replace('__ID__',document.getElementById('er-id').value)">
                    @csrf
                    <div class="space-y-2">
                        <input type="number" id="er-id" placeholder="Entity DB ID" required
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 mono-ui focus:border-cyan-400/40 focus:outline-none"/>
                        <div class="grid grid-cols-2 gap-2">
                            <select name="format" class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                <option value="json">JSON</option>
                                <option value="markdown">Markdown</option>
                                <option value="html">HTML</option>
                            </select>
                            <input type="text" name="reason" placeholder="Reason (optional)"
                                   class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none"/>
                        </div>
                        <button type="submit"
                                class="w-full rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                            Download Entity Risk Report
                        </button>
                    </div>
                </form>
            </div>

            {{-- Trace Export --}}
            <div class="glass-card p-5">
                <h3 class="text-sm font-semibold text-cyan-200 mb-1">Trace Investigation Report</h3>
                <p class="text-xs text-cyan-200/50 mb-4">Pipeline event chain, alerts generated, incidents linked, entity involvement.</p>
                <form method="POST" action="{{ route('export.trace', ['traceId' => '__ID__']) }}"
                      onsubmit="this.action=this.action.replace('__ID__',document.getElementById('tr-id').value)">
                    @csrf
                    <div class="space-y-2">
                        <input type="text" id="tr-id" placeholder="Trace ID (e.g. 3a7f1c2e-...)" required
                               class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 mono-ui focus:border-cyan-400/40 focus:outline-none"/>
                        <div class="grid grid-cols-2 gap-2">
                            <select name="format" class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                                <option value="json">JSON</option>
                                <option value="markdown">Markdown</option>
                                <option value="html">HTML</option>
                            </select>
                            <input type="text" name="reason" placeholder="Reason (optional)"
                                   class="rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none"/>
                        </div>
                        <button type="submit"
                                class="w-full rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                            Download Trace Report
                        </button>
                    </div>
                </form>
            </div>

        </div>

        {{-- Recent exports --}}
        @if ($recent->isNotEmpty())
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3 flex items-center justify-between">
                    <h3 class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">Recent Exports</h3>
                    <a href="{{ route('export.history') }}" class="text-xs text-cyan-400 hover:text-cyan-200 transition">View all →</a>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-2.5">Export ID</th>
                            <th class="px-4 py-2.5">Type</th>
                            <th class="px-4 py-2.5">Format</th>
                            <th class="px-4 py-2.5">By</th>
                            <th class="px-4 py-2.5">At</th>
                            <th class="px-4 py-2.5">Size</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($recent as $exp)
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-2.5"><span class="mono-ui text-xs text-cyan-200/60">{{ $exp->export_id }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/70">{{ str_replace('_',' ',$exp->export_type) }}</td>
                                <td class="px-4 py-2.5"><span class="mono-ui text-xs text-cyan-200/60">{{ $exp->export_format }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/60">{{ $exp->exported_by_name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/50 mono-ui">{{ \Carbon\Carbon::parse($exp->exported_at)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/40">{{ $exp->export_size_bytes ? number_format($exp->export_size_bytes / 1024, 1) . ' KB' : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</x-app-layout>
