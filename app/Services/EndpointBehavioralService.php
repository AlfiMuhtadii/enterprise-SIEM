<?php

namespace App\Services;

use App\Models\EndpointAgent;
use App\Models\EndpointNetworkCorrelation;
use App\Models\EndpointPersistenceItem;
use App\Models\EndpointProcessEntry;
use App\Models\EndpointProcessSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores and queries behavioral endpoint visibility data.
 * Shadow-only visibility — no active containment, no process killing, no enforcement.
 */
class EndpointBehavioralService
{
    private const LONG_LIVED_THRESHOLD_SECONDS = 3600; // 1 hour

    private const SHELL_PROCESS_NAMES = [
        'bash', 'sh', 'zsh', 'dash', 'ksh', 'tcsh', 'fish',
        'python', 'python3', 'python2', 'perl', 'ruby',
        'curl', 'wget',
    ];

    private const WEB_SERVER_PROCESS_NAMES = [
        'nginx', 'apache', 'apache2', 'httpd', 'gunicorn',
        'uwsgi', 'php-fpm', 'tomcat', 'mysqld', 'postgres', 'mongod',
    ];

    // -----------------------------------------------------------------------
    // Store incoming behavioral snapshot from agent
    // -----------------------------------------------------------------------

    /**
     * Parse and persist an agent behavioral snapshot.
     * Returns the created snapshot record.
     */
    public function storeSnapshot(EndpointAgent $agent, array $payload, string $traceId): EndpointProcessSnapshot
    {
        $processes          = $payload['processes'] ?? [];
        $persistenceItems   = $payload['persistence_items'] ?? [];
        $networkCorrelations= $payload['network_correlations'] ?? [];
        $collectedAt        = $payload['collected_at'] ?? now()->toIso8601String();

        $shellCount    = 0;
        $longLivedCount= 0;
        $suspiciousCount=0;

        foreach ($processes as $proc) {
            if (!empty($proc['is_shell'])) $shellCount++;
            if (!empty($proc['is_long_lived'])) $longLivedCount++;
            if (!empty($proc['is_suspicious'])) $suspiciousCount++;
        }

        $snapshot = EndpointProcessSnapshot::create([
            'snapshot_id'    => EndpointProcessSnapshot::generateSnapshotId(),
            'agent_id'       => $agent->id,
            'collected_at'   => $collectedAt,
            'process_count'  => count($processes),
            'shell_count'    => $shellCount,
            'long_lived_count'=> $longLivedCount,
            'suspicious_count'=> $suspiciousCount,
            'trace_id'       => $traceId,
        ]);

        // Insert process entries
        $now = now();
        $entryRows = [];
        foreach (array_slice($processes, 0, 2000) as $proc) {
            $entryRows[] = [
                'snapshot_id'        => $snapshot->id,
                'agent_id'           => $agent->id,
                'pid'                => $proc['pid'] ?? null,
                'ppid'               => $proc['ppid'] ?? null,
                'process_name'       => isset($proc['process_name']) ? substr($proc['process_name'], 0, 255) : null,
                'parent_process_name'=> isset($proc['parent_process_name']) ? substr($proc['parent_process_name'], 0, 255) : null,
                'executable_path'    => $proc['executable_path'] ?? null,
                'command_line'       => isset($proc['command_line']) ? substr($proc['command_line'], 0, 4096) : null,
                'user'               => isset($proc['user']) ? substr($proc['user'], 0, 255) : null,
                'session_id'         => isset($proc['session_id']) ? substr($proc['session_id'], 0, 40) : null,
                'first_seen_at'      => $proc['first_seen_at'] ?? null,
                'last_seen_at'       => $proc['last_seen_at'] ?? null,
                'duration_seconds'   => $proc['duration_seconds'] ?? null,
                'is_shell'           => !empty($proc['is_shell']) ? 1 : 0,
                'is_long_lived'      => !empty($proc['is_long_lived']) ? 1 : 0,
                'is_suspicious'      => !empty($proc['is_suspicious']) ? 1 : 0,
                'trace_id'           => $traceId,
                'created_at'         => $now,
            ];
        }
        if ($entryRows) {
            DB::table('endpoint_process_entries')->insert($entryRows);
        }

        // Upsert persistence items
        foreach (array_slice($persistenceItems, 0, 500) as $item) {
            $itemKey = isset($item['item_key']) ? substr($item['item_key'], 0, 255) : null;
            if (!$itemKey) continue;

            $existing = EndpointPersistenceItem::where('agent_id', $agent->id)
                ->where('item_key', $itemKey)
                ->first();

            if ($existing) {
                $existing->update([
                    'last_seen_at' => $collectedAt,
                    'is_new'       => false,
                    'trace_id'     => $traceId,
                ]);
            } else {
                EndpointPersistenceItem::create([
                    'agent_id'     => $agent->id,
                    'item_type'    => isset($item['item_type']) ? substr($item['item_type'], 0, 60) : 'unknown',
                    'item_key'     => $itemKey,
                    'item_name'    => isset($item['item_name']) ? substr($item['item_name'], 0, 255) : $itemKey,
                    'item_path'    => $item['item_path'] ?? null,
                    'is_new'       => true,
                    'first_seen_at'=> $collectedAt,
                    'last_seen_at' => $collectedAt,
                    'trace_id'     => $traceId,
                ]);
            }
        }

        // Insert network correlations
        $corrRows = [];
        foreach (array_slice($networkCorrelations, 0, 1000) as $corr) {
            $corrRows[] = [
                'snapshot_id'           => $snapshot->id,
                'agent_id'              => $agent->id,
                'pid'                   => $corr['pid'] ?? null,
                'process_name'          => isset($corr['process_name']) ? substr($corr['process_name'], 0, 255) : null,
                'remote_ip'             => isset($corr['remote_ip']) ? substr($corr['remote_ip'], 0, 45) : null,
                'remote_port'           => $corr['remote_port'] ?? null,
                'proto'                 => isset($corr['proto']) ? substr($corr['proto'], 0, 10) : null,
                'correlation_confidence'=> $corr['correlation_confidence'] ?? null,
                'trace_id'              => $traceId,
                'created_at'            => $now,
            ];
        }
        if ($corrRows) {
            DB::table('endpoint_network_correlations')->insert($corrRows);
        }

        // Project entity relationships from snapshot
        $this->projectEntityRelationships($agent, $snapshot, $processes, $persistenceItems);

        // Run behavioral analytics — advisory-only, append-only findings
        app(BehavioralAnalyticsService::class)->analyzeSnapshot(
            $agent, $snapshot, $processes, $persistenceItems, $networkCorrelations, $traceId
        );

        return $snapshot->fresh();
    }

