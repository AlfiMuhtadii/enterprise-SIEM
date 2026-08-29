<?php

namespace App\Services;

use App\Models\EndpointAgent;
use App\Models\EndpointAgentConfig;
use App\Models\EndpointAgentHeartbeat;
use App\Models\EndpointAgentMetric;
use App\Models\SecurityHardeningEvent;
use Illuminate\Support\Facades\DB;

class EndpointAgentService
{
    // Health state thresholds as multipliers of heartbeat_interval_seconds
    private const ONLINE_MULTIPLIER   = 2;
    private const DEGRADED_MULTIPLIER = 5;
    private const STALE_MULTIPLIER    = 30;

    /**
     * Compute an agent's online/offline status from its last heartbeat time.
     *
     * Centralizes the staleness comparison previously duplicated across the SOC
     * dashboard, API, agent and endpoint controllers. Uses the configured
     * offline threshold unless one is supplied. Timezone-safe because the DB
     * session is pinned to UTC (see config/database.php — TZ-AGENT-STALE).
     *
     * @param  mixed  $lastSeenAt  string|\DateTimeInterface|null
     */
    public static function computeStatus($lastSeenAt, ?int $offlineAfterSeconds = null): string
    {
        $threshold = $offlineAfterSeconds ?? (int) config('soc.agent_offline_after_seconds', 180);

        return $lastSeenAt && now()->diffInSeconds($lastSeenAt) <= $threshold ? 'online' : 'offline';
    }

    // -----------------------------------------------------------------------
    // Enrollment
    // -----------------------------------------------------------------------

    /**
     * Validate an enrollment token against the global SOC token.
     * Returns true when the token is non-empty and matches.
     */
    public function validateEnrollmentToken(string $token): bool
    {
        $globalToken = config('soc.agent_enrollment_token', '');
        if ($globalToken === '' || $token === '') {
            return false;
        }
        return hash_equals($globalToken, $token);
    }

    /**
     * Enroll a new agent. Returns the EndpointAgent record.
     * If the host is already enrolled (same host_id), returns the existing record with
     * updated last_seen_at rather than creating a duplicate.
     */
    public function enroll(
        string $enrollmentToken,
        string $hostId,
        string $hostname,
        string $agentVersion = '1.0.0',
        ?string $agentId = null,
        ?string $ipAddress = null,
        ?string $platform = null
    ): EndpointAgent {
        $tokenHash = EndpointAgent::hashEnrollmentToken($enrollmentToken);

        $existing = EndpointAgent::where('host_id', $hostId)->first();
        if ($existing) {
            $existing->update([
                'agent_version' => $agentVersion,
                'ip_address'    => $ipAddress,
                'last_seen_at'  => now(),
                'platform'      => $platform,
                'os_family'     => $platform,
                'health_state'  => EndpointAgent::HEALTH_ONLINE,
                'status'        => 'online',
            ]);
            return $existing;
        }

        $agent = EndpointAgent::create([
            'agent_id'               => $agentId ?? EndpointAgent::generateAgentId(),
            'host_id'                => $hostId,
            'host_fingerprint'       => hash('sha256', $hostId),
            'hostname'               => $hostname,
            'enrollment_token_hash'  => $tokenHash,
            'agent_version'          => $agentVersion,
            'ip_address'             => $ipAddress,
            'platform'               => $platform,
            'os_family'              => $platform,
            'health_state'           => EndpointAgent::HEALTH_ONLINE,
            'status'                 => 'online',
            'enrolled_at'            => now(),
            'last_seen_at'           => now(),
        ]);

        // Create default config policy for new agent
        EndpointAgentConfig::create([
            'agent_id'       => $agent->id,
            'policy_version' => '1.0.0',
            'config'         => EndpointAgentConfig::DEFAULT_CONFIG,
            'applied_at'     => now(),
        ]);

        return $agent;
    }

    // -----------------------------------------------------------------------
    // Heartbeat
    // -----------------------------------------------------------------------

    /**
     * Verify the HMAC-SHA256 heartbeat signature.
     * Signature format: sha256=<hex>
     * Signed over the raw JSON payload string using the global enrollment token as key.
     */
    public function verifyHeartbeatSignature(string $signature, string $rawPayload): bool
    {
        $token = config('soc.agent_enrollment_token', '');
        if ($token === '') {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $rawPayload, $token);
        return hash_equals($expected, $signature);
    }

