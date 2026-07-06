<?php

namespace App\Services;

use App\Models\DataErasureAuditEvent;
use App\Models\DataErasureRequest;
use App\Models\TenantRetentionPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * DATA-RESIDENCY-ERASURE: per-tenant retention overrides + GDPR
 * right-to-erasure workflow.
 *
 * Erasure execution (real deletion) always requires an approved request and
 * a distinct approver from the requester (self-approve blocked). Dry-run
 * previews are read-only (row counts only) and do not require approval.
 * security_events has no tenant_id column (documented platform-wide gap —
 * see TenantBoundaryService::UNISOLATED_TABLES) so it cannot be scoped to a
 * single tenant and is intentionally excluded from erasure execution.
 */
class DataResidencyErasureService
{
    public const ERASABLE_TABLES = [
        'security_alerts' => 'detected_at',
        'security_incidents' => 'created_at',
    ];

    public function setRetentionPolicy(
        string $tenantId,
        ?int $eventsDays,
        ?int $alertsDays,
        ?int $incidentsDays,
        string $updatedBy,
    ): TenantRetentionPolicy {
        foreach (['events_days' => $eventsDays, 'alerts_days' => $alertsDays, 'incidents_days' => $incidentsDays] as $field => $value) {
            if ($value !== null && $value < 1) {
                throw new InvalidArgumentException("{$field} must be at least 1 day.");
            }
        }

        return TenantRetentionPolicy::updateOrCreate(
            ['tenant_id' => $tenantId],
            [
                'events_days' => $eventsDays,
                'alerts_days' => $alertsDays,
                'incidents_days' => $incidentsDays,
                'updated_by' => $updatedBy,
            ]
        );
    }

    public function getRetentionPolicy(string $tenantId): ?TenantRetentionPolicy
    {
        return TenantRetentionPolicy::where('tenant_id', $tenantId)->first();
    }

    /** Resolve the effective retention window: per-tenant override, else the global default. */
    public function resolveRetentionDays(string $tenantId, string $type, int $globalDefault): int
    {
        $field = "{$type}_days";
        $policy = $this->getRetentionPolicy($tenantId);

        return ($policy?->$field) ?? $globalDefault;
    }

    public function requestErasure(string $tenantId, string $reason, string $requestedBy, bool $dryRun = true): DataErasureRequest
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('An erasure request must include a reason.');
        }

        $request = DataErasureRequest::create([
            'request_id' => 'erasure-'.Str::uuid(),
            'tenant_id' => $tenantId,
            'reason' => $reason,
            'status' => DataErasureRequest::STATUS_PENDING,
            'requested_by' => $requestedBy,
            'dry_run' => $dryRun,
        ]);

        $this->recordAudit($request, DataErasureAuditEvent::EVENT_REQUESTED, $requestedBy, [
            'reason' => $reason,
            'dry_run' => $dryRun,
        ]);

        return $request;
    }

    public function approveErasure(int $requestId, string $approvedBy): DataErasureRequest
    {
        $request = DataErasureRequest::findOrFail($requestId);
        if ($request->status !== DataErasureRequest::STATUS_PENDING) {
            throw new RuntimeException("Request {$request->request_id} is not pending (status={$request->status}).");
        }
        if ($approvedBy === $request->requested_by) {
            throw new RuntimeException('Self-approval is not permitted — approver must differ from requester.');
        }

        $request->update([
            'status' => DataErasureRequest::STATUS_APPROVED,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        $this->recordAudit($request, DataErasureAuditEvent::EVENT_APPROVED, $approvedBy);

        return $request->fresh();
    }

    public function rejectErasure(int $requestId, string $rejectedBy, ?string $notes = null): DataErasureRequest
    {
        $request = DataErasureRequest::findOrFail($requestId);
        if ($request->status !== DataErasureRequest::STATUS_PENDING) {
            throw new RuntimeException("Request {$request->request_id} is not pending (status={$request->status}).");
        }

        $request->update(['status' => DataErasureRequest::STATUS_REJECTED]);

        $this->recordAudit($request, DataErasureAuditEvent::EVENT_REJECTED, $rejectedBy, ['notes' => $notes]);

        return $request->fresh();
    }

    /**
     * Execute a request. Dry-run requests (request-level dry_run=true) only
     * count matching rows and never require approval — nothing is deleted.
     * Real deletion requires status=approved.
     */
    public function executeErasure(int $requestId, string $actor): array
    {
        $request = DataErasureRequest::findOrFail($requestId);

        if (!$request->dry_run && $request->status !== DataErasureRequest::STATUS_APPROVED) {
            throw new RuntimeException(
                "Request {$request->request_id} must be approved before real deletion (status={$request->status})."
            );
        }

        $summary = [];
        foreach (self::ERASABLE_TABLES as $table => $column) {
            $query = DB::table($table)->where('tenant_id', $request->tenant_id);
            if ($request->dry_run) {
                $count = $query->count();
            } else {
                $count = $query->delete();
            }
            $summary[$table] = $count;

            $this->recordAudit(
                $request,
                $request->dry_run ? DataErasureAuditEvent::EVENT_DRY_RUN : DataErasureAuditEvent::EVENT_EXECUTED,
                $actor,
                [],
                $table,
                $count,
            );
        }

        $request->update([
            'status' => $request->dry_run ? $request->status : DataErasureRequest::STATUS_EXECUTED,
            'executed_at' => now(),
            'execution_summary' => $summary,
        ]);

        return $summary;
    }

    public function pendingRequests(): Collection
    {
        return DataErasureRequest::where('status', DataErasureRequest::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get();
    }

    public function requestsForTenant(string $tenantId): Collection
    {
        return DataErasureRequest::where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get();
    }

    private function recordAudit(
        DataErasureRequest $request,
        string $eventType,
        string $actor,
        array $details = [],
        ?string $table = null,
        ?int $rowCount = null,
    ): void {
        DataErasureAuditEvent::create([
            'audit_id' => 'era-audit-'.Str::uuid(),
            'request_id' => $request->id,
            'event_type' => $eventType,
            'tenant_id' => $request->tenant_id,
            'table_name' => $table,
            'row_count' => $rowCount,
            'actor' => $actor,
            'details' => $details,
        ]);
    }
}