    // -----------------------------------------------------------------------
    // Queries for UI
    // -----------------------------------------------------------------------

    /**
     * Returns the most recent snapshot for an agent plus basic activity summary.
     */
    public function getActivityTimeline(EndpointAgent $agent, int $limit = 20): array
    {
        $snapshots = EndpointProcessSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('collected_at')
            ->limit($limit)
            ->get();

        return $snapshots->map(fn ($s) => [
            'snapshot_id'    => $s->snapshot_id,
            'collected_at'   => $s->collected_at?->toIso8601String(),
            'process_count'  => $s->process_count,
            'shell_count'    => $s->shell_count,
            'long_lived_count'=> $s->long_lived_count,
            'suspicious_count'=> $s->suspicious_count,
            'trace_id'       => $s->trace_id,
        ])->all();
    }

    /**
     * Build a process tree (flat list with parent references) from the latest snapshot.
     */
    public function getProcessTree(EndpointAgent $agent): array
    {
        $snapshot = EndpointProcessSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('collected_at')
            ->first();

        if (!$snapshot) {
            return [];
        }

        return EndpointProcessEntry::where('snapshot_id', $snapshot->id)
            ->orderBy('ppid')
            ->orderBy('process_name')
            ->get()
            ->map(fn ($e) => [
                'pid'                => $e->pid,
                'ppid'               => $e->ppid,
                'process_name'       => $e->process_name,
                'parent_process_name'=> $e->parent_process_name,
                'executable_path'    => $e->executable_path,
                'user'               => $e->user,
                'is_shell'           => $e->is_shell,
                'is_long_lived'      => $e->is_long_lived,
                'is_suspicious'      => $e->is_suspicious,
                'duration_seconds'   => $e->duration_seconds,
            ])->all();
    }

    /**
     * Get the persistence inventory for an agent (latest state per item_key).
     */
    public function getPersistenceInventory(EndpointAgent $agent): array
    {
        return EndpointPersistenceItem::where('agent_id', $agent->id)
            ->orderBy('item_type')
            ->orderBy('item_name')
            ->get()
            ->map(fn ($i) => [
                'item_type'    => $i->item_type,
                'item_key'     => $i->item_key,
                'item_name'    => $i->item_name,
                'item_path'    => $i->item_path,
                'is_new'       => $i->is_new,
                'first_seen_at'=> $i->first_seen_at?->toIso8601String(),
                'last_seen_at' => $i->last_seen_at?->toIso8601String(),
            ])->all();
    }

