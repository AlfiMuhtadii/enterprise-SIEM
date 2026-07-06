<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantHierarchy;
use Illuminate\Support\Facades\DB;

/**
 * CAP-MSSP-TENANCY: parent (MSSP) -> child (customer) tenant hierarchy and
 * read-only rollup views. Advisory only — no autonomous cross-tenant
 * action is ever driven from this service.
 *
 * Deliberately does NOT reuse TenantBoundaryService's null-tenant-context
 * convention (a null tenant_id there means "unscoped", which every role
 * already receives by default when no X-Tenant-ID header is sent — an
 * existing accidental cross-tenant leak, not something to build on top
 * of). Rollups here are driven exclusively by an explicit, enumerated
 * whitelist of child tenant IDs from tenant_hierarchy — a tenant that
 * isn't an active, linked child is never included, full stop.
 */
class TenantHierarchyService
{
    public function registerTenant(string $tenantId, ?string $name = null): Tenant
    {
        return Tenant::updateOrCreate(
            ['tenant_id' => $tenantId],
            array_filter(['name' => $name], fn ($v) => $v !== null),
        );
    }

    public function linkChild(string $parentTenantId, string $childTenantId, ?int $actorId = null): TenantHierarchy
    {
        if ($parentTenantId === $childTenantId) {
            throw new \InvalidArgumentException('A tenant cannot be its own MSSP parent.');
        }

        $this->registerTenant($parentTenantId);
        $this->registerTenant($childTenantId);

        $now = now();

        return TenantHierarchy::updateOrCreate(
            ['parent_tenant_id' => $parentTenantId, 'child_tenant_id' => $childTenantId],
            [
                'is_active' => true,
                'linked_by' => $actorId,
                'linked_at' => $now,
                'unlinked_by' => null,
                'unlinked_at' => null,
            ],
        );
    }

    public function unlinkChild(string $parentTenantId, string $childTenantId, ?int $actorId = null): bool
    {
        return TenantHierarchy::where('parent_tenant_id', $parentTenantId)
            ->where('child_tenant_id', $childTenantId)
            ->update([
                'is_active' => false,
                'unlinked_by' => $actorId,
                'unlinked_at' => now(),
            ]) > 0;
    }

    /** @return string[] active child tenant_ids for the given parent */
    public function childrenOf(string $parentTenantId): array
    {
        return TenantHierarchy::where('parent_tenant_id', $parentTenantId)
            ->where('is_active', true)
            ->pluck('child_tenant_id')
            ->all();
    }

    public function isActiveChildOf(string $parentTenantId, string $childTenantId): bool
    {
        return in_array($childTenantId, $this->childrenOf($parentTenantId), true);
    }

    /**
     * Read-only advisory rollup: alert/incident counts per active child
     * tenant. Always driven by the explicit enumerated whitelist from
     * childrenOf() — never an unscoped cross-tenant query.
     */
    public function rollupSummary(string $parentTenantId): array
    {
        $children = $this->childrenOf($parentTenantId);
        if ($children === []) {
            return [];
        }

        $alertCounts = DB::table('security_alerts')
            ->whereIn('tenant_id', $children)
            ->select('tenant_id', DB::raw('count(*) as alert_count'))
            ->groupBy('tenant_id')
            ->pluck('alert_count', 'tenant_id');

        $incidentCounts = DB::table('security_incidents')
            ->whereIn('tenant_id', $children)
            ->select('tenant_id', DB::raw('count(*) as incident_count'))
            ->groupBy('tenant_id')
            ->pluck('incident_count', 'tenant_id');

        $openIncidentCounts = DB::table('security_incidents')
            ->whereIn('tenant_id', $children)
            ->where('status', 'open')
            ->select('tenant_id', DB::raw('count(*) as open_incident_count'))
            ->groupBy('tenant_id')
            ->pluck('open_incident_count', 'tenant_id');

        return array_map(fn ($childTenantId) => [
            'tenant_id' => $childTenantId,
            'alert_count' => (int) ($alertCounts[$childTenantId] ?? 0),
            'incident_count' => (int) ($incidentCounts[$childTenantId] ?? 0),
            'open_incident_count' => (int) ($openIncidentCounts[$childTenantId] ?? 0),
        ], $children);
    }
}
