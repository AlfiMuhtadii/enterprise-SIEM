<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // IOC-HITS-IDEMPOTENCY: ioc_hits had no unique key, so re-running IOC
        // enrichment (POST /soc/threat-intel/enrich) appended duplicate hit rows
        // every time. Remove any pre-existing duplicate (ioc_id, alert_id) rows —
        // keeping the earliest — then add a unique index so insertOrIgnore actually
        // de-duplicates and enrichment becomes idempotent.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'DELETE FROM ioc_hits a USING ioc_hits b '
                .'WHERE a.id > b.id AND a.ioc_id = b.ioc_id AND a.alert_id = b.alert_id'
            );
        }

        Schema::table('ioc_hits', function (Blueprint $table) {
            $table->unique(['ioc_id', 'alert_id'], 'ioc_hits_ioc_alert_unique');
        });
    }

    public function down(): void
    {
        Schema::table('ioc_hits', function (Blueprint $table) {
            $table->dropUnique('ioc_hits_ioc_alert_unique');
        });
    }
};
