<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Hunt Query Builder</h2>
                <p class="text-xs text-amber-400/80 mt-1 font-medium">Threat hunting is advisory-only and non-destructive.</p>
            </div>
            <a href="{{ route('threat-hunt.dashboard') }}"
               class="text-sm px-3 py-1.5 rounded border border-cyan-200/20 bg-cyan-100/5 text-cyan-200/70 hover:text-cyan-200">← Dashboard</a>
        </div>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

            @if ($errors->any())
                <div class="glass-card p-4 border border-red-400/30 text-red-300 text-sm">{{ $errors->first() }}</div>
            @endif

            <div class="glass-card p-6">
                <form method="POST" action="{{ route('threat-hunt.execute') }}" class="space-y-4">
                    @csrf
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-cyan-400/70 mb-1">Hunt Title *</label>
                            <input type="text" name="title" value="{{ old('title') }}" required
                                class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200 placeholder-cyan-400/40"
                                placeholder="e.g. Hunt for nginx→bash chains">
                        </div>
                        <div>
                            <label class="block text-xs text-cyan-400/70 mb-1">Query Domain *</label>
                            <select name="query_domain" class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                                @foreach ($domains as $domain)
                                    <option value="{{ $domain }}" {{ old('query_domain') === $domain ? 'selected' : '' }}>{{ str_replace('_', ' ', $domain) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-cyan-400/70 mb-1">Time Range Start</label>
                            <input type="datetime-local" name="time_range_start"
                                class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                        </div>
                        <div>
                            <label class="block text-xs text-cyan-400/70 mb-1">Time Range End</label>
                            <input type="datetime-local" name="time_range_end"
                                class="w-full text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs text-cyan-400/70 mb-2">Filter Conditions</label>
                        <div class="space-y-2">
                            @foreach (range(0, 2) as $i)
                            <div class="grid grid-cols-3 gap-2">
                                <input type="text" name="filters[{{ $i }}][field]" placeholder="Field (e.g. process_name)"
                                    class="text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200 placeholder-cyan-400/40">
                                <select name="filters[{{ $i }}][operator]"
                                    class="text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                                    @foreach (['=', 'contains', '>=', '<=', '!='] as $op)
                                        <option value="{{ $op }}">{{ $op }}</option>
                                    @endforeach
                                </select>
                                <input type="text" name="filters[{{ $i }}][value]" placeholder="Value"
                                    class="text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200 placeholder-cyan-400/40">
                            </div>
                            @endforeach
                        </div>
                        <p class="text-xs text-cyan-400/40 mt-1">Max 3 filters. Leave empty to skip. All fields are allowlisted per domain — no raw SQL.</p>
                    </div>

                    <div class="flex items-center gap-4">
                        <div>
                            <label class="block text-xs text-cyan-400/70 mb-1">Max Results (1-500)</label>
                            <input type="number" name="max_results" value="100" min="1" max="500"
                                class="w-32 text-sm px-3 py-2 rounded bg-black/30 border border-cyan-200/20 text-cyan-200">
                        </div>
                        <div class="flex-1"></div>
                        <button type="submit"
                            class="px-6 py-2 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 hover:bg-cyan-700/60 text-sm font-medium">
                            Execute Hunt
                        </button>
                    </div>
                </form>
            </div>

            <div class="glass-card p-4 border border-amber-400/20">
                <p class="text-xs text-amber-300/80">
                    Threat hunting is advisory-only and non-destructive. All queries use allowlisted fields only.
                    Maximum query window: {{ \App\Services\ThreatHuntingService::MAX_QUERY_WINDOW_DAYS }} days.
                    Maximum results: {{ \App\Services\ThreatHuntingService::MAX_RESULTS }}.
                </p>
            </div>
        </div>
    </div>
</x-app-layout>
