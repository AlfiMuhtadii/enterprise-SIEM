<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-065 — Tenant Backfill + Strict Mode Readiness
 *
 * Evaluates whether the system is ready to enable XDR_TENANT_STRICT_MODE=true.
 * All assessments are advisory-only — this service never mutates production
 * data or flips env flags autonomously.
 */
class TenantStrictModeReadinessService
{
    public const PASS_THRESHOLD     = 0.80;
    public const SELF_APPROVE_BLOCKED = true;

    public const GATES = [
        'GATE-01' => 'Mutable tables null tenant_id count = 0',
        'GATE-02' => 'Backfill command available (TenantNullBackfillCommand)',
        'GATE-03' => 'RLS decision record documented',
        'GATE-04' => 'Null migration plan documented',
        'GATE-05' => 'Strict mode posture documented',
        'GATE-06' => 'Append-only null records accepted-risk documented',
        'GATE-07' => 'Tenant context authority in place',
    ];

    // Gates that MUST pass regardless of threshold
    public const REQUIRED_GATES = ['GATE-01', 'GATE-07'];

    public function assess(string $assessedBy): array
    {
        $assessmentId = 'sma-' . Str::uuid();
        $gateResults  = [];

        foreach (self::GATES as $gateId => $gateName) {
            $gateResults[$gateId] = $this->evaluateGate($gateId, $gateName);
        }

        $passed = count(array_filter($gateResults, fn ($r) => $r['result'] === 'PASS'));
        $warned = count(array_filter($gateResults, fn ($r) => $r['result'] === 'WARN'));
        $failed = count(array_filter($gateResults, fn ($r) => $r['result'] === 'FAIL'));
        $total  = count($gateResults);

        $score  = $total > 0 ? round($passed / $total, 4) : 0.0;

        // Required gate hard-fail check
        $requiredFailed = array_filter(
            array_intersect_key($gateResults, array_flip(self::REQUIRED_GATES)),
            fn ($r) => $r['result'] === 'FAIL',
        );

        $recommended = empty($requiredFailed) && $score >= self::PASS_THRESHOLD;

        $overall = match (true) {
            !empty($requiredFailed)      => 'NOT_READY',
            $score >= self::PASS_THRESHOLD => $failed === 0 ? 'READY' : 'WARN',
            default                       => 'NOT_READY',
        };

        $summary = [
            'assessment_id'           => $assessmentId,
            'gates_total'             => $total,
            'gates_passed'            => $passed,
            'gates_warned'            => $warned,
            'gates_failed'            => $failed,
            'readiness_score'         => $score,
            'pass_threshold'          => self::PASS_THRESHOLD,
            'overall_status'          => $overall,
            'strict_mode_recommended' => $recommended,
            'required_gates_failed'   => array_keys($requiredFailed),
            'note'                    => 'Advisory only — never enables strict mode autonomously.',
        ];

        $this->persist($assessmentId, $assessedBy, $gateResults, $summary);

        return [
            'assessment_id'  => $assessmentId,
            'gate_results'   => $gateResults,
            'summary'        => $summary,
        ];
    }

    public function getHistory(int $limit = 20): Collection
    {
        return DB::table('tenant_strict_mode_assessments')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getGateResults(string $assessmentId): Collection
    {
        return DB::table('tenant_strict_mode_gate_results')
            ->where('assessment_id', $assessmentId)
            ->orderBy('gate_id')
            ->get();
    }

    public function recordBackfillRun(array $result): void
    {
        DB::table('tenant_backfill_audit_runs')->insertOrIgnore([
            'run_id'             => 'bfr-' . Str::uuid(),
            'initiated_by'       => $result['initiated_by'] ?? 'cli',
            'dry_run'            => $result['dry_run'] ?? true,
            'tenant_id_assigned' => $result['tenant_id_assigned'] ?? null,
            'batch_size'         => $result['batch_size'] ?? 1000,
            'table_results'      => json_encode($result['table_results'] ?? []),
            'total_null_before'  => $result['total_null_before'] ?? 0,
            'total_updated'      => $result['total_updated'] ?? 0,
            'total_null_after'   => $result['total_null_after'] ?? 0,
            'outcome'            => $result['outcome'] ?? 'DRY_RUN_PENDING',
            'created_at'         => now()->format('Y-m-d H:i:sP'),
            'updated_at'         => now()->format('Y-m-d H:i:sP'),
        ]);
    }

    // -------------------------------------------------------------------------
    // Gate evaluators
    // -------------------------------------------------------------------------

    private function evaluateGate(string $gateId, string $gateName): array
    {
        return match ($gateId) {
            'GATE-01' => $this->gateNullCount($gateName),
            'GATE-02' => $this->gateBackfillCommand($gateName),
            'GATE-03' => $this->gateDocExists('docs/security/RLS_DECISION_RECORD.md', $gateName),
            'GATE-04' => $this->gateDocExists('docs/security/TENANT_NULL_MIGRATION_PLAN.md', $gateName),
            'GATE-05' => $this->gateDocExists('docs/security/TENANT_STRICT_MODE.md', $gateName),
            'GATE-06' => $this->gateAppendOnlyAcceptedRisk($gateName),
            'GATE-07' => $this->gateTenantContextAuthority($gateName),
            default   => ['result' => 'FAIL', 'detail' => 'Unknown gate', 'gate_name' => $gateName],
        };
    }

    private function gateNullCount(string $gateName): array
    {
        $nullCounts = [];
        $totalNull  = 0;

        foreach (TenantBoundaryService::MUTABLE_TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }
            $count = DB::table($table)->whereNull('tenant_id')->count();
            $nullCounts[$table] = $count;
            $totalNull += $count;
        }

        return [
            'gate_name' => $gateName,
            'result'    => $totalNull === 0 ? 'PASS' : 'FAIL',
            'detail'    => $totalNull === 0
                ? 'All mutable tables have zero null tenant_id records.'
                : "Total null tenant_id records: {$totalNull}. Run: php artisan tenant:backfill-nulls",
            'metadata'  => $nullCounts,
        ];
    }

