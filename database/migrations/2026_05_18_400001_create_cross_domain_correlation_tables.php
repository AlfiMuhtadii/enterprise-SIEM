<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cross-domain correlation records — append-only, advisory-only, never trigger enforcement
        Schema::create('cross_domain_correlations', function (Blueprint $table) {
            $table->id();
            $table->string('correlation_id', 40)->unique()->index();   // CORR-YYYY-NNNNN
            $table->string('correlation_type', 60)->index();           // identity_endpoint|saas_endpoint|cloud_endpoint|multi_host|cross_domain
            $table->decimal('confidence_score', 4, 2)->default(0.00);
            $table->jsonb('attack_stages')->default('[]');             // ordered stage labels
            $table->string('primary_entity_type', 30)->nullable();
            $table->string('primary_entity_key', 255)->nullable()->index();
            $table->string('actor_key', 255)->nullable()->index();
            $table->jsonb('involved_hosts')->default('[]');
            $table->jsonb('involved_users')->default('[]');
            $table->jsonb('involved_ips')->default('[]');
            $table->timestamp('time_window_start')->nullable();
            $table->timestamp('time_window_end')->nullable();
            $table->jsonb('alert_ids')->default('[]');
            $table->jsonb('trace_ids')->default('[]');
            $table->jsonb('correlation_metadata')->default('{}');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');                           // no updated_at — append-only
        });

        // Attack stage timeline — one row per stage per correlation, append-only
        Schema::create('attack_stage_timelines', function (Blueprint $table) {
            $table->id();
            $table->string('timeline_id', 40)->unique()->index();      // AST-YYYY-NNNNN
            $table->unsignedBigInteger('correlation_id');
            $table->foreign('correlation_id')->references('id')->on('cross_domain_correlations')->cascadeOnDelete();
            $table->string('stage', 40)->index();                      // initial_access|credential_access|execution|persistence|command_and_control|lateral_movement|exfiltration|impact
            $table->unsignedTinyInteger('stage_order')->default(0);
            $table->decimal('stage_confidence', 4, 2)->default(0.00);
            $table->text('evidence_summary')->nullable();
            $table->jsonb('evidence_references')->default('[]');
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('trace_id', 120)->nullable()->index();
            $table->timestamp('created_at');                           // no updated_at — append-only
        });

        // Evidence links — snapshot of source evidence at correlation time, append-only
        Schema::create('correlation_evidence_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('correlation_id');
            $table->foreign('correlation_id')->references('id')->on('cross_domain_correlations')->cascadeOnDelete();
            $table->string('evidence_type', 60)->index();              // security_alert|endpoint_finding|hunt_result|entity_observation|process_entry
            $table->unsignedBigInteger('evidence_id')->nullable();
            $table->string('evidence_table', 80);
            $table->jsonb('evidence_snapshot');                        // read-only snapshot — source record NEVER mutated
            $table->string('domain', 30)->index();                     // identity|cloud|saas|endpoint
            $table->timestamp('created_at');                           // no updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('correlation_evidence_links');
        Schema::dropIfExists('attack_stage_timelines');
        Schema::dropIfExists('cross_domain_correlations');
    }
};
