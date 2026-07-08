<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // entities.tenant_id already exists (2026_06_29_080001) but the sole
        // DB-level uniqueness guarantee is entities_type_key_unique
        // (entity_type, entity_key) — no tenant_id. That's the exact root
        // cause of ENT-TENANCY-ENTITY-GRAPH: two tenants observing the same
        // entity_key (a shared IP, a common email) upsert into the SAME row.
        //
        // Replaced with an expression-based unique index using
        // COALESCE(tenant_id, '_none') rather than a plain multi-column
        // unique(entity_type, entity_key, tenant_id): Postgres treats NULL
        // as distinct from NULL in unique constraints, so a plain
        // multi-column constraint would silently stop deduplicating every
        // existing legacy/demo entity (tenant_id IS NULL) the moment it
        // shipped. COALESCE keeps ALL null-tenant rows in one shared
        // "_none" bucket — preserving today's exact dedup behavior for
        // legacy data — while genuinely separating any two distinct
        // non-null tenant_id values.
        Schema::table('entities', function (Blueprint $table) {
            $table->dropUnique('entities_type_key_unique');
        });
        DB::statement(
            "CREATE UNIQUE INDEX entities_type_key_tenant_unique ON entities (entity_type, entity_key, COALESCE(tenant_id, '_none'))"
        );

        // entity_relationships has no tenant_id at all today, and no DB-level
        // unique constraint either (uniqueness is app-enforced in
        // EntityGraphService::upsertRelationship() via a plain SELECT before
        // INSERT) — so no constraint migration is needed here, only the
        // column, to let the same app-level lookup include tenant_id.
        Schema::table('entity_relationships', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->index()->after('target_entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('entity_relationships', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });

        DB::statement('DROP INDEX IF EXISTS entities_type_key_tenant_unique');
        Schema::table('entities', function (Blueprint $table) {
            $table->unique(['entity_type', 'entity_key'], 'entities_type_key_unique');
        });
    }
};
