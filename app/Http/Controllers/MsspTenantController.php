<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\UserTenantMembership;
use App\Services\TenantHierarchyService;
use Illuminate\Http\Request;

/**
 * CAP-MSSP-TENANCY: parent/child tenant hierarchy management + read-only
 * rollup dashboard. Advisory only — no autonomous cross-tenant action.
 *
 * Non-admin callers must hold an active membership in the requested
 * parent tenant (via user_tenant_memberships, the existing tenancy
 * mechanism) — rollup access is not granted merely by holding the generic
 * mssp.rollup.view permission with no tenant tie at all.
 */
class MsspTenantController extends Controller
{
    public function __construct(private TenantHierarchyService $service) {}

    public function rollup(Request $request)
    {
        $parentTenantId = (string) $request->query('parent_tenant_id', '');
        $summary = [];
        $children = [];

        if ($parentTenantId !== '' && $this->userCanViewParent($request, $parentTenantId)) {
            $summary = $this->service->rollupSummary($parentTenantId);
            $children = $this->service->childrenOf($parentTenantId);
        }

        $parentTenants = $this->parentTenantsFor($request);

        return view('mssp.rollup', compact('parentTenantId', 'summary', 'children', 'parentTenants'));
    }

    public function linkChild(Request $request)
    {
        $this->authorize('soc:mssp.hierarchy.manage');

        $data = $request->validate([
            'parent_tenant_id' => 'required|string|max:255',
            'child_tenant_id' => 'required|string|max:255|different:parent_tenant_id',
        ]);

        $this->service->linkChild($data['parent_tenant_id'], $data['child_tenant_id'], auth()->id());

        return redirect()->route('mssp.rollup', ['parent_tenant_id' => $data['parent_tenant_id']])
            ->with('success', 'Tenant linked.');
    }

    public function unlinkChild(Request $request)
    {
        $this->authorize('soc:mssp.hierarchy.manage');

        $data = $request->validate([
            'parent_tenant_id' => 'required|string|max:255',
            'child_tenant_id' => 'required|string|max:255',
        ]);

        $this->service->unlinkChild($data['parent_tenant_id'], $data['child_tenant_id'], auth()->id());

        return redirect()->route('mssp.rollup', ['parent_tenant_id' => $data['parent_tenant_id']])
            ->with('success', 'Tenant unlinked.');
    }

    private function userCanViewParent(Request $request, string $parentTenantId): bool
    {
        $user = $request->user();
        if ($user->role === 'admin') {
            return true;
        }

        return UserTenantMembership::where('user_id', $user->id)
            ->where('tenant_id', $parentTenantId)
            ->where('is_active', true)
            ->exists();
    }

    private function parentTenantsFor(Request $request): array
    {
        $user = $request->user();
        if ($user->role === 'admin') {
            return Tenant::pluck('tenant_id')->all();
        }

        return UserTenantMembership::where('user_id', $user->id)
            ->where('is_active', true)
            ->pluck('tenant_id')
            ->all();
    }
}
