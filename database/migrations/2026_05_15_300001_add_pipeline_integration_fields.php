<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Allows the scenario runner (real mode) to poll for alerts produced
        // by the actual pipeline using the propagated trace_id.
        Schema::table('security_alerts', function (Blueprint $table) {
            if (!Schema::hasColumn('security_alerts', 'trace_id')) {
                $table->string('trace_id', 120)->nullable()->index()->after('alert_id');
            }
        });

        // Distinguishes synthetic runner evidence from real pipeline artifacts
        // on the timeline view.
        Schema::table('scenario_evidence', function (Blueprint $table) {
            if (!Schema::hasColumn('scenario_evidence', 'stage_type')) {
                $table->string('stage_type', 40)->nullable()->after('stage');
                // simulated_runner_stage | real_pipeline_stage
            }
        });
    }

    public function down(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            if (Schema::hasColumn('security_alerts', 'trace_id')) {
                $table->dropColumn('trace_id');
            }
        });

        Schema::table('scenario_evidence', function (Blueprint $table) {
            if (Schema::hasColumn('scenario_evidence', 'stage_type')) {
                $table->dropColumn('stage_type');
            }
        });
    }
};
