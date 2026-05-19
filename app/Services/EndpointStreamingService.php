<?php

namespace App\Services;

use App\Models\EndpointStreamCheckpoint;
use App\Models\EndpointStreamEvent;
use App\Models\EndpointStreamHealth;
use App\Models\EndpointStreamOffset;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Endpoint Streaming Telemetry Service — Phase 1.
 *
 * Near-real-time advisory telemetry. Not kernel-level EDR.
 * All analytics are read-only and advisory-only.
 * No autonomous containment. No kernel telemetry.
 * Append-only audit posture throughout.
 */
class EndpointStreamingService
{
    public const REPLAY_WINDOW_SECONDS  = 300;   // 5-min bounded replay window
    public const GAP_TOLERANCE_SECONDS  = 60;    // late-event tolerance
    public const MAX_BATCH_SIZE         = 200;   // bounded ingestion batch
    public const RAPID_WINDOW_SECONDS   = 60;    // rapid analytics detection window
    public const BURST_THRESHOLD        = 10;    // events in window = burst
    public const BEACON_INTERVAL_RANGE  = 5;     // ±5s beacon interval tolerance

    // -----------------------------------------------------------------------
    // Batch ingestion — idempotent, replay-safe
    // -----------------------------------------------------------------------

    /**
     * Ingest a batch of stream events from an agent.
     * Idempotent: duplicate event_ids are silently skipped (ON CONFLICT).
     * Returns count of newly persisted events.
     */
    public function ingestBatch(string $agentId, string $hostId, array $events, string $batchId = ''): array
    {
        if (empty($events)) {
            return ['accepted' => 0, 'duplicate' => 0, 'invalid' => 0, 'batch_id' => $batchId];
        }

        $batchId     = $batchId ?: 'batch-' . Str::uuid();
        $accepted    = 0;
        $duplicates  = 0;
        $invalid     = 0;
        $maxSequence = 0;

        foreach (array_slice($events, 0, self::MAX_BATCH_SIZE) as $raw) {
            $eventType = $raw['event_type'] ?? '';
            if (!in_array($eventType, EndpointStreamEvent::EVENT_TYPES, true)) {
                $invalid++;
                continue;
            }

            $eventId    = $raw['event_id'] ?? EndpointStreamEvent::generateEventId();
            $sequenceId = (int) ($raw['sequence_id'] ?? 0);
            $maxSequence = max($maxSequence, $sequenceId);

            // ON CONFLICT behavior — skip duplicates
            $exists = EndpointStreamEvent::where('event_id', $eventId)->exists();
            if ($exists) {
                $duplicates++;
                continue;
            }

            EndpointStreamEvent::create([
                'event_id'       => $eventId,
                'agent_id'       => $agentId,
                'host_id'        => $hostId,
                'event_type'     => $eventType,
                'sequence_id'    => $sequenceId,
                'batch_id'       => $batchId,
                'occurred_at'    => $raw['occurred_at'] ?? now(),
                'process_pid'    => $raw['process_pid'] ?? null,
                'process_name'   => $raw['process_name'] ?? null,
                'process_path'   => $raw['process_path'] ?? null,
                'process_cmdline'=> isset($raw['process_cmdline']) ? substr($raw['process_cmdline'], 0, 2048) : null,
                'parent_pid'     => $raw['parent_pid'] ?? null,
                'parent_name'    => $raw['parent_name'] ?? null,
                'process_uid'    => $raw['process_uid'] ?? null,
                'connection_dest'=> $raw['connection_dest'] ?? null,
                'connection_port'=> $raw['connection_port'] ?? null,
                'connection_proto'=> $raw['connection_proto'] ?? null,
                'persistence_key' => isset($raw['persistence_key']) ? substr($raw['persistence_key'], 0, 512) : null,
                'persistence_type'=> $raw['persistence_type'] ?? null,
                'persistence_path'=> isset($raw['persistence_path']) ? substr($raw['persistence_path'], 0, 1024) : null,
                'shell_hint'     => isset($raw['shell_hint']) ? substr($raw['shell_hint'], 0, 256) : null,
                'response_exec_id'=> $raw['response_exec_id'] ?? null,
                'trace_id'       => $raw['trace_id'] ?? null,
                'metadata'       => $raw['metadata'] ?? null,
            ]);
            $accepted++;
        }

        if ($accepted > 0) {
            $this->recordOffset($agentId, $maxSequence, $batchId, $accepted);
            $this->recordCheckpoint($agentId, $maxSequence, $batchId, $accepted);
            $this->updateStreamHealth($agentId, $maxSequence, $accepted);
        }

        return ['accepted' => $accepted, 'duplicate' => $duplicates, 'invalid' => $invalid, 'batch_id' => $batchId];
    }

