<?php

namespace App\Services;

use App\Models\EndpointAgent;
use App\Models\EndpointAgentEnrollmentEvent;
use App\Models\EndpointAgentHeartbeat;
use App\Models\EndpointAgentPolicyAssignment;
use App\Models\EndpointFleetPolicy;
use App\Models\EndpointSpoolSnapshot;
use App\Models\EndpointTamperEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Endpoint Fleet Hardening Phase 1 — Production management service.
 *
 * All operations are:
 *   - advisory-only: tamper findings DO NOT trigger autonomous enforcement
 *   - deterministic: same data → same result (replay-safe)
 *   - audit-logged: every policy assignment and enrollment event is append-only
 *   - operator-visible: all state changes are queryable and explainable
 *
 * NOT implemented:
 *   - autonomous host isolation
 *   - automatic process termination
 *   - remote shell execution
 *   - self-updating binaries
 *   - automatic remediation
 */
class EndpointFleetService
{
    // Telemetry gap thresholds (multipliers of heartbeat_interval_seconds)
    public const GAP_DEGRADED_MULTIPLIER = 3;
    public const GAP_STALE_MULTIPLIER    = 10;
    public const GAP_OFFLINE_MULTIPLIER  = 30;

    // Spool health thresholds
    public const SPOOL_WARN_PERCENT    = 60.0; // % of 10 MiB cap
    public const SPOOL_CRITICAL_PERCENT= 85.0;
    public const DROPPED_EVENTS_WARN   = 10;   // dropped events per snapshot triggering concern

    // Policy drift — if reported config_hash diverges from assigned
    public const POLICY_DRIFT_MIN_AGE_SECONDS = 300; // 5 min grace after assignment

    // -----------------------------------------------------------------------
    // Fleet dashboard
    // -----------------------------------------------------------------------

    /**
     * Aggregate fleet-level health summary. Deterministic, read-only.
     */
    public function getDashboardStats(): array
    {
        $agents = EndpointAgent::all();
        $total  = $agents->count();

        return [
            'total'        => $total,
            'online'       => $agents->where('health_state', EndpointAgent::HEALTH_ONLINE)->count(),
            'degraded'     => $agents->where('health_state', EndpointAgent::HEALTH_DEGRADED)->count(),
            'stale'        => $agents->where('health_state', EndpointAgent::HEALTH_STALE)->count(),
            'offline'      => $agents->where('health_state', EndpointAgent::HEALTH_OFFLINE)->count(),
            'revoked'      => $agents->where('status', 'revoked')->count(),
            'tamper_events_7d' => EndpointTamperEvent::where('detected_at', '>=', now()->subDays(7))->count(),
            'policy_drifts'    => $this->countPolicyDrift(),
            'spool_warnings'   => $this->countSpoolWarnings(),
            'total_dropped_events_24h' => EndpointSpoolSnapshot::where('recorded_at', '>=', now()->subDay())
                ->sum('dropped_events'),
            'fleet_policies'   => EndpointFleetPolicy::where('is_active', true)->count(),
        ];
    }

    // -----------------------------------------------------------------------
    // Stale agent detection
    // -----------------------------------------------------------------------

    /**
     * Get agents with stale or offline health state.
     * Deterministic — based on last_seen_at and heartbeat_interval_seconds config.
     */
    public function getStaleAgents(int $heartbeatIntervalSeconds = 60): Collection
    {
        $degradedThreshold = now()->subSeconds($heartbeatIntervalSeconds * self::GAP_DEGRADED_MULTIPLIER);

        return EndpointAgent::where(function ($q) {
            $q->where('health_state', EndpointAgent::HEALTH_STALE)
              ->orWhere('health_state', EndpointAgent::HEALTH_OFFLINE)
              ->orWhere('health_state', EndpointAgent::HEALTH_DEGRADED);
        })
        ->orWhereNull('last_seen_at')
        ->orderBy('last_seen_at')
        ->get();
    }

    /**
     * Calculate telemetry lag in seconds for a given agent.
     * Returns null if agent has never been seen.
     */
    public function calculateTelemetryLag(EndpointAgent $agent): ?float
    {
        if ($agent->last_seen_at === null) {
            return null;
        }
        return (float) now()->diffInSeconds($agent->last_seen_at, true);
    }

