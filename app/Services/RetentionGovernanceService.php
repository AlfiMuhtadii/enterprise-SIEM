<?php

namespace App\Services;

use App\Models\RetentionAuditEvent;
use App\Models\RetentionExecution;
use App\Models\RetentionPolicy;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Retention Governance Service — operational hardening.
 *
 * Operational hardening and recovery tooling only. No autonomous infrastructure mutation.
 * - Dry-run mode is the DEFAULT — actual deletion requires status=active
 * - Append-only audit tables are NEVER candidates for deletion
 * - Bounded cleanup batches prevent unbounded delete operations
 * - All executions are audited in retention_audit_events
 */
class RetentionGovernanceService
{
    // -----------------------------------------------------------------------
    // Policy management
    // -----------------------------------------------------------------------

    public function createPolicy(array $data, ?User $actor = null): RetentionPolicy
    {
        $domain = $data['domain'] ?? '';
        if (!in_array($domain, RetentionPolicy::ELIGIBLE_DOMAINS, true)) {
            throw new \InvalidArgumentException(
                "Domain '{$domain}' is not eligible for retention. Eligible: " .
                implode(', ', RetentionPolicy::ELIGIBLE_DOMAINS)
            );
        }

        $policy = RetentionPolicy::create([
            'policy_id'             => RetentionPolicy::generatePolicyId(),
            'domain'                => $domain,
            'target_table'          => $data['target_table'] ?? $domain,
            'time_column'           => $data['time_column'] ?? 'created_at',
            'retention_days'        => (int) ($data['retention_days'] ?? 90),
            'max_rows'              => $data['max_rows'] ?? null,
            'batch_size'            => min((int) ($data['batch_size'] ?? 500), 2000),
            'status'                => RetentionPolicy::STATUS_DRY_RUN_ONLY,
            'safe_archive_mode'     => true,
            'append_only_protected' => false,
            'description'           => $data['description'] ?? null,
        ]);

        $this->auditEvent(
            RetentionAuditEvent::EVENT_POLICY_UPDATED,
            $domain, 0, true, $actor,
            ['action' => 'created', 'policy_id' => $policy->policy_id]
        );

        return $policy;
    }

    // -----------------------------------------------------------------------
    // Preview — never modifies data
    // -----------------------------------------------------------------------

    /**
     * Preview what rows would be eligible for retention.
     * Read-only. Never deletes or archives anything.
     */
    public function previewRetention(RetentionPolicy $policy): array
    {
        $cutoff = now()->subDays($policy->retention_days);
        $table  = $policy->target_table;
        $col    = $policy->time_column;

        $eligibleCount = DB::table($table)
            ->where($col, '<', $cutoff)
            ->count();

        $oldestRow = DB::table($table)
            ->where($col, '<', $cutoff)
            ->orderBy($col)
            ->value($col);

        $this->auditEvent(
            RetentionAuditEvent::EVENT_PREVIEW,
            $policy->domain, $eligibleCount, true, null,
            ['policy_id' => $policy->policy_id, 'cutoff' => $cutoff->toIso8601String()]
        );

        return [
            'policy_id'         => $policy->policy_id,
            'domain'            => $policy->domain,
            'retention_days'    => $policy->retention_days,
            'cutoff_date'       => $cutoff->toIso8601String(),
            'eligible_count'    => $eligibleCount,
            'oldest_row_at'     => $oldestRow,
            'dry_run'           => true,
            'advisory_note'     => 'Preview only — no data modified.',
        ];
    }

    // -----------------------------------------------------------------------
    // Dry-run execution — simulates deletion without modifying data
    // -----------------------------------------------------------------------

    /**
     * Run a dry-run retention simulation.
     * Always returns rows_deleted = 0. Safe for production use.
     */
    public function executeDryRun(RetentionPolicy $policy, ?User $actor = null): RetentionExecution
    {
        $start  = microtime(true);
        $cutoff = now()->subDays($policy->retention_days);
        $table  = $policy->target_table;
        $col    = $policy->time_column;

        $rowsScanned  = DB::table($table)->count();
        $rowsEligible = DB::table($table)->where($col, '<', $cutoff)->count();

        $exec = RetentionExecution::create([
            'execution_id'     => RetentionExecution::generateExecutionId(),
            'policy_id'        => $policy->id,
            'dry_run'          => true,
            'rows_scanned'     => $rowsScanned,
            'rows_eligible'    => $rowsEligible,
            'rows_deleted'     => 0, // ALWAYS 0 for dry-run
            'execution_time_ms'=> (int) ((microtime(true) - $start) * 1000),
            'status'           => RetentionExecution::STATUS_DRY_RUN,
            'actor_id'         => $actor?->id,
        ]);

        $this->auditEvent(
            RetentionAuditEvent::EVENT_DRY_RUN,
            $policy->domain, $rowsEligible, true, $actor,
            ['execution_id' => $exec->execution_id, 'cutoff' => $cutoff->toIso8601String()]
        );

        return $exec;
    }

