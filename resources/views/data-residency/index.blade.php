<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-cyan-100 leading-tight">Data Residency &amp; GDPR Erasure</h2>
        <p class="text-xs text-amber-400/80 mt-1">Real deletion happens only via <code>php artisan data-erasure:execute</code> — approving a request here never deletes data by itself.</p>
    </x-slot>

    @if (session('status'))
        <div class="mx-4 mt-4 max-w-7xl mx-auto rounded-lg border border-cyan-200/20 bg-cyan-100/10 p-3 text-sm text-cyan-50">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="mx-4 mt-4 max-w-7xl mx-auto rounded-lg border border-red-400/30 bg-red-900/10 p-3 text-sm text-red-300">{{ $errors->first() }}</div>
    @endif

    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">
            advisory-gated workflow — erasure requests require an approver distinct from the requester (self-approval blocked), and approval only changes status. Execution is a separate CLI step.
        </div>

        @if (in_array(Auth::user()?->role, ['admin'], true))
        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Retention Policy (Tenant: {{ $tenantId }})</h3>
            <p class="mt-1 text-xs text-muted-ui">Leave a field blank to fall back to the global default (security:retention command options).</p>
            <form method="POST" action="{{ route('data-residency.policy.update') }}" class="mt-3 grid gap-2 md:grid-cols-3">
                @csrf
                <input type="number" name="events_days" min="1" value="{{ $policy->events_days ?? '' }}" placeholder="events days (global only)" disabled class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50 opacity-50">
                <input type="number" name="alerts_days" min="1" value="{{ $policy->alerts_days ?? '' }}" placeholder="alerts days" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <input type="number" name="incidents_days" min="1" value="{{ $policy->incidents_days ?? '' }}" placeholder="incidents days" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50">
                <button class="md:col-span-3 rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Save Policy</button>
            </form>
        </section>
        @endif

        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Request Erasure</h3>
            <form method="POST" action="{{ route('data-residency.erasure.request') }}" class="mt-3 grid gap-2">
                @csrf
                <textarea name="reason" required placeholder="Reason for erasure request" class="rounded border border-cyan-200/30 bg-slate-950 px-3 py-2 text-sm text-cyan-50"></textarea>
                <label class="flex items-center gap-2 text-xs text-muted-ui">
                    <input type="checkbox" name="dry_run" value="1" checked> Dry-run (count only, no deletion — recommended first step)
                </label>
                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-3 py-2 text-sm text-cyan-50">Submit Request</button>
            </form>
        </section>

        <section class="glass-card p-5">
            <h3 class="text-lg font-semibold text-main-ui">Erasure Requests (Tenant: {{ $tenantId }})</h3>
            @if ($requests->isEmpty())
                <p class="mt-3 text-xs text-muted-ui">No erasure requests yet.</p>
            @else
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-xs text-cyan-50">
                    <thead><tr class="text-cyan-200/60 border-b border-cyan-200/20">
                        <th class="text-left py-1">Request ID</th>
                        <th class="text-left py-1">Status</th>
                        <th class="text-left py-1">Requested By</th>
                        <th class="text-left py-1">Dry Run</th>
                        <th class="text-left py-1">Reason</th>
                        @if (in_array(Auth::user()?->role, ['admin'], true))
                        <th class="text-left py-1">Actions</th>
                        @endif
                    </tr></thead>
                    <tbody>
                    @foreach ($requests as $req)
                    <tr class="border-b border-cyan-200/10">
                        <td class="py-1 mono-ui">{{ $req->request_id }}</td>
                        <td class="py-1">{{ $req->status }}</td>
                        <td class="py-1">{{ $req->requested_by }}</td>
                        <td class="py-1">{{ $req->dry_run ? 'yes' : 'no' }}</td>
                        <td class="py-1">{{ \Illuminate\Support\Str::limit($req->reason, 60) }}</td>
                        @if (in_array(Auth::user()?->role, ['admin'], true))
                        <td class="py-1">
                            @if ($req->status === 'pending')
                            <form method="POST" action="{{ route('data-residency.erasure.approve', $req->id) }}" class="inline">
                                @csrf
                                <button class="rounded border border-cyan-200/20 bg-cyan-100/10 px-2 py-1 text-xs text-cyan-50">Approve</button>
                            </form>
                            <form method="POST" action="{{ route('data-residency.erasure.reject', $req->id) }}" class="inline">
                                @csrf
                                <button class="rounded border border-red-400/30 bg-red-900/10 px-2 py-1 text-xs text-red-300">Reject</button>
                            </form>
                            @endif
                        </td>
                        @endif
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </section>
    </div>
</x-app-layout>
