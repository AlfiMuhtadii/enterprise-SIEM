<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Mutable: integration configuration registry
        Schema::create('external_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('integration_id')->unique();
            $table->string('integration_type');        // idp, saas, ticketing, notification
            $table->string('provider');                // okta, azure_ad, jira, slack, pagerduty, etc.
            $table->string('name');
            $table->string('status')->default('inactive'); // active, inactive, error, disabled
            $table->json('config')->nullable();            // non-secret config (urls, tenant ids)
            $table->json('field_mappings')->nullable();
            $table->boolean('inbound_enabled')->default(false);
            $table->boolean('outbound_enabled')->default(false);
            $table->boolean('sync_advisory_only')->default(true);
            $table->timestamp('last_sync_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->integer('sync_error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        // Append-only: sync event audit log
        Schema::create('integration_sync_events', function (Blueprint $table) {
            $table->id();
            $table->string('sync_id')->unique();
            $table->foreignId('integration_id')->constrained('external_integrations')->cascadeOnDelete();
            $table->string('direction');                // inbound, outbound
            $table->string('sync_type');               // full_pull, incremental_pull, push_case, push_notification
            $table->string('status');                  // started, completed, failed, skipped
            $table->integer('records_processed')->default(0);
            $table->integer('records_failed')->default(0);
            $table->boolean('dry_run')->default(true);
            $table->boolean('advisory_only')->default(true);
            $table->json('summary')->nullable();
            $table->text('error_detail')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });

        // Append-only: links between SOC cases and external ticketing systems
        Schema::create('external_case_links', function (Blueprint $table) {
            $table->id();
            $table->string('link_id')->unique();
            $table->string('investigation_id');
            $table->foreignId('integration_id')->constrained('external_integrations')->cascadeOnDelete();
            $table->string('external_ticket_id');
            $table->string('external_ticket_url')->nullable();
            $table->string('external_status')->nullable();
            $table->string('link_direction');            // soc_to_external, external_to_soc
            $table->boolean('sync_advisory_only')->default(true);
            $table->boolean('auto_closed')->default(false);  // always false — no autonomous closure
            $table->json('external_metadata')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });

        // Append-only: outbound notification events
        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            $table->string('notification_id')->unique();
            $table->foreignId('integration_id')->constrained('external_integrations')->cascadeOnDelete();
            $table->string('notification_type');         // alert_fired, incident_created, escalation, sla_breach
            $table->string('severity');
            $table->string('channel');                   // slack, webhook, pagerduty, email
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->json('target_metadata')->nullable();
            $table->json('source_reference')->nullable();  // { type: investigation, id: xxx }
            $table->boolean('requires_analyst_approval')->default(false);
            $table->string('trace_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
        });

        // Append-only: delivery attempts per notification
        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->foreignId('notification_event_id')->constrained('notification_events')->cascadeOnDelete();
            $table->string('status');                     // pending, delivered, failed, skipped
            $table->integer('attempt_number')->default(1);
            $table->integer('http_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->text('error_detail')->nullable();
            $table->boolean('simulated')->default(true);  // default: never actually sent in demo
            $table->string('trace_id')->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('created_at')->nullable();
        });

        // Append-only: normalized SaaS audit events (inbound from SaaS providers)
        Schema::create('saas_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->foreignId('integration_id')->constrained('external_integrations')->cascadeOnDelete();
            $table->string('provider');                   // office365, gsuite, salesforce, github
            $table->string('actor_id')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('actor_ip')->nullable();
            $table->string('action');                     // login, file_download, permission_change, etc.
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('target_id')->nullable();
            $table->string('target_email')->nullable();
            $table->json('additional_details')->nullable();
            $table->boolean('is_suspicious')->default(false);
            $table->float('risk_score')->nullable();
            $table->string('source_country')->nullable();
            $table->string('user_agent')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
        });

        // Append-only: normalized identity provider events (inbound from IdP)
        Schema::create('identity_provider_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->foreignId('integration_id')->constrained('external_integrations')->cascadeOnDelete();
            $table->string('provider');                   // okta, azure_ad, ping, auth0
            $table->string('event_type');                 // login, mfa_challenge, password_reset, account_locked, etc.
            $table->string('user_id')->nullable();
            $table->string('user_email')->nullable();
            $table->string('source_ip')->nullable();
            $table->string('geo_country')->nullable();
            $table->string('user_agent')->nullable();
            $table->boolean('mfa_used')->default(false);
            $table->boolean('is_failed')->default(false);
            $table->integer('failed_attempt_count')->nullable();
            $table->boolean('is_suspicious')->default(false);
            $table->float('risk_score')->nullable();
            $table->json('raw_attributes')->nullable();
            $table->string('trace_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_provider_events');
        Schema::dropIfExists('saas_audit_events');
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('external_case_links');
        Schema::dropIfExists('integration_sync_events');
        Schema::dropIfExists('external_integrations');
    }
};