    // -----------------------------------------------------------------------
    // Bounded cleanup — only when policy is status=active
    // -----------------------------------------------------------------------

    /**
     * Execute a bounded cleanup batch.
     * Requires policy->status === active.
     * Never deletes from append-only tables.
     * Bounded by batch_size to prevent runaway deletions.
     */
    public function executeBoundedCleanup(RetentionPolicy $policy, ?User $actor = null): RetentionExecution
    {
        if ($policy->status !== RetentionPolicy::STATUS_ACTIVE) {
            throw new \InvalidArgumentException(
                "Policy '{$policy->policy_id}' is not active (status={$policy->status}). " .
                "Set status=active to enable actual deletion."
            );
        }
        if ($policy->append_only_protected) {
            throw new \InvalidArgumentException(
                "Policy '{$policy->policy_id}' targets an append-only protected table. Deletion is forbidden."
            );
        }

        $start  = microtime(true);
        $cutoff = now()->subDays($policy->retention_days);
        $table  = $policy->target_table;
        $col    = $policy->time_column;
        $batch  = min($policy->batch_size, 2000); // hard cap regardless of policy setting

        $rowsScanned  = DB::table($table)->count();
        $rowsEligible = DB::table($table)->where($col, '<', $cutoff)->count();

        // Bounded deletion — limited to batch_size rows per execution
        $ids = DB::table($table)
            ->where($col, '<', $cutoff)
            ->orderBy($col)
            ->limit($batch)
            ->pluck('id')
            ->toArray();

        $rowsDeleted = 0;
        if (!empty($ids)) {
            $rowsDeleted = DB::table($table)->whereIn('id', $ids)->delete();
        }

        $exec = RetentionExecution::create([
            'execution_id'      => RetentionExecution::generateExecutionId(),
            'policy_id'         => $policy->id,
            'dry_run'           => false,
            'rows_scanned'      => $rowsScanned,
            'rows_eligible'     => $rowsEligible,
            'rows_deleted'      => $rowsDeleted,
            'execution_time_ms' => (int) ((microtime(true) - $start) * 1000),
            'status'            => RetentionExecution::STATUS_COMPLETED,
            'actor_id'          => $actor?->id,
        ]);

        $this->auditEvent(
            RetentionAuditEvent::EVENT_DELETION,
            $policy->domain, $rowsDeleted, false, $actor,
            ['execution_id' => $exec->execution_id, 'batch_limit' => $batch, 'cutoff' => $cutoff->toIso8601String()]
        );

        return $exec;
    }

    // -----------------------------------------------------------------------
    // Query helpers
    // -----------------------------------------------------------------------

    public function getPolicies(): \Illuminate\Support\Collection
    {
        return RetentionPolicy::with(['executions' => fn ($q) => $q->latest()->limit(1)])
            ->orderBy('domain')
            ->get();
    }

    public function getRecentExecutions(int $limit = 50): \Illuminate\Support\Collection
    {
        return RetentionExecution::with('policy')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getAuditTrail(int $limit = 100): \Illuminate\Support\Collection
    {
        return RetentionAuditEvent::orderByDesc('id')->limit($limit)->get();
    }

    // -----------------------------------------------------------------------
    // Private helper
    // -----------------------------------------------------------------------

    private function auditEvent(
        string  $eventType,
        string  $domain,
        int     $rowCount,
        bool    $dryRun,
        ?User   $actor,
        array   $details = []
    ): void {
        RetentionAuditEvent::create([
            'audit_id'   => RetentionAuditEvent::generateAuditId(),
            'event_type' => $eventType,
            'domain'     => $domain,
            'row_count'  => $rowCount,
            'dry_run'    => $dryRun,
            'actor_id'   => $actor?->id,
            'details'    => $details,
            'trace_id'   => 'ops-' . Str::uuid(),
        ]);
    }
}
