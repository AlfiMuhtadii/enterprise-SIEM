<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BACKLOG-TENANCY-020: User-Tenant Membership Registry
 *
 * user_tenant_memberships — mutable; tracks which tenants a user is authorised
 *   to access. UPDATE allowed (is_active, revoked_by, revoked_at, granted_at,
 *   granted_by on re-grant). DELETE forbidden.
 *
 * tenant_membership_audit_events — append-only audit trail; one row per
 *   lifecycle event (granted / revoked / re_granted). Never updated or deleted.
 *
 * NOTE: PostgreSQL Row-Level Security (RLS) is NOT enabled here.
 * See docs/security/TENANT_NULL_MIGRATION_PLAN.md and
 * docs/security/TENANT_ISOLATION_POSTURE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tenant_memberships', function (Blueprint $table) {
            $table->id();
            $table->string('membership_id')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('tenant_id')->index();
            $table->unsignedBigInteger('granted_by');
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            $table->unique(['user_id', 'tenant_id']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::create('tenant_membership_audit_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('membership_id')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('tenant_id')->index();
            $table->string('event_type'); // granted | revoked | re_granted
            $table->unsignedBigInteger('actor_id');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // No updated_at — append-only. Never deleted or updated.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_membership_audit_events');
        Schema::dropIfExists('user_tenant_memberships');
    }
};
