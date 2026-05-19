<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Notification Center</h2>
        <p class="text-xs text-amber-400/80 mt-1 font-medium">Simulated by default. No live delivery without explicit analyst enablement.</p>
    </x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            All notifications are simulated (no actual dispatch) in advisory mode.
            Escalation and SLA breach notifications require analyst approval before dispatch.
            No autonomous account suspension via notification channels.
        </div>
        <div class="grid grid-cols-3 gap-4">
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Total Notifications</div>
                <div class="text-2xl font-bold text-amber-300">{{ $notifications->count() }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Pending Analyst Approval</div>
                <div class="text-2xl font-bold text-red-400">{{ $pending }}</div>
            </div>
            <div class="glass-card p-4">
                <div class="text-xs text-gray-400 uppercase mb-1">Dispatch Mode</div>
                <div class="text-sm font-bold text-amber-300">Simulated / Advisory</div>
            </div>
        </div>
        <div class="glass-card p-4">
            <h3 class="text-sm font-semibold text-cyan-200 mb-3">Notification Events</h3>
            <table class="w-full text-xs">
                <thead><tr class="text-gray-400 uppercase text-left border-b border-gray-700">
                    <th class="pb-2 pr-4">Time</th>
                    <th class="pb-2 pr-4">Type</th>
                    <th class="pb-2 pr-4">Channel</th>
                    <th class="pb-2 pr-4">Severity</th>
                    <th class="pb-2 pr-4">Subject</th>
                    <th class="pb-2 pr-4">Approval Required</th>
                    <th class="pb-2">Deliveries</th>
                </tr></thead>
                <tbody>
                @forelse($notifications as $notif)
                <tr class="border-b border-gray-800 hover:bg-gray-800/30">
                    <td class="py-1.5 pr-4 text-gray-500">{{ $notif->created_at?->diffForHumans() }}</td>
                    <td class="py-1.5 pr-4 text-blue-300">{{ $notif->notification_type }}</td>
                    <td class="py-1.5 pr-4 text-purple-300">{{ $notif->channel }}</td>
                    <td class="py-1.5 pr-4">
                        <span class="px-2 py-0.5 rounded text-xs font-medium
                            {{ $notif->severity === 'critical' ? 'bg-red-900/40 text-red-300' : ($notif->severity === 'high' ? 'bg-orange-900/40 text-orange-300' : 'bg-gray-700 text-gray-400') }}">
                            {{ $notif->severity }}
                        </span>
                    </td>
                    <td class="py-1.5 pr-4 text-gray-300 max-w-xs truncate">{{ $notif->subject }}</td>
                    <td class="py-1.5 pr-4">
                        @if($notif->requires_analyst_approval)
                        <span class="px-2 py-0.5 rounded bg-amber-900/40 text-amber-300 text-xs">Yes</span>
                        @else
                        <span class="text-gray-600">No</span>
                        @endif
                    </td>
                    <td class="py-1.5 text-gray-500">{{ $notif->deliveries->count() }} deliveries</td>
                </tr>
                @empty
                <tr><td colspan="7" class="py-4 text-center text-gray-500">No notifications sent.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
