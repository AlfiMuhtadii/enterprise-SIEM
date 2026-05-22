<x-app-layout>
    <x-slot name="header"><h2 class="font-semibold text-xl text-cyan-100 leading-tight">Package Signature Validation Viewer</h2><p class="text-xs text-amber-400/80 mt-1">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</p></x-slot>
    <div class="py-6 px-4 max-w-7xl mx-auto space-y-6">
        <div class="rounded border border-amber-400/30 bg-amber-900/10 px-4 py-3 text-sm text-amber-300">Sensor governance workflows are advisory-only and replay-safe. No autonomous remediation, kernel enforcement, or destructive endpoint action is executed.</div>
        <div class="rounded border border-slate-700 bg-slate-800/50 p-4">
            <div class="overflow-x-auto"><table class="w-full text-xs text-slate-300">
                <thead><tr class="text-slate-500 border-b border-slate-700"><th class="text-left py-1">Validation ID</th><th class="text-left py-1">Package</th><th class="text-left py-1">Version</th><th class="text-left py-1">Verdict</th><th class="text-left py-1">Sig Valid</th><th class="text-left py-1">Hash Valid</th><th class="text-left py-1">Signer</th><th class="text-left py-1">By</th><th class="text-left py-1">At</th></tr></thead>
                <tbody>@foreach($validations as $v)<tr class="border-b border-slate-800"><td class="py-1 font-mono">{{ $v->validation_id }}</td><td class="py-1">{{ $v->package_name }}</td><td class="py-1">{{ $v->package_version }}</td><td class="py-1"><span class="px-1 rounded text-xs {{ $v->verdict === 'pass' ? 'bg-green-800 text-green-200' : 'bg-red-800 text-red-200' }}">{{ $v->verdict }}</span></td><td class="py-1">{{ $v->signature_valid ? 'Y' : 'N' }}</td><td class="py-1">{{ $v->hash_valid ? 'Y' : 'N' }}</td><td class="py-1">{{ $v->signer ?? '—' }}</td><td class="py-1">{{ $v->validated_by }}</td><td class="py-1">{{ $v->created_at }}</td></tr>@endforeach</tbody>
            </table></div>
        </div>
    </div>
</x-app-layout>