    /**
     * Get telemetry lag summary for all agents, ordered by highest lag first.
     */
    public function getTelemetryLagSummary(int $limit = 50): Collection
    {
        return DB::table('endpoint_agents')
            ->whereNotNull('last_seen_at')
            ->select('agent_id', 'hostname', 'health_state', 'last_seen_at',
                DB::raw("EXTRACT(EPOCH FROM (NOW() - last_seen_at))::integer AS lag_seconds"))
            ->orderByDesc(DB::raw("EXTRACT(EPOCH FROM (NOW() - last_seen_at))"))
            ->limit($limit)
            ->get();
    }

    // -----------------------------------------------------------------------
    // Tamper visibility — advisory-only detection
    // -----------------------------------------------------------------------

    /**
     * Evaluate advisory tamper indicators for an agent.
     * NO enforcement action is taken. Returns collection of EndpointTamperEvents created.
     */
    public function detectTamperEvents(EndpointAgent $agent, int $heartbeatIntervalSeconds = 60): Collection
    {
        $findings = collect();
        $now      = now();

        // 1. Heartbeat gap — agent has not sent a heartbeat in expected window
        if ($agent->last_seen_at !== null) {
            $lagSeconds = $now->diffInSeconds($agent->last_seen_at, true);
            $gapThreshold = $heartbeatIntervalSeconds * self::GAP_STALE_MULTIPLIER;

            if ($lagSeconds > $gapThreshold) {
                $findings->push($this->recordTamperEvent($agent, [
                    'tamper_type' => EndpointTamperEvent::TYPE_HEARTBEAT_GAP,
                    'severity'    => $lagSeconds > $gapThreshold * 3
                        ? EndpointTamperEvent::SEVERITY_CRITICAL
                        : EndpointTamperEvent::SEVERITY_HIGH,
                    'description' => "Agent heartbeat gap of {$lagSeconds}s exceeds stale threshold ({$gapThreshold}s).",
                    'evidence'    => [
                        'last_seen_at'       => $agent->last_seen_at->toIso8601String(),
                        'lag_seconds'        => $lagSeconds,
                        'gap_threshold'      => $gapThreshold,
                        'heartbeat_interval' => $heartbeatIntervalSeconds,
                    ],
                    'confidence'  => min(0.95, 0.7 + ($lagSeconds / ($gapThreshold * 10))),
                ]));
            }
        } elseif ($agent->enrolled_at !== null) {
            // Enrolled but never sent a heartbeat
            $findings->push($this->recordTamperEvent($agent, [
                'tamper_type' => EndpointTamperEvent::TYPE_AGENT_STOPPED,
                'severity'    => EndpointTamperEvent::SEVERITY_HIGH,
                'description' => 'Agent enrolled but no heartbeat received.',
                'evidence'    => ['enrolled_at' => $agent->enrolled_at->toIso8601String()],
                'confidence'  => 0.75,
            ]));
        }

        // 2. Policy drift — reported config_hash diverges from assigned policy hash
        $drift = $this->checkPolicyDrift($agent);
        if ($drift) {
            $findings->push($this->recordTamperEvent($agent, [
                'tamper_type' => EndpointTamperEvent::TYPE_POLICY_DRIFT,
                'severity'    => EndpointTamperEvent::SEVERITY_MEDIUM,
                'description' => 'Agent config hash does not match currently assigned fleet policy.',
                'evidence'    => $drift,
                'confidence'  => 0.80,
            ]));
        }

        // 3. Spool capped — local spool is at or near capacity (potential telemetry loss)
        $latestSpool = EndpointSpoolSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('recorded_at')->first();
        if ($latestSpool?->spool_capped) {
            $findings->push($this->recordTamperEvent($agent, [
                'tamper_type' => EndpointTamperEvent::TYPE_TELEMETRY_INTERRUPTION,
                'severity'    => EndpointTamperEvent::SEVERITY_MEDIUM,
                'description' => 'Agent spool reached capacity — events may have been dropped.',
                'evidence'    => [
                    'spool_disk_bytes'   => $latestSpool->spool_disk_bytes,
                    'dropped_events'     => $latestSpool->dropped_events,
                    'spool_capped'       => true,
                    'recorded_at'        => $latestSpool->recorded_at?->toIso8601String(),
                ],
                'confidence'  => 0.90,
            ]));
        }

        return $findings;
    }

