<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PERF-SIEM-FALLBACK-SCAN: SiemSearchService::searchPostgres() (the
 * OpenSearch-unavailable fallback path) does `evidence::text ILIKE ?` /
 * `raw_event::text ILIKE ?` alongside indexed exact/ILIKE columns
 * (alert_type/ip/detector_name), all inside one OR group. A single OR
 * group mixing indexed and unindexed conditions can't be satisfied by an
 * index-only scan for the unindexed half, forcing Postgres to fall back to
 * scanning every row that matches tenant_id+detected_at (already indexed,
 * but not as a composite) and only then apply the expensive `::text` casts
 * and ILIKE row-by-row -- a real DoS/latency risk under enterprise alert
 * volume when OpenSearch happens to be down (exactly when search still
 * needs to work).
 *
 * Fixed at the index level, not by changing the query or dropping free-text
 * search capability: `pg_trgm` is Postgres's standard, widely-used contrib
 * extension for making `ILIKE '%...%'` (leading-wildcard) queries use a GIN
 * index instead of a sequential scan -- ships with virtually every Postgres
 * distribution, no non-standard dependency. A composite (tenant_id,
 * detected_at) index lets the *other* half of the WHERE clause (every
 * fallback search already scopes to one tenant + a bounded <=30-day window)
 * use a single index instead of two separate ones.
 *
 * Verified live (not just "the migration ran"): with only evidence/raw_event
 * trigram-indexed, EXPLAIN ANALYZE against 158k seeded rows still chose a
 * full (parallel) sequential scan, 1.17s -- because alert_type/ip/
 * detector_name only had plain btree indexes, which cannot accelerate a
 * leading-wildcard ILIKE at all. A single OR group needs *every* branch to
 * have a usable index before Postgres will build a BitmapOr plan instead of
 * falling back to a full scan for the whole predicate -- a partially
 * indexed OR clause gets zero benefit. Adding trigram indexes on all 5
 * ILIKE'd columns (not just the two JSON ones) is what actually closes the
 * gap; re-verified after this change that the planner switches to a Bitmap
 * Heap Scan combining all 5 GIN indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::table('security_alerts', function ($table) {
            $table->index(['tenant_id', 'detected_at'], 'security_alerts_tenant_detected_idx');
        });

        DB::statement('CREATE INDEX security_alerts_evidence_trgm_idx ON security_alerts USING gin ((evidence::text) gin_trgm_ops)');
        DB::statement('CREATE INDEX security_alerts_raw_event_trgm_idx ON security_alerts USING gin ((raw_event::text) gin_trgm_ops)');
        DB::statement('CREATE INDEX security_alerts_alert_type_trgm_idx ON security_alerts USING gin (alert_type gin_trgm_ops)');
        DB::statement('CREATE INDEX security_alerts_ip_trgm_idx ON security_alerts USING gin (ip gin_trgm_ops)');
        DB::statement('CREATE INDEX security_alerts_detector_name_trgm_idx ON security_alerts USING gin (detector_name gin_trgm_ops)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS security_alerts_detector_name_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS security_alerts_ip_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS security_alerts_alert_type_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS security_alerts_raw_event_trgm_idx');
        DB::statement('DROP INDEX IF EXISTS security_alerts_evidence_trgm_idx');

        Schema::table('security_alerts', function ($table) {
            $table->dropIndex('security_alerts_tenant_detected_idx');
        });
    }
};
