<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * ENTERPRISE-052: Real Pilot Tenant Onboarding
     *
     * pilot_tenant_profiles   — mutable per-tenant config (upsert-safe by tenant_id)
     * pilot_tenant_onboarding_events — append-only audit trail
     *
     * NEVER DELETE rows from pilot_tenant_onboarding_events.
     */
    public function up(): void
    {
        Schema::create('pilot_tenant_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique()->index();
            $table->string('tenant_name', 128);
            $table->string('tenant_type', 32)->default('pilot');    // pilot | demo | staging
            $table->string('status', 32)->default('onboarding');    // onboarding | active | suspended
            $table->boolean('strict_mode_compatible')->default(false);
            $table->boolean('null_backfill_completed')->default(false);
            $table->unsignedInteger('member_count')->default(0);
            $table->unsignedInteger('alert_count')->default(0);
            $table->unsignedInteger('incident_count')->default(0);
            $table->boolean('is_advisory')->default(true);
            $table->string('onboarded_by')->nullable();
            $table->timestampTz('onboarded_at')->useCurrent();
            $table->timestampTz('last_validated_at')->nullable();
        });

        Schema::create('pilot_tenant_onboarding_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique()->index();
            $table->string('tenant_id')->index();
            $table->string('event_type', 64);   // profile_created | member_seeded | backfill_run | health_check | validation
            $table->string('status', 16)->default('ok');    // ok | warn | fail
            $table->text('details')->nullable();
            $table->boolean('is_advisory')->default(true);
            $table->timestampTz('occurred_at')->useCurrent();
            $table->index(['tenant_id', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pilot_tenant_onboarding_events');
        Schema::dropIfExists('pilot_tenant_profiles');
    }
};
