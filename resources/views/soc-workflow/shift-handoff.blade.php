<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Shift Handoff</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Analyst-driven collaborative workflows only. No autonomous SOC operations.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-5xl mx-auto space-y-5">
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Record Handoff</h3>
            <form method="POST" action="{{ route('soc.workflow.handoff.create') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Handoff To (optional)</label>
                    <select name="to_analyst_id" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5 w-full">
                        <option value="">Team / Next Shift</option>
                        @foreach($analysts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Shift Summary</label>
                    <textarea name="shift_summary" rows="4" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-2 w-full" placeholder="Summary of shift activity, key findings, open items..."></textarea>
                </div>
                <div>
                    <label class="text-xs text-gray-400 block mb-1">Additional Notes</label>
                    <textarea name="notes" rows="2" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-2 w-full" placeholder="Anything the next analyst should know..."></textarea>
                </div>
                <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Record Handoff</button>
            </form>
        </div>

        <div>
            <h3 class="text-sm font-semibold text-cyan-200 mb-2">Recent Handoffs</h3>
            @forelse($handoffs as $h)
            <div class="rounded border border-cyan-400/10 bg-gray-900/20 p-3 mb-2">
                <div class="flex gap-3 items-center text-xs mb-1">
                    <span class="font-mono text-cyan-400">{{ $h->handoff_id }}</span>
                    <span>{{ $h->fromAnalyst?->name ?? '?' }} → {{ $h->toAnalyst?->name ?? 'Team' }}</span>
                    <span class="ml-auto text-gray-500">{{ $h->created_at?->format('M d H:i') }}</span>
                </div>
                <div class="text-xs text-gray-400 space-x-4">
                    <span>{{ $h->unresolved_count }} unresolved</span>
                    <span>{{ $h->pending_escalation_count }} pending escalations</span>
                </div>
                @if($h->shift_summary)
                <div class="text-xs text-gray-300 mt-1">{{ Str::limit($h->shift_summary, 200) }}</div>
                @endif
            </div>
            @empty
            <p class="text-xs text-gray-500">No handoffs recorded.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
