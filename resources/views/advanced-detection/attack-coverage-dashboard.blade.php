<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">ATT&CK Coverage Dashboard</h2>
        <p class="text-xs text-amber-400/80 mt-1">Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['total_validation_runs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Validation Runs</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-green-300">{{ $stats['pass_runs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Pass</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-red-300">{{ $stats['fail_runs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Fail</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-purple-300">{{ $stats['multi_stage_progressions'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Multi-Stage</div>
            </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-orange-300">{{ $stats['total_chained_graphs'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Chain Graphs</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-yellow-300">{{ $stats['total_evasion_reports'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Evasion Reports</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold text-cyan-300">{{ $stats['detection_survived_count'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Detection Survived</div>
            </div>
            <div class="rounded border border-slate-700 bg-slate-800/50 p-4 text-center">
                <div class="text-2xl font-bold {{ $stats['propagation_detected'] > 0 ? 'text-red-300' : 'text-slate-400' }}">{{ $stats['propagation_detected'] }}</div>
                <div class="text-xs text-slate-400 mt-1">Propagation Detected</div>
            </div>
        </div>
    </div>
</x-app-layout>