    // -----------------------------------------------------------------------
    // Sequence and gap detection
    // -----------------------------------------------------------------------

    /**
     * Detect sequence gaps for an agent.
     * Returns list of gap ranges: [['from' => N, 'to' => M], ...]
     * Advisory-only: does not auto-trigger replay.
     */
    public function detectSequenceGaps(string $agentId, int $windowSeconds = self::REPLAY_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $sequences = EndpointStreamEvent::where('agent_id', $agentId)
            ->where('occurred_at', '>=', $since)
            ->orderBy('sequence_id')
            ->pluck('sequence_id')
            ->unique()
            ->values()
            ->toArray();

        if (count($sequences) < 2) {
            return [];
        }

        $gaps = [];
        for ($i = 0; $i < count($sequences) - 1; $i++) {
            $expected = $sequences[$i] + 1;
            $actual   = $sequences[$i + 1];
            if ($actual > $expected) {
                $gaps[] = ['from' => $sequences[$i], 'to' => $actual, 'missing_count' => $actual - $expected];
            }
        }
        return $gaps;
    }

    /**
     * Get events within a bounded replay window for an agent.
     * Bounded by REPLAY_WINDOW_SECONDS to prevent unbounded replay.
     */
    public function getReplayWindow(string $agentId, int $fromSequence, ?int $toSequence = null): Collection
    {
        $q = EndpointStreamEvent::where('agent_id', $agentId)
            ->where('sequence_id', '>=', $fromSequence)
            ->where('occurred_at', '>=', now()->subSeconds(self::REPLAY_WINDOW_SECONDS))
            ->orderBy('sequence_id');

        if ($toSequence !== null) {
            $q->where('sequence_id', '<=', $toSequence);
        }

        return $q->limit(500)->get();
    }

    // -----------------------------------------------------------------------
    // Rapid behavioral analytics — advisory-only, read-only
    // -----------------------------------------------------------------------

    /**
     * Detect rapid shell chain activity.
     * Returns advisory findings — no auto-response.
     */
    public function detectRapidShellChains(string $agentId, int $windowSeconds = self::RAPID_WINDOW_SECONDS): array
    {
        $since  = now()->subSeconds($windowSeconds);
        $events = EndpointStreamEvent::where('agent_id', $agentId)
            ->whereIn('event_type', [
                EndpointStreamEvent::TYPE_SHELL_EXECUTION_DETECTED,
                EndpointStreamEvent::TYPE_PROCESS_STARTED,
            ])
            ->where('occurred_at', '>=', $since)
            ->get();

        $shellNames = ['bash', 'sh', 'zsh', 'cmd', 'powershell', 'python', 'python3', 'perl', 'ruby'];
        $shellEvents = $events->filter(fn ($e) =>
            $e->event_type === EndpointStreamEvent::TYPE_SHELL_EXECUTION_DETECTED ||
            in_array(strtolower($e->process_name ?? ''), $shellNames, true)
        );

        if ($shellEvents->count() < 3) {
            return [];
        }

        return [[
            'finding_type'   => 'rapid_shell_chain',
            'agent_id'       => $agentId,
            'event_count'    => $shellEvents->count(),
            'window_seconds' => $windowSeconds,
            'advisory_only'  => true,
            'process_names'  => $shellEvents->pluck('process_name')->unique()->values()->toArray(),
            'detected_at'    => now()->toIso8601String(),
        ]];
    }

    /**
     * Detect burst outbound connection activity.
     */
    public function detectBurstOutbound(string $agentId, int $windowSeconds = self::RAPID_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $count = EndpointStreamEvent::where('agent_id', $agentId)
            ->where('event_type', EndpointStreamEvent::TYPE_OUTBOUND_CONNECTION_OPENED)
            ->where('occurred_at', '>=', $since)
            ->count();

        if ($count < self::BURST_THRESHOLD) {
            return [];
        }

        $dests = EndpointStreamEvent::where('agent_id', $agentId)
            ->where('event_type', EndpointStreamEvent::TYPE_OUTBOUND_CONNECTION_OPENED)
            ->where('occurred_at', '>=', $since)
            ->pluck('connection_dest')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        return [[
            'finding_type'       => 'burst_outbound_activity',
            'agent_id'           => $agentId,
            'connection_count'   => $count,
            'unique_destinations'=> count($dests),
            'window_seconds'     => $windowSeconds,
            'advisory_only'      => true,
            'detected_at'        => now()->toIso8601String(),
        ]];
    }

