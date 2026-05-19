<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Mutable: named fleet-level policies that can be assigned to agents.
        // Separate from per-agent configs — fleet policies are shared templates.
        Schema::create('endpoint_fleet_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('policy_version');
            $table->json('config');                      // policy config payload
            $table->string('config_hash');               // SHA-256 of sorted JSON for drift detection
            $table->boolean('is_active')->default(true);
            $table->boolean('rollback_supported')->default(true);
            $table->string('previous_policy_id')->nullable(); // rollback chain
            $table->integer('assigned_agent_count')->default(0);
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        // Append-only: history of policy assignments from fleet policies to agents.
        // Each row records one assignment event — never updated after insert.
        Schema::create('endpoint_agent_policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->string('assignment_id')->unique();
            $table->foreignId('agent_id')->constrained('endpoint_agents')->cascadeOnDelete();
            $table->string('policy_id');               // references endpoint_fleet_policies.policy_id
            $table->string('policy_version');
            $table->string('config_hash');
            $table->string('assignment_reason')->nullable(); // manual, bulk_rollout, rollback, re-enrollment
            $table->boolean('applied_to_agent')->default(false);  // has agent acknowledged this policy?
            $table->string('trace_id')->nullable();
            $table->timestamp('assigned_at');
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('created_at')->nullable();  // no updated_at — append-only
        });

        // Append-only: enrollment lifecycle events for each agent.
        // Tracks state transitions: enrolled, re_enrolled, revoked, updated, failed.
        Schema::create('endpoint_agent_enrollment_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->foreignId('agent_id')->constrained('endpoint_agents')->cascadeOnDelete();
            $table->string('event_type');          // enrolled, re_enrolled, revoked, token_refreshed, version_updated, failed
            $table->string('agent_version')->nullable();
            $table->string('platform')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('enrollment_token_hash')->nullable();
            $table->json('metadata')->nullable();
            $table->text('failure_reason')->nullable();
            $table->string('trace_id')->nullable();
            $table->boolean('successful')->default(true);
            $table->timestamp('occurred_at');
            $table->unsignedBigInteger('triggered_by')->nullable(); // null = agent-initiated
            $table->timestamp('created_at')->nullable();            // no updated_at — append-only
        });

        // Append-only: advisory-only tamper visibility findings.
        // Records indicators of potential tampering. No enforcement action is taken.
        Schema::create('endpoint_tamper_events', function (Blueprint $table) {
            $table->id();
            $table->string('tamper_id')->unique();
            $table->foreignId('agent_id')->constrained('endpoint_agents')->cascadeOnDelete();
            $table->string('tamper_type');         // heartbeat_gap, config_mismatch, binary_hash_mismatch,
                                                   // suspicious_uninstall, telemetry_interruption,
                                                   // policy_drift, disabled_collector, agent_stopped
            $table->string('severity');            // critical, high, medium, low
            $table->text('description');
            $table->json('evidence')->nullable();  // explainable evidence fields
            $table->float('confidence')->nullable();
            $table->boolean('is_advisory')->default(true);  // always true — no autonomous enforcement
            $table->boolean('acknowledged')->default(false); // analyst-acknowledged
            $table->string('trace_id')->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('created_at')->nullable();    // no updated_at — append-only
        });

        // Append-only: spool health snapshots from agent heartbeat reports.
        // Tracks local telemetry durability state per agent per heartbeat.
        Schema::create('endpoint_spool_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id')->unique();
            $table->foreignId('agent_id')->constrained('endpoint_agents')->cascadeOnDelete();
            $table->integer('queued_events')->default(0);   // events in stream queue
            $table->integer('dropped_events')->default(0);  // events dropped this cycle
            $table->integer('retry_count')->default(0);
            $table->integer('spool_disk_bytes')->default(0);// spool file size in bytes
            $table->integer('oldest_spool_age_seconds')->nullable(); // age of oldest spooled event
            $table->float('events_per_sec')->nullable();
            $table->integer('buffer_depth')->default(0);
            $table->boolean('spool_capped')->default(false); // spool hit max size cap
            $table->boolean('disk_pressure')->default(false);
            $table->string('trace_id')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamp('created_at')->nullable();    // no updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_spool_snapshots');
        Schema::dropIfExists('endpoint_tamper_events');
        Schema::dropIfExists('endpoint_agent_enrollment_events');
        Schema::dropIfExists('endpoint_agent_policy_assignments');
        Schema::dropIfExists('endpoint_fleet_policies');
    }
};
