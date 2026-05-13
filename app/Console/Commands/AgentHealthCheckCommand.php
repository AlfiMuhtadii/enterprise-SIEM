<?php

namespace App\Console\Commands;

use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AgentHealthCheckCommand extends Command
{
    protected $signature = 'soc:agent-health-check';
    protected $description = 'Detect stale agents, retry queue growth, delivery failures, and policy/config anomalies.';

    public function handle(): int
    {
        $offlineAfter = (int) config('soc.agent_offline_after_seconds', 180);
        $retryThreshold = (int) config('soc.agent_retry_queue_alert_threshold', 1000);
        $failureThreshold = (int) config('soc.agent_delivery_failure_alert_threshold', 5);
        $now = now();
        $alerts = 0;

        $agents = DB::table('endpoint_agents')->get();
        foreach ($agents as $agent) {
            $stale = !$agent->last_seen_at || $now->diffInSeconds($agent->last_seen_at) > $offlineAfter;
            if ($stale) {
                $alerts += $this->createAgentAlert($agent, 'AGENT_STALE_OR_STOPPED', 'high', 0.8, [
                    'reason' => 'missing heartbeat or unexpected stop',
                    'last_seen_at' => $agent->last_seen_at,
                    'offline_after_seconds' => $offlineAfter,
                ]);
                DB::table('endpoint_agents')->where('agent_id', $agent->agent_id)->update(['status' => 'offline', 'updated_at' => $now]);
            }

            if ((int) ($agent->retry_queue_depth ?? 0) >= $retryThreshold) {
                $alerts += $this->createAgentAlert($agent, 'AGENT_RETRY_QUEUE_GROWTH', 'medium', 0.65, [
                    'retry_queue_depth' => $agent->retry_queue_depth,
                    'threshold' => $retryThreshold,
                ]);
            }

            if (!empty($agent->policy_id)) {
                $policy = DB::table('agent_policies')->where('policy_id', $agent->policy_id)->first();
                if ($policy && (int) $agent->policy_version_seen > 0 && (int) $agent->policy_version_seen < (int) $policy->version) {
                    $alerts += $this->createAgentAlert($agent, 'AGENT_POLICY_OUTDATED', 'low', 0.45, [
                        'seen_version' => $agent->policy_version_seen,
                        'current_version' => $policy->version,
                    ]);
                }
            }

            $metadata = json_decode($agent->metadata ?: '{}', true) ?: [];
            if (($metadata['startup_integrity_ok'] ?? true) === false) {
                $alerts += $this->createAgentAlert($agent, 'AGENT_STARTUP_INTEGRITY_FAILED', 'high', 0.85, [
                    'reason' => 'agent startup integrity check failed',
                    'agent_script_sha256' => $metadata['agent_script_sha256'] ?? null,
                    'package_manifest' => $metadata['package_manifest'] ?? null,
                ]);
            }
            if ((int) ($metadata['unexpected_restart_count'] ?? 0) > 0) {
                $alerts += $this->createAgentAlert($agent, 'AGENT_UNEXPECTED_RESTART', 'medium', 0.7, [
                    'unexpected_restart_count' => (int) $metadata['unexpected_restart_count'],
                    'last_start_at' => $metadata['last_start_at'] ?? null,
                ]);
            }
        }

        $failures = DB::table('agent_delivery_failures')
            ->select('agent_id', DB::raw('count(*) as total'))
            ->where('failed_at', '>=', now()->subMinutes(30))
            ->groupBy('agent_id')
            ->get();
        foreach ($failures as $failure) {
            if ((int) $failure->total >= $failureThreshold) {
                $agent = DB::table('endpoint_agents')->where('agent_id', $failure->agent_id)->first();
                if ($agent) {
                    $alerts += $this->createAgentAlert($agent, 'AGENT_REPEATED_DELIVERY_FAILURE', 'medium', 0.7, [
                        'failures_30m' => $failure->total,
                        'threshold' => $failureThreshold,
                    ]);
                }
            }
        }

        $this->info("agent_health_alerts={$alerts}");
        return self::SUCCESS;
    }

    private function createAgentAlert(object $agent, string $type, string $severity, float $score, array $evidence): int
    {
        $alertId = hash('sha256', implode('|', [$type, $agent->agent_id, now()->format('YmdHi')]));
        $inserted = DB::table('security_alerts')->insertOrIgnore([
            'alert_id' => $alertId,
            'alert_fingerprint' => hash('sha256', $type.'|'.$agent->agent_id),
            'dedup_group' => $type.'|'.$agent->agent_id,
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => $type,
            'detector_name' => 'agent-health-monitor',
            'detector_version' => 'v1',
            'severity' => $severity,
            'actor_key' => $agent->agent_id,
            'score' => $score,
            'evidence' => json_encode(array_merge($evidence, [
                'agent_id' => $agent->agent_id,
                'host_id' => $agent->host_id,
                'agent_version' => $agent->agent_version,
                'detection_layer' => 'agent-management',
            ])),
            'raw_event' => json_encode($agent),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($inserted) {
            AuditLogger::log('system', 'agent.tamper_alert', 'agent', $agent->agent_id, null, ['alert_type' => $type], $evidence);
        }
        return $inserted;
    }
}