    /**
     * Detect short-lived suspicious processes.
     * Processes that start and terminate within the window with suspicious names.
     */
    public function detectShortLivedSuspiciousProcesses(string $agentId, int $windowSeconds = self::RAPID_WINDOW_SECONDS): array
    {
        $since     = now()->subSeconds($windowSeconds);
        $started   = EndpointStreamEvent::where('agent_id', $agentId)
            ->where('event_type', EndpointStreamEvent::TYPE_PROCESS_STARTED)
            ->where('occurred_at', '>=', $since)
            ->get()
            ->keyBy('process_pid');

        $terminated = EndpointStreamEvent::where('agent_id', $agentId)
            ->where('event_type', EndpointStreamEvent::TYPE_PROCESS_TERMINATED)
            ->where('occurred_at', '>=', $since)
            ->get()
            ->keyBy('process_pid');

        $suspiciousNames = ['nc', 'ncat', 'nmap', 'wget', 'curl', 'certutil', 'bitsadmin', 'mshta', 'wscript', 'cscript'];
        $findings = [];

        foreach ($started as $pid => $startEv) {
            if (!isset($terminated[$pid])) {
                continue;
            }
            $name = strtolower($startEv->process_name ?? '');
            if (!in_array($name, $suspiciousNames, true)) {
                continue;
            }
            $durationSeconds = (int) abs($terminated[$pid]->occurred_at->diffInSeconds($startEv->occurred_at));
            if ($durationSeconds < 30) {
                $findings[] = [
                    'finding_type'     => 'short_lived_suspicious_process',
                    'agent_id'         => $agentId,
                    'process_name'     => $name,
                    'process_pid'      => $pid,
                    'duration_seconds' => $durationSeconds,
                    'advisory_only'    => true,
                    'detected_at'      => now()->toIso8601String(),
                ];
            }
        }

        return $findings;
    }

    /**
     * Detect rapid persistence creation.
     */
    public function detectRapidPersistenceCreation(string $agentId, int $windowSeconds = self::RAPID_WINDOW_SECONDS): array
    {
        $since = now()->subSeconds($windowSeconds);
        $count = EndpointStreamEvent::where('agent_id', $agentId)
            ->whereIn('event_type', [
                EndpointStreamEvent::TYPE_PERSISTENCE_ITEM_CREATED,
                EndpointStreamEvent::TYPE_PERSISTENCE_ITEM_MODIFIED,
            ])
            ->where('occurred_at', '>=', $since)
            ->count();

        if ($count < 3) {
            return [];
        }

        return [[
            'finding_type'   => 'rapid_persistence_creation',
            'agent_id'       => $agentId,
            'event_count'    => $count,
            'window_seconds' => $windowSeconds,
            'advisory_only'  => true,
            'detected_at'    => now()->toIso8601String(),
        ]];
    }

    /**
     * Detect rapid execution beacon pattern (regular interval process starts).
     */
    public function detectStreamBeaconPattern(string $agentId, int $windowSeconds = 300): array
    {
        $since = now()->subSeconds($windowSeconds);
        $events = EndpointStreamEvent::where('agent_id', $agentId)
            ->where('event_type', EndpointStreamEvent::TYPE_PROCESS_STARTED)
            ->where('occurred_at', '>=', $since)
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('process_name');

        $findings = [];
        foreach ($events as $name => $group) {
            if ($group->count() < 4) {
                continue;
            }
            $timestamps = $group->pluck('occurred_at')->map(fn ($t) => $t->timestamp)->values()->toArray();
            $intervals  = [];
            for ($i = 1; $i < count($timestamps); $i++) {
                $intervals[] = $timestamps[$i] - $timestamps[$i - 1];
            }
            if (empty($intervals)) {
                continue;
            }
            $avgInterval = array_sum($intervals) / count($intervals);
            $variance    = max($intervals) - min($intervals);
            if ($avgInterval > 0 && $variance <= self::BEACON_INTERVAL_RANGE * 2) {
                $findings[] = [
                    'finding_type'       => 'rapid_execution_beacon_pattern',
                    'agent_id'           => $agentId,
                    'process_name'       => $name,
                    'execution_count'    => $group->count(),
                    'avg_interval_seconds'=> round($avgInterval, 1),
                    'interval_variance'  => $variance,
                    'advisory_only'      => true,
                    'detected_at'        => now()->toIso8601String(),
                ];
            }
        }
        return $findings;
    }

