<?php

namespace App\Jobs;

use App\Models\ScenarioEvidence;
use App\Models\ScenarioRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ExecuteScenarioRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 1;

    public function __construct(public readonly int $runId) {}

    // -------------------------------------------------------------------------
    // Job entry point
    // -------------------------------------------------------------------------

    public function handle(): void
    {
        $run = ScenarioRun::find($this->runId);
        if (!$run || !in_array($run->status, ['pending', 'running'], true)) {
            return;
        }

        $run->update(['status' => 'running']);

        $scenario = $run->definition();
        if (!$scenario) {
            $this->markFailed($run, 'Scenario definition not found for id: ' . $run->scenario_id);
            return;
        }

        $mode = (string) config('scenarios.pipeline_mode', 'stub');

        if ($mode === 'real') {
            $this->executeRealPipeline($run, $scenario);
        } else {
            $this->executeStubPipeline($run, $scenario);
        }
    }

    public function failed(\Throwable $e): void
    {
        $run = ScenarioRun::find($this->runId);
        if ($run) {
            $this->markFailed($run, $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // STUB MODE — synthetic evidence, no real services required
    // -------------------------------------------------------------------------

    private function executeStubPipeline(ScenarioRun $run, array $scenario): void
    {
        $stages            = $scenario['pipeline_stages'];
        $expectedDetection = $scenario['expected_detection'];
        $expectedRule      = $expectedDetection['rule']       ?? 'UNKNOWN';
        $expectedSeverity  = $expectedDetection['severity']   ?? 'medium';
        $alertCount        = (int) ($expectedDetection['alert_count'] ?? 1);

        $alertId    = 'alert-' . substr(str_replace('-', '', (string) str()->uuid()), 0, 16);
        $incidentId = 'inc-'   . substr(str_replace('-', '', (string) str()->uuid()), 0, 16);
        $eventId    = 'evt-'   . substr(str_replace('-', '', (string) str()->uuid()), 0, 16);

        $stageLatencyRanges = [
            'ingestion'                  => [12, 45],
            'telemetry.raw'              => [4,  12],
            'normalizer'                 => [18, 55],
            'telemetry.normalized'       => [4,  12],
            'correlation'                => [25, 80],
            'xdr.alerts'                 => [8,  25],
            'xdr.alerts.shadow.endpoint' => [8,  25],
            'alerts.created'             => [12, 40],
            'incidents.updated'          => [18, 50],
        ];

        $correlationStages = ['correlation', 'xdr.alerts', 'xdr.alerts.shadow.endpoint'];
        $alertStages       = ['xdr.alerts', 'xdr.alerts.shadow.endpoint', 'alerts.created', 'incidents.updated'];

        $baseTs       = now();
        $cumulativeMs = 0;
        $delayMs      = (int) config('scenarios.stage_delay_ms', 0);

        foreach ($stages as $stage) {
            if (ScenarioRun::where('id', $run->id)->value('status') === 'stopped') {
                return;
            }

            [$minMs, $maxMs] = $stageLatencyRanges[$stage] ?? [10, 40];
            $latencyMs    = random_int($minMs, $maxMs);
            $cumulativeMs += $latencyMs;

            $this->recordEvidence(
                run:       $run,
                stage:     $stage,
                stageType: 'simulated_runner_stage',
                eventId:   $eventId,
                payload:   $this->buildStagePayload($stage, $scenario, $eventId, $alertId, $incidentId, $run->trace_id, $expectedDetection),
                ruleId:    in_array($stage, $correlationStages, true) ? $expectedRule : null,
                severity:  in_array($stage, $alertStages, true) ? $expectedSeverity : null,
                status:    'detected',
                latencyMs: $latencyMs,
                processedAt: $baseTs->copy()->addMilliseconds($cumulativeMs),
            );

            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $run->update([
            'status'            => 'completed',
            'alerts_detected'   => $alertCount,
            'detection_passed'  => true,
            'validation_result' => 'PASS',
            'recommendation'    => 'Detection rule ' . $expectedRule . ' fired as expected across all ' . count($stages) . ' stages (stub mode).',
            'completed_at'      => $baseTs->copy()->addMilliseconds($cumulativeMs),
            'results'           => [
                'run_type'         => 'synthetic_stub',
                'stages_total'     => count($stages),
                'stages_passed'    => count($stages),
                'stages_failed'    => 0,
                'total_latency_ms' => $cumulativeMs,
                'alert_id'         => $alertId,
                'incident_id'      => $incidentId,
                'event_id'         => $eventId,
                'rule_matched'     => $expectedRule,
                'confidence'       => $expectedDetection['confidence'] ?? null,
                'severity'         => $expectedSeverity,
                'alert_count'      => $alertCount,
                'engine'           => config('xdr.correlation.engine', 'go'),
                'scope'            => config('xdr.correlation.scope', 'identity-cloud'),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // REAL MODE — publish to ingestion-gateway, poll for actual pipeline output
    // -------------------------------------------------------------------------

    private function executeRealPipeline(ScenarioRun $run, array $scenario): void
    {
        $gatewayUrl    = rtrim((string) config('scenarios.ingestion_gateway_url', 'http://127.0.0.1:8091'), '/');
        $secret        = (string) config('scenarios.ingestion_gateway_secret', '');
        $timeoutSec    = (int) config('scenarios.pipeline_timeout_seconds', 30);
        $pollMs        = (int) config('scenarios.pipeline_poll_ms', 1000);

        $expectedDetection = $scenario['expected_detection'];
        $expectedRule      = $expectedDetection['rule']     ?? 'UNKNOWN';
        $expectedSeverity  = $expectedDetection['severity'] ?? 'medium';

        $baseEventId = 'evt-scr-' . substr(str_replace('-', '', (string) str()->uuid()), 0, 12);

        // ---- Stage 1: publish batch to ingestion-gateway ----
        // The batch size is determined by the minimum count needed to trigger the rule.
        $events = $this->buildRealTelemetryBatch($scenario, $baseEventId, $run->trace_id, $run->id);
        $body   = json_encode($events);
        $eventId = $baseEventId . '-0'; // representative event_id for evidence rows
        $signature      = $secret !== '' ? 'sha256=' . hash_hmac('sha256', $body, $secret) : '';

        $headers = ['Content-Type' => 'application/json'];
        if ($signature !== '') {
            $headers['X-XDR-Signature'] = $signature;
        }

        try {
            $response = Http::withHeaders($headers)
                ->timeout(10)
                ->withBody($body, 'application/json')
                ->post($gatewayUrl . '/v1/ingest');
            $gatewayOk = $response->successful();
            $gatewayBody = $response->json() ?? [];
            $gatewayStatus = $response->status();
        } catch (\Throwable $e) {
            $gatewayOk = false;
            $gatewayBody = [];
            $gatewayStatus = 0;
            $gatewayError = $e->getMessage();
        }

        if (!$gatewayOk) {
            $this->recordEvidence(
                run:       $run,
                stage:     'ingestion',
                stageType: 'real_pipeline_stage',
                eventId:   $eventId,
                payload:   ['error' => $gatewayError ?? 'http_' . $gatewayStatus, 'gateway_url' => $gatewayUrl],
                status:    'failed',
                latencyMs: 0,
            );
            $this->markFailed($run, 'Ingestion gateway returned ' . ($gatewayStatus ?: 'connection error') . ': ' . ($gatewayError ?? 'HTTP ' . $gatewayStatus));
            return;
        }

        $this->recordEvidence(
            run:       $run,
            stage:     'ingestion',
            stageType: 'real_pipeline_stage',
            eventId:   $eventId,
            payload:   [
                'gateway_url'    => $gatewayUrl,
                'event_id'       => $eventId,
                'telemetry_type' => $scenario['telemetry_type'],
                'event_type'     => $scenario['expected_telemetry']['event_type'],
                'accepted'       => $gatewayBody['accepted'] ?? count($events),
                'events_sent'    => count($events),
                'gateway_ms'     => $gatewayBody['latency_ms'] ?? null,
                'published_at'   => now()->toIso8601String(),
            ],
            status:    'detected',
            latencyMs: $gatewayBody['latency_ms'] ?? 10,
        );

        // ---- Stage 2: poll for alert by actor_key + rule + started_at ----
        // The correlation-worker generates its own trace_id — it does not propagate
        // the incoming event trace_id. We identify our alerts via actor_key and
        // the expected alert_type, scoped to events after this run started.
        $deadline = now()->addSeconds($timeoutSec);
        $alert    = null;

        while (now()->lt($deadline)) {
            if (ScenarioRun::where('id', $run->id)->value('status') === 'stopped') {
                return;
            }

            $alert = DB::table('security_alerts')
                ->where('actor_key', 'scenario-actor@test.local')
                ->where('alert_type', $expectedRule)
                ->where('detected_at', '>=', $run->started_at)
                ->orderByDesc('detected_at')
                ->first();

            if ($alert) {
                break;
            }

            if ($pollMs > 0) {
                usleep($pollMs * 1000);
            }
        }

        if (!$alert) {
            $this->recordEvidence(
                run:       $run,
                stage:     'telemetry.raw',
                stageType: 'real_pipeline_stage',
                eventId:   $eventId,
                payload:   ['status' => 'propagating', 'timeout_seconds' => $timeoutSec],
                status:    'processing',
                latencyMs: null,
            );

            $run->update([
                'status'            => 'completed',
                'detection_passed'  => false,
                'validation_result' => 'PARTIAL',
                'failure_reason'    => 'Telemetry published to ingestion-gateway but expected detection '
                    . '(' . $expectedRule . ') was not observed within ' . $timeoutSec . 's timeout.',
                'recommendation'    => 'Verify that correlation-worker is running and the rule '
                    . $expectedRule . ' is active in the current correlation scope.',
                'completed_at'      => now(),
                'results'           => [
                    'run_type'        => 'real_pipeline',
                    'event_id'        => $eventId,
                    'rule_expected'   => $expectedRule,
                    'alert_found'     => false,
                    'timeout_seconds' => $timeoutSec,
                    'engine'          => config('xdr.correlation.engine', 'go'),
                    'scope'           => config('xdr.correlation.scope', 'identity-cloud'),
                ],
            ]);
            return;
        }

        // ---- Stage 3: record real alert evidence ----
        $this->recordEvidence(
            run:       $run,
            stage:     'xdr.alerts',
            stageType: 'real_pipeline_stage',
            eventId:   $eventId,
            payload:   [
                'alert_id'   => $alert->alert_id,
                'alert_type' => $alert->alert_type,
                'severity'   => $alert->severity,
                'score'      => $alert->score,
                'evidence'   => json_decode($alert->evidence ?? '{}', true),
            ],
            ruleId:    $alert->alert_type,
            severity:  $alert->severity,
            status:    'detected',
            latencyMs: null,
        );

        $this->recordEvidence(
            run:       $run,
            stage:     'alerts.created',
            stageType: 'real_pipeline_stage',
            eventId:   $eventId,
            payload:   [
                'alert_id'    => $alert->alert_id,
                'incident_id' => $alert->incident_id ?? null,
                'alert_type'  => $alert->alert_type,
            ],
            ruleId:    $alert->alert_type,
            severity:  $alert->severity,
            status:    'detected',
            latencyMs: null,
        );

        // ---- Stage 4: poll for incident (opportunistic, non-blocking) ----
        $incidentId = null;
        if (!empty($alert->incident_id)) {
            $incident = DB::table('security_incidents')->where('incident_id', $alert->incident_id)->first();
            if ($incident) {
                $incidentId = $incident->incident_id;
                $this->recordEvidence(
                    run:       $run,
                    stage:     'incidents.updated',
                    stageType: 'real_pipeline_stage',
                    eventId:   $eventId,
                    payload:   [
                        'incident_id' => $incident->incident_id,
                        'title'       => $incident->title,
                        'status'      => $incident->status,
                        'severity'    => $incident->severity,
                    ],
                    severity:  $incident->severity,
                    status:    'detected',
                    latencyMs: null,
                );
            }
        }

        // ---- Determine PASS / PARTIAL ----
        $ruleMatched      = ($alert->alert_type === $expectedRule);
        $severityMatched  = ($alert->severity   === $expectedSeverity);
        $validationResult = ($ruleMatched && $severityMatched) ? 'PASS' : 'PARTIAL';

        $run->update([
            'status'            => 'completed',
            'alerts_detected'   => 1,
            'detection_passed'  => $ruleMatched,
            'validation_result' => $validationResult,
            'recommendation'    => $ruleMatched
                ? 'Real pipeline detection confirmed. Rule ' . $expectedRule . ' fired for alert ' . $alert->alert_id . '.'
                : 'Alert fired but rule mismatch. Expected: ' . $expectedRule . ', got: ' . $alert->alert_type . '.',
            'completed_at'      => now(),
            'results'           => [
                'run_type'      => 'real_pipeline',
                'event_id'      => $eventId,
                'alert_id'      => $alert->alert_id,
                'incident_id'   => $incidentId,
                'rule_matched'  => $alert->alert_type,
                'rule_expected' => $expectedRule,
                'severity'      => $alert->severity,
                'alert_count'   => 1,
                'engine'        => 'real',
                'scope'         => config('xdr.correlation.scope', 'identity-cloud'),
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    private function recordEvidence(
        ScenarioRun $run,
        string      $stage,
        string      $stageType,
        string      $eventId,
        array       $payload,
        ?string     $ruleId     = null,
        ?string     $severity   = null,
        string      $status     = 'detected',
        ?int        $latencyMs  = null,
        ?\Illuminate\Support\Carbon $processedAt = null,
    ): void {
        ScenarioEvidence::create([
            'scenario_run_id' => $run->id,
            'stage'           => $stage,
            'stage_type'      => $stageType,
            'event_id'        => $eventId,
            'trace_id'        => $run->trace_id,
            'rule_id'         => $ruleId,
            'severity'        => $severity,
            'status'          => $status,
            'payload'         => $payload,
            'processed_at'    => $processedAt ?? now(),
            'latency_ms'      => $latencyMs,
        ]);
    }

    private function markFailed(ScenarioRun $run, string $reason): void
    {
        // Count only detected (successfully processed) stages — failed evidence
        // rows don't represent completed pipeline progress.
        $completedStages = ScenarioEvidence::where('scenario_run_id', $run->id)
            ->where('status', 'detected')
            ->count();

        $run->update([
            'status'            => 'failed',
            'validation_result' => $completedStages > 0 ? 'PARTIAL' : 'FAIL',
            'failure_reason'    => $reason,
            'completed_at'      => now(),
            'results'           => [
                'error'            => $reason,
                'stages_completed' => $completedStages,
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Real mode payload builders — synthetic telemetry, no real traffic
    // -------------------------------------------------------------------------

    // Returns a batch of events sized to satisfy the correlation rule thresholds.
    // Field names match the correlation-worker Event struct (json tags).
    private function buildRealTelemetryBatch(array $scenario, string $baseEventId, string $traceId, int $runId): array
    {
        $expectedTel  = $scenario['expected_telemetry'];
        $count        = max((int) ($expectedTel['count'] ?? 1), $this->minimumBatchCount($scenario['id']));
        $telType      = $scenario['telemetry_type'];
        $eventType    = $expectedTel['event_type'];
        $events       = [];

        for ($i = 0; $i < $count; $i++) {
            $event = [
                // correlation-worker Event struct field names
                'event_id'        => $baseEventId . '-' . $i,
                'ts'              => now()->toIso8601String(),
                'telemetry_type'  => $telType,
                'event_type'      => $eventType,
                'user'            => 'scenario-actor@test.local',   // json:"user" in Event struct
                'host'            => 'scenario-host-01',
                'source_ip'       => '203.0.113.' . random_int(1, 254),
                'risk_score'      => $expectedTel['risk_score_min'] ?? 0.75,
                'event_source'    => 'xdr-scenario-runner',
                // scenario metadata for tracing
                'trace_id'        => $traceId,
                'scenario_id'     => $scenario['id'],
                'scenario_run_id' => $runId,
                'source'          => 'xdr-scenario-runner',
                'schema_version'  => 'v1',
            ];

            // Scenario-specific extra fields
            $event = array_merge($event, $this->scenarioEventFields($scenario, $i));
            $events[] = $event;
        }

        return $events;
    }

    // Minimum events needed to trigger the correlation rule for each scenario.
    private function minimumBatchCount(string $scenarioId): int
    {
        return match ($scenarioId) {
            'sql_injection_emulation'   => 5,  // objectAccess >= 5
            'failed_login_burst'        => 6,  // failed >= 5 (send 6 for margin)
            default                     => 1,
        };
    }

    // Scenario-specific fields that influence correlation rules.
    private function scenarioEventFields(array $scenario, int $index): array
    {
        return match ($scenario['id']) {
            'sql_injection_emulation' => [
                // cloudSaasAlerts: contains "object" → objectAccess count
                'action'        => 'GetObject',
                'cloud_account' => 'acct-scenario-test',
                'result'        => 'success',
            ],
            'failed_login_burst' => [
                // identityAlerts: event_type login_failed → failed count
                'event_type'    => 'login_failed',
                'result'        => 'failure',
            ],
            'suspicious_dns_query' => [
                'domain'        => 'xn--' . substr(str_replace('-', '', (string) str()->uuid()), 0, 14) . '.dga.test',
            ],
            'ioc_match' => [
                'destination_ip'   => '185.220.101.200',
                'destination_port' => 4444,
                'protocol'         => 'tcp',
            ],
            'suspicious_powershell_event' => [
                'action' => 'process_create',
            ],
            default => [],
        };
    }

    private function buildRawEvent(array $scenario): array
    {
        return match ($scenario['id']) {
            'sql_injection_emulation' => [
                'api_endpoint'      => '/api/v2/objects',
                'method'            => 'GET',
                'params'            => ['search' => "' OR 1=1 --", 'limit' => '100'],
                'user_agent'        => 'python-requests/2.28.0',
                'response_code'     => 200,
                'bytes_transferred' => random_int(50000, 500000),
                'cloud_account'     => 'acct-scenario-test',
                'region'            => 'us-east-1',
                'risk_score'        => 0.82,
            ],
            'failed_login_burst' => [
                'username'       => 'scenario-test-user@test.local',
                'failure_reason' => 'invalid_credentials',
                'mfa_attempted'  => false,
                'attempt_count'  => 6,
                'window_seconds' => 120,
                'source_ip'      => '203.0.113.' . random_int(1, 254),
                'user_agent'     => 'Mozilla/5.0 (compatible; synthetic-test)',
                'auth_provider'  => 'azure-ad',
            ],
            'suspicious_dns_query' => [
                'query_name'    => 'xn--' . substr(str_replace('-', '', (string) str()->uuid()), 0, 16) . '.dga-beacon.test',
                'query_type'    => 'A',
                'response_code' => 'NXDOMAIN',
                'process_name'  => 'svchost.exe',
                'pid'           => random_int(1000, 65535),
                'host_id'       => 'endpoint-scenario-01',
            ],
            'ioc_match' => [
                'destination_ip'   => '185.220.101.200',
                'destination_port' => 4444,
                'protocol'         => 'tcp',
                'process_name'     => 'cmd.exe',
                'bytes_sent'       => random_int(100, 5000),
                'bytes_received'   => random_int(100, 5000),
                'host_id'          => 'endpoint-scenario-01',
                'ioc_type'         => 'ip',
                'ioc_match'        => true,
            ],
            'suspicious_powershell_event' => [
                'process_name'   => 'powershell.exe',
                'command_line'   => 'powershell.exe -EncodedCommand ' . base64_encode('Write-Host "Synthetic scenario test — safe stub"'),
                'parent_process' => 'cmd.exe',
                'pid'            => random_int(1000, 65535),
                'parent_pid'     => random_int(1000, 65535),
                'user'           => 'SCENARIO\\testuser',
                'host_id'        => 'endpoint-scenario-01',
            ],
            default => ['scenario_id' => $scenario['id'], 'synthetic' => true],
        };
    }

    // -------------------------------------------------------------------------
    // Stub mode payload builders
    // -------------------------------------------------------------------------

    private function buildStagePayload(
        string $stage,
        array  $scenario,
        string $eventId,
        string $alertId,
        string $incidentId,
        string $traceId,
        array  $expectedDetection
    ): array {
        $telemetryType = $scenario['telemetry_type'];
        $expectedTel   = $scenario['expected_telemetry'];
        $confidence    = $expectedDetection['confidence'] ?? 0.80;
        $severity      = $expectedDetection['severity']   ?? 'medium';
        $rule          = $expectedDetection['rule'];

        return match ($stage) {
            'ingestion' => [
                'source'         => 'xdr-scenario-runner',
                'telemetry_type' => $telemetryType,
                'event_type'     => $expectedTel['event_type'],
                'event_id'       => $eventId,
                'trace_id'       => $traceId,
                'host_id'        => 'scenario-host-01',
                'actor'          => 'scenario-actor@test.local',
                'source_ip'      => '10.99.' . random_int(0, 255) . '.' . random_int(2, 254),
                'risk_score'     => $expectedTel['risk_score_min'] ?? 0.75,
                'raw_event'      => $this->buildRawEvent($scenario),
                'ingestion_ts'   => now()->toIso8601String(),
            ],
            'telemetry.raw' => [
                'topic'     => 'telemetry.raw',
                'partition' => 0,
                'offset'    => random_int(100000, 9999999),
                'key'       => $eventId,
                'headers'   => ['trace_id' => $traceId, 'source' => 'ingestion-gateway', 'schema_version' => 'v1'],
                'event_id'  => $eventId,
            ],
            'normalizer' => [
                'normalizer_version'          => 'endpoint-v1',
                'input_event_id'              => $eventId,
                'normalized_event_id'         => $eventId,
                'telemetry_type'              => $telemetryType,
                'normalization_rules_applied' => ['schema_v1', 'field_mapping', 'risk_score_normalization'],
                'output_schema'               => "telemetry-normalized.{$telemetryType}.v1",
                'fields_mapped'               => random_int(8, 20),
            ],
            'telemetry.normalized' => [
                'topic'               => 'telemetry.normalized',
                'partition'           => 0,
                'offset'              => random_int(100000, 9999999),
                'normalized_event_id' => $eventId,
                'trace_id'            => $traceId,
                'telemetry_type'      => $telemetryType,
            ],
            'correlation' => [
                'engine'                => config('xdr.correlation.engine', 'go'),
                'scope'                 => config('xdr.correlation.scope', 'identity-cloud'),
                'rule_id'               => $rule,
                'rule_version'          => '1.0',
                'confidence'            => $confidence,
                'alert_triggered'       => true,
                'alert_id'              => $alertId,
                'trace_id'              => $traceId,
                'correlation_window_ms' => random_int(50, 200),
                'events_correlated'     => $expectedTel['count'] ?? 1,
            ],
            'xdr.alerts', 'xdr.alerts.shadow.endpoint' => [
                'topic'      => $stage,
                'partition'  => 0,
                'offset'     => random_int(100000, 9999999),
                'alert_id'   => $alertId,
                'rule_id'    => $rule,
                'severity'   => $severity,
                'confidence' => $confidence,
                'trace_id'   => $traceId,
                'actor'      => 'scenario-actor@test.local',
            ],
            'alerts.created' => [
                'alert_id'    => $alertId,
                'incident_id' => $incidentId,
                'rule_id'     => $rule,
                'severity'    => $severity,
                'trace_id'    => $traceId,
                'stored_at'   => now()->toIso8601String(),
            ],
            'incidents.updated' => [
                'incident_id' => $incidentId,
                'alert_count' => $expectedDetection['alert_count'] ?? 1,
                'severity'    => $severity,
                'trace_id'    => $traceId,
                'status'      => 'open',
                'updated_at'  => now()->toIso8601String(),
            ],
            default => ['stage' => $stage, 'trace_id' => $traceId, 'event_id' => $eventId],
        };
    }
}
