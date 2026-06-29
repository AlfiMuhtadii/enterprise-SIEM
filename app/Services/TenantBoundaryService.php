<?php

namespace App\Services;

use App\Exceptions\TenantBoundaryViolationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant Boundary Service — BACKLOG-TENANCY-019
 *
 * Provides object-level tenant authorization and query scoping.
 *
 * Current posture (2026-06-23/020):
 * - PostgreSQL Row-Level Security (RLS) is NOT enabled. This service is the
 *   primary enforcement layer until RLS is activated.
 * - User model does NOT carry tenant_id. Tenant context reaches controllers via
 *   X-Tenant-ID header, validated by TenantContextAuthority (BACKLOG-020).
 * - null tenant_id on a record = legacy single-tenant mode (no isolation);
 *   such records are accessible regardless of the request's tenant context.
 * - null tenant_id in the request context = no scoping (backward compatible).
 * - user_tenant_memberships table added (BACKLOG-020) — authority validation
 *   runs before this service's assertAccess() is reached.
 *
 * See docs/security/TENANT_ISOLATION_POSTURE.md and
 *     docs/security/TENANT_NULL_MIGRATION_PLAN.md
 */
class TenantBoundaryService
{
    public const RLS_ENABLED = false;
    public const USER_MODEL_HAS_TENANT_ID = false;
    public const USER_TENANT_MEMBERSHIPS_SUPPORTED = true;
    public const STRICT_MODE_DEFAULT = false;

    // Tables with tenant_id column — isolation is possible for these
    public const ISOLATED_TABLES = [
        'advisory_findings',
        'advisory_finding_events',
        'dlq_records',
        'dlq_normalization_events',
        'shadow_soak_runs',
        'shadow_soak_evidence_snapshots',
        'shadow_soak_gate_checks',
        'shadow_soak_domain_assessments',
        'shadow_soak_finding_summaries',
        'shadow_soak_confidence_bands',
        'shadow_soak_suppression_stats',
        'shadow_soak_coverage_stats',
        'shadow_soak_audit_events',
        'security_alerts',
        'security_incidents',
        'user_tenant_memberships',
        'tenant_membership_audit_events',
        // AGENT-TENANCY-GAP: endpoint fleet scoping
        'endpoint_agents',
        // TENANT-UNSCOPED-TABLES: workflow and graph tables
        'investigations',
        'response_plans',
        'entities',
        'threat_hunts',
    ];

    // Subset of ISOLATED_TABLES where UPDATE tenant_id is permitted.
    // Phase 3 backfill: security_alerts and security_incidents are mutable.
    // dlq_records is mutable (UPDATE allowed per operational policy — status/reviewed_by/replay/tenant_id).
    // All other ISOLATED_TABLES are append-only and MUST NOT be updated.
    public const MUTABLE_TABLES = [
        'security_alerts',
        'security_incidents',
        'dlq_records',
        'endpoint_agents',
        'investigations',
        'response_plans',
        'entities',
    ];

    // Append-only ISOLATED tables — tenant_id backfill is FORBIDDEN on these.
    // Null tenant_id on these rows is an accepted risk documented in
    // docs/security/TENANT_NULL_MIGRATION_PLAN.md (Phase 3 exclusions).
    public const APPEND_ONLY_ISOLATED_TABLES = [
        'advisory_findings',
        'advisory_finding_events',
        'dlq_normalization_events',
        'shadow_soak_runs',
        'shadow_soak_evidence_snapshots',
        'shadow_soak_gate_checks',
        'shadow_soak_domain_assessments',
        'shadow_soak_finding_summaries',
        'shadow_soak_confidence_bands',
        'shadow_soak_suppression_stats',
        'shadow_soak_coverage_stats',
        'shadow_soak_audit_events',
        'user_tenant_memberships',
        'tenant_membership_audit_events',
        // threat_hunts is append-only per operational policy — tenant_id set at insert only
        'threat_hunts',
    ];

    // Tables that still lack tenant_id — documented isolation gap
    public const UNISOLATED_TABLES = [
        'users',
        'security_audit_trails',
        'telemetry_events',
        'endpoint_agent_heartbeats',
    ];

    /**
     * Assert that the requesting tenant context may access a record.
     *
     * Throws TenantBoundaryViolationException if:
     * - the record has a non-null tenant_id
     * - AND the request has a non-null tenant context
     * - AND they do NOT match
     *
     * If either tenant_id is null, access is permitted (backward-compatible).
     */
    public function assertAccess(?string $recordTenantId, ?string $requestTenantId): void
    {
        if ($recordTenantId === null || $requestTenantId === null) {
            return;
        }

        if ($recordTenantId !== $requestTenantId) {
            throw new TenantBoundaryViolationException($recordTenantId, $requestTenantId);
        }
    }

    /**
     * Returns true if the tenant context may access the record.
     * Non-throwing variant of assertAccess().
     */
    public function canAccess(?string $recordTenantId, ?string $requestTenantId): bool
    {
        try {
            $this->assertAccess($recordTenantId, $requestTenantId);
            return true;
        } catch (TenantBoundaryViolationException) {
            return false;
        }
    }

    /**
     * Scope an Eloquent query to a tenant.
     *
     * If tenantId is null, the query is returned unchanged (no scoping).
     * If tenantId is non-null, adds a WHERE tenant_id = ? clause.
     *
     * NULL-stored records are excluded from tenant-scoped queries to prevent
     * leaking unscoped legacy data into tenant contexts. Use scopeOrLegacy()
     * if null-tenant records should remain visible.
     */
    public function scopeQuery(Builder $query, ?string $tenantId): Builder
    {
        if ($tenantId !== null) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    /**
     * Scope a query to a tenant, also including records with null tenant_id
     * (legacy single-tenant records visible to all tenant contexts).
     */
    public function scopeOrLegacy(Builder $query, ?string $tenantId): Builder
    {
        if ($tenantId !== null) {
            $query->where(function ($q) use ($tenantId) {
                $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id');
            });
        }

        return $query;
    }

    /**
     * Returns whether a given table has a tenant_id column (schema-level isolation).
     */
    public function tableHasIsolation(string $table): bool
    {
        return in_array($table, self::ISOLATED_TABLES, true);
    }

    /**
     * Returns the list of tables that are known to be missing tenant_id.
     */
    public function getUnisolatedTables(): array
    {
        return self::UNISOLATED_TABLES;
    }

    /**
     * Returns the isolation posture summary.
     */
    public function getPostureSummary(): array
    {
        return [
            'rls_enabled'                  => self::RLS_ENABLED,
            'user_model_has_tenant_id'     => self::USER_MODEL_HAS_TENANT_ID,
            'tenant_context_source'        => 'X-Tenant-ID request header',
            'isolated_tables_count'        => count(self::ISOLATED_TABLES),
            'unisolated_tables_count'      => count(self::UNISOLATED_TABLES),
            'enforcement_layer'            => 'application',
            'rls_roadmap'                  => 'see docs/security/TENANT_ISOLATION_POSTURE.md',
        ];
    }
}
