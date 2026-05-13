<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_events', function (Blueprint $table) {
            foreach ([
                'xdr_user' => 160,
                'xdr_host' => 160,
                'source_ip' => 64,
                'destination_ip' => 64,
                'domain' => 255,
                'file_hash' => 128,
                'email_sender' => 255,
                'email_recipient' => 255,
                'cloud_account' => 160,
                'xdr_action' => 160,
                'xdr_result' => 80,
                'event_source' => 120,
            ] as $column => $length) {
                if (!Schema::hasColumn('telemetry_events', $column)) {
                    $table->string($column, $length)->nullable()->index();
                }
            }
            if (!Schema::hasColumn('telemetry_events', 'risk_score')) {
                $table->float('risk_score')->nullable()->index();
            }
        });

        Schema::table('security_incidents', function (Blueprint $table) {
            foreach (['xdr_domains', 'involved_users', 'involved_hosts', 'involved_cloud_accounts', 'involved_email_artifacts', 'involved_external_ips', 'xdr_kill_chain_summary'] as $column) {
                if (!Schema::hasColumn('security_incidents', $column)) {
                    $table->jsonb($column)->nullable();
                }
            }
        });

        DB::statement('CREATE INDEX IF NOT EXISTS telemetry_events_xdr_user_ts_idx ON telemetry_events (xdr_user, ts)');
        DB::statement('CREATE INDEX IF NOT EXISTS telemetry_events_event_source_ts_idx ON telemetry_events (event_source, ts)');
        DB::statement('CREATE INDEX IF NOT EXISTS telemetry_events_cloud_account_ts_idx ON telemetry_events (cloud_account, ts)');
        DB::statement('CREATE INDEX IF NOT EXISTS telemetry_events_domain_ts_idx ON telemetry_events (domain, ts)');
    }

    public function down(): void
    {
        foreach ([
            'telemetry_events_xdr_user_ts_idx',
            'telemetry_events_event_source_ts_idx',
            'telemetry_events_cloud_account_ts_idx',
            'telemetry_events_domain_ts_idx',
        ] as $index) {
            DB::statement("DROP INDEX IF EXISTS {$index}");
        }

        Schema::table('security_incidents', function (Blueprint $table) {
            foreach (['xdr_domains', 'involved_users', 'involved_hosts', 'involved_cloud_accounts', 'involved_email_artifacts', 'involved_external_ips', 'xdr_kill_chain_summary'] as $column) {
                if (Schema::hasColumn('security_incidents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('telemetry_events', function (Blueprint $table) {
            foreach (['xdr_user', 'xdr_host', 'source_ip', 'destination_ip', 'domain', 'file_hash', 'email_sender', 'email_recipient', 'cloud_account', 'xdr_action', 'xdr_result', 'risk_score', 'event_source'] as $column) {
                if (Schema::hasColumn('telemetry_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
