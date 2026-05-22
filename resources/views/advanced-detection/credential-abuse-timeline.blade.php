<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Credential Abuse Timeline</h2>
        <p class="text-xs text-amber-400/80 mt-1">Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            Advanced detections are advisory-only, replay-safe, and evidence-linked. No autonomous enforcement or offensive action is executed.
        </div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto">
            <table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700">
                    <th class="text-left py-1">Timeline ID</th><th class="text-left py-1">Technique</th><th class="text-left py-1">Event Type</th><th class="text-left py-1">Host</th><th class="text-left py-1">Actor</th><th class="text-left py-1">Seq</th><th class="text-left py-1">Occurred</th>
                </tr></thead>
                <tbody>
                @foreach($events as $e)
                <tr class="border-b border-slate-800">
                    <td class="py-1 font-mono">{{ $e->timeline_id }}</td>
                    <td class="py-1">{{ $e->technique_id ?? '—' }}</td>
                    <td class="py-1">{{ $e->event_type }}</td>
                    <td class="py-1">{{ $e->host_id ?? '—' }}</td>
                    <td class="py-1">{{ $e->actor ?? '—' }}</td>
                    <td class="py-1">{{ $e->sequence_index }}</td>
                    <td class="py-1">{{ $e->occurred_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        </div>
    </div>
</x-app-layout>
