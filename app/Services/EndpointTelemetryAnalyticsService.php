<?php

namespace App\Services;

use App\Models\EndpointAgent;
use App\Models\EndpointScriptExecution;
use App\Models\EndpointPrivilegeEscalation;
use App\Models\EndpointContainerActivity;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Low-level endpoint telemetry analytics service — Phase 1.
 * All analytics are advisory-only. No autonomous enforcement, no process termination,
 * no host isolation, no memory scanning.
 */
class EndpointTelemetryAnalyticsService
{
    // Lookback window for recent analytics
    public const DEFAULT_LOOKBACK_DAYS = 7;

    // -------------------------------------------------------------------------
    // Script execution analytics
    // -------------------------------------------------------------------------

    /**
     * Record a script execution event (append-only).
     */
    public function recordScriptExecution(EndpointAgent $agent, array $payload): EndpointScriptExecution
    {
        return EndpointScriptExecution::create([
            'execution_id'       => EndpointScriptExecution::generateExecutionId(),
            'agent_id'           => $agent->id,
            'process_name'       => $payload['process_name'] ?? 'unknown',
            'parent_process_name'=> $payload['parent_process_name'] ?? null,
            'command_line'       => isset($payload['command_line']) ? substr($payload['command_line'], 0, 32768) : null,
            'script_source'      => $payload['script_source'] ?? EndpointScriptExecution::SOURCE_INLINE,
            'is_encoded'         => (bool) ($payload['is_encoded'] ?? false),
            'decoded_preview'    => isset($payload['decoded_preview']) ? substr($payload['decoded_preview'], 0, 256) : null,
            'script_hash'        => $payload['script_hash'] ?? null,
            'user'               => $payload['user'] ?? null,
            'telemetry_source'   => $payload['telemetry_source'] ?? EndpointScriptExecution::TELEM_AGENT_PROC,
            'host_id'            => $agent->host_id ?? $payload['host_id'] ?? '',
            'trace_id'           => $payload['trace_id'] ?? null,
            'is_advisory'        => true,
            'occurred_at'        => $payload['occurred_at'] ?? now(),
            'created_at'         => now(),
        ]);
    }

