<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl">Commercial Product Audit Timeline</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-blue-50 border border-blue-300 rounded p-3 mb-6 text-sm text-blue-800">
            Commercial readiness workflows are bounded, replay-safe, and audit-visible. No destructive support action, hidden telemetry collection, or autonomous remediation is executed.
        </div>
        <div class="bg-white rounded shadow overflow-hidden">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">Audit ID</th>
                        <th class="px-4 py-2 text-left">Event Type</th>
                        <th class="px-4 py-2 text-left">Actor</th>
                        <th class="px-4 py-2 text-left">Target</th>
                        <th class="px-4 py-2 text-left">Autonomous</th>
                        <th class="px-4 py-2 text-left">Destructive</th>
                        <th class="px-4 py-2 text-left">Replay Safe</th>
                        <th class="px-4 py-2 text-left">Time</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($events as $event)
                    <tr class="border-t">
                        <td class="px-4 py-2 font-mono text-xs">{{ $event->commercial_audit_id }}</td>
                        <td class="px-4 py-2">{{ $event->audit_event_type }}</td>
                        <td class="px-4 py-2">{{ $event->actor_type }}</td>
                        <td class="px-4 py-2 text-gray-600">{{ $event->target_entity ?? '—' }}</td>
                        <td class="px-4 py-2 text-{{ $event->autonomous_action_taken ? 'red' : 'green' }}-700">{{ $event->autonomous_action_taken ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-{{ $event->destructive_action_taken ? 'red' : 'green' }}-700">{{ $event->destructive_action_taken ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2">{{ $event->replay_safe ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-2 text-gray-500">{{ $event->created_at->diffForHumans() }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $events->links() }}</div>
    </div>
</x-app-layout>