    /**
     * Get recent tamper events for an agent, ordered by detection time desc.
     */
    public function getTamperHistory(EndpointAgent $agent, int $days = 30): Collection
    {
        return EndpointTamperEvent::where('agent_id', $agent->id)
            ->where('detected_at', '>=', now()->subDays($days))
            ->orderByDesc('detected_at')
            ->get();
    }

    /**
     * Get fleet-wide tamper visibility summary.
     */
    public function getTamperVisibilitySummary(int $days = 7): Collection
    {
        return DB::table('endpoint_tamper_events')
            ->join('endpoint_agents', 'endpoint_tamper_events.agent_id', '=', 'endpoint_agents.id')
            ->select(
                'endpoint_agents.agent_id',
                'endpoint_agents.hostname',
                DB::raw('count(*) as tamper_count'),
                DB::raw('max(endpoint_tamper_events.detected_at) as last_tamper_at'),
                DB::raw("string_agg(DISTINCT endpoint_tamper_events.tamper_type, ', ') as tamper_types")
            )
            ->where('endpoint_tamper_events.detected_at', '>=', now()->subDays($days))
            ->groupBy('endpoint_agents.id', 'endpoint_agents.agent_id', 'endpoint_agents.hostname')
            ->orderByDesc('tamper_count')
            ->limit(100)
            ->get();
    }

    // -----------------------------------------------------------------------
    // Fleet policy management
    // -----------------------------------------------------------------------

    /**
     * Create a new fleet policy. Advisory — does NOT automatically push to agents.
     */
    public function createFleetPolicy(array $config, string $name, ?int $actorId = null, ?string $description = null): EndpointFleetPolicy
    {
        $version    = $config['policy_version'] ?? '1.0.0';
        $configHash = EndpointFleetPolicy::hashConfig($config);

        return EndpointFleetPolicy::create([
            'policy_id'       => EndpointFleetPolicy::generatePolicyId(),
            'name'            => $name,
            'description'     => $description,
            'policy_version'  => $version,
            'config'          => $config,
            'config_hash'     => $configHash,
            'is_active'       => true,
            'rollback_supported'=> true,
            'activated_at'    => now(),
            'created_by'      => $actorId,
        ]);
    }

    /**
     * Assign a fleet policy to a specific agent.
     * Append-only record. Does NOT execute anything on the agent automatically.
     * Agent will receive the policy on next config poll.
     */
    public function assignPolicyToAgent(
        EndpointAgent $agent,
        EndpointFleetPolicy $policy,
        string $reason = EndpointFleetPolicy::REASON_MANUAL,
        ?int $actorId = null,
        ?string $traceId = null
    ): EndpointAgentPolicyAssignment {
        $assignment = EndpointAgentPolicyAssignment::create([
            'assignment_id'    => 'assign-' . Str::uuid(),
            'agent_id'         => $agent->id,
            'policy_id'        => $policy->policy_id,
            'policy_version'   => $policy->policy_version,
            'config_hash'      => $policy->config_hash,
            'assignment_reason'=> $reason,
            'applied_to_agent' => false,
            'trace_id'         => $traceId,
            'assigned_at'      => now(),
            'assigned_by'      => $actorId,
            'created_at'       => now(),
        ]);

        // Update fleet policy agent count
        $policy->increment('assigned_agent_count');

        return $assignment;
    }

    /**
     * Mark a policy assignment as applied (agent acknowledged it).
     * Mutable update on the assignment record — sets applied_to_agent=true.
     * This is not append-only because it's tracking acknowledgement state.
     */
    public function markPolicyApplied(string $assignmentId): bool
    {
        return (bool) DB::table('endpoint_agent_policy_assignments')
            ->where('assignment_id', $assignmentId)
            ->update(['applied_to_agent' => true]);
    }

