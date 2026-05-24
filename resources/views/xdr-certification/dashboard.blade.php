<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Final XDR Readiness Certification — Dashboard
        </h2>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">

        <div class="bg-yellow-50 border border-yellow-300 rounded p-4 text-sm text-yellow-800">
            <strong>Advisory Only:</strong> XDR readiness certification workflows are bounded, replay-safe, and audit-visible.
            No autonomous go-live approval. No cutover without analyst sign-off. All workflows are advisory-only.
        </div>

        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-blue-600">{{ $stats['total_certifications'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Total Certifications</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-green-600">{{ $stats['passed_certifications'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Passed</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-indigo-600">{{ $stats['gates_passed'] }}/{{ $stats['total_gates'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Gates Passed</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-red-600">{{ $stats['critical_risks'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Critical Risks Open</div>
            </div>
            <div class="bg-white rounded shadow p-4 text-center">
                <div class="text-2xl font-bold text-purple-600">{{ $stats['go_live_passed'] }}/{{ $stats['go_live_validations'] }}</div>
                <div class="text-xs text-gray-500 mt-1">Go-Live Passed</div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-2">Recent Certifications</h3>
                @forelse($recentCertifications as $cert)
                    <div class="text-sm border-b py-1 flex justify-between">
                        <span class="font-mono text-xs text-gray-500">{{ substr($cert->certification_id, 0, 16) }}…</span>
                        <span class="text-xs font-medium {{ $cert->certification_state === 'passed' ? 'text-green-600' : 'text-gray-500' }}">
                            {{ $cert->certification_state }}
                        </span>
                    </div>
                @empty
                    <div class="text-xs text-gray-400">No certifications yet.</div>
                @endforelse
            </div>

            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-2">Open Risks</h3>
                @forelse($openRisks as $risk)
                    <div class="text-sm border-b py-1 flex justify-between">
                        <span class="text-xs text-gray-600">{{ $risk->risk_category }}</span>
                        <span class="text-xs font-medium {{ $risk->risk_severity === 'critical' ? 'text-red-600' : 'text-yellow-600' }}">
                            {{ $risk->risk_severity }}
                        </span>
                    </div>
                @empty
                    <div class="text-xs text-gray-400">No open risks.</div>
                @endforelse
            </div>

            <div class="bg-white rounded shadow p-4">
                <h3 class="font-semibold text-gray-700 mb-2">Recent Go-Live Validations</h3>
                @forelse($recentGoLive as $run)
                    <div class="text-sm border-b py-1 flex justify-between">
                        <span class="text-xs text-gray-600">{{ $run->validation_scope }}</span>
                        <span class="text-xs font-medium {{ $run->validation_state === 'passed' ? 'text-green-600' : 'text-red-600' }}">
                            {{ $run->validation_state }}
                        </span>
                    </div>
                @empty
                    <div class="text-xs text-gray-400">No go-live validations yet.</div>
                @endforelse
            </div>
        </div>

        <div class="bg-white rounded shadow p-4">
            <h3 class="font-semibold text-gray-700 mb-3">Navigation</h3>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                <a href="{{ route('xdr-cert.certifications') }}" class="text-sm text-blue-600 hover:underline">Certification Center</a>
                <a href="{{ route('xdr-cert.gates') }}" class="text-sm text-blue-600 hover:underline">Acceptance Gates</a>
                <a href="{{ route('xdr-cert.limitations') }}" class="text-sm text-blue-600 hover:underline">Limitation Register</a>
                <a href="{{ route('xdr-cert.risks') }}" class="text-sm text-blue-600 hover:underline">Risk Register</a>
                <a href="{{ route('xdr-cert.executive') }}" class="text-sm text-blue-600 hover:underline">Executive Reports</a>
                <a href="{{ route('xdr-cert.technical') }}" class="text-sm text-blue-600 hover:underline">Technical Reports</a>
                <a href="{{ route('xdr-cert.go-live') }}" class="text-sm text-blue-600 hover:underline">Go-Live Validations</a>
                <a href="{{ route('xdr-cert.audit') }}" class="text-sm text-blue-600 hover:underline">Audit Timeline</a>
            </div>
        </div>

    </div>
</x-app-layout>
