<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            if (!Schema::hasColumn('security_alerts', 'alert_fingerprint')) {
                $table->string('alert_fingerprint', 96)->nullable()->after('alert_id')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'dedup_group')) {
                $table->string('dedup_group', 160)->nullable()->after('alert_fingerprint')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'suppressed_until')) {
                $table->timestampTz('suppressed_until')->nullable()->after('dedup_group')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'is_suppressed')) {
                $table->boolean('is_suppressed')->default(false)->after('suppressed_until')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'incident_id')) {
                $table->string('incident_id', 80)->nullable()->after('is_suppressed')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            foreach (['alert_fingerprint', 'dedup_group', 'suppressed_until', 'is_suppressed', 'incident_id'] as $col) {
                if (Schema::hasColumn('security_alerts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
