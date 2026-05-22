<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only: file hash lineage
        Schema::create('endpoint_file_hash_lineage', function (Blueprint $table) {
            $table->id();
            $table->string('lineage_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('file_hash_sha256');
            $table->string('file_path');
            $table->string('process_name');
            $table->string('parent_hash_sha256')->nullable();
            $table->boolean('renamed_executable')->default(false);
            $table->integer('reuse_count')->default(1);
            $table->boolean('suspicious_propagation')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('lineage_context')->nullable();
            $table->timestamps();
        });

        // Append-only: module/DLL loads
        Schema::create('endpoint_module_loads', function (Blueprint $table) {
            $table->id();
            $table->string('load_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('module_name');
            $table->string('module_path');
            $table->string('process_name');
            $table->string('module_hash_sha256')->nullable();
            $table->boolean('is_signed')->default(true);
            $table->boolean('suspicious_lineage')->default(false);
            $table->integer('load_frequency')->default(1);
            $table->boolean('network_correlated')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('load_context')->nullable();
            $table->timestamps();
        });

        // Append-only: registry modification timelines
        Schema::create('endpoint_registry_timelines', function (Blueprint $table) {
            $table->id();
            $table->string('timeline_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('registry_key');
            $table->string('registry_value_name')->nullable();
            $table->string('modification_type'); // create, modify, delete
            $table->string('key_category'); // persistence, startup, service, autorun, other
            $table->string('process_name');
            $table->boolean('suspicious_lineage')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('registry_context')->nullable();
            $table->timestamps();
        });

        // Append-only: socket lifecycle
        Schema::create('endpoint_socket_lifecycle', function (Blueprint $table) {
            $table->id();
            $table->string('socket_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('process_name');
            $table->string('local_address');
            $table->integer('local_port');
            $table->string('remote_address')->nullable();
            $table->integer('remote_port')->nullable();
            $table->string('protocol'); // tcp, udp
            $table->string('state'); // listen, established, closed, time_wait
            $table->float('connection_duration_seconds')->default(0.0);
            $table->integer('reconnect_count')->default(0);
            $table->boolean('suspicious_port')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('socket_context')->nullable();
            $table->timestamps();
        });

        // Append-only: process ancestry validation
        Schema::create('endpoint_process_ancestry_validation', function (Blueprint $table) {
            $table->id();
            $table->string('validation_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('process_name');
            $table->string('parent_process_name')->nullable();
            $table->boolean('parent_found')->default(true);
            $table->boolean('orphaned_chain')->default(false);
            $table->boolean('lineage_consistent')->default(true);
            $table->float('lineage_confidence')->default(1.0);
            $table->boolean('ancestry_divergence_detected')->default(false);
            $table->boolean('replay_ordered')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('ancestry_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: anti-evasion indicators
        Schema::create('endpoint_anti_evasion_indicators', function (Blueprint $table) {
            $table->id();
            $table->string('indicator_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('indicator_type'); // telemetry_suppression, delayed_execution, parent_spoofing, abnormal_ancestry, module_load_anomaly, runtime_divergence
            $table->string('severity'); // low, medium, high, critical
            $table->string('process_name');
            $table->boolean('evasion_confirmed')->default(false);
            $table->float('confidence_score')->default(0.0);
            $table->boolean('replay_validated')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('evasion_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: runtime visibility
        Schema::create('endpoint_runtime_visibility', function (Blueprint $table) {
            $table->id();
            $table->string('visibility_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('visibility_type'); // process_snapshot, module_snapshot, socket_snapshot, registry_snapshot
            $table->integer('process_count')->default(0);
            $table->integer('module_count')->default(0);
            $table->integer('socket_count')->default(0);
            $table->integer('registry_key_count')->default(0);
            $table->float('visibility_completeness_pct')->default(1.0);
            $table->boolean('is_advisory')->default(true);
            $table->json('visibility_snapshot')->nullable();
            $table->timestamps();
        });

        // Append-only: lineage confidence
        Schema::create('endpoint_lineage_confidence', function (Blueprint $table) {
            $table->id();
            $table->string('confidence_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('lineage_type'); // process, module, registry, socket, file_hash
            $table->float('confidence_score')->default(1.0);
            $table->float('baseline_confidence')->default(1.0);
            $table->float('degradation_delta')->default(0.0);
            $table->boolean('degradation_detected')->default(false);
            $table->boolean('replay_safe')->default(true);
            $table->boolean('is_advisory')->default(true);
            $table->json('confidence_evidence')->nullable();
            $table->timestamps();
        });

        // Append-only: socket anomalies
        Schema::create('endpoint_socket_anomalies', function (Blueprint $table) {
            $table->id();
            $table->string('anomaly_id')->unique();
            $table->string('endpoint_id');
            $table->string('tenant_id');
            $table->string('process_name');
            $table->string('anomaly_type'); // repeated_reconnect, suspicious_port, unusual_destination, long_lived_connection, beaconing_pattern
            $table->float('anomaly_score')->default(0.0);
            $table->string('severity'); // low, medium, high, critical
            $table->boolean('evasion_indicator')->default(false);
            $table->boolean('replay_validated')->default(false);
            $table->boolean('is_advisory')->default(true);
            $table->json('anomaly_evidence')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('endpoint_socket_anomalies');
        Schema::dropIfExists('endpoint_lineage_confidence');
        Schema::dropIfExists('endpoint_runtime_visibility');
        Schema::dropIfExists('endpoint_anti_evasion_indicators');
        Schema::dropIfExists('endpoint_process_ancestry_validation');
        Schema::dropIfExists('endpoint_socket_lifecycle');
        Schema::dropIfExists('endpoint_registry_timelines');
        Schema::dropIfExists('endpoint_module_loads');
        Schema::dropIfExists('endpoint_file_hash_lineage');
    }
};
