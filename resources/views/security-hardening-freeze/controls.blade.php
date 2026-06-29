<x-app-layout>
    <x-slot name="header"><h2 class="text-xl font-semibold">Control Checks</h2></x-slot>
    <div class="py-6 max-w-7xl mx-auto px-4">
        <div class="bg-yellow-50 border border-yellow-300 rounded p-3 mb-4 text-sm">
            Advisory only — displays evidence of implemented security controls.
        </div>
        <table class="w-full text-sm border">
            <thead class="bg-gray-50">
                <tr>
                    <th class="p-2 text-left">Control ID</th>
                    <th class="p-2">Category</th>
                    <th class="p-2">Result</th>
                    <th class="p-2 text-left">Detail</th>
                </tr>
            </thead>
            <tbody>
            @foreach($checks as $check)
            <tr class="border-t {{ $check->passed ? '' : 'bg-red-50' }}">
                <td class="p-2 font-mono text-xs">{{ $check->control_id }}</td>
                <td class="p-2 text-center text-xs">{{ $check->control_category }}</td>
                <td class="p-2 text-center font-semibold {{ $check->passed ? 'text-green-600' : 'text-red-600' }}">{{ $check->result }}</td>
                <td class="p-2 text-xs">{{ $check->detail }}</td>
            </tr>
            @endforeach
            @if($checks->isEmpty())
            <tr>
                <td colspan="4" class="p-4 text-center text-gray-400">
                    No checks recorded. Run <code>php artisan security:hardening-freeze</code>.
                </td>
            </tr>
            @endif
            </tbody>
        </table>
    </div>
</x-app-layout>
