<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX IF NOT EXISTS telemetry_events_ts_id_idx ON telemetry_events (ts, id)');
        DB::statement('CREATE INDEX IF NOT EXISTS telemetry_events_src_ts_idx ON telemetry_events (src_ip, ts)');
        DB::statement('CREATE INDEX IF NOT EXISTS telemetry_events_dst_ts_idx ON telemetry_events (dst_ip, ts)');
        DB::statement('CREATE INDEX IF NOT EXISTS security_alerts_detector_ts_idx ON security_alerts (detector_name, detected_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS security_alerts_incident_ts_idx ON security_alerts (incident_id, detected_at)');
        DB::statement('CREATE INDEX IF NOT EXISTS security_alerts_dedup_ts_idx ON security_alerts (dedup_group, detected_at)');
    }

    public function down(): void
    {
        foreach ([
            'telemetry_events_ts_id_idx',
            'telemetry_events_src_ts_idx',
            'telemetry_events_dst_ts_idx',
            'security_alerts_detector_ts_idx',
            'security_alerts_incident_ts_idx',
            'security_alerts_dedup_ts_idx',
        ] as $idx) {
            DB::statement("DROP INDEX IF EXISTS {$idx}");
        }
    }
};
