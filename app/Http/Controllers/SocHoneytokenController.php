<?php

namespace App\Http\Controllers;

use App\Models\Honeytoken;
use App\Models\HoneytokenHit;
use App\Services\HoneytokenService;
use App\Services\TenantContextAuthority;
use Illuminate\Http\Request;

/**
 * CAP-DECEPTION-HONEYTOKEN: seed/list/deactivate honeytokens and review
 * hits. Purely detective — no offensive deployment, no active response.
 */
class SocHoneytokenController extends Controller
{
    public function __construct(
        private HoneytokenService $service,
        private TenantContextAuthority $tenantAuthority,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        $honeytokens = Honeytoken::when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();
        $hits = HoneytokenHit::when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('matched_at')
            ->limit(200)
            ->get();

        return view('honeytoken.index', compact('honeytokens', 'hits'));
    }

    public function store(Request $request)
    {
        $this->authorize('soc:honeytoken.manage');

        $data = $request->validate([
            'token_type' => 'required|in:'.implode(',', Honeytoken::TOKEN_TYPES),
            'token_value' => 'required|string|max:512',
            'label' => 'nullable|string|max:255',
        ]);

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        $this->service->create(
            $data['token_type'],
            $data['token_value'],
            $data['label'] ?? null,
            $tenantId,
            auth()->user()->email ?? 'system',
        );

        return redirect()->route('honeytoken.index')->with('success', 'Honeytoken created.');
    }

    public function deactivate(Request $request, string $honeytokenId)
    {
        $this->authorize('soc:honeytoken.manage');

        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user());

        $this->service->deactivate($honeytokenId, $tenantId);

        return redirect()->route('honeytoken.index')->with('success', 'Honeytoken deactivated.');
    }
}
