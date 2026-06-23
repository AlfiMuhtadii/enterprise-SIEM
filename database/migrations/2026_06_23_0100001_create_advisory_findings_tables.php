<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mutable: deduplicates shadow alerts by fingerprint; tracks current analyst review state.
        // Populated by alert-writer-service shadow consumer (XDR_SHADOW_CONSUMER_ENABLED=true).
        // Never cross-references security_alerts or security_incidents — shadow boundary is strict.
        Schema::create('advisory_findings', function (Blueprint $table) {
            $table->id();
            $table->string('finding_id')->unique();
            $table->string('rule_id');
            $table->string('domain');             // endpoint | network | ueba | threat_intel
            $table->string('source_topic');        // xdr.alerts.shadow.endpoint | xdr.alerts.shadow.network
            $table->string('alert_type');          // event_type / rule name from EndpointAlert
            $table->string('severity')->default('medium');
            $table->float('confidence')->nullable();
            $table->string('actor_key')->nullable(); // host or user from EndpointAlert
            $table->string('tenant_id')->nullable();
            $table->json('evidence')->nullable();
            $table->json('source_event_ids')->nullable();
            $table->boolean('promotion_candidate')->default(false);
            $table->string('promotion_blocker')->nullable(); // e.g. "domain_soak_required"
            // Analyst review state
            $table->string('status')->default('new'); // new | reviewed | dismissed | nominated
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            // Deduplication / recurrence tracking
            $table->string('fingerprint')->unique();
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->index('rule_id');
            $table->index('domain');
            $table->index('status');
            $table->index('severity');
            $table->index('promotion_candidate');
            $table->index('last_seen_at');
        });

        // Append-only: every analyst action on an advisory finding.
        Schema::create('advisory_finding_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('finding_id');   // advisory_findings.finding_id
            // event_type: finding_created | occurrence_incremented | reviewed | dismissed |
            //             nominated_for_promotion | review_note_updated
            $table->string('event_type');
            $table->unsignedBigInteger('analyst_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('finding_id');
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_finding_events');
        Schema::dropIfExists('advisory_findings');
    }
};
