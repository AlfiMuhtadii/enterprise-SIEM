<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Replay Result Viewer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Detection lifecycle actions are governed, replay-validated, and do not execute autonomous response.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">

        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            <strong>Governance Notice:</strong> Replay results record deterministic test outcomes. Unexpected enforcement is a FAIL condition.
        </div>

        <div class="glass-card p-4">
            <div class="text-xs text-gray-400 mb-1">Global Pass Rate</div>
            <div class="text-3xl font-bold {{ $passRate >= 0.9 ? 'text-green-400' : ($passRate >= 0.7 ? 'text-yellow-400' : 'text-red-400') }}">
                {{ number_format($passRate * 100, 1) }}%
            </div>
        </div>

        <div class="space-y-2">
            @forelse($results as $result)
            <div class="glass-card p-4 space-y-1 text-xs border {{ $result->passed ? 'border-green-700/30' : 'border-red-700/40' }}">
                <div class="flex justify-between">
                    <span class="{{ $result->passed ? 'text-green-300' : 'text-red-300' }} font-semibold">{{ $result->passed ? 'PASS' : 'FAIL' }}</span>
                    <span class="text-gray-500">{{ $result->created_at?->diffForHumans() }}</span>
                </div>
                <div class="flex gap-4 text-gray-400">
                    <span>Rule: <span class="font-mono text-gray-300">{{ $result->rule_id }}</span></span>
                    <span>Cases: {{ $result->cases_passed }}/{{ $result->cases_run }}</span>
                    @if($result->evidence_mismatch)<span class="text-red-400">evidence_mismatch</span>@endif
                    @if($result->trace_id_missing)<span class="text-red-400">trace_id_missing</span>@endif
                    @if($result->unexpected_enforcement)<span class="text-red-500 font-bold">unexpected_enforcement</span>@endif
                </div>
            </div>
            @empty
            <p class="text-xs text-gray-500 p-4">No replay results recorded yet.</p>
            @endforelse
        </div>

    </div>
</x-app-layout>
