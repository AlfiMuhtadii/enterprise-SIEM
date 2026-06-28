<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Redpanda Recovery Events</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4">
        <div class="bg-white shadow rounded p-5">
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">Event ID</th><th class="text-left py-1">Type</th><th class="text-left py-1">Topic/Group</th><th class="py-1">Outcome</th><th class="text-left py-1">Detail</th><th class="text-left py-1">Date</th></tr></thead>
                <tbody>
                @forelse($events as $event)
                <tr class="border-b">
                    <td class="py-1 font-mono text-xs">{{ $event->event_id }}</td>
                    <td class="py-1 text-xs font-mono">{{ $event->event_type }}</td>
                    <td class="py-1 text-xs">{{ $event->affected_topic ?? $event->affected_group ?? '—' }}</td>
                    <td class="py-1 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $event->outcome === 'SUCCESS' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ $event->outcome }}</span></td>
                    <td class="py-1 text-xs text-gray-600">{{ $event->detail }}</td>
                    <td class="py-1 text-xs text-gray-500">{{ $event->created_at }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="py-4 text-center text-gray-500">No recovery events recorded.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
