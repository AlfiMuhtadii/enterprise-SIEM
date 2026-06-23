<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Tables that had tenant_id columns but were missing the index (DB-2).
    private const TABLES = [
        'advisory_findings',
        'shadow_soak_runs',
        'shadow_soak_evidence_snapshots',
        'shadow_soak_gate_checks',
        'shadow_soak_domain_assessments',
        'shadow_soak_finding_summaries',
        'shadow_soak_confidence_bands',
        'shadow_soak_suppression_stats',
        'shadow_soak_coverage_stats',
        'shadow_soak_audit_events',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropIndex(['tenant_id']);
            });
        }
    }
};
