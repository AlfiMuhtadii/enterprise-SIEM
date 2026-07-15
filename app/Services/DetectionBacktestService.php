<?php

namespace App\Services;

use App\Models\DetectionBacktestMatch;
use App\Models\DetectionBacktestRun;
use Illuminate\Support\Carbon;
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
 * PHP, evaluated read-only against telemetry_events.
 *
 * PERF-BACKTEST-OOM: a 30-day window can hold far more rows than fit in
 * PHP memory at once. Rather than loading every row into a Collection and
 * grouping it 3 ways (the previous implementation), the window is streamed
 * in bounded chunks (Postgres: chunkById(); ClickHouse: LIMIT/OFFSET
 * pages), and only a small per-actor accumulator (a handful of counters,
 * booleans, and bounded sets — memory proportional to the number of
 * DISTINCT actors, never to the number of events) is kept across the
 * whole window. Each rule's threshold check now reads from that
 * accumulator instead of re-scanning raw rows.
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

    /** Rows held in memory per chunk/page — bounds peak memory regardless of window size. */
    public const CHUNK_SIZE = 2000;

    /** Sample events kept per matching actor (unchanged from the original behavior). */
    private const MAX_SAMPLES = 5;

    public function run(array $ruleIds, int $days, ?int $triggeredBy = null): DetectionBacktestRun
    {
        $unsupported = array_diff($ruleIds, self::SUPPORTED_RULE_IDS);
        if ($unsupported !== []) {
            throw new \InvalidArgumentException('Unsupported rule_id(s) for backtest: '.implode(', ', $unsupported));
        }

        $windowEnd = now();
        $windowStart = $windowEnd->copy()->subDays($days);

        // ARCH-DB-SPLIT (read path): same ClickHouse-when-configured,
        // Postgres-fallback-on-failure pattern as the other migrated read
        // paths. Safe to migrate despite reading the same identity/cloud/
        // saas range the 2 excluded correlation detectors also read —
        // this service only ever writes to the advisory-only
        // detection_backtest_runs/detection_backtest_matches tables, never
        // to security_alerts/security_incidents (see class docblock).
        //
        // All-or-nothing fallback: ClickHouse is accumulated into its own
        // scratch state first. If any page fails partway through, that
        // partial state is discarded entirely and Postgres is streamed
        // from scratch — never a mix of both backends' rows in one run.
        $identityByUser = [];
        $identityByIp = [];
        $cloudSaasByActor = [];
        $eventCount = 0;

        $usedClickHouse = false;
        if (config('xdr.infrastructure.clickhouse.telemetry_write_target') === 'clickhouse') {
            $chIdentityByUser = [];
            $chIdentityByIp = [];
            $chCloudSaasByActor = [];
            $chEventCount = 0;
            $ok = $this->streamClickHouse($windowStart, $windowEnd, function ($row) use (&$chIdentityByUser, &$chIdentityByIp, &$chCloudSaasByActor, &$chEventCount) {
                $chEventCount++;
                $this->accumulateRow($chIdentityByUser, $chIdentityByIp, $chCloudSaasByActor, $row);
            });
            if ($ok) {
                $identityByUser = $chIdentityByUser;
                $identityByIp = $chIdentityByIp;
                $cloudSaasByActor = $chCloudSaasByActor;
                $eventCount = $chEventCount;
                $usedClickHouse = true;
            }
        }

        if (! $usedClickHouse) {
            $this->streamPostgres($windowStart, $windowEnd, function ($row) use (&$identityByUser, &$identityByIp, &$cloudSaasByActor, &$eventCount) {
                $eventCount++;
                $this->accumulateRow($identityByUser, $identityByIp, $cloudSaasByActor, $row);
            });
        }

        $run = DetectionBacktestRun::create([
            'run_id' => 'btr_'.Str::uuid(),
            'rule_ids' => array_values($ruleIds),
            'window_start' => $windowStart,
            'window_end' => $windowEnd,
            'telemetry_event_count' => $eventCount,
            'triggered_by' => $triggeredBy,
            'created_at' => now(),
        ]);

        foreach ($ruleIds as $ruleId) {
            $this->evaluateRule($run, $ruleId, $identityByUser, $identityByIp, $cloudSaasByActor);
        }

        return $run;
    }

    private function streamPostgres(Carbon $windowStart, Carbon $windowEnd, \Closure $onRow): void
    {
        DB::table('telemetry_events')
            ->whereIn('telemetry_type', ['identity', 'cloud', 'saas'])
            ->whereBetween('ts', [$windowStart, $windowEnd])
            ->chunkById(self::CHUNK_SIZE, function ($rows) use ($onRow) {
                foreach ($rows as $row) {
                    $onRow($row);
                }
            });
    }

    /**
     * Returns false (caller falls back to Postgres) if any page fails.
     */
    private function streamClickHouse(Carbon $windowStart, Carbon $windowEnd, \Closure $onRow): bool
    {
        $reader = new ClickHouseTelemetryReader;
        $offset = 0;
        while (true) {
            $page = $reader->identityCloudSaasWindowPage($windowStart, $windowEnd, self::CHUNK_SIZE, $offset);
            if ($page === null) {
                return false;
            }
            foreach ($page as $row) {
                $onRow($row);
            }
            if ($page->count() < self::CHUNK_SIZE) {
                break;
            }
            $offset += self::CHUNK_SIZE;
        }

        return true;
    }

    /**
     * Routes one raw telemetry row into the accumulator map(s) it belongs
     * to. Mirrors the original grouping exactly: identity rows update
     * BOTH the by-user and by-ip maps (independently, each only if its own
     * key is non-empty); cloud/saas rows update the by-actor map (actor
     * key falls back to the literal string 'unknown', never empty).
     */
    private function accumulateRow(array &$identityByUser, array &$identityByIp, array &$cloudSaasByActor, $row): void
    {
        if ($row->telemetry_type === 'identity') {
            $userKey = (string) ($row->xdr_user ?? '');
            if ($userKey !== '') {
                $identityByUser[$userKey] ??= $this->newAccumulator();
                $this->accumulateIdentityByUser($identityByUser[$userKey], $row);
            }
            $ipKey = (string) ($row->source_ip ?: ($row->src_ip ?? ''));
            if ($ipKey !== '') {
                $identityByIp[$ipKey] ??= $this->newAccumulator();
                $this->accumulateIdentityByIp($identityByIp[$ipKey], $row);
            }
        } elseif (in_array($row->telemetry_type, ['cloud', 'saas'], true)) {
            $actorKey = (string) ($row->xdr_user ?: ($row->cloud_account ?: 'unknown'));
            $cloudSaasByActor[$actorKey] ??= $this->newAccumulator();
            $this->accumulateCloudSaas($cloudSaasByActor[$actorKey], $row);
        }
    }

    private function newAccumulator(): array
    {
        return [
            'event_count' => 0,
            'failed_count' => 0,
            'failed_event_sources' => [],
            'has_risky_ip_login' => false,
            'login_success_ips' => [],
            'has_privilege_escalation' => false,
            'distinct_users' => [],
            'high_risk_count' => 0,
            'object_access_count' => 0,
            'download_count' => 0,
            'has_new_access_key' => false,
            'has_security_setting_modified' => false,
            'has_saas_type' => false,
            'has_saas_admin_activity' => false,
            'samples' => [],
        ];
    }

    private function accumulateIdentityByUser(array &$acc, $row): void
    {
        $acc['event_count']++;
        if ($this->isFailed($row)) {
            $acc['failed_count']++;
            if (! empty($row->event_source)) {
                $acc['failed_event_sources'][$row->event_source] = true;
            }
        }
        if ((float) $row->risk_score >= 0.7 && $row->event_type === 'login_success') {
            $acc['has_risky_ip_login'] = true;
        }
        if ($row->event_type === 'login_success') {
            $ip = $row->source_ip ?: $row->src_ip;
            if (! empty($ip)) {
                $acc['login_success_ips'][$ip] = true;
            }
        }
        $eventType = strtolower((string) $row->event_type);
        $xdrAction = strtolower((string) $row->xdr_action);
        if (str_contains($eventType, 'privilege') || str_contains($xdrAction, 'role') || str_contains($xdrAction, 'admin')) {
            $acc['has_privilege_escalation'] = true;
        }
        $this->addSample($acc, $row);
    }

    private function accumulateIdentityByIp(array &$acc, $row): void
    {
        $acc['event_count']++;
        if (! empty($row->xdr_user)) {
            $acc['distinct_users'][$row->xdr_user] = true;
        }
        $this->addSample($acc, $row);
    }

    private function accumulateCloudSaas(array &$acc, $row): void
    {
        $acc['event_count']++;
        if ((float) $row->risk_score >= 0.7) {
            $acc['high_risk_count']++;
        }
        $eventType = strtolower((string) $row->event_type);
        $xdrAction = strtolower((string) $row->xdr_action);
        if (str_contains($eventType, 'object') || str_contains($xdrAction, 'getobject')) {
            $acc['object_access_count']++;
        }
        $downloadSource = strtolower((string) ($row->event_type ?: $row->xdr_action));
        if (str_contains($downloadSource, 'download')) {
            $acc['download_count']++;
        }
        if (str_contains($eventType, 'access_key') || $row->xdr_action === 'CreateAccessKey') {
            $acc['has_new_access_key'] = true;
        }
        if (str_contains($eventType, 'security_setting') || str_contains($xdrAction, 'policy')) {
            $acc['has_security_setting_modified'] = true;
        }
        if ($row->telemetry_type === 'saas') {
            $acc['has_saas_type'] = true;
        }
        $adminSource = strtolower((string) ($row->xdr_action ?: $row->event_type));
        if (str_contains($adminSource, 'admin')) {
            $acc['has_saas_admin_activity'] = true;
        }
        $this->addSample($acc, $row);
    }

    /**
     * Keeps the MAX_SAMPLES earliest-by-ts rows seen for this actor,
     * regardless of the order chunks/pages happen to arrive in (Postgres
     * chunks by id, not ts; ts is not guaranteed unique across rows).
     */
    private function addSample(array &$acc, $row): void
    {
        $sample = ['ts' => (string) $row->ts, 'event_type' => $row->event_type, 'event_source' => $row->event_source ?? null];

        if (count($acc['samples']) < self::MAX_SAMPLES) {
            $acc['samples'][] = $sample;

            return;
        }

        $maxIdx = 0;
        foreach ($acc['samples'] as $i => $s) {
            if ($s['ts'] > $acc['samples'][$maxIdx]['ts']) {
                $maxIdx = $i;
            }
        }
        if ($sample['ts'] < $acc['samples'][$maxIdx]['ts']) {
            $acc['samples'][$maxIdx] = $sample;
        }
    }

    private function evaluateRule(
        DetectionBacktestRun $run,
        string $ruleId,
        array $identityByUser,
        array $identityByIp,
        array $cloudSaasByActor,
    ): void {
        match ($ruleId) {
            'IDENTITY_MFA_FAILURE_BURST' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($acc) => $acc['failed_count'] >= 5),
            'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($acc) => $acc['failed_count'] >= 4 && count($acc['failed_event_sources']) >= 2),
            'IDENTITY_RISKY_IP_LOGIN' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($acc) => $acc['has_risky_ip_login']),
            'IDENTITY_IMPOSSIBLE_TRAVEL' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($acc) => count($acc['login_success_ips']) >= 2),
            'IDENTITY_PRIVILEGE_ESCALATION' => $this->forEachActor($run, $ruleId, $identityByUser, fn ($acc) => $acc['has_privilege_escalation']),
            'IDENTITY_UNUSUAL_LOGIN_SOURCE' => $this->forEachActor($run, $ruleId, $identityByIp, fn ($acc) => count($acc['distinct_users']) >= 3),
            'CLOUD_UNUSUAL_API_ACTIVITY' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($acc) => $acc['high_risk_count'] >= 3),
            'CLOUD_SUSPICIOUS_OBJECT_ACCESS' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($acc) => $acc['object_access_count'] >= 5),
            'CLOUD_MASS_DOWNLOAD' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($acc) => $acc['download_count'] >= 10),
            'CLOUD_NEW_ACCESS_KEY' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($acc) => $acc['has_new_access_key']),
            'CLOUD_SECURITY_SETTING_MODIFIED' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($acc) => $acc['has_security_setting_modified']),
            'SAAS_UNUSUAL_ADMIN_ACTIVITY' => $this->forEachActor($run, $ruleId, $cloudSaasByActor, fn ($acc) => $acc['has_saas_type'] && $acc['has_saas_admin_activity']),
            default => null,
        };
    }

    private function forEachActor(DetectionBacktestRun $run, string $ruleId, array $accumulators, \Closure $matches): void
    {
        foreach ($accumulators as $actorKey => $acc) {
            if ($actorKey === '') {
                continue;
            }
            if (! $matches($acc)) {
                continue;
            }
            DetectionBacktestMatch::create([
                'match_id' => 'btm_'.Str::uuid(),
                'run_id' => $run->run_id,
                'rule_id' => $ruleId,
                'actor_key' => (string) $actorKey,
                'event_count' => $acc['event_count'],
                'sample_events' => $acc['samples'],
                'created_at' => now(),
            ]);
        }
    }

    private function isFailed($row): bool
    {
        return in_array($row->event_type, ['login_failed', 'mfa_failed'], true) || $row->xdr_result === 'failure';
    }
}
