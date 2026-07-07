<?php

namespace App\Services;

use App\Models\DetectionBacktestMatch;
use App\Models\DetectionBacktestRun;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CAP-DETECT-BACKTEST: replay-safe historical backtest for a bounded set of
 * identity/cloud/saas detection rules. Reports an advisory "would-have-
 * fired" count + sample matches over a trailing window of retained
 * normalized telemetry (telemetry_events) — before committing a candidate
 * rule to a live soak.
 *
 * Deliberately NOT a generic rule evaluator: registry.v1.json has no
 * field-condition/expression language, and every existing rule's real
 * match logic is hand-coded (Go for endpoint/network shadow rules, Python
 * for identity/cloud/saas in scripts/xdr_correlation_detector.py).
 * Building a generic DSL here would be new, speculative architecture, not
 * a bounded extension. Instead, this service PORTS the exact thresholds
 * from detect_identity()/detect_cloud_saas() in that Python script into
 * PHP, evaluated read-only against telemetry_events — mirroring the
 * Collection::groupBy pattern already used by
 * App\Console\Commands\XdrIdentityRiskCommand rather than inventing a new
 * query convention.
 *
 * Absolutely never writes to security_alerts or security_incidents (the
 * tables the Python script's insert_alerts()/expand_incidents() write to)
 * — only detection_backtest_runs/detection_backtest_matches, both
 * dedicated advisory-only tables.
 */
class DetectionBacktestService
{
    public const IDENTITY_RULE_IDS = [
        'IDENTITY_MFA_FAILURE_BURST',
        'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES',
        'IDENTITY_RISKY_IP_LOGIN',
        'IDENTITY_IMPOSSIBLE_TRAVEL',
        'IDENTITY_PRIVILEGE_ESCALATION',
        'IDENTITY_UNUSUAL_LOGIN_SOURCE',
    ];

    public const CLOUD_SAAS_RULE_IDS = [
        'CLOUD_UNUSUAL_API_ACTIVITY',
        'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
        'CLOUD_MASS_DOWNLOAD',
        'CLOUD_NEW_ACCESS_KEY',
        'CLOUD_SECURITY_SETTING_MODIFIED',
        'SAAS_UNUSUAL_ADMIN_ACTIVITY',
    ];

    public const SUPPORTED_RULE_IDS = [...self::IDENTITY_RULE_IDS, ...self::CLOUD_SAAS_RULE_IDS];

    public function run(array $ruleIds, int $days, ?int $triggeredBy = null): DetectionBacktestRun
    {
        $unsupported = array_diff($ruleIds, self::SUPPORTED_RULE_IDS);
        if ($unsupported !== []) {
            throw new \InvalidArgumentException('Unsupported rule_id(s) for backtest: '.implode(', ', $unsupported));
        }

        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subDays($days);

        $events = DB::table('telemetry_events')
            ->whereIn('telemetry_type', ['identity', 'cloud', 'saas'])
            ->whereBetween('ts', [$windowStart, $windowEnd])
            ->orderBy('ts')
            ->get();

        $run = DetectionBacktestRun::create([
            'run_id' => 'btr_'.Str::uuid(),
            'rule_ids' => array_values($ruleIds),
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'telemetry_event_count' => $events->count(),
            'triggered_by' => $triggeredBy,
            'created_at' => now(),
        ]);

        $identityEvents = $events->where('telemetry_type', 'identity')->groupBy('xdr_user');
        $identityByIp = $events->where('telemetry_type', 'identity')
            ->groupBy(fn ($row) => $row->source_ip ?: $row->src_ip);
        $cloudSaasEvents = $events->whereIn('telemetry_type', ['cloud', 'saas'])
            ->groupBy(fn ($row) => $row->xdr_user ?: $row->cloud_account ?: 'unknown');

        foreach ($ruleIds as $ruleId) {
            $this->evaluateRule($run, $ruleId, $identityEvents, $identityByIp, $cloudSaasEvents);
        }

        return $run;
    }

    private function evaluateRule(
        DetectionBacktestRun $run,
        string $ruleId,
        Collection $identityByUser,
        Collection $identityByIp,
        Collection $cloudSaasByActor,
    ): void {
        match ($ruleId) {
            'IDENTITY_MFA_FAILURE_BURST' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($rows) => $this->failedCount($rows) >= 5),
            'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES' => $this->forEachActor($run, $ruleId, $identityByUser, function ($rows) {
                $failed = $rows->filter(fn ($r) => $this->isFailed($r));
                return $failed->count() >= 4 && $failed->pluck('event_source')->filter()->unique()->count() >= 2;
            }),
            'IDENTITY_RISKY_IP_LOGIN' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($rows) => $rows->contains(
                fn ($r) => (float) $r->risk_score >= 0.7 && $r->event_type === 'login_success'
            )),
            'IDENTITY_IMPOSSIBLE_TRAVEL' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($rows) => $rows
                ->where('event_type', 'login_success')
                ->map(fn ($r) => $r->source_ip ?: $r->src_ip)
                ->filter()
                ->unique()
                ->count() >= 2),
            'IDENTITY_PRIVILEGE_ESCALATION' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($rows) => $rows->contains(
                fn ($r) => str_contains(strtolower((string) $r->event_type), 'privilege')
                    || str_contains(strtolower((string) $r->xdr_action), 'role')
                    || str_contains(strtolower((string) $r->xdr_action), 'admin')
            )),
            'IDENTITY_UNUSUAL_LOGIN_SOURCE' => $this->forEachActor($run, $ruleId, $identityByIp, fn ($rows) => $rows
                ->pluck('xdr_user')->filter()->unique()->count() >= 3),
            'CLOUD_UNUSUAL_API_ACTIVITY' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($rows) => $rows
                ->filter(fn ($r) => (float) $r->risk_score >= 0.7)->count() >= 3),
            'CLOUD_SUSPICIOUS_OBJECT_ACCESS' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($rows) => $rows
                ->filter(fn ($r) => str_contains(strtolower((string) $r->event_type), 'object')
                    || str_contains(strtolower((string) $r->xdr_action), 'getobject'))->count() >= 5),
            'CLOUD_MASS_DOWNLOAD' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($rows) => $rows
                ->filter(fn ($r) => str_contains(strtolower((string) ($r->event_type ?: $r->xdr_action)), 'download'))->count() >= 10),
            'CLOUD_NEW_ACCESS_KEY' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($rows) => $rows->contains(
                fn ($r) => str_contains(strtolower((string) $r->event_type), 'access_key') || $r->xdr_action === 'CreateAccessKey'
            )),
            'CLOUD_SECURITY_SETTING_MODIFIED' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($rows) => $rows->contains(
                fn ($r) => str_contains(strtolower((string) $r->event_type), 'security_setting')
                    || str_contains(strtolower((string) $r->xdr_action), 'policy')
            )),
            'SAAS_UNUSUAL_ADMIN_ACTIVITY' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($rows) => $rows->contains('telemetry_type', 'saas')
                && $rows->contains(fn ($r) => str_contains(strtolower((string) ($r->xdr_action ?: $r->event_type)), 'admin'))),
            default => null,
        };
    }

    private function forEachActor(DetectionBacktestRun $run, string $ruleId, Collection $groups, \Closure $matches): void
    {
        foreach ($groups as $actorKey => $rows) {
            if ($actorKey === '' || $actorKey === null) {
                continue;
            }
            if (!$matches($rows)) {
                continue;
            }
            DetectionBacktestMatch::create([
                'match_id' => 'btm_'.Str::uuid(),
                'run_id' => $run->run_id,
                'rule_id' => $ruleId,
                'actor_key' => (string) $actorKey,
                'event_count' => $rows->count(),
                'sample_events' => $rows->take(5)->map(fn ($r) => [
                    'ts' => (string) $r->ts,
                    'event_type' => $r->event_type,
                    'event_source' => $r->event_source ?? null,
                ])->values()->all(),
                'created_at' => now(),
            ]);
        }
    }

    private function failedCount(Collection $rows): int
    {
        return $rows->filter(fn ($r) => $this->isFailed($r))->count();
    }

    private function isFailed($row): bool
    {
        return in_array($row->event_type, ['login_failed', 'mfa_failed'], true) || $row->xdr_result === 'failure';
    }
}
