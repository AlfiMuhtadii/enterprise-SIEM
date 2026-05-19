<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Investigation collaborators — append-only (never deleted, archived via active flag)
        Schema::create('investigation_collaborators', function (Blueprint $table) {
            $table->id();
            $table->string('collab_id', 48)->unique()->index();
            $table->string('investigation_id', 48)->index();
            $table->unsignedBigInteger('analyst_id')->index();
            $table->string('role', 30)->default('collaborator'); // lead|collaborator|reviewer
            $table->unsignedBigInteger('added_by')->nullable();
            $table->boolean('active')->default(true);
            $table->string('note', 500)->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only history
        });

        // Investigation watchers — append-only
        Schema::create('investigation_watchers', function (Blueprint $table) {
            $table->id();
            $table->string('watcher_id', 48)->unique()->index();
            $table->string('investigation_id', 48)->index();
            $table->unsignedBigInteger('analyst_id')->index();
            $table->string('watch_reason', 500)->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Escalation events — append-only audit trail for all escalation activity
        Schema::create('escalation_events', function (Blueprint $table) {
            $table->id();
            $table->string('escalation_id', 48)->index();
            $table->string('investigation_id', 48)->index();
            $table->string('event_type', 40)->index();  // created|acknowledged|routed|reassigned|resolved|timed_out|workflow_state
            $table->string('workflow_state', 30)->nullable()->index(); // queued|assigned|triaged|investigating|escalated|awaiting_response|monitoring|resolved|closed
            $table->unsignedBigInteger('from_analyst_id')->nullable();
            $table->unsignedBigInteger('to_analyst_id')->nullable()->index();
            $table->string('severity', 20)->nullable();
            $table->text('reason')->nullable();
            $table->text('routing_rule')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgement_note')->nullable();
            $table->string('trace_id', 48)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Analyst shift handoffs — append-only
        Schema::create('analyst_handoffs', function (Blueprint $table) {
            $table->id();
            $table->string('handoff_id', 48)->unique()->index();
            $table->unsignedBigInteger('from_analyst_id')->index();
            $table->unsignedBigInteger('to_analyst_id')->nullable()->index();
            $table->text('shift_summary')->nullable();
            $table->unsignedSmallInteger('unresolved_count')->default(0);
            $table->unsignedSmallInteger('pending_escalation_count')->default(0);
            $table->text('notes')->nullable();
            $table->jsonb('active_investigations')->nullable(); // snapshot of investigation IDs
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Analyst watchlists — mutable (active/inactive, expiration)
        Schema::create('watchlists', function (Blueprint $table) {
            $table->id();
            $table->string('watchlist_id', 48)->unique()->index();
            $table->unsignedBigInteger('analyst_id')->index();
            $table->string('watch_type', 30)->index(); // user|host|domain|ip|process|trace|investigation
            $table->string('watch_key', 512)->index();
            $table->string('watch_reason', 1000)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->string('trace_id', 48)->nullable();
            $table->timestamps();
        });

        // Watchlist events — append-only
        Schema::create('watchlist_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 48)->unique()->index();
            $table->string('watchlist_id', 48)->index();
            $table->string('event_type', 30)->index(); // created|triggered|expired|deactivated
            $table->unsignedBigInteger('analyst_id')->nullable();
            $table->jsonb('details')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // SLA policies — mutable (configurable)
        Schema::create('sla_policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_id', 48)->unique()->index();
            $table->string('policy_type', 30)->index(); // triage|escalation|response|investigation
            $table->string('severity', 20)->nullable()->index(); // critical|high|medium|low
            $table->unsignedInteger('max_seconds');  // SLA threshold in seconds
            $table->boolean('active')->default(true);
            $table->string('description', 500)->nullable();
            $table->timestamps();
        });

        // SLA events — append-only timeline
        Schema::create('sla_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 48)->unique()->index();
            $table->string('investigation_id', 48)->index();
            $table->string('sla_policy_id', 48)->index();
            $table->string('event_type', 30)->index(); // started|paused|resumed|completed|breached
            $table->unsignedBigInteger('analyst_id')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // SLA breaches — append-only
        Schema::create('sla_breaches', function (Blueprint $table) {
            $table->id();
            $table->string('breach_id', 48)->unique()->index();
            $table->string('investigation_id', 48)->index();
            $table->string('sla_policy_id', 48)->index();
            $table->string('breach_type', 30)->index(); // triage|escalation|response|investigation
            $table->string('severity', 20)->nullable();
            $table->unsignedInteger('overdue_seconds')->default(0);
            $table->unsignedBigInteger('analyst_id')->nullable();
            $table->boolean('acknowledged')->default(false);
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('trace_id', 48)->nullable();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sla_breaches');
        Schema::dropIfExists('sla_events');
        Schema::dropIfExists('sla_policies');
        Schema::dropIfExists('watchlist_events');
        Schema::dropIfExists('watchlists');
        Schema::dropIfExists('analyst_handoffs');
        Schema::dropIfExists('escalation_events');
        Schema::dropIfExists('investigation_watchers');
        Schema::dropIfExists('investigation_collaborators');
    }
};
