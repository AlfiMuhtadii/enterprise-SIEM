<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Redpanda Health &amp; Recovery</h2></x-slot>
    <div class="py-6 max-w-5xl mx-auto px-4">
        @if(session('status'))
            <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">{{ session('status') }}</div>
        @endif

        <div class="flex justify-between items-center mb-6">
            <p class="text-gray-600">Advisory-only topic bootstrap and runtime recovery monitoring.</p>
            <form method="POST" action="{{ route('soc.redpanda.health.check') }}">
                @csrf
                <button class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700">Run Health Check</button>
            </form>
        </div>

        <div class="grid grid-cols-2 gap-6 mb-6">
            <div class="bg-white shadow rounded p-5">
                <h3 class="font-semibold mb-3">Expected Topics ({{ count($expectedTopics) }})</h3>
                <ul class="text-sm space-y-1">
                @foreach($expectedTopics as $topic)
                    <li class="font-mono text-gray-700">{{ $topic }}</li>
                @endforeach
                </ul>
            </div>
            <div class="bg-white shadow rounded p-5">
                <h3 class="font-semibold mb-3">Expected Consumer Groups ({{ count($expectedGroups) }})</h3>
                <ul class="text-sm space-y-1">
                @foreach($expectedGroups as $group)
                    <li class="font-mono text-gray-700">{{ $group }}</li>
                @endforeach
                </ul>
            </div>
        </div>

        @if($topicHistory->count())
        <div class="bg-white shadow rounded p-5 mb-6">
            <h3 class="font-semibold mb-3">Recent Topic Health Runs</h3>
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">Run ID</th><th class="py-1">Expected</th><th class="py-1">Found</th><th class="py-1">Missing</th><th class="py-1">Status</th><th class="text-left py-1">Date</th></tr></thead>
                <tbody>
                @foreach($topicHistory as $row)
                <tr class="border-b">
                    <td class="py-1 font-mono text-xs">{{ $row->run_id }}</td>
                    <td class="py-1 text-center">{{ $row->topics_expected }}</td>
                    <td class="py-1 text-center text-green-600">{{ $row->topics_found }}</td>
                    <td class="py-1 text-center {{ $row->topics_missing > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ $row->topics_missing }}</td>
                    <td class="py-1 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $row->overall_status === 'PASS' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ $row->overall_status }}</span></td>
                    <td class="py-1 text-xs text-gray-500">{{ $row->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif

        @if($recoveryEvents->count())
        <div class="bg-white shadow rounded p-5">
            <div class="flex justify-between items-center mb-3">
                <h3 class="font-semibold">Recovery Events</h3>
                <a href="{{ route('soc.redpanda.health.events') }}" class="text-sm text-blue-600 hover:underline">View all</a>
            </div>
            <table class="w-full text-sm">
                <thead><tr class="border-b"><th class="text-left py-1">Type</th><th class="text-left py-1">Topic / Group</th><th class="py-1">Outcome</th><th class="text-left py-1">Date</th></tr></thead>
                <tbody>
                @foreach($recoveryEvents->take(10) as $event)
                <tr class="border-b">
                    <td class="py-1 text-xs font-mono">{{ $event->event_type }}</td>
                    <td class="py-1 text-xs">{{ $event->affected_topic ?? $event->affected_group ?? '—' }}</td>
                    <td class="py-1 text-center"><span class="px-2 py-0.5 rounded text-xs {{ $event->outcome === 'SUCCESS' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ $event->outcome }}</span></td>
                    <td class="py-1 text-xs text-gray-500">{{ $event->created_at }}</td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </div>
</x-app-layout>