    /**
     * Process an incoming heartbeat for an agent.
     * Records the heartbeat (append-only), updates agent last_seen_at and health_state,
     * upserts metrics snapshot.
     * Returns array with 'valid' boolean and 'health_state' string.
     */
    public function processHeartbeat(
        EndpointAgent $agent,
        string $signature,
        string $rawPayload,
        array $payload
    ): array {
        $valid       = $this->verifyHeartbeatSignature($signature, $rawPayload);
        $healthState = $this->calculateHealthStateNow();
        $metrics     = $payload['metrics'] ?? [];
        $ipAddress   = $payload['ip_address'] ?? null;

        if (!$valid) {
            SecurityHardeningEvent::record(
                SecurityHardeningEvent::EVENT_SIGNATURE_FAILURE,
                'endpoint-agent',
                [
                    'agent_id'   => $agent->agent_id,
                    'host_id'    => $agent->host_id,
                    'reason'     => 'heartbeat_hmac_invalid',
                ],
                $agent->agent_id,
                $payload['trace_id'] ?? null
            );
        }

        // Append heartbeat record (append-only)
        EndpointAgentHeartbeat::create([
            'agent_id'        => $agent->id,
            'tenant_id'       => $agent->tenant_id,
            'signature'       => $signature,
            'signature_valid' => $valid,
            'health_state'    => $healthState,
            'ip_address'      => $ipAddress,
            'metrics'         => $metrics,
            'heartbeat_at'    => now(),
            'created_at'      => now(),
        ]);

        // Update agent last_seen_at and health_state (only on valid sig)
        if ($valid) {
            $agent->update([
                'last_seen_at' => now(),
                'health_state' => EndpointAgent::HEALTH_ONLINE,
                'ip_address'   => $ipAddress ?? $agent->ip_address,
            ]);

            $this->upsertMetrics($agent->id, $metrics);
        }

        return [
            'valid'        => $valid,
            'health_state' => $valid ? EndpointAgent::HEALTH_ONLINE : $agent->health_state,
        ];
    }

    // -----------------------------------------------------------------------
    // Health state
    // -----------------------------------------------------------------------

    /**
     * Calculate health state for a given agent based on last_seen_at.
     */
    public function calculateHealthState(EndpointAgent $agent): string
    {
        if ($agent->last_seen_at === null) {
            return EndpointAgent::HEALTH_OFFLINE;
        }

        $interval = config('soc.agent_heartbeat_interval_seconds', 60);
        $secondsAgo = now()->diffInSeconds($agent->last_seen_at, true);

        return match (true) {
            $secondsAgo <= $interval * self::ONLINE_MULTIPLIER   => EndpointAgent::HEALTH_ONLINE,
            $secondsAgo <= $interval * self::DEGRADED_MULTIPLIER => EndpointAgent::HEALTH_DEGRADED,
            $secondsAgo <= $interval * self::STALE_MULTIPLIER    => EndpointAgent::HEALTH_STALE,
            default                                               => EndpointAgent::HEALTH_OFFLINE,
        };
    }

    /**
     * Bulk recalculate and persist health states for all agents.
     * Returns count of agents updated.
     */
    public function refreshAllHealthStates(): int
    {
        $updated = 0;
        EndpointAgent::all()->each(function (EndpointAgent $agent) use (&$updated) {
            $newState = $this->calculateHealthState($agent);
            if ($agent->health_state !== $newState) {
                $agent->update(['health_state' => $newState]);
                $updated++;
            }
        });
        return $updated;
    }

    // -----------------------------------------------------------------------
    // Config policy
    // -----------------------------------------------------------------------

    /**
     * Get the current config policy for an agent.
     * Falls back to DEFAULT_CONFIG if no policy exists.
     */
    public function getConfig(EndpointAgent $agent): array
    {
        $config = EndpointAgentConfig::where('agent_id', $agent->id)
            ->orderByDesc('id')
            ->first();

        return $config?->config ?? EndpointAgentConfig::DEFAULT_CONFIG;
    }

    /**
     * Update the config policy for an agent.
     * Creates a new config record (history preserved).
     */
    public function updateConfig(EndpointAgent $agent, array $config, ?int $actorId = null): EndpointAgentConfig
    {
        return EndpointAgentConfig::create([
            'agent_id'       => $agent->id,
            'policy_version' => $config['policy_version'] ?? '1.0.0',
            'config'         => $config,
            'applied_at'     => now(),
            'created_by'     => $actorId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Metrics
    // -----------------------------------------------------------------------

    private function upsertMetrics(int $agentPk, array $metrics): void
    {
        $data = [
            'agent_id'            => $agentPk,
            'events_per_sec'      => $metrics['events_per_sec']     ?? 0,
            'dropped_events'      => $metrics['dropped_events']      ?? 0,
            'retry_count'         => $metrics['retry_count']         ?? 0,
            'buffer_depth'        => $metrics['buffer_depth']        ?? 0,
            'total_sent'          => $metrics['total_sent']          ?? 0,
            'collection_cycles'   => $metrics['collection_cycles']   ?? 0,
            'last_successful_send'=> isset($metrics['last_successful_send'])
                ? \Carbon\Carbon::parse($metrics['last_successful_send'])
                : null,
            'recorded_at'         => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ];

        EndpointAgentMetric::updateOrCreate(
            ['agent_id' => $agentPk],
            $data
        );
    }

    private function calculateHealthStateNow(): string
    {
        return EndpointAgent::HEALTH_ONLINE;
    }
}