    /**
     * Get policy assignment history for an agent.
     */
    public function getPolicyAssignmentHistory(EndpointAgent $agent, int $limit = 50): Collection
    {
        return EndpointAgentPolicyAssignment::where('agent_id', $agent->id)
            ->orderByDesc('assigned_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get the current (most recent) policy assignment for an agent.
     */
    public function getCurrentPolicyAssignment(EndpointAgent $agent): ?EndpointAgentPolicyAssignment
    {
        return EndpointAgentPolicyAssignment::where('agent_id', $agent->id)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')   // tiebreaker for assignments in the same second
            ->first();
    }

    // -----------------------------------------------------------------------
    // Policy drift
    // -----------------------------------------------------------------------

    /**
     * Check if an agent is running a different config than its assigned fleet policy.
     * Returns array of evidence if drift detected, null if none.
     */
    public function checkPolicyDrift(EndpointAgent $agent): ?array
    {
        $currentAssignment = $this->getCurrentPolicyAssignment($agent);
        if (!$currentAssignment) {
            return null;
        }

        // Allow a grace period after assignment before flagging drift
        $graceThreshold = now()->subSeconds(self::POLICY_DRIFT_MIN_AGE_SECONDS);
        if ($currentAssignment->assigned_at >= $graceThreshold) {
            return null;
        }

        // Get reported config from latest heartbeat metrics
        $latestHeartbeat = EndpointAgentHeartbeat::where('agent_id', $agent->id)
            ->orderByDesc('heartbeat_at')
            ->first();

        if (!$latestHeartbeat) {
            return null;
        }

        $reportedConfigHash = $latestHeartbeat->metrics['config_hash'] ?? null;
        if (!$reportedConfigHash) {
            return null;
        }

        if ($reportedConfigHash !== $currentAssignment->config_hash) {
            return [
                'assigned_config_hash' => $currentAssignment->config_hash,
                'reported_config_hash' => $reportedConfigHash,
                'assigned_policy_id'   => $currentAssignment->policy_id,
                'assigned_at'          => $currentAssignment->assigned_at?->toIso8601String(),
                'last_heartbeat_at'    => $latestHeartbeat->heartbeat_at?->toIso8601String(),
            ];
        }

        return null;
    }

    /**
     * Count agents with policy drift.
     */
    public function countPolicyDrift(): int
    {
        $count = 0;
        EndpointAgent::where('health_state', '!=', EndpointAgent::HEALTH_OFFLINE)
            ->each(function (EndpointAgent $agent) use (&$count) {
                if ($this->checkPolicyDrift($agent) !== null) {
                    $count++;
                }
            });
        return $count;
    }

    /**
     * Get all agents with policy drift.
     */
    public function getAgentsWithPolicyDrift(): Collection
    {
        $results = collect();
        EndpointAgent::where('health_state', '!=', EndpointAgent::HEALTH_OFFLINE)
            ->each(function (EndpointAgent $agent) use ($results) {
                $drift = $this->checkPolicyDrift($agent);
                if ($drift !== null) {
                    $results->push([
                        'agent'    => $agent,
                        'drift'    => $drift,
                        'severity' => 'medium',
                    ]);
                }
            });
        return $results;
    }

    // -----------------------------------------------------------------------
    // Enrollment events
    // -----------------------------------------------------------------------

    /**
     * Record an enrollment lifecycle event (append-only).
     */
    public function recordEnrollmentEvent(
        EndpointAgent $agent,
        string $eventType,
        array $metadata = [],
        bool $successful = true,
        ?string $failureReason = null,
        ?int $triggeredBy = null,
        ?string $traceId = null
    ): EndpointAgentEnrollmentEvent {
        return EndpointAgentEnrollmentEvent::create([
            'event_id'              => 'enroll-' . Str::uuid(),
            'agent_id'              => $agent->id,
            'event_type'            => $eventType,
            'agent_version'         => $agent->agent_version,
            'platform'              => $agent->platform,
            'ip_address'            => $agent->ip_address,
            'enrollment_token_hash' => $agent->enrollment_token_hash,
            'metadata'              => $metadata ?: null,
            'failure_reason'        => $failureReason,
            'trace_id'              => $traceId,
            'successful'            => $successful,
            'occurred_at'           => now(),
            'triggered_by'          => $triggeredBy,
            'created_at'            => now(),
        ]);
    }

    /**
     * Get enrollment history for an agent.
     */
    public function getEnrollmentHistory(EndpointAgent $agent, int $limit = 100): Collection
    {
        return EndpointAgentEnrollmentEvent::where('agent_id', $agent->id)
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    // -----------------------------------------------------------------------
    // Spool health
    // -----------------------------------------------------------------------

    /**
     * Record a spool snapshot from heartbeat metrics (append-only).
     */
    public function recordSpoolSnapshot(EndpointAgent $agent, array $spoolStats, ?string $traceId = null): EndpointSpoolSnapshot
    {
        return EndpointSpoolSnapshot::create([
            'snapshot_id'             => 'spool-' . Str::uuid(),
            'agent_id'                => $agent->id,
            'queued_events'           => $spoolStats['queued_events'] ?? 0,
            'dropped_events'          => $spoolStats['dropped_events'] ?? 0,
            'retry_count'             => $spoolStats['retry_count'] ?? 0,
            'spool_disk_bytes'        => $spoolStats['spool_disk_bytes'] ?? 0,
            'oldest_spool_age_seconds'=> $spoolStats['oldest_spool_age_seconds'] ?? null,
            'events_per_sec'          => $spoolStats['events_per_sec'] ?? null,
            'buffer_depth'            => $spoolStats['buffer_depth'] ?? 0,
            'spool_capped'            => (bool) ($spoolStats['spool_capped'] ?? false),
            'disk_pressure'           => (bool) ($spoolStats['disk_pressure'] ?? false),
            'trace_id'                => $traceId,
            'recorded_at'             => now(),
            'created_at'              => now(),
        ]);
    }

    /**
     * Get the latest spool snapshot for an agent.
     */
    public function getLatestSpoolSnapshot(EndpointAgent $agent): ?EndpointSpoolSnapshot
    {
        return EndpointSpoolSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('recorded_at')
            ->first();
    }

    /**
     * Count agents with spool warnings (high utilization or recent drops).
     */
    public function countSpoolWarnings(): int
    {
        return DB::table('endpoint_spool_snapshots as s')
            ->join(
                DB::raw('(SELECT agent_id, MAX(recorded_at) as max_ts FROM endpoint_spool_snapshots GROUP BY agent_id) as latest'),
                fn ($j) => $j->on('s.agent_id', '=', 'latest.agent_id')->on('s.recorded_at', '=', 'latest.max_ts')
            )
            ->where(function ($q) {
                $q->where('s.spool_capped', true)
                  ->orWhere('s.dropped_events', '>=', self::DROPPED_EVENTS_WARN)
                  ->orWhere('s.disk_pressure', true);
            })
            ->count();
    }

    /**
     * Get agents with spool health concerns.
     */
    public function getSpoolHealthSummary(int $limit = 50): Collection
    {
        return DB::table('endpoint_spool_snapshots as s')
            ->join(
                DB::raw('(SELECT agent_id, MAX(recorded_at) as max_ts FROM endpoint_spool_snapshots GROUP BY agent_id) as latest'),
                fn ($j) => $j->on('s.agent_id', '=', 'latest.agent_id')->on('s.recorded_at', '=', 'latest.max_ts')
            )
            ->join('endpoint_agents as a', 's.agent_id', '=', 'a.id')
            ->select(
                'a.agent_id', 'a.hostname', 'a.health_state',
                's.queued_events', 's.dropped_events', 's.retry_count',
                's.spool_disk_bytes', 's.spool_capped', 's.disk_pressure',
                's.recorded_at'
            )
            ->orderByDesc('s.dropped_events')
            ->limit($limit)
            ->get();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function recordTamperEvent(EndpointAgent $agent, array $data): EndpointTamperEvent
    {
        return EndpointTamperEvent::create([
            'tamper_id'   => 'tamper-' . Str::uuid(),
            'agent_id'    => $agent->id,
            'tamper_type' => $data['tamper_type'],
            'severity'    => $data['severity'],
            'description' => $data['description'],
            'evidence'    => $data['evidence'] ?? null,
            'confidence'  => $data['confidence'] ?? 0.7,
            'is_advisory' => true,    // always true — no autonomous enforcement
            'acknowledged'=> false,
            'trace_id'    => $data['trace_id'] ?? null,
            'detected_at' => now(),
            'created_at'  => now(),
        ]);
    }

    private function calculateHealthStateNow(int $heartbeatIntervalSeconds = 60): string
    {
        return EndpointAgent::HEALTH_ONLINE;
    }
}
