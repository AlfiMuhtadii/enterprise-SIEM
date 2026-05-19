<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Mutable: rolling behavioral baseline profiles (1 row per entity + dimension)
        // Updated as new observations roll the window forward.
        Schema::create('entity_behavior_baselines', function (Blueprint $table) {
            $table->id();
            $table->string('entity_id');            // entity_key reference (not FK — cross-type)
            $table->string('entity_type');           // user, host, ip, process, domain
            $table->string('entity_key');
            $table->string('dimension');             // login_frequency, failed_login_ratio, etc.
            $table->float('baseline_mean')->nullable();
            $table->float('baseline_median')->nullable();
            $table->float('baseline_stddev')->nullable();
            $table->float('baseline_mad')->nullable();   // median absolute deviation for robust z-score
            $table->float('baseline_p10')->nullable();   // 10th percentile
            $table->float('baseline_p90')->nullable();   // 90th percentile
            $table->integer('sample_count')->default(0);
            $table->integer('window_days')->default(30);
            $table->string('peer_group_key')->nullable(); // assigned peer group
            $table->boolean('advisory_only')->default(true);
            $table->timestamp('window_start')->nullable();
            $table->timestamp('window_end')->nullable();
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['entity_key', 'entity_type', 'dimension'], 'ueba_baseline_entity_dimension_unique');
            $table->index(['entity_type', 'dimension']);
            $table->index('peer_group_key');
        });

        // Append-only: individual observed values feeding into baselines
        Schema::create('baseline_observations', function (Blueprint $table) {
            $table->id();
            $table->string('observation_id')->unique();
            $table->string('entity_key');
            $table->string('entity_type');
            $table->string('dimension');             // which behavioral metric was observed
            $table->float('observed_value');
            $table->string('source_table')->nullable();   // where the raw event came from
            $table->string('source_event_id')->nullable();
            $table->string('trace_id')->nullable();
            $table->json('context')->nullable();          // additional evidence context
            $table->boolean('advisory_only')->default(true);
            $table->timestamp('observed_at');
            $table->timestamp('created_at')->nullable();  // no updated_at — append-only
        });

        // Append-only: scored anomaly records (explainable, evidence-linked)
        Schema::create('baseline_anomaly_scores', function (Blueprint $table) {
            $table->id();
            $table->string('score_id')->unique();
            $table->string('entity_key');
            $table->string('entity_type');
            $table->string('anomaly_type');           // unusual_login_time, peer_group_behavior_deviation, etc.
            $table->string('dimension');              // which baseline metric triggered the anomaly
            $table->float('observed_value');
            $table->float('baseline_value');          // mean or median used as comparison
            $table->float('deviation');               // absolute deviation
            $table->float('z_score')->nullable();     // robust z-score (may be null if insufficient samples)
            $table->float('percentile_rank')->nullable(); // 0–100
            $table->string('scoring_method');         // robust_z_score, percentile_rank, frequency_rarity, peer_group_deviation
            $table->float('confidence');              // 0.0–1.0
            $table->json('evidence_references')->nullable(); // source event IDs
            $table->json('trace_ids')->nullable();
            $table->string('peer_group_key')->nullable();
            $table->float('peer_group_deviation')->nullable();
            $table->boolean('is_advisory')->default(true);  // always true — no autonomous enforcement
            $table->boolean('acted_on')->default(false);    // analyst manually investigated
            $table->timestamp('scored_at');
            $table->timestamp('created_at')->nullable();    // no updated_at — append-only
        });

        // Mutable: peer group definitions (deterministic grouping, bounded size)
        Schema::create('peer_group_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('peer_group_key')->unique();
            $table->string('group_type');             // user_role, host_function, saas_tenant, endpoint_agent, network_destination
            $table->string('group_label');
            $table->json('criteria')->nullable();     // deterministic assignment criteria (role, platform, etc.)
            $table->integer('entity_count')->default(0);
            $table->json('member_entity_keys')->nullable();   // bounded list of member entity keys
            $table->json('dimension_stats')->nullable();      // per-dimension mean/stddev for the group
            $table->boolean('advisory_only')->default(true);
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->index('group_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_anomaly_scores');
        Schema::dropIfExists('baseline_observations');
        Schema::dropIfExists('peer_group_profiles');
        Schema::dropIfExists('entity_behavior_baselines');
    }
};
