<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-RESIDENCY-ERASURE: per-tenant retention overrides + GDPR erasure
 * request lifecycle. security_events/security_alerts/security_incidents are
 * NOT in claude.md's append-only table list, so deletion is permitted; the
 * audit trail of who requested/approved/executed an erasure IS append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_retention_policies', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->unsignedInteger('events_days')->nullable();
            $table->unsignedInteger('alerts_days')->nullable();
            $table->unsignedInteger('incidents_days')->nullable();
            $table->string('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('data_erasure_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_id', 64)->unique();
            $table->string('tenant_id');
            $table->string('reason', 2000);
            $table->string('status', 20)->default('pending');
            $table->string('requested_by');
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('dry_run')->default(true);
            $table->timestamp('executed_at')->nullable();
            $table->json('execution_summary')->nullable();
            $table->timestamps();
            $table->index('tenant_id');
            $table->index('status');
        });

        Schema::create('data_erasure_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('audit_id', 64)->unique();
            $table->unsignedBigInteger('request_id');
            $table->string('event_type', 20);
            $table->string('tenant_id');
            $table->string('table_name')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('actor');
            $table->json('details')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign('request_id')->references('id')->on('data_erasure_requests');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_erasure_audit_events');
        Schema::dropIfExists('data_erasure_requests');
        Schema::dropIfExists('tenant_retention_policies');
    }
};
