<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Response Audit Explorer</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Controlled manual response only. No autonomous containment.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-6xl mx-auto space-y-5">
        <form method="GET" class="flex gap-3 items-center">
            <select name="action_type" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                <option value="">All Actions</option>
                @foreach(\App\Models\ResponseExecution::ALLOWED_ACTIONS as $action)
                    <option value="{{ $action }}" @selected($action === $actionType)>{{ str_replace('_', ' ', $action) }}</option>
                @endforeach
            </select>
            <select name="status" class="bg-gray-800 border border-gray-600 text-gray-200 text-sm rounded px-3 py-1.5">
                <option value="">All Statuses</option>
                @foreach(\App\Models\ResponseExecution::STATUSES as $s)
                    <option value="{{ $s }}" @selected($s === $status)>{{ str_replace('_', ' ', $s) }}</option>
                @endforeach
            </select>
            <button type="submit" class="px-4 py-1.5 rounded bg-cyan-700/40 border border-cyan-400/30 text-cyan-200 text-sm">Filter</button>
        </form>

        @if($executions->isEmpty())
            <p class="text-sm text-gray-500">No executions found.</p>
        @else
        <div class="overflow-x-auto">
        <table class="w-full text-xs text-left text-gray-300">
            <thead class="text-gray-400 uppercase border-b border-gray-700">
                <tr>
                    <th class="py-2 pr-3">ID</th><th class="py-2 pr-3">Action</th><th class="py-2 pr-3">Target</th>
                    <th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Creator</th>
                    <th class="py-2 pr-3">Blast</th><th class="py-2">Created</th>
                </tr>
            </thead>
            <tbody>
                @foreach($executions as $e)
                <tr class="border-b border-gray-800 hover:bg-gray-800/20">
                    <td class="py-1.5 pr-3"><a href="{{ route('active-response.show', $e->execution_id) }}" class="text-cyan-400 hover:underline font-mono">{{ $e->execution_id }}</a></td>
                    <td class="py-1.5 pr-3">{{ str_replace('_', ' ', $e->action_type) }}</td>
                    <td class="py-1.5 pr-3 font-mono">{{ $e->target_entity_key }}</td>
                    <td class="py-1.5 pr-3"><span class="px-1.5 py-0.5 rounded bg-gray-700 text-gray-300">{{ str_replace('_', ' ', $e->status) }}</span></td>
                    <td class="py-1.5 pr-3">{{ $e->creator?->name }}</td>
                    <td class="py-1.5 pr-3">{{ number_format($e->blast_radius_score * 100) }}%</td>
                    <td class="py-1.5 text-gray-500">{{ $e->created_at?->format('Y-m-d H:i') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
        @endif
    </div>
</x-app-layout>
