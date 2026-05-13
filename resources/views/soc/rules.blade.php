<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="brand-chip">Detection Engineering</p>
            <h2 class="mt-2 text-2xl font-semibold text-main-ui">Rule Management</h2>
        </div>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif

    @php
        $tests = collect($quality['regression']['tests'] ?? [])->keyBy('rule_id');
    @endphp

    <div class="grid gap-4">
        @foreach ($rules as $rule)
            @php $test = $tests[$rule['rule_id']] ?? null; @endphp
            <section class="glass-card p-5">
                <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                    <div>
                        <p class="mono-ui text-sm text-cyan-100">{{ $rule['rule_id'] }} <span class="text-cyan-100/50">v{{ $rule['rule_version'] ?? '1.0.0' }}</span></p>
                        <h3 class="mt-1 text-lg font-semibold text-main-ui">{{ $rule['name'] }}</h3>
                        <p class="mt-1 text-sm text-muted-ui">{{ $rule['description'] ?? '' }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-xs text-cyan-100">severity: {{ $rule['severity_override'] ?: $rule['severity'] }}</span>
                            <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-xs text-cyan-100">enabled: {{ ($rule['enabled'] ?? true) ? 'yes' : 'no' }}</span>
                            <span class="rounded-full bg-cyan-100/10 px-2 py-1 text-xs text-cyan-100">test: {{ $test['status'] ?? 'not-run' }}</span>
                        </div>
                    </div>
                    <form method="POST" action="{{ route('soc.rules.update', $rule['rule_id']) }}" class="grid min-w-72 gap-2">
                        @csrf
                        <label class="text-xs text-cyan-100/70">Enabled</label>
                        <select name="enabled" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                            <option value="1" @selected($rule['enabled'] ?? true)>enabled</option>
                            <option value="0" @selected(!($rule['enabled'] ?? true))>disabled</option>
                        </select>
                        <label class="text-xs text-cyan-100/70">Severity override</label>
                        <select name="severity_override" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                            <option value="">none</option>
                            @foreach (['low','medium','high','critical'] as $s)
                                <option value="{{ $s }}" @selected(($rule['severity_override'] ?? null) === $s)>{{ $s }}</option>
                            @endforeach
                        </select>
                        <input name="metadata_owner" value="{{ $rule['metadata']['owner'] ?? '' }}" placeholder="Owner" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        <input name="metadata_status" value="{{ $rule['metadata']['status'] ?? '' }}" placeholder="Status" class="rounded-lg border border-cyan-200/30 bg-slate-950/70 px-3 py-2 text-sm text-cyan-50">
                        <button class="rounded-lg border border-cyan-200/30 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Save Rule</button>
                    </form>
                </div>
                <details class="mt-4">
                    <summary class="cursor-pointer text-sm text-cyan-200">Metadata, MITRE, regression detail</summary>
                    <pre class="mt-3 max-h-80 overflow-auto rounded bg-slate-950/70 p-3 text-xs text-cyan-50">{{ json_encode(['metadata' => $rule['metadata'] ?? [], 'mitre' => $rule['mitre'] ?? [], 'test' => $test], JSON_PRETTY_PRINT) }}</pre>
                </details>
            </section>
        @endforeach
    </div>
</x-app-layout>
