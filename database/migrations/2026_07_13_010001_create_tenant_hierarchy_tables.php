<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // First-class tenant registry. Before this table, tenant_id was a bare
        // string convention scattered across ~19 tables with nothing
        // authoritative to reference — tenant_hierarchy below needs a real FK
        // target, so this registry has to exist first. Rows are created via
        // TenantHierarchyService::registerTenant() (upsert), not backfilled
        // from existing tenant_id values automatically — an MSSP link is an
        // explicit administrative action, not an inferred one.
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->unique();
            $table->string('name')->nullable();
            $table->string('status')->default('active'); // active | suspended
            $table->timestamps();
        });

        // Parent (MSSP) -> child (customer) tenant relationships. Mutable:
        // is_active supports unlinking without losing the relationship's
        // history (mirrors user_tenant_memberships' granted/revoked pattern).
        // CAP-MSSP-TENANCY: advisory, read-only rollups only — no autonomous
        // cross-tenant action is ever driven from this table.
        Schema::create('tenant_hierarchy', function (Blueprint $table) {
            $table->id();
            $table->string('parent_tenant_id');
            $table->string('child_tenant_id');
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('linked_by')->nullable();
            $table->unsignedBigInteger('unlinked_by')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();

            $table->foreign('parent_tenant_id')->references('tenant_id')->on('tenants');
            $table->foreign('child_tenant_id')->references('tenant_id')->on('tenants');
            $table->unique(['parent_tenant_id', 'child_tenant_id']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_hierarchy');
        Schema::dropIfExists('tenants');
    }
};
