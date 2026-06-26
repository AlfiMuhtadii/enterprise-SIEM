<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Pilot Tenant: {{ $tenant_id }}
            <span class="ml-2 text-sm font-normal text-yellow-600">ADVISORY ONLY</span>
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">

            <div class="bg-white rounded shadow p-4 mb-6">
                <p class="text-sm font-semibold mb-2">Health Checks</p>
                <ul class="space-y-1 text-sm">
                    @foreach($health['checks'] as $key => $val)
                    <li>
                        <span class="font-mono text-gray-600">{{ $key }}:</span>
                        @if(is_bool($val))
                            {!! $val ? '<span class="text-green-600 font-medium">true</span>' : '<span class="text-yellow-600 font-medium">false</span>' !!}
                        @else
                            <span class="text-gray-800">{{ $val }}</span>
                        @endif
                    </li>
                    @endforeach
                </ul>
            </div>

            <div class="bg-white rounded shadow p-4">
                <p class="text-sm font-semibold mb-2">Onboarding Events</p>
                @if($events->isEmpty())
                    <p class="text-sm text-gray-400">No events recorded.</p>
                @else
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 border-b">
                            <tr>
                                <th class="px-3 py-2 text-left">Event Type</th>
                                <th class="px-3 py-2 text-left">Status</th>
                                <th class="px-3 py-2 text-left">Details</th>
                                <th class="px-3 py-2 text-left">Time</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach($events as $ev)
                            <tr>
                                <td class="px-3 py-2 font-mono text-xs">{{ $ev->event_type }}</td>
                                <td class="px-3 py-2">
                                    <span class="px-2 py-0.5 rounded text-xs {{ $ev->status === 'ok' ? 'bg-green-100 text-green-700' : 'bg-yellow-100 text-yellow-700' }}">{{ $ev->status }}</span>
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-600">{{ $ev->details }}</td>
                                <td class="px-3 py-2 text-xs text-gray-400">{{ $ev->occurred_at }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

        </div>
    </div>
</x-app-layout>
