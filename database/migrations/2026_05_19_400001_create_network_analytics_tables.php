<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // DNS telemetry events — append-only
        Schema::create('dns_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 48)->unique()->index();
            $table->string('agent_id', 64)->nullable()->index();
            $table->string('host_id', 128)->nullable()->index();
            $table->string('source_ip', 46)->nullable()->index();
            $table->string('user', 256)->nullable()->index();
            $table->string('queried_domain', 512)->nullable()->index();
            $table->string('tld', 32)->nullable()->index();
            $table->string('query_type', 16)->nullable();    // A, AAAA, MX, TXT, CNAME…
            $table->string('response_code', 16)->nullable(); // NOERROR, NXDOMAIN, SERVFAIL…
            $table->boolean('is_nxdomain')->default(false)->index();
            $table->jsonb('resolved_ips')->nullable();
            $table->float('domain_entropy')->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('trace_id', 48)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Proxy telemetry events — append-only
        Schema::create('proxy_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 48)->unique()->index();
            $table->string('agent_id', 64)->nullable()->index();
            $table->string('host_id', 128)->nullable()->index();
            $table->string('source_ip', 46)->nullable()->index();
            $table->string('user', 256)->nullable()->index();
            $table->text('url')->nullable();
            $table->string('domain', 512)->nullable()->index();
            $table->string('http_method', 16)->nullable();   // GET, POST, CONNECT…
            $table->unsignedSmallInteger('status_code')->nullable()->index();
            $table->string('user_agent', 1024)->nullable();
            $table->unsignedBigInteger('bytes_out')->nullable();
            $table->unsignedBigInteger('bytes_in')->nullable();
            $table->boolean('is_denied')->default(false)->index();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('trace_id', 48)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Firewall flow events — append-only
        Schema::create('firewall_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 48)->unique()->index();
            $table->string('agent_id', 64)->nullable()->index();
            $table->string('host_id', 128)->nullable()->index();
            $table->string('source_ip', 46)->nullable()->index();
            $table->string('destination_ip', 46)->nullable()->index();
            $table->unsignedSmallInteger('destination_port')->nullable()->index();
            $table->string('protocol', 16)->nullable();      // tcp, udp, icmp…
            $table->string('action', 16)->nullable()->index(); // allow, deny, drop
            $table->boolean('is_deny')->default(false)->index();
            $table->unsignedBigInteger('bytes_out')->nullable();
            $table->unsignedBigInteger('bytes_in')->nullable();
            $table->string('rule_name', 256)->nullable();
            $table->string('user', 256)->nullable()->index();
            $table->string('host_id_src', 128)->nullable();
            $table->timestamp('occurred_at')->nullable()->index();
            $table->string('trace_id', 48)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Network behavioral findings — append-only advisory analysis results
        Schema::create('network_behavioral_findings', function (Blueprint $table) {
            $table->id();
            $table->string('finding_id', 48)->unique()->index();
            $table->string('finding_type', 80)->index();     // suspicious_dns_tld, repeated_nxdomain…
            $table->string('source_domain', 20)->index();    // dns | proxy | firewall
            $table->string('host_id', 128)->nullable()->index();
            $table->string('agent_id', 64)->nullable()->index();
            $table->string('user', 256)->nullable()->index();
            $table->string('target_domain', 512)->nullable()->index();
            $table->string('target_ip', 46)->nullable();
            $table->unsignedSmallInteger('target_port')->nullable();
            $table->string('severity', 20)->default('medium');
            $table->float('confidence')->default(0.5);
            $table->boolean('advisory_only')->default(true);
            $table->jsonb('evidence')->nullable();
            $table->string('trace_id', 48)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();
            // No updated_at — append-only
        });

        // Network destination profiles — enrichment layer for known destinations
        Schema::create('network_destination_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('destination_key', 512)->unique()->index(); // domain or IP
            $table->string('destination_type', 10)->default('domain'); // domain | ip
            $table->unsignedInteger('contact_count')->default(0);
            $table->unsignedSmallInteger('host_count')->default(0);
            $table->unsignedSmallInteger('user_count')->default(0);
            $table->boolean('is_rare')->default(true);
            $table->float('entropy_score')->nullable();
            $table->string('tld', 32)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('network_destination_profiles');
        Schema::dropIfExists('network_behavioral_findings');
        Schema::dropIfExists('firewall_events');
        Schema::dropIfExists('proxy_events');
        Schema::dropIfExists('dns_events');
    }
};
