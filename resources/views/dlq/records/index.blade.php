<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            DLQ Review — Normalization Failures
        </h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        {{-- Summary bar --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-8 gap-3">
            @foreach([
                ['label' => 'Total',            'key' => 'total',            'color' => 'gray'],
                ['label' => 'New',              'key' => 'new',              'color' => 'yellow'],
                ['label' => 'Reviewed',         'key' => 'reviewed',         'color' => 'blue'],
                ['label' => 'Ignored',          'key' => 'ignored',          'color' => 'gray'],
                ['label' => 'Replay Requested', 'key' => 'replay_requested', 'color' => 'purple'],
                ['label' => 'Replayed',         'key' => 'replayed',         'color' => 'green'],
                ['label' => 'Replay Failed',    'key' => 'replay_failed',    'color' => 'red'],
                ['label' => 'Replayable',       'key' => 'replayable',       'color' => 'indigo'],
            ] as $item)
            <div class="bg-white rounded shadow p-3 text-center">
                <div class="text-2xl font-bold text-{{ $item['color'] }}-600">{{ $summary[$item['key']] ?? 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">{{ $item['label'] }}</div>
            </div>
            @endforeach
        </div>

        {{-- Filters --}}
        <form method="GET" class="bg-white rounded shadow p-4 flex flex-wrap gap-3 items-end">
            <div>
                <label class="block text-xs text-gray-600 mb-1">Status</label>
                <select name="status" class="border rounded px-2 py-1 text-sm">
                    <option value="">All</option>
                    @foreach(['new','reviewed','ignored','replay_requested','replayed','replay_failed'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Event Type</label>
                <select name="dlq_event_type" class="border rounded px-2 py-1 text-sm">
                    <option value="">All</option>
                    @foreach(['poison_message_isolated','malformed_record','normalization_failure','invalid_json','publish_failure','unknown'] as $t)
                    <option value="{{ $t }}" @selected(($filters['dlq_event_type'] ?? '') === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Source Topic</label>
                <input type="text" name="source_topic" value="{{ $filters['source_topic'] ?? '' }}" placeholder="telemetry.raw" class="border rounded px-2 py-1 text-sm w-40">
            </div>
            <div>
                <label class="block text-xs text-gray-600 mb-1">Tenant</label>
                <input type="text" name="tenant_id" value="{{ $filters['tenant_id'] ?? '' }}" placeholder="tenant-id" class="border rounded px-2 py-1 text-sm w-32">
            </div>
            <div class="flex items-center gap-2">
                <label class="text-xs text-gray-600"><input type="checkbox" name="replayable" value="1" @checked(!empty($filters['replayable']))> Replayable only</label>
            </div>
            <button type="submit" class="bg-blue-600 text-white px-3 py-1 rounded text-sm">Filter</button>
            <a href="{{ route('dlq.records.index') }}" class="text-sm text-gray-500 underline self-center">Clear</a>
        </form>

        {{-- Records table --}}
        <div class="bg-white rounded shadow overflow-x-auto">
            @if ($records->isEmpty())
            <div class="p-8 text-center text-gray-400">
                No DLQ records found.
                @if (empty($filters['status']) && empty($filters['dlq_event_type']))
                <p class="text-xs mt-2">DLQ consumer requires <code class="bg-gray-100 px-1">XDR_DLQ_CONSUMER_ENABLED=true</code> in alert-writer-service.</p>
                @endif
            </div>
            @else
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-gray-600 text-xs uppercase">
                    <tr>
                        <th class="px-4 py-2 text-left">Record ID</th>
                        <th class="px-4 py-2 text-left">Event Type</th>
                        <th class="px-4 py-2 text-left">Source Topic</th>
                        <th class="px-4 py-2 text-left">Error</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Occurrences</th>
                        <th class="px-4 py-2 text-left">Last Seen</th>
                        <th class="px-4 py-2 text-left">Replay</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($records as $record)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs text-gray-500">{{ Str::limit($record->record_id, 20) }}</td>
                        <td class="px-4 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs font-medium
                                {{ $record->dlq_event_type === 'poison_message_isolated' ? 'bg-red-100 text-red-700' :
                                   ($record->dlq_event_type === 'normalization_failure' ? 'bg-orange-100 text-orange-700' :
                                   'bg-gray-100 text-gray-600') }}">
                                {{ $record->dlq_event_type }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $record->source_topic ?? '—' }}</td>
                        <td class="px-4 py-2 text-xs text-gray-600 max-w-xs truncate" title="{{ $record->error_message }}">
                            {{ $record->error_message ? Str::limit($record->error_message, 60) : '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <span class="px-1.5 py-0.5 rounded text-xs font-medium
                                {{ $record->status === 'new' ? 'bg-yellow-100 text-yellow-700' :
                                   ($record->status === 'reviewed' ? 'bg-blue-100 text-blue-700' :
                                   ($record->status === 'ignored' ? 'bg-gray-100 text-gray-500' :
                                   ($record->status === 'replay_requested' ? 'bg-purple-100 text-purple-700' :
                                   ($record->status === 'replayed' ? 'bg-green-100 text-green-700' :
                                   ($record->status === 'replay_failed' ? 'bg-red-100 text-red-700' :
                                   'bg-gray-100 text-gray-600'))))) }}">
                                {{ $record->status }}
                            </span>
                        </td>
                        <td class="px-4 py-2 text-center">{{ $record->occurrence_count }}</td>
                        <td class="px-4 py-2 text-xs text-gray-500">{{ $record->last_seen_at?->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-center text-xs">
                            {{ $record->raw_payload !== null ? '✓' : '✗ poison' }}
                        </td>
                        <td class="px-4 py-2">
                            <a href="{{ route('dlq.records.show', $record->record_id) }}" class="text-blue-600 text-xs underline">View</a>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3">{{ $records->withQueryString()->links() }}</div>
            @endif
        </div>

        {{-- By event type breakdown --}}
        @if (!empty($summary['by_type']))
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Failure Type Breakdown</h3>
            <div class="flex flex-wrap gap-4">
                @foreach ($summary['by_type'] as $type => $count)
                <div class="text-sm"><span class="font-mono text-gray-500">{{ $type }}</span>: <strong>{{ $count }}</strong></div>
                @endforeach
            </div>
        </div>
        @endif

        <div class="text-xs text-gray-400 bg-gray-50 rounded p-3">
            Replay is only available for records with a recoverable raw payload (not poison messages).
            Use <code>php artisan dlq:replay</code> to execute replay_requested records through the normalizer safe path.
            Replay does not create incidents automatically.
        </div>

    </div>
</x-app-layout>
