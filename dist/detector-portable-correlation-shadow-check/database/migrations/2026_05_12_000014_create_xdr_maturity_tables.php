<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xdr_stream_reliability_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 80)->index();
            $table->string('topic', 120)->index();
            $table->string('consumer_group', 120)->index();
            $table->unsignedInteger('partition_count')->default(1);
            $table->unsignedInteger('parallel_consumers')->default(1);
            $table->unsignedBigInteger('partition_lag')->default(0)->index();
            $table->unsignedBigInteger('retry_count')->default(0);
            $table->unsignedBigInteger('dead_letter_count')->default(0);
            $table->unsignedBigInteger('rebalance_count')->default(0);
            $table->float('backpressure_ratio')->default(0);
            $table->float('replay_pressure')->default(0);
            $table->float('throughput_eps')->default(0);
            $table->float('saturation_ratio')->default(0)->index();
            $table->string('status', 32)->default('healthy')->index();
            $table->jsonb('warnings')->nullable();
            $table->timestampTz('measured_at')->index();
            $table->timestampsTz();
        });

        Schema::create('xdr_detection_rule_maturity', function (Blueprint $table) {
            $table->id();
            $table->string('rule_id', 120)->index();
            $table->string('rule_pack', 120)->index();
            $table->string('environment', 32)->default('staging')->index();
            $table->string('status', 32)->default('enabled')->index();
            $table->float('confidence')->default(0.5);
            $table->float('quality_score')->default(0.5)->index();
            $table->jsonb('sigma_like_rule');
            $table->jsonb('dependencies')->nullable();
            $table->jsonb('drift_metrics')->nullable();
            $table->jsonb('regression_history')->nullable();
            $table->timestampTz('evaluated_at')->index();
            $table->timestampsTz();
            $table->unique(['rule_id', 'environment']);
        });

        Schema::create('xdr_identity_risk_timelines', function (Blueprint $table) {
            $table->id();
            $table->string('user_key', 160)->index();
            $table->float('risk_score')->default(0)->index();
            $table->jsonb('risk_factors');
            $table->jsonb('session_anomalies')->nullable();
            $table->jsonb('mfa_anomalies')->nullable();
            $table->jsonb('privileged_activity')->nullable();
            $table->timestampTz('first_seen_at')->nullable()->index();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('xdr_attack_reconstructions', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id', 100)->index();
            $table->string('incident_id', 100)->nullable()->index();
            $table->float('chain_confidence')->default(0);
            $table->jsonb('attack_graph');
            $table->jsonb('cross_domain_timeline');
            $table->jsonb('linked_evidence');
            $table->jsonb('visualization')->nullable();
            $table->timestampTz('first_seen_at')->nullable()->index();
            $table->timestampTz('last_seen_at')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('xdr_storage_maturity_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('store_name', 80)->index();
            $table->string('tier', 32)->index();
            $table->unsignedInteger('retention_days')->default(30);
            $table->float('compression_ratio')->nullable();
            $table->float('estimated_storage_gb')->default(0);
            $table->float('estimated_monthly_cost_usd')->default(0);
            $table->jsonb('optimization_actions')->nullable();
            $table->jsonb('rollover_policy')->nullable();
            $table->timestampTz('measured_at')->index();
            $table->timestampsTz();
        });

        Schema::create('xdr_recovery_reports', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 80)->unique();
            $table->string('scenario', 120)->index();
            $table->string('status', 32)->default('completed')->index();
            $table->jsonb('service_dependency_failures')->nullable();
            $table->jsonb('degraded_mode_behavior')->nullable();
            $table->jsonb('recovery_metrics')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->timestampTz('started_at')->nullable()->index();
            $table->timestampTz('completed_at')->nullable()->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xdr_recovery_reports');
        Schema::dropIfExists('xdr_storage_maturity_metrics');
        Schema::dropIfExists('xdr_attack_reconstructions');
        Schema::dropIfExists('xdr_identity_risk_timelines');
        Schema::dropIfExists('xdr_detection_rule_maturity');
        Schema::dropIfExists('xdr_stream_reliability_metrics');
    }
};
