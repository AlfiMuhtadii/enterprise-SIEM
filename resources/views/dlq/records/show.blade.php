<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            DLQ Record — {{ $record->dlq_event_type }}
        </h2>
    </x-slot>

    <div class="py-8 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">

        @if (session('success'))
        <div class="bg-green-100 border border-green-300 text-green-800 text-sm rounded p-3">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
        <div class="bg-red-100 border border-red-300 text-red-800 text-sm rounded p-3">{{ $errors->first() }}</div>
        @endif

        {{-- Record details --}}
        <div class="bg-white rounded shadow p-6 space-y-4">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="font-semibold text-gray-800">Record Details</h3>
                    <p class="text-xs text-gray-400 font-mono">{{ $record->record_id }}</p>
                </div>
                <span class="px-2 py-1 rounded text-sm font-medium
                    {{ $record->status === 'new' ? 'bg-yellow-100 text-yellow-700' :
                       ($record->status === 'reviewed' ? 'bg-blue-100 text-blue-700' :
                       ($record->status === 'ignored' ? 'bg-gray-100 text-gray-500' :
                       ($record->status === 'replay_requested' ? 'bg-purple-100 text-purple-700' :
                       ($record->status === 'replayed' ? 'bg-green-100 text-green-700' :
                       ($record->status === 'replay_failed' ? 'bg-red-100 text-red-700' :
                       'bg-gray-100 text-gray-600'))))) }}">
                    {{ $record->status }}
                </span>
            </div>

            <div class="grid grid-cols-2 gap-4 text-sm">
                <div><span class="text-gray-500">Event Type</span><br><strong>{{ $record->dlq_event_type }}</strong></div>
                <div><span class="text-gray-500">Source Topic</span><br><strong>{{ $record->source_topic ?? '—' }}</strong></div>
                <div><span class="text-gray-500">Partition / Offset</span><br>
                    @if ($record->source_partition !== null)
                    <strong>{{ $record->source_partition }} / {{ $record->source_offset }}</strong>
                    @else
                    <span class="text-gray-400">N/A</span>
                    @endif
                </div>
                <div><span class="text-gray-500">Consumer Group</span><br><strong>{{ $record->consumer_group ?? '—' }}</strong></div>
                <div><span class="text-gray-500">Error Code</span><br><strong>{{ $record->error_code ?? '—' }}</strong></div>
                <div><span class="text-gray-500">Tenant</span><br><strong>{{ $record->tenant_id ?? '—' }}</strong></div>
                <div><span class="text-gray-500">Occurrences</span><br><strong>{{ $record->occurrence_count }}</strong></div>
                <div><span class="text-gray-500">Isolated At</span><br><strong>{{ $record->isolated_at?->format('Y-m-d H:i:s') ?? '—' }}</strong></div>
                <div><span class="text-gray-500">First Seen</span><br><strong>{{ $record->first_seen_at?->format('Y-m-d H:i:s') }}</strong></div>
                <div><span class="text-gray-500">Last Seen</span><br><strong>{{ $record->last_seen_at?->diffForHumans() }}</strong></div>
            </div>

            @if ($record->error_message)
            <div>
                <span class="text-xs text-gray-500">Error Message</span>
                <pre class="mt-1 bg-red-50 text-red-800 text-xs rounded p-3 overflow-x-auto whitespace-pre-wrap">{{ $record->error_message }}</pre>
            </div>
            @endif

            @if ($record->review_note)
            <div>
                <span class="text-xs text-gray-500">Review Note</span>
                <p class="mt-1 text-sm text-gray-700 bg-gray-50 rounded p-2">{{ $record->review_note }}</p>
            </div>
            @endif

            @if ($record->replayed_at)
            <div class="text-xs text-green-700 bg-green-50 rounded p-2">
                Replayed at {{ $record->replayed_at->format('Y-m-d H:i:s') }}
            </div>
            @endif

            @if ($record->status === 'replay_failed' && $record->replay_result)
            <div>
                <span class="text-xs text-red-600">Replay failure details</span>
                <pre class="mt-1 bg-red-50 text-red-800 text-xs rounded p-2 overflow-x-auto">{{ json_encode($record->replay_result, JSON_PRETTY_PRINT) }}</pre>
            </div>
            @endif
        </div>

        {{-- Raw payload --}}
        @if ($record->raw_payload)
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">Raw Payload <span class="text-xs font-normal text-green-600">(replayable)</span></h3>
            <pre class="bg-gray-50 text-xs rounded p-3 overflow-auto max-h-64">{{ json_encode($record->raw_payload, JSON_PRETTY_PRINT) }}</pre>
        </div>
        @else
        <div class="bg-yellow-50 border border-yellow-200 rounded p-4 text-sm text-yellow-800">
            <strong>No raw payload available.</strong>
            Poison messages (Pandaproxy serialization failures) cannot be replayed — the raw bytes were not recoverable.
            Review the source offset coordinates above and investigate via Redpanda Console if needed.
        </div>
        @endif

        {{-- Analyst action form --}}
        @can('soc:dlq.review')
        @if ($record->isActionable() || $record->status === 'replay_failed')
        <div class="bg-white rounded shadow p-6">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">Analyst Action</h3>
            <form method="POST" action="{{ route('dlq.records.review', $record->record_id) }}">
                @csrf
                <div class="space-y-4">
                    <div class="flex flex-wrap gap-4">
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="radio" name="action" value="reviewed" required> Mark Reviewed
                        </label>
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="radio" name="action" value="ignored"> Ignore (dismiss as noise)
                        </label>
                        @if ($record->isReplayable())
                        <label class="flex items-center gap-2 text-sm cursor-pointer">
                            <input type="radio" name="action" value="replay_requested"> Request Replay
                        </label>
                        @else
                        <span class="text-sm text-gray-400 italic">Replay unavailable ({{ $record->raw_payload === null ? 'no raw payload' : 'terminal status' }})</span>
                        @endif
                    </div>

                    @if ($record->isReplayable())
                    <p class="text-xs text-yellow-700 bg-yellow-50 rounded p-2">
                        Requesting replay marks this record for processing by <code>php artisan dlq:replay</code>.
                        Replay re-submits the raw event to the normalizer safe path — it does not create incidents automatically.
                    </p>
                    @endif

                    <div>
                        <label class="block text-xs text-gray-600 mb-1">Note (optional)</label>
                        <textarea name="note" rows="2" maxlength="1000" class="w-full border rounded px-3 py-2 text-sm" placeholder="Analysis notes…"></textarea>
                    </div>

                    <button type="submit" class="bg-blue-600 text-white px-4 py-2 rounded text-sm hover:bg-blue-700">Submit</button>
                </div>
            </form>
        </div>
        @endif
        @endcan

        {{-- Audit trail --}}
        <div class="bg-white rounded shadow p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">Audit Trail</h3>
            @if ($events->isEmpty())
            <p class="text-sm text-gray-400">No events recorded.</p>
            @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach ($events as $event)
                <li class="py-2 flex items-start gap-3">
                    <span class="text-xs text-gray-400 whitespace-nowrap mt-0.5">{{ $event->created_at?->format('Y-m-d H:i:s') }}</span>
                    <span class="px-1.5 py-0.5 rounded text-xs bg-gray-100 text-gray-600 whitespace-nowrap">{{ $event->event_type }}</span>
                    @if ($event->analyst_id)
                    <span class="text-xs text-gray-500">analyst #{{ $event->analyst_id }}</span>
                    @endif
                    @if ($event->metadata)
                    <span class="text-xs text-gray-400 truncate">{{ json_encode($event->metadata) }}</span>
                    @endif
                </li>
                @endforeach
            </ul>
            @endif
        </div>

        <div class="flex gap-3">
            <a href="{{ route('dlq.records.index') }}" class="text-sm text-blue-600 underline">← Back to DLQ list</a>
        </div>

    </div>
</x-app-layout>
