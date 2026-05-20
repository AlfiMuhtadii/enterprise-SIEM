<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-safe investigation graph nodes (no updated_at — new node added per pivot)
        Schema::create('investigation_graph_nodes', function (Blueprint $table) {
            $table->id();
            $table->string('node_id', 40)->unique()->index();
            $table->string('investigation_id', 40)->nullable()->index();
            $table->string('session_id', 40)->nullable()->index();
            // node_type: user, host, process, ip, domain, container, alert, incident, technique, artifact
            $table->string('node_type', 40)->index();
            $table->string('entity_id', 120)->nullable()->index();
            $table->string('label', 255);
            $table->string('source_domain', 80)->nullable()->index();
            // normalized observable value (ip, hash, username, fqdn, etc.)
            $table->string('observable_value', 512)->nullable()->index();
            $table->float('risk_score')->default(0.0);
            $table->boolean('is_pivot_origin')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->jsonb('attributes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-safe
        });

        // Append-safe investigation graph edges — evidence-backed relationships
        Schema::create('investigation_graph_edges', function (Blueprint $table) {
            $table->id();
            $table->string('edge_id', 40)->unique()->index();
            $table->string('investigation_id', 40)->nullable()->index();
            $table->string('session_id', 40)->nullable()->index();
            $table->string('from_node_id', 40)->index();
            $table->string('to_node_id', 40)->index();
            // relationship_type: spawned, connected_to, authenticated_as, observed_on, triggered, linked_to, pivoted_from
            $table->string('relationship_type', 80);
            $table->string('evidence_source', 120)->nullable();
            $table->string('evidence_id', 120)->nullable()->index();
            $table->float('confidence')->default(0.0);
            $table->boolean('is_advisory')->default(true);
            $table->jsonb('attributes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-safe
        });

        // Mutable investigation workspace sessions
        Schema::create('investigation_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('session_id', 40)->unique()->index();
            $table->string('investigation_id', 40)->nullable()->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            // status: active, paused, completed, abandoned
            $table->string('status', 40)->default('active')->index();
            $table->string('focus_entity_id', 120)->nullable()->index();
            $table->string('focus_node_type', 40)->nullable();
            $table->integer('hop_depth')->default(2);
            $table->boolean('include_advisory')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // Mutable saved pivots/bookmarks per session
        Schema::create('investigation_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->string('bookmark_id', 40)->unique()->index();
            $table->string('session_id', 40)->index();
            $table->string('investigation_id', 40)->nullable()->index();
            $table->string('label', 255);
            $table->text('notes')->nullable();
            $table->string('pivot_type', 80)->nullable();
            $table->string('pivot_value', 512)->nullable();
            $table->string('pivot_domain', 80)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
        });

        // Mutable retrospective hunt query definitions
        Schema::create('retrospective_hunt_queries', function (Blueprint $table) {
            $table->id();
            $table->string('query_id', 40)->unique()->index();
            $table->string('investigation_id', 40)->nullable()->index();
            $table->string('session_id', 40)->nullable()->index();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('domain', 80)->index();
            $table->jsonb('filters')->nullable();
            $table->string('time_range_start')->nullable();
            $table->string('time_range_end')->nullable();
            $table->integer('result_count')->nullable();
            // status: draft, executed, archived
            $table->string('status', 40)->default('draft')->index();
            $table->boolean('is_advisory')->default(true);
            $table->boolean('replay_safe')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('last_executed_at')->nullable();
            $table->timestamps();
        });

        // Append-only evidence linkage records
        Schema::create('investigation_evidence_links', function (Blueprint $table) {
            $table->id();
            $table->string('link_id', 40)->unique()->index();
            $table->string('investigation_id', 40)->index();
            $table->string('session_id', 40)->nullable()->index();
            $table->string('node_id', 40)->nullable()->index();
            $table->string('edge_id', 40)->nullable()->index();
            // evidence_type: alert, incident, entity_observation, hunt_result, cross_domain_correlation, stream_event
            $table->string('evidence_type', 80)->index();
            $table->string('evidence_id', 120)->index();
            $table->string('evidence_table', 120)->nullable();
            $table->text('rationale')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only ordered attack timeline events
        Schema::create('investigation_timeline_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 40)->unique()->index();
            $table->string('investigation_id', 40)->index();
            $table->string('session_id', 40)->nullable()->index();
            // event_category: initial_access, execution, persistence, privilege_escalation, credential_access, discovery, lateral_movement, collection, exfiltration, impact
            $table->string('event_category', 80)->index();
            $table->string('event_source', 80)->nullable();
            $table->string('source_id', 120)->nullable()->index();
            $table->string('mitre_technique', 40)->nullable()->index();
            $table->string('mitre_tactic', 40)->nullable();
            $table->text('description');
            $table->string('actor_entity_id', 120)->nullable()->index();
            $table->string('target_entity_id', 120)->nullable()->index();
            $table->float('confidence')->default(0.0);
            $table->boolean('is_advisory')->default(true);
            $table->jsonb('attributes')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investigation_timeline_events');
        Schema::dropIfExists('investigation_evidence_links');
        Schema::dropIfExists('retrospective_hunt_queries');
        Schema::dropIfExists('investigation_bookmarks');
        Schema::dropIfExists('investigation_sessions');
        Schema::dropIfExists('investigation_graph_edges');
        Schema::dropIfExists('investigation_graph_nodes');
    }
};