    /**
     * Get recent script executions for an agent. Advisory-only view.
     */
    public function getScriptExecutions(EndpointAgent $agent, int $days = self::DEFAULT_LOOKBACK_DAYS): Collection
    {
        return EndpointScriptExecution::where('agent_id', $agent->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();
    }

    /**
     * Get encoded script executions — high-interest subset for analyst review.
     */
    public function getEncodedScriptExecutions(int $days = self::DEFAULT_LOOKBACK_DAYS): Collection
    {
        return EndpointScriptExecution::where('is_encoded', true)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();
    }

    /**
     * Count encoded scripts in last N days — for dashboard metrics.
     */
    public function countEncodedScripts(int $days = self::DEFAULT_LOOKBACK_DAYS): int
    {
        return EndpointScriptExecution::where('is_encoded', true)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->count();
    }

    /**
     * Get top interpreters by execution count. Useful for spotting unusual interpreter activity.
     */
    public function getTopInterpreters(int $days = self::DEFAULT_LOOKBACK_DAYS, int $limit = 10): Collection
    {
        return DB::table('endpoint_script_executions')
            ->where('occurred_at', '>=', now()->subDays($days))
            ->select('process_name', DB::raw('count(*) as execution_count'))
            ->groupBy('process_name')
            ->orderByDesc('execution_count')
            ->limit($limit)
            ->get();
    }

    /**
     * Summary for dashboard: total executions, encoded count, top interpreters.
     */
    public function getScriptExecutionSummary(int $days = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $since = now()->subDays($days);
        return [
            'total_executions'   => EndpointScriptExecution::where('occurred_at', '>=', $since)->count(),
            'encoded_count'      => EndpointScriptExecution::where('is_encoded', true)->where('occurred_at', '>=', $since)->count(),
            'top_interpreters'   => $this->getTopInterpreters($days, 5),
            'advisory_only'      => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Privilege escalation analytics
    // -------------------------------------------------------------------------

    /**
     * Record a privilege escalation indicator (append-only).
     */
    public function recordPrivilegeEscalation(EndpointAgent $agent, array $payload): EndpointPrivilegeEscalation
    {
        return EndpointPrivilegeEscalation::create([
            'escalation_id'   => EndpointPrivilegeEscalation::generateEscalationId(),
            'agent_id'        => $agent->id,
            'process_name'    => $payload['process_name'] ?? 'unknown',
            'pid'             => $payload['pid'] ?? null,
            'original_uid'    => $payload['original_uid'] ?? null,
            'escalated_uid'   => $payload['escalated_uid'] ?? null,
            'original_user'   => $payload['original_user'] ?? null,
            'escalated_user'  => $payload['escalated_user'] ?? null,
            'escalation_type' => $payload['escalation_type'] ?? EndpointPrivilegeEscalation::TYPE_UID_TRANSITION,
            'command_line'    => isset($payload['command_line']) ? substr($payload['command_line'], 0, 32768) : null,
            'telemetry_source'=> $payload['telemetry_source'] ?? 'agent_proc',
            'host_id'         => $agent->host_id ?? $payload['host_id'] ?? '',
            'trace_id'        => $payload['trace_id'] ?? null,
            'is_advisory'     => true,
            'confidence'      => (float) ($payload['confidence'] ?? 0.75),
            'occurred_at'     => $payload['occurred_at'] ?? now(),
            'created_at'      => now(),
        ]);
    }

    /**
     * Get recent privilege escalation indicators for an agent.
     */
    public function getPrivilegeEscalations(EndpointAgent $agent, int $days = self::DEFAULT_LOOKBACK_DAYS): Collection
    {
        return EndpointPrivilegeEscalation::where('agent_id', $agent->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();
    }

    /**
     * Get fleet-wide privilege escalation timeline — sorted by confidence desc.
     */
    public function getPrivilegeEscalationTimeline(int $days = self::DEFAULT_LOOKBACK_DAYS): Collection
    {
        return EndpointPrivilegeEscalation::where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('confidence')
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();
    }

    /**
     * Summary: count by escalation type, total count, high-confidence count.
     */
    public function getPrivilegeEscalationSummary(int $days = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $since = now()->subDays($days);
        $byType = DB::table('endpoint_privilege_escalations')
            ->where('occurred_at', '>=', $since)
            ->select('escalation_type', DB::raw('count(*) as count'))
            ->groupBy('escalation_type')
            ->get();

        return [
            'total'           => EndpointPrivilegeEscalation::where('occurred_at', '>=', $since)->count(),
            'high_confidence' => EndpointPrivilegeEscalation::where('occurred_at', '>=', $since)->where('confidence', '>=', 0.8)->count(),
            'by_type'         => $byType,
            'advisory_only'   => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Container activity analytics
    // -------------------------------------------------------------------------

    /**
     * Record a container activity event (append-only).
     */
    public function recordContainerActivity(EndpointAgent $agent, array $payload): EndpointContainerActivity
    {
        return EndpointContainerActivity::create([
            'activity_id'    => EndpointContainerActivity::generateActivityId(),
            'agent_id'       => $agent->id,
            'container_id'   => $payload['container_id'] ?? null,
            'container_name' => $payload['container_name'] ?? null,
            'image_name'     => $payload['image_name'] ?? null,
            'activity_type'  => $payload['activity_type'] ?? EndpointContainerActivity::TYPE_NAMESPACE_DETECTED,
            'pid'            => $payload['pid'] ?? null,
            'process_name'   => $payload['process_name'] ?? null,
            'namespace_type' => $payload['namespace_type'] ?? EndpointContainerActivity::NS_UNKNOWN,
            'host_id'        => $agent->host_id ?? $payload['host_id'] ?? '',
            'trace_id'       => $payload['trace_id'] ?? null,
            'is_advisory'    => true,
            'occurred_at'    => $payload['occurred_at'] ?? now(),
            'created_at'     => now(),
        ]);
    }

    /**
     * Get recent container activities for an agent.
     */
    public function getContainerActivities(EndpointAgent $agent, int $days = self::DEFAULT_LOOKBACK_DAYS): Collection
    {
        return EndpointContainerActivity::where('agent_id', $agent->id)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();
    }

    /**
     * Get breakout indicators across the fleet — analyst priority queue.
     */
    public function getContainerBreakoutIndicators(int $days = self::DEFAULT_LOOKBACK_DAYS): Collection
    {
        return EndpointContainerActivity::where('activity_type', EndpointContainerActivity::TYPE_BREAKOUT_INDICATOR)
            ->where('occurred_at', '>=', now()->subDays($days))
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();
    }

    /**
     * Summary: active containers, breakout indicators, namespace type breakdown.
     */
    public function getContainerActivitySummary(int $days = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $since = now()->subDays($days);
        $byNs = DB::table('endpoint_container_activities')
            ->where('occurred_at', '>=', $since)
            ->select('namespace_type', DB::raw('count(*) as count'))
            ->groupBy('namespace_type')
            ->get();

        return [
            'total_activities'        => EndpointContainerActivity::where('occurred_at', '>=', $since)->count(),
            'breakout_indicators'     => EndpointContainerActivity::where('occurred_at', '>=', $since)
                ->where('activity_type', EndpointContainerActivity::TYPE_BREAKOUT_INDICATOR)->count(),
            'unique_containers'       => DB::table('endpoint_container_activities')
                ->where('occurred_at', '>=', $since)->whereNotNull('container_id')
                ->distinct('container_id')->count('container_id'),
            'by_namespace_type'       => $byNs,
            'advisory_only'           => true,
        ];
    }

    // -------------------------------------------------------------------------
    // Cross-domain dashboard stats
    // -------------------------------------------------------------------------

    /**
     * Fleet-wide dashboard statistics for the low-level telemetry overview.
     */
    public function getDashboardStats(int $days = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $since = now()->subDays($days);
        return [
            'script_executions'        => EndpointScriptExecution::where('occurred_at', '>=', $since)->count(),
            'encoded_scripts'          => EndpointScriptExecution::where('is_encoded', true)->where('occurred_at', '>=', $since)->count(),
            'privilege_escalations'    => EndpointPrivilegeEscalation::where('occurred_at', '>=', $since)->count(),
            'high_confidence_escalations' => EndpointPrivilegeEscalation::where('occurred_at', '>=', $since)->where('confidence', '>=', 0.8)->count(),
            'container_activities'     => EndpointContainerActivity::where('occurred_at', '>=', $since)->count(),
            'container_breakouts'      => EndpointContainerActivity::where('occurred_at', '>=', $since)
                ->where('activity_type', EndpointContainerActivity::TYPE_BREAKOUT_INDICATOR)->count(),
            'advisory_only'            => true,
            'lookback_days'            => $days,
        ];
    }

    /**
     * Get process execution statistics from existing endpoint_process_entries table.
     */
    public function getProcessExecutionStats(int $days = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $since = now()->subDays($days);
        return [
            'total_processes'   => DB::table('endpoint_process_entries')->where('endpoint_process_entries.created_at', '>=', $since)->count(),
            'shell_processes'   => DB::table('endpoint_process_entries')->where('endpoint_process_entries.created_at', '>=', $since)->where('is_shell', true)->count(),
            'suspicious'        => DB::table('endpoint_process_entries')->where('endpoint_process_entries.created_at', '>=', $since)->where('is_suspicious', true)->count(),
            'long_lived'        => DB::table('endpoint_process_entries')->where('endpoint_process_entries.created_at', '>=', $since)->where('is_long_lived', true)->count(),
            'advisory_only'     => true,
        ];
    }

    /**
     * Get network connection stats from existing endpoint_network_correlations.
     */
    public function getNetworkConnectionStats(int $days = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $since = now()->subDays($days);
        $total = DB::table('endpoint_network_correlations')->where('endpoint_network_correlations.created_at', '>=', $since)->count();
        return [
            'total_connections' => $total,
            'advisory_only'     => true,
        ];
    }

    /**
     * Get persistence indicator stats from existing endpoint_persistence_items.
     */
    public function getPersistenceIndicatorStats(int $days = self::DEFAULT_LOOKBACK_DAYS): array
    {
        $since = now()->subDays($days);
        return [
            'total_items'   => DB::table('endpoint_persistence_items')->where('endpoint_persistence_items.created_at', '>=', $since)->count(),
            'new_items'     => DB::table('endpoint_persistence_items')->where('endpoint_persistence_items.created_at', '>=', $since)->where('is_new', true)->count(),
            'advisory_only' => true,
        ];
    }
}
