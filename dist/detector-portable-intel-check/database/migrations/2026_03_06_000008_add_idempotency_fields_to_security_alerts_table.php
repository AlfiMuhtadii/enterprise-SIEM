<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            if (!Schema::hasColumn('security_alerts', 'detector_name')) {
                $table->string('detector_name', 64)->default('realtime')->after('alert_type')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'detector_version')) {
                $table->string('detector_version', 64)->default('v1')->after('detector_name')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'actor_key')) {
                $table->string('actor_key', 128)->nullable()->after('request_id')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'window_start')) {
                $table->timestampTz('window_start')->nullable()->after('event_id_ref')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'window_end')) {
                $table->timestampTz('window_end')->nullable()->after('window_start')->index();
            }
            if (!Schema::hasColumn('security_alerts', 'threshold_profile_hash')) {
                $table->string('threshold_profile_hash', 64)->nullable()->after('score')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('security_alerts', function (Blueprint $table) {
            foreach ([
                'detector_name',
                'detector_version',
                'actor_key',
                'window_start',
                'window_end',
                'threshold_profile_hash',
            ] as $col) {
                if (Schema::hasColumn('security_alerts', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