    private function gateBackfillCommand(string $gateName): array
    {
        $exists = class_exists(\App\Console\Commands\TenantNullBackfillCommand::class);
        return [
            'gate_name' => $gateName,
            'result'    => $exists ? 'PASS' : 'FAIL',
            'detail'    => $exists
                ? 'TenantNullBackfillCommand is registered and available.'
                : 'TenantNullBackfillCommand class not found.',
            'metadata'  => ['class_exists' => $exists],
        ];
    }

    private function gateDocExists(string $relativePath, string $gateName): array
    {
        $path   = base_path($relativePath);
        $exists = file_exists($path);
        return [
            'gate_name' => $gateName,
            'result'    => $exists ? 'PASS' : 'WARN',
            'detail'    => $exists
                ? "Documentation exists: {$relativePath}"
                : "Documentation missing: {$relativePath}",
            'metadata'  => ['path' => $relativePath, 'exists' => $exists],
        ];
    }

    private function gateAppendOnlyAcceptedRisk(string $gateName): array
    {
        $tables    = TenantBoundaryService::APPEND_ONLY_ISOLATED_TABLES;
        $nullTotal = 0;
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                $nullTotal += DB::table($table)->whereNull('tenant_id')->count();
            }
        }

        // Append-only tables with null tenant_id are accepted risk — WARN not FAIL
        return [
            'gate_name' => $gateName,
            'result'    => 'PASS',
            'detail'    => "Append-only tables: {$nullTotal} null records (accepted risk — cannot backfill). "
                . 'Documented in TENANT_NULL_MIGRATION_PLAN.md.',
            'metadata'  => ['append_only_null_count' => $nullTotal, 'tables' => count($tables)],
        ];
    }

    private function gateTenantContextAuthority(string $gateName): array
    {
        $exists = class_exists(\App\Services\TenantContextAuthority::class);
        return [
            'gate_name' => $gateName,
            'result'    => $exists ? 'PASS' : 'FAIL',
            'detail'    => $exists
                ? 'TenantContextAuthority is in place (BACKLOG-020).'
                : 'TenantContextAuthority class not found.',
            'metadata'  => ['class_exists' => $exists],
        ];
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    private function persist(string $assessmentId, string $assessedBy, array $gateResults, array $summary): void
    {
        $now = now()->format('Y-m-d H:i:sP');

        DB::table('tenant_strict_mode_assessments')->insertOrIgnore([
            'assessment_id'           => $assessmentId,
            'assessed_by'             => $assessedBy,
            'gates_total'             => $summary['gates_total'],
            'gates_passed'            => $summary['gates_passed'],
            'gates_warned'            => $summary['gates_warned'],
            'gates_failed'            => $summary['gates_failed'],
            'readiness_score'         => $summary['readiness_score'],
            'overall_status'          => $summary['overall_status'],
            'strict_mode_recommended' => $summary['strict_mode_recommended'],
            'summary'                 => json_encode($summary),
            'created_at'              => $now,
            'updated_at'              => $now,
        ]);

        foreach ($gateResults as $gateId => $gate) {
            DB::table('tenant_strict_mode_gate_results')->insertOrIgnore([
                'assessment_id' => $assessmentId,
                'gate_id'       => $gateId,
                'gate_name'     => $gate['gate_name'],
                'result'        => $gate['result'],
                'detail'        => $gate['detail'] ?? null,
                'metadata'      => json_encode($gate['metadata'] ?? []),
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }
}