    /**
     * Get process-to-network correlations from the latest snapshot.
     */
    public function getNetworkCorrelations(EndpointAgent $agent): array
    {
        $snapshot = EndpointProcessSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('collected_at')
            ->first();

        if (!$snapshot) {
            return [];
        }

        return EndpointNetworkCorrelation::where('snapshot_id', $snapshot->id)
            ->orderBy('process_name')
            ->get()
            ->map(fn ($c) => [
                'pid'                   => $c->pid,
                'process_name'          => $c->process_name,
                'remote_ip'             => $c->remote_ip,
                'remote_port'           => $c->remote_port,
                'proto'                 => $c->proto,
                'correlation_confidence'=> $c->correlation_confidence,
            ])->all();
    }

    /**
     * Get long-lived processes from the latest snapshot.
     */
    public function getLongLivedProcesses(EndpointAgent $agent): array
    {
        $snapshot = EndpointProcessSnapshot::where('agent_id', $agent->id)
            ->orderByDesc('collected_at')
            ->first();

        if (!$snapshot) {
            return [];
        }

        return EndpointProcessEntry::where('snapshot_id', $snapshot->id)
            ->where('is_long_lived', true)
            ->orderByDesc('duration_seconds')
            ->get()
            ->map(fn ($e) => [
                'pid'              => $e->pid,
                'process_name'     => $e->process_name,
                'user'             => $e->user,
                'duration_seconds' => $e->duration_seconds,
                'first_seen_at'    => $e->first_seen_at?->toIso8601String(),
                'last_seen_at'     => $e->last_seen_at?->toIso8601String(),
                'is_shell'         => $e->is_shell,
                'command_line'     => $e->command_line,
            ])->all();
    }

    // -----------------------------------------------------------------------
    // Entity graph projection
    // -----------------------------------------------------------------------

    /**
     * Project behavioral snapshot into entity graph relationships.
     * Shadow-only — no enforcement, advisory visibility only.
     */
    private function projectEntityRelationships(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        array $processes,
        array $persistenceItems
    ): void {
        $entitySvc = app(EntityGraphService::class);
        $traceId   = $snapshot->trace_id;
        $now       = $snapshot->collected_at?->toIso8601String() ?? now()->toIso8601String();
        $host      = $agent->hostname ?? $agent->host_id ?? "host:{$agent->id}";

        foreach (array_slice($processes, 0, 200) as $proc) {
            $procName = $proc['process_name'] ?? '';
            $pid      = $proc['pid'] ?? 0;
            $user     = $proc['user'] ?? '';
            $parentName = $proc['parent_process_name'] ?? '';

            if (!$procName) continue;

            $procKey  = "process:{$host}:{$procName}:{$pid}";
            $procId   = $entitySvc->upsertEntity('process', $procKey, "{$procName}[{$pid}]@{$host}", $now);

            // process_started_by_user
            if ($user) {
                $userKey = "user:{$user}";
                $userId  = $entitySvc->upsertEntity('user', $userKey, $user, $now);
                $entitySvc->upsertRelationship($procId, $userId, 'process_started_by_user', $now, null, null, null, $traceId);
            }

            // process_spawned_process (child → parent)
            if ($parentName) {
                $parentKey = "process:{$host}:{$parentName}";
                $parentId  = $entitySvc->upsertEntity('process', $parentKey, "{$parentName}@{$host}", $now);
                $entitySvc->upsertRelationship($parentId, $procId, 'process_spawned_process', $now, null, null, null, $traceId);
            }
        }

        // host_has_persistence_item
        $hostKey = "host:{$host}";
        $hostId  = $entitySvc->upsertEntity('host', $hostKey, $host, $now);
        foreach (array_slice($persistenceItems, 0, 100) as $item) {
            $itemKey   = $item['item_key'] ?? '';
            $itemName  = $item['item_name'] ?? $itemKey;
            if (!$itemKey) continue;
            $persKey   = "persistence:{$host}:{$itemKey}";
            $persId    = $entitySvc->upsertEntity('persistence', $persKey, "{$itemName}@{$host}", $now);
            $entitySvc->upsertRelationship($hostId, $persId, 'host_has_persistence_item', $now, null, null, null, $traceId);
        }
    }
}
