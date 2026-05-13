<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class XdrIdentityRiskCommand extends Command
{
    protected $signature = 'xdr:identity-risk {--minutes=1440}';

    protected $description = 'Build identity-centric XDR risk timelines from identity, SaaS, cloud, and alert telemetry.';

    public function handle(): int
    {
        $since = now()->subMinutes(max(15, (int) $this->option('minutes')));
        $events = DB::table('telemetry_events')
            ->where('ts', '>=', $since)
            ->whereNotNull('xdr_user')
            ->whereIn('telemetry_type', ['identity', 'saas', 'cloud', 'email'])
            ->orderBy('ts')
            ->get()
            ->groupBy('xdr_user');

        foreach ($events as $user => $rows) {
            $failed = $rows->filter(fn ($row) => str_contains((string) $row->event_type, 'failed') || $row->xdr_result === 'failure')->count();
            $sources = $rows->pluck('source_ip')->filter()->unique()->values();
            $highRisk = $rows->where('risk_score', '>=', 0.7)->count();
            $privileged = $rows->filter(fn ($row) => str_contains(strtolower((string) $row->event_type.' '.$row->xdr_action), 'admin') || str_contains(strtolower((string) $row->event_type), 'privilege'))->count();
            $risk = min(1, ($failed * 0.08) + ($sources->count() * 0.07) + ($highRisk * 0.18) + ($privileged * 0.22));
            DB::table('xdr_identity_risk_timelines')->insert([
                'user_key' => $user,
                'risk_score' => round($risk, 3),
                'risk_factors' => json_encode([
                    'failed_logins' => $failed,
                    'unique_sources' => $sources,
                    'high_risk_events' => $highRisk,
                    'privileged_activity' => $privileged,
                    'cross_service_events' => $rows->pluck('event_source')->filter()->unique()->values(),
                ]),
                'session_anomalies' => json_encode(['source_ip_count' => $sources->count(), 'impossible_travel_proxy' => $sources->count() >= 2]),
                'mfa_anomalies' => json_encode(['failure_burst' => $failed >= 5]),
                'privileged_activity' => json_encode(['count' => $privileged]),
                'first_seen_at' => $rows->min('ts'),
                'last_seen_at' => $rows->max('ts'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->line("{$user}: risk=".round($risk, 3));
        }

        return self::SUCCESS;
    }
}