    // -----------------------------------------------------------------------
    // Stream health and checkpoint management
    // -----------------------------------------------------------------------

    public function getStreamHealthSummary(): Collection
    {
        return DB::table('endpoint_stream_health as h1')
            ->whereNotExists(function ($q) {
                $q->from('endpoint_stream_health as h2')
                  ->whereColumn('h2.agent_id', 'h1.agent_id')
                  ->whereColumn('h2.id', '>', 'h1.id');
            })
            ->get();
    }

    public function getLatestCheckpoints(int $limit = 50): Collection
    {
        return EndpointStreamCheckpoint::orderByDesc('id')->limit($limit)->get();
    }

    public function getRecentEvents(int $limit = 100, ?string $agentId = null, ?string $eventType = null): Collection
    {
        $q = EndpointStreamEvent::orderByDesc('sequence_id')->limit($limit);
        if ($agentId) {
            $q->where('agent_id', $agentId);
        }
        if ($eventType) {
            $q->where('event_type', $eventType);
        }
        return $q->get();
    }

    public function getAgentTimeline(string $agentId, int $limit = 200): Collection
    {
        return EndpointStreamEvent::where('agent_id', $agentId)
            ->orderByDesc('sequence_id')
            ->limit($limit)
            ->get();
    }

    public function getBurstActivitySummary(int $windowSeconds = 300): Collection
    {
        $since = now()->subSeconds($windowSeconds);
        return EndpointStreamEvent::where('occurred_at', '>=', $since)
            ->selectRaw('agent_id, event_type, count(*) as event_count')
            ->groupBy(['agent_id', 'event_type'])
            ->orderByDesc('event_count')
            ->get();
    }

    // -----------------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------------

    private function recordOffset(string $agentId, int $sequenceId, string $batchId, int $eventCount): void
    {
        EndpointStreamOffset::create([
            'agent_id'        => $agentId,
            'sequence_id'     => $sequenceId,
            'batch_id'        => $batchId,
            'event_count'     => $eventCount,
            'trace_id'        => 'str-' . Str::uuid(),
            'acknowledged_at' => now(),
        ]);
    }

    private function recordCheckpoint(string $agentId, int $sequenceId, string $batchId, int $eventCount): void
    {
        $prevOffset = EndpointStreamOffset::latestForAgent($agentId);
        $lagSeconds = $prevOffset ? max(0, now()->diffInSeconds($prevOffset->acknowledged_at ?? now())) : 0;

        EndpointStreamCheckpoint::create([
            'checkpoint_id'       => EndpointStreamCheckpoint::generateCheckpointId(),
            'agent_id'            => $agentId,
            'sequence_id'         => $sequenceId,
            'batch_id'            => $batchId,
            'event_count'         => $eventCount,
            'stream_lag_seconds'  => min($lagSeconds, 65535),
            'dropped_event_count' => 0,
            'trace_id'            => 'str-' . Str::uuid(),
        ]);
    }

    private function updateStreamHealth(string $agentId, int $sequenceId, int $batchCount): void
    {
        $prevHealth = EndpointStreamHealth::latestForAgent($agentId);
        $lagSeconds = 0;
        $dropped    = $prevHealth?->dropped_event_count ?? 0;

        $state = EndpointStreamHealth::classifyState($lagSeconds, $dropped);

        EndpointStreamHealth::create([
            'agent_id'            => $agentId,
            'stream_health_state' => $state,
            'last_sequence_seen'  => $sequenceId,
            'stream_lag_seconds'  => $lagSeconds,
            'dropped_event_count' => $dropped,
            'batch_count'         => ($prevHealth?->batch_count ?? 0) + 1,
            'trace_id'            => 'str-' . Str::uuid(),
        ]);
    }
}
