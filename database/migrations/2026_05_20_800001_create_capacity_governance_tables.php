<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only — telemetry ingestion capacity snapshots (events/sec, events/day, topic growth)
        Schema::create('telemetry_capacity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 40)->unique()->index();
            $table->string('topic', 120)->nullable()->index();
            $table->float('events_per_sec')->default(0.0);
            $table->bigInteger('events_per_day')->default(0);
            $table->float('topic_growth_pct')->default(0.0);
            $table->float('partition_utilization_pct')->default(0.0);
            $table->bigInteger('queue_depth')->default(0);
            $table->float('ingestion_spike_ratio')->default(1.0);
            $table->float('cardinality_pressure_score')->default(0.0);
            // pressure_state: normal, elevated, high, critical
            $table->string('pressure_state', 40)->default('normal')->index();
            $table->string('window_duration', 40)->default('1m');
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — replay economics run records
        Schema::create('replay_economics_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 40)->unique()->index();
            $table->string('consumer_group', 120)->nullable()->index();
            $table->string('topic', 120)->nullable()->index();
            $table->integer('replay_duration_seconds')->default(0);
            $table->float('replay_throughput_eps')->default(0.0);
            $table->bigInteger('events_replayed')->default(0);
            $table->bigInteger('replay_backlog')->default(0);
            $table->float('replay_amplification_ratio')->default(1.0);
            $table->float('replay_storage_impact_mb')->default(0.0);
            $table->float('replay_cost_estimate')->default(0.0);
            $table->float('replay_queue_pressure')->default(0.0);
            // concurrency_state: bounded, elevated, approaching_limit, at_limit
            $table->string('concurrency_state', 40)->default('bounded')->index();
            $table->boolean('is_bounded')->default(true);
            $table->string('triggered_by', 120)->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — query performance snapshots per backend
        Schema::create('query_performance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 40)->unique()->index();
            // backend: postgresql, clickhouse, opensearch, threat_hunting, graph_traversal
            $table->string('backend', 80)->index();
            $table->float('p50_latency_ms')->default(0.0);
            $table->float('p95_latency_ms')->default(0.0);
            $table->float('p99_latency_ms')->default(0.0);
            $table->integer('slow_query_count')->default(0);
            $table->float('slow_query_threshold_ms')->default(1000.0);
            $table->float('index_hit_ratio')->default(1.0);
            $table->float('index_miss_ratio')->default(0.0);
            $table->float('search_degradation_pct')->default(0.0);
            $table->float('replay_query_overhead_pct')->default(0.0);
            // latency_state: normal, elevated, degraded, critical
            $table->string('latency_state', 40)->default('normal')->index();
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — storage capacity growth projections (distinct from storage_pressure_snapshots)
        Schema::create('storage_capacity_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('snapshot_id', 40)->unique()->index();
            // backend: postgresql, clickhouse, opensearch, redpanda, qdrant
            $table->string('backend', 40)->index();
            $table->bigInteger('current_size_bytes')->default(0);
            $table->float('growth_rate_bytes_per_day')->default(0.0);
            $table->float('compression_ratio')->default(1.0);
            $table->float('index_growth_pct')->default(0.0);
            $table->float('shard_pressure_pct')->default(0.0);
            $table->float('partition_pressure_pct')->default(0.0);
            $table->string('estimated_exhaustion_date', 30)->nullable()->index();
            $table->float('retention_cost_estimate')->default(0.0);
            // capacity_state: healthy, growing, pressure, critical
            $table->string('capacity_state', 40)->default('healthy')->index();
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — cardinality pressure report
        Schema::create('cardinality_pressure_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 40)->unique()->index();
            $table->string('field_name', 120)->index();
            $table->string('source_table', 120)->nullable()->index();
            $table->bigInteger('cardinality_count')->default(0);
            $table->float('growth_rate_pct')->default(0.0);
            // pressure_type: entity_explosion, label_explosion, graph_node_growth,
            //   process_telemetry_growth, attack_mapping_expansion
            $table->string('pressure_type', 80)->index();
            // severity: informational, warning, critical
            $table->string('severity', 40)->default('informational')->index();
            $table->boolean('bounded')->default(true);
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — capacity projection run (30/90-day growth forecast)
        Schema::create('capacity_projection_runs', function (Blueprint $table) {
            $table->id();
            $table->string('run_id', 40)->unique()->index();
            $table->string('scope', 80)->index();
            $table->integer('projection_days')->default(30);
            $table->float('current_daily_growth_bytes')->default(0.0);
            $table->float('projected_30d_bytes')->default(0.0);
            $table->float('projected_90d_bytes')->default(0.0);
            $table->float('replay_scaling_threshold_eps')->default(0.0);
            $table->integer('worker_scaling_threshold')->default(0);
            $table->string('storage_exhaustion_forecast', 30)->nullable();
            $table->string('queue_pressure_forecast', 40)->default('normal');
            $table->float('confidence_level')->default(0.0);
            $table->text('assumptions')->nullable();
            $table->boolean('deterministic')->default(true);
            $table->string('triggered_by', 120)->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Append-only — replay amplification audit records
        Schema::create('replay_amplification_reports', function (Blueprint $table) {
            $table->id();
            $table->string('report_id', 40)->unique()->index();
            $table->string('consumer_group', 120)->nullable()->index();
            $table->string('topic', 120)->nullable()->index();
            $table->float('amplification_ratio')->default(1.0);
            $table->bigInteger('original_event_count')->default(0);
            $table->bigInteger('replayed_event_count')->default(0);
            $table->float('storage_amplification_mb')->default(0.0);
            $table->boolean('exceeds_safe_threshold')->default(false);
            $table->float('safe_threshold')->default(3.0);
            $table->string('triggered_by', 120)->nullable();
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });

        // Mutable — current per-partition pressure state (updated in-place)
        Schema::create('partition_pressure_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('partition_key', 120)->unique()->index();
            $table->string('topic', 120)->index();
            $table->integer('partition_id')->default(0);
            $table->bigInteger('offset_lag')->default(0);
            $table->float('utilization_pct')->default(0.0);
            $table->float('throughput_eps')->default(0.0);
            $table->boolean('is_leader')->default(false);
            // health_state: healthy, lagging, pressure, stalled
            $table->string('health_state', 40)->default('healthy')->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only — infrastructure cost estimates
        Schema::create('infrastructure_cost_estimates', function (Blueprint $table) {
            $table->id();
            $table->string('estimate_id', 40)->unique()->index();
            // scope: storage, compute, replay, query, total
            $table->string('scope', 80)->index();
            $table->string('backend', 80)->nullable()->index();
            $table->float('daily_cost_estimate')->default(0.0);
            $table->float('monthly_cost_estimate')->default(0.0);
            $table->float('projected_90d_cost')->default(0.0);
            $table->string('cost_unit', 40)->default('relative_units');
            $table->float('growth_rate_pct')->default(0.0);
            $table->text('estimation_method')->nullable();
            $table->text('assumptions')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestamp('created_at');
            // NO updated_at — append-only
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('infrastructure_cost_estimates');
        Schema::dropIfExists('partition_pressure_snapshots');
        Schema::dropIfExists('replay_amplification_reports');
        Schema::dropIfExists('capacity_projection_runs');
        Schema::dropIfExists('cardinality_pressure_reports');
        Schema::dropIfExists('storage_capacity_snapshots');
        Schema::dropIfExists('query_performance_snapshots');
        Schema::dropIfExists('replay_economics_runs');
        Schema::dropIfExists('telemetry_capacity_snapshots');
    }
};
