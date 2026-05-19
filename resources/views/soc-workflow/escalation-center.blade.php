<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Escalation Center</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-5">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-3 py-2 text-xs text-amber-300">
            No automatic escalation execution. No autonomous analyst assignment. All escalations require explicit operator action.
        </div>

        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Create Escalation</h3>
            <form method="POST" action="{{ route('soc.workflow.escalation.create') }}" class="flex gap-3 items-end flex-wrap">
                @csrf
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Investigation ID</label>
                    <input name="investigation_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-48" placeholder="INV-...">
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Severity</label>
                    <select name="severity" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                        @foreach(['critical', 'high', 'medium', 'low'] as $s)
                        <option>{{ $s }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Route To (optional)</label>
                    <select name="to_analyst_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                        <option value="">Team Queue</option>
                        @foreach($analysts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="flex-1">
                    <label class="text-xs text-gray-400 block mb-1">Reason</label>
                    <input name="reason" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-full" placeholder="Escalation reason">
                </div>
                <button type="submit" class="px-4 py-1.5 rounded bg-red-700/40 border border-red-400/30 text-red-200 text-sm">Escalate</button>
            </form>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Open Escalation Queue ({{ $queue->count() }})</h3>
            @forelse($queue as $e)
            <div class="flex gap-3 items-center py-1.5 border-b border-gray-800 text-xs">
                <span class="font-mono text-cyan-400 text-xs">{{ $e->escalation_id }}</span>
                <span>{{ $e->investigation_id }}</span>
                <span class="px-1.5 py-0.5 rounded {{ $e->severity === 'critical' ? 'bg-red-900/40 text-red-300' : 'bg-amber-900/40 text-amber-300' }}">{{ $e->severity }}</span>
                <span class="text-gray-400">→ {{ $e->toAnalyst?->name ?? 'Team' }}</span>
                <span class="ml-auto text-gray-500">{{ $e->created_at?->format('M d H:i') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-500">No open escalations.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
