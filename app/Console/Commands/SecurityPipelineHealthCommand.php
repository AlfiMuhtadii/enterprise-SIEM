<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SecurityPipelineHealthCommand extends Command
{
    protected $signature = 'security:pipeline-health
                            {--minutes=15 : Freshness window in minutes}
                            {--redpanda=http://127.0.0.1:8082/topics : Redpanda health endpoint}
                            {--clickhouse=http://127.0.0.1:8123/ping : ClickHouse health endpoint}
                            {--fail-on-stale : Return failure when no fresh events or alerts exist}';

    protected $description = 'Show operational health for the security detection pipeline';

    public function handle(): int
    {
        $minutes = max(1, (int) $this->option('minutes'));
        $since = now()->subMinutes($minutes);

        $events = DB::table('security_events')->count();
        $alerts = DB::table('security_alerts')->count();
        $responses = DB::table('security_responses')->count();
        $freshEvents = DB::table('security_events')->where('ts', '>=', $since)->count();
        $freshAlerts = DB::table('security_alerts')->where('detected_at', '>=', $since)->count();
        $lastEvent = DB::table('security_events')->max('ts');
        $lastAlert = DB::table('security_alerts')->max('detected_at');

        $redpandaOk = $this->httpOk((string) $this->option('redpanda'));
        $clickhouseOk = $this->httpOk((string) $this->option('clickhouse'));

        $this->table(
            ['component', 'status', 'detail'],
            [
                ['redpanda_rest', $redpandaOk ? 'ok' : 'fail', (string) $this->option('redpanda')],
                ['clickhouse_http', $clickhouseOk ? 'ok' : 'fail', (string) $this->option('clickhouse')],
                ['events_total', 'ok', (string) $events],
                ['alerts_total', 'ok', (string) $alerts],
                ['responses_total', 'ok', (string) $responses],
                ["events_last_{$minutes}m", $freshEvents > 0 ? 'ok' : 'stale', (string) $freshEvents],
                ["alerts_last_{$minutes}m", $freshAlerts > 0 ? 'ok' : 'stale', (string) $freshAlerts],
                ['last_event_at', $lastEvent ? 'ok' : 'missing', (string) ($lastEvent ?? '')],
                ['last_alert_at', $lastAlert ? 'ok' : 'missing', (string) ($lastAlert ?? '')],
            ]
        );

        if ($this->option('fail-on-stale') && ($freshEvents === 0 || $freshAlerts === 0 || ! $redpandaOk || ! $clickhouseOk)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function httpOk(string $url): bool
    {
        try {
            return Http::timeout(3)->get($url)->status() < 500;
        } catch (\Throwable) {
            return false;
        }
    }
}
