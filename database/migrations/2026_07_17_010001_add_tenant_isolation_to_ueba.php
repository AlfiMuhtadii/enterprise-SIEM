<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // entity_behavior_baselines: DB-level dedup was
        // ueba_baseline_entity_dimension_unique on (entity_key, entity_type,
        // dimension) — no tenant_id. Two tenants observing the same
        // entity_key (a shared username, a shared host label) would merge
        // into ONE rolling baseline, skewing anomaly detection with the
        // other tenant's traffic. Replaced with an expression-based unique
        // index using COALESCE(tenant_id, '_none'), same technique as
        // ENT-TENANCY-ENTITY-GRAPH's entities_type_key_tenant_unique — every
        // null-tenant row stays in one shared bucket (preserving today's
        // exact dedup behavior for legacy data) while genuinely separating
        // any two distinct non-null tenant values.
        Schema::table('entity_behavior_baselines', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->index()->after('entity_key');
        });
        Schema::table('entity_behavior_baselines', function (Blueprint $table) {
            $table->dropUnique('ueba_baseline_entity_dimension_unique');
        });
        DB::statement(
            "CREATE UNIQUE INDEX ueba_baseline_entity_dimension_tenant_unique ON entity_behavior_baselines (entity_key, entity_type, dimension, COALESCE(tenant_id, '_none'))"
        );

        // baseline_observations, baseline_anomaly_scores: append-only (never
        // UPDATEd or DELETEd by this migration, only given a new nullable
        // column) — no DB-level unique constraint exists on either, so no
        // constraint change is needed.
        Schema::table('baseline_observations', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->index()->after('entity_key');
        });
        Schema::table('baseline_anomaly_scores', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->index()->after('entity_key');
        });

        // peer_group_profiles: DB-level uniqueness was a bare peer_group_key
        // column unique constraint. UEBABaselineService::deriveGroupKey()
        // produces GLOBAL keys like "user_role:admin" with no tenant
        // component at all — two tenants' admin users would be assigned to
        // the exact same peer group, mixing their behavioral baselines
        // together (the core of this finding: "peer grouping calculated
        // globally... allows cross-tenant visibility of user/host
        // anomalies"). Same COALESCE-expression-index fix as above.
        Schema::table('peer_group_profiles', function (Blueprint $table) {
            $table->string('tenant_id', 36)->nullable()->index()->after('peer_group_key');
        });
        Schema::table('peer_group_profiles', function (Blueprint $table) {
            $table->dropUnique(['peer_group_key']);
        });
        DB::statement(
            "CREATE UNIQUE INDEX peer_group_profiles_key_tenant_unique ON peer_group_profiles (peer_group_key, COALESCE(tenant_id, '_none'))"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS peer_group_profiles_key_tenant_unique');
        Schema::table('peer_group_profiles', function (Blueprint $table) {
            $table->unique('peer_group_key');
            $table->dropColumn('tenant_id');
        });

        Schema::table('baseline_anomaly_scores', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });
        Schema::table('baseline_observations', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });

        DB::statement('DROP INDEX IF EXISTS ueba_baseline_entity_dimension_tenant_unique');
        Schema::table('entity_behavior_baselines', function (Blueprint $table) {
            $table->unique(['entity_key', 'entity_type', 'dimension'], 'ueba_baseline_entity_dimension_unique');
            $table->dropColumn('tenant_id');
        });
    }
};
