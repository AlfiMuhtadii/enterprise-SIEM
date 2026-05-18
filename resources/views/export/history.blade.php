<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <p class="brand-chip">Export Center</p>
                <h2 class="mt-2 text-xl font-semibold leading-tight text-main-ui">Export History</h2>
            </div>
            <a href="{{ route('export.index') }}"
               class="rounded-lg border border-cyan-200/20 bg-cyan-100/5 px-3 py-1.5 text-sm text-cyan-200/70 hover:text-cyan-200 transition">
                ← Export Center
            </a>
        </div>
    </x-slot>

    <div class="space-y-4">

        {{-- Filter --}}
        <div class="glass-card p-4">
            <form method="GET" action="{{ route('export.history') }}" class="flex flex-wrap gap-3 items-end">
                <div class="w-44">
                    <label class="mb-1 block text-xs text-cyan-200/60">Export Type</label>
                    <select name="type" class="w-full rounded-lg border border-cyan-200/20 bg-black/30 px-3 py-2 text-sm text-cyan-50 focus:outline-none">
                        <option value="">All types</option>
                        @foreach (\App\Models\ExportAuditLog::EXPORT_TYPES as $t)
                            <option value="{{ $t }}" @selected($typeFilter === $t)>{{ str_replace('_',' ',ucfirst($t)) }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-lg border border-cyan-400/40 bg-cyan-500/15 px-4 py-2 text-sm text-cyan-200 hover:bg-cyan-500/25 transition">
                    Filter
                </button>
                @if ($typeFilter)
                    <a href="{{ route('export.history') }}" class="text-xs text-cyan-200/40 hover:text-cyan-200 transition self-end pb-2">Clear</a>
                @endif
            </form>
        </div>

        @if ($history->isEmpty())
            <div class="glass-card p-8 text-center text-sm text-cyan-200/40">No export records found.</div>
        @else
            <div class="glass-card overflow-hidden">
                <div class="border-b border-cyan-100/10 px-5 py-3">
                    <span class="text-xs uppercase tracking-[0.12em] text-cyan-200/50">{{ $history->count() }} export records</span>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-cyan-100/10 text-left text-xs uppercase tracking-[0.09em] text-cyan-200/40">
                            <th class="px-5 py-3">Export ID</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Format</th>
                            <th class="px-4 py-3">Source ID</th>
                            <th class="px-4 py-3">Exported By</th>
                            <th class="px-4 py-3">Exported At</th>
                            <th class="px-4 py-3">Size</th>
                            <th class="px-4 py-3">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-cyan-100/5">
                        @foreach ($history as $exp)
                            <tr class="hover:bg-cyan-100/3 transition">
                                <td class="px-5 py-2.5"><span class="mono-ui text-xs text-cyan-200/60">{{ $exp->export_id }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/70">{{ str_replace('_',' ',ucfirst($exp->export_type)) }}</td>
                                <td class="px-4 py-2.5"><span class="mono-ui text-xs text-cyan-200/60 uppercase">{{ $exp->export_format }}</span></td>
                                <td class="px-4 py-2.5"><span class="mono-ui text-xs text-cyan-200/60">{{ Str::limit($exp->source_id, 24) }}</span></td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/70">{{ $exp->exported_by_name ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/50 mono-ui">{{ \Carbon\Carbon::parse($exp->exported_at)->format('Y-m-d H:i:s') }}</td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/40">
                                    {{ $exp->export_size_bytes ? number_format($exp->export_size_bytes / 1024, 1) . ' KB' : '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-xs text-cyan-200/50">{{ Str::limit($exp->export_reason ?? '—', 32) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

    </div>
</x-app-layout>
