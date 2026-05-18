<?php

namespace App\Services;

use App\Models\EndpointAgent;
use App\Models\EndpointBeaconPattern;
use App\Models\EndpointBehavioralFinding;
use App\Models\EndpointExecutionChain;
use App\Models\EndpointPersistenceItem;
use App\Models\EndpointProcessSnapshot;
use Illuminate\Support\Collection;

/**
 * Behavioral Detection Analytics Phase 1.
 *
 * All analytics are advisory-only and shadow-mode.
 * No active containment, no process kill, no isolation.
 * All findings are append-only records for investigation enrichment.
 */
class BehavioralAnalyticsService
{
    // -----------------------------------------------------------------------
    // LOLBin / shell lists
    // -----------------------------------------------------------------------

    private const LOLBIN_NAMES = [
        'curl', 'wget', 'bash', 'sh', 'python', 'python3', 'python2',
        'perl', 'nc', 'netcat', 'ncat', 'base64', 'systemctl', 'crontab',
        'dd', 'awk', 'sed', 'tee', 'xargs', 'chmod', 'chown',
    ];

    private const SHELL_NAMES = [
        'bash', 'sh', 'zsh', 'dash', 'ksh', 'tcsh', 'fish',
        'python', 'python3', 'python2', 'perl', 'ruby',
    ];

    private const DOWNLOADER_NAMES = ['curl', 'wget'];

    /** Parent processes whose shell children are anomalous */
    private const SUSPICIOUS_PARENT_CHILD = [
        'nginx'     => ['bash', 'sh', 'python', 'python3', 'perl', 'curl', 'wget'],
        'apache2'   => ['bash', 'sh', 'python', 'python3', 'perl', 'curl', 'wget'],
        'apache'    => ['bash', 'sh', 'python', 'python3', 'perl', 'curl', 'wget'],
        'httpd'     => ['bash', 'sh', 'python', 'python3', 'perl', 'curl', 'wget'],
        'gunicorn'  => ['bash', 'sh', 'python', 'python3'],
        'uwsgi'     => ['bash', 'sh', 'python', 'python3'],
        'mysqld'    => ['bash', 'sh', 'python'],
        'postgres'  => ['bash', 'sh', 'python'],
        'mongod'    => ['bash', 'sh', 'python'],
        'cron'      => ['bash', 'sh', 'curl', 'wget', 'nc', 'netcat'],
        'crond'     => ['bash', 'sh', 'curl', 'wget', 'nc', 'netcat'],
        'php-fpm'   => ['bash', 'sh', 'python', 'python3', 'perl'],
    ];

    private const BEACON_THRESHOLD = 3;        // min connections for beacon detection
    private const CHAIN_SCORE_THRESHOLD = 0.50; // min score to emit a finding

    // -----------------------------------------------------------------------
    // Main entry — analyse all aspects of a snapshot
    // -----------------------------------------------------------------------

    /**
     * Run all behavioral analytics on a stored snapshot.
     * Returns count of findings generated.
     * Advisory-only — no side effects on process state.
     */
    public function analyzeSnapshot(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        array $processes,
        array $persistenceItems,
        array $networkCorrelations,
        string $traceId
    ): int {
        $count = 0;
        $count += $this->detectExecutionChains($agent, $snapshot, $processes, $networkCorrelations, $traceId);
        $count += $this->detectBeaconPatterns($agent, $snapshot, $networkCorrelations, $traceId);
        $count += $this->detectLolbinUsage($agent, $snapshot, $processes, $networkCorrelations, $traceId);
        $count += $this->detectPersistenceCorrelation($agent, $snapshot, $processes, $persistenceItems, $networkCorrelations, $traceId);
        $count += $this->detectRareParentChild($agent, $snapshot, $processes, $traceId);

        // Project analytics findings into entity graph (relationship enrichment)
        if ($count > 0) {
            $findings = EndpointBehavioralFinding::where('snapshot_id', $snapshot->id)->get();
            $this->projectAnalyticsRelationships($agent, $snapshot, $findings, $traceId);
        }

        return $count;
    }

    // -----------------------------------------------------------------------
    // 1. Execution chain detection
    // -----------------------------------------------------------------------

    public function detectExecutionChains(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        array $processes,
        array $networkCorrelations,
        string $traceId
    ): int {
        // Build pid → process map
        $byPid = [];
        foreach ($processes as $proc) {
            $pid = $proc['pid'] ?? null;
            if ($pid !== null) {
                $byPid[$pid] = $proc;
            }
        }

        // Identify remote IP/port set for outbound correlation
        $outboundPids = array_column($networkCorrelations, 'pid');

        $count = 0;
        foreach ($processes as $proc) {
            $name = strtolower($proc['process_name'] ?? '');
            if (!in_array($name, self::SHELL_NAMES, true)) {
                continue;
            }

            // Build ancestry chain from this process upwards
            $chain     = [];
            $current   = $proc;
            $visited   = [];
            $depth     = 0;
            while ($current && $depth < 8) {
                $key = ($current['pid'] ?? 0) . ':' . ($current['process_name'] ?? '');
                if (isset($visited[$key])) break;
                $visited[$key] = true;
                $chain[] = [
                    'pid'          => $current['pid'] ?? null,
                    'process_name' => $current['process_name'] ?? '',
                    'parent_name'  => $current['parent_process_name'] ?? '',
                    'is_shell'     => !empty($current['is_shell']),
                    'cmd'          => isset($current['command_line']) ? substr($current['command_line'], 0, 200) : null,
                ];
                $ppid = $current['ppid'] ?? null;
                $current = ($ppid !== null && isset($byPid[$ppid])) ? $byPid[$ppid] : null;
                $depth++;
            }

            if (count($chain) < 2) {
                continue;
            }

            // Calculate chain score
            $shellCount   = count(array_filter($chain, fn ($s) => $s['is_shell']));
            $involvesOutbound = in_array($proc['pid'] ?? null, $outboundPids, true);
            $involvesDownloader = collect($chain)->contains(
                fn ($s) => in_array(strtolower($s['process_name']), self::DOWNLOADER_NAMES, true)
            );

            $score = 0.0;
            $score += min($shellCount / 3.0, 0.40);          // shell depth
            if ($involvesDownloader) $score += 0.30;          // curl/wget in chain
            if ($involvesOutbound)   $score += 0.20;          // outbound connection
            if (count($chain) >= 3)  $score += 0.10;          // chain length bonus

            if ($score < self::CHAIN_SCORE_THRESHOLD) {
                continue;
            }

            // Emit execution chain record
            $chainRecord = EndpointExecutionChain::create([
                'chain_id'           => EndpointExecutionChain::generateChainId(),
                'agent_id'           => $agent->id,
                'snapshot_id'        => $snapshot->id,
                'chain_steps'        => array_reverse($chain), // root → leaf
                'chain_length'       => count($chain),
                'involves_shell'     => $shellCount > 0,
                'involves_outbound'  => $involvesOutbound,
                'involves_persistence'=> false, // enriched later by persistence correlation
                'chain_score'        => round($score, 2),
                'trace_id'           => $traceId,
                'detected_at'        => now(),
            ]);

            // Emit finding
            $this->emitFinding($agent, $snapshot, EndpointBehavioralFinding::TYPE_EXECUTION_CHAIN,
                'high', round($score, 2),
                'Suspicious Execution Chain Detected',
                "Shell chain detected (depth {$chainRecord->chain_length}) with score {$chainRecord->chain_score}.",
                ['chain_id' => $chainRecord->chain_id, 'chain_steps' => array_slice($chain, 0, 5), 'score' => $score],
                $traceId
            );
            $count++;
        }

        return $count;
    }

    // -----------------------------------------------------------------------
    // 2. Beacon pattern detection
    // -----------------------------------------------------------------------

    public function detectBeaconPatterns(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        array $networkCorrelations,
        string $traceId
    ): int {
        // Group connections by (process_name, remote_ip, remote_port)
        $grouped = [];
        foreach ($networkCorrelations as $corr) {
            $procName = strtolower($corr['process_name'] ?? 'unknown');
            $ip       = $corr['remote_ip'] ?? '';
            $port     = $corr['remote_port'] ?? 0;
            if (!$ip) continue;
            $key = "{$procName}:{$ip}:{$port}";
            $grouped[$key][] = $corr;
        }

        // Count repeated destinations per process
        $destByProcess = [];
        foreach ($grouped as $key => $entries) {
            [$procName] = explode(':', $key, 2);
            $destByProcess[$procName] = ($destByProcess[$procName] ?? 0) + count($entries);
        }

        $count = 0;
        foreach ($grouped as $key => $entries) {
            if (count($entries) < self::BEACON_THRESHOLD) {
                continue;
            }

            [$procName, $ip, $port] = explode(':', $key . '::extra', 3);
            $totalDests   = $destByProcess[$procName] ?? 1;
            $reuseScore   = round(count($entries) / max($totalDests, 1), 2);

            EndpointBeaconPattern::create([
                'pattern_id'            => EndpointBeaconPattern::generatePatternId(),
                'agent_id'              => $agent->id,
                'snapshot_id'           => $snapshot->id,
                'process_name'          => $procName,
                'remote_ip'             => $ip,
                'remote_port'           => (int) $port ?: null,
                'connection_count'      => count($entries),
                'avg_interval_seconds'  => null, // snapshot-only; no timestamp diff available
                'interval_variance'     => null,
                'destination_reuse_score' => $reuseScore,
                'trace_id'              => $traceId,
                'detected_at'           => now(),
            ]);

            $confidence = min(0.50 + ($reuseScore * 0.30), 0.85);
            $this->emitFinding($agent, $snapshot, EndpointBehavioralFinding::TYPE_BEACON_PATTERN,
                'medium', round($confidence, 2),
                "Beacon-like Pattern: {$procName} → {$ip}:{$port}",
                "Process '{$procName}' made " . count($entries) . " connections to {$ip}:{$port}. Destination reuse score: {$reuseScore}.",
                ['process' => $procName, 'destination' => "{$ip}:{$port}", 'count' => count($entries), 'reuse_score' => $reuseScore],
                $traceId
            );
            $count++;
        }

        return $count;
    }

    // -----------------------------------------------------------------------
    // 3. Living-off-the-land (LOLBin) usage
    // -----------------------------------------------------------------------

    public function detectLolbinUsage(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        array $processes,
        array $networkCorrelations,
        string $traceId
    ): int {
        $outboundProcNames = array_map(
            fn ($c) => strtolower($c['process_name'] ?? ''), $networkCorrelations
        );

        $count = 0;
        foreach ($processes as $proc) {
            $name = strtolower($proc['process_name'] ?? '');
            if (!in_array($name, self::LOLBIN_NAMES, true)) {
                continue;
            }

            $parentName  = strtolower($proc['parent_process_name'] ?? '');
            $hasOutbound = in_array($name, $outboundProcNames, true);
            $hasBase64   = str_contains(strtolower($proc['command_line'] ?? ''), 'base64');
            $suspParent  = isset(self::SUSPICIOUS_PARENT_CHILD[$parentName]);

            $confidence = 0.55;
            $indicators = [$name];
            if ($hasOutbound)  { $confidence += 0.15; $indicators[] = 'outbound_connection'; }
            if ($hasBase64)    { $confidence += 0.15; $indicators[] = 'encoded_command'; }
            if ($suspParent)   { $confidence += 0.10; $indicators[] = "suspicious_parent:{$parentName}"; }
            if (!empty($proc['is_long_lived'])) { $confidence += 0.05; $indicators[] = 'long_lived'; }

            $confidence = round(min($confidence, 0.95), 2);

            $this->emitFinding($agent, $snapshot, EndpointBehavioralFinding::TYPE_LOLBIN_USAGE,
                $confidence >= 0.80 ? 'high' : 'medium', $confidence,
                "LOLBin Usage: {$name}",
                "Living-off-the-land binary '{$name}' executed. Indicators: " . implode(', ', $indicators),
                ['process_name' => $name, 'pid' => $proc['pid'] ?? null,
                 'parent' => $parentName, 'indicators' => $indicators,
                 'command_line' => isset($proc['command_line']) ? substr($proc['command_line'], 0, 200) : null],
                $traceId
            );
            $count++;
        }

        return $count;
    }

    // -----------------------------------------------------------------------
    // 4. Persistence + outbound correlation
    // -----------------------------------------------------------------------

    public function detectPersistenceCorrelation(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        array $processes,
        array $persistenceItems,
        array $networkCorrelations,
        string $traceId
    ): int {
        if (empty($persistenceItems) || empty($processes)) {
            return 0;
        }

        // Check for any shell processes with outbound connections
        $shellProcesses = array_filter($processes, fn ($p) => !empty($p['is_shell']));
        $outboundNames  = array_map(fn ($c) => strtolower($c['process_name'] ?? ''), $networkCorrelations);

        $suspiciousShells = array_filter($shellProcesses, function ($proc) use ($outboundNames) {
            return in_array(strtolower($proc['process_name'] ?? ''), $outboundNames, true);
        });

        if (empty($suspiciousShells)) {
            return 0;
        }

        // New persistence items increase the finding confidence
        $newPersistence = array_filter($persistenceItems, fn ($i) => !empty($i['is_new'] ?? false));
        $confidence = 0.60 + (count($newPersistence) > 0 ? 0.15 : 0.0);
        $confidence = round(min($confidence, 0.85), 2);

        $shellNames = array_unique(array_column(array_values($suspiciousShells), 'process_name'));
        $persNames  = array_column(array_slice($persistenceItems, 0, 5), 'item_name');

        $this->emitFinding($agent, $snapshot, EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION,
            'high', $confidence,
            'Persistence + Outbound Correlation',
            "Active persistence items detected alongside shell processes with outbound connections. " .
            "This combination is indicative of a persistence mechanism establishing C2 communication.",
            [
                'persistence_items'   => $persNames,
                'shell_processes'     => $shellNames,
                'new_persistence_count' => count($newPersistence),
                'outbound_connections'  => count($networkCorrelations),
            ],
            $traceId
        );

        return 1;
    }

    // -----------------------------------------------------------------------
    // 5. Rare parent-child relationship
    // -----------------------------------------------------------------------

    public function detectRareParentChild(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        array $processes,
        string $traceId
    ): int {
        $count = 0;
        foreach ($processes as $proc) {
            $parentName = strtolower($proc['parent_process_name'] ?? '');
            $childName  = strtolower($proc['process_name'] ?? '');

            if (!isset(self::SUSPICIOUS_PARENT_CHILD[$parentName])) {
                continue;
            }
            if (!in_array($childName, self::SUSPICIOUS_PARENT_CHILD[$parentName], true)) {
                continue;
            }

            // Rarity score: certain parent-child pairs are more concerning
            $rarityScore = match (true) {
                in_array($parentName, ['mysqld', 'postgres', 'mongod'], true)  => 0.90,
                in_array($parentName, ['nginx', 'apache2', 'apache', 'httpd', 'php-fpm', 'uwsgi'], true) => 0.82,
                in_array($parentName, ['gunicorn'], true) => 0.78,
                default => 0.70,
            };

            $this->emitFinding($agent, $snapshot, EndpointBehavioralFinding::TYPE_RARE_PARENT_CHILD,
                'high', $rarityScore,
                "Rare Parent-Child: {$parentName} → {$childName}",
                "Unusual process ancestry detected: '{$childName}' spawned by '{$parentName}'. " .
                "This parent-child combination is rare in normal operation and may indicate exploitation.",
                [
                    'parent_process' => $parentName,
                    'child_process'  => $childName,
                    'pid'            => $proc['pid'] ?? null,
                    'ppid'           => $proc['ppid'] ?? null,
                    'rarity_score'   => $rarityScore,
                    'command_line'   => isset($proc['command_line']) ? substr($proc['command_line'], 0, 200) : null,
                ],
                $traceId
            );
            $count++;
        }

        return $count;
    }

    // -----------------------------------------------------------------------
    // Query methods for UI
    // -----------------------------------------------------------------------

    public function getRecentFindings(EndpointAgent $agent, int $limit = 50): array
    {
        return EndpointBehavioralFinding::where('agent_id', $agent->id)
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get()
            ->map(fn ($f) => [
                'finding_id'  => $f->finding_id,
                'finding_type'=> $f->finding_type,
                'severity'    => $f->severity,
                'confidence'  => $f->confidence,
                'title'       => $f->title,
                'detected_at' => $f->detected_at?->toIso8601String(),
                'evidence'    => $f->evidence,
                'trace_id'    => $f->trace_id,
            ])->all();
    }

    public function getExecutionChains(EndpointAgent $agent, int $limit = 30): array
    {
        return EndpointExecutionChain::where('agent_id', $agent->id)
            ->orderByDesc('detected_at')
            ->orderByDesc('chain_score')
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'chain_id'           => $c->chain_id,
                'chain_steps'        => $c->chain_steps,
                'chain_length'       => $c->chain_length,
                'involves_shell'     => $c->involves_shell,
                'involves_outbound'  => $c->involves_outbound,
                'involves_persistence'=> $c->involves_persistence,
                'chain_score'        => $c->chain_score,
                'detected_at'        => $c->detected_at?->toIso8601String(),
            ])->all();
    }

    public function getBeaconPatterns(EndpointAgent $agent, int $limit = 30): array
    {
        return EndpointBeaconPattern::where('agent_id', $agent->id)
            ->orderByDesc('detected_at')
            ->orderByDesc('destination_reuse_score')
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'pattern_id'             => $p->pattern_id,
                'process_name'           => $p->process_name,
                'remote_ip'              => $p->remote_ip,
                'remote_port'            => $p->remote_port,
                'connection_count'       => $p->connection_count,
                'destination_reuse_score'=> $p->destination_reuse_score,
                'detected_at'            => $p->detected_at?->toIso8601String(),
            ])->all();
    }

    public function getRareParentChildFindings(EndpointAgent $agent, int $limit = 30): array
    {
        return EndpointBehavioralFinding::where('agent_id', $agent->id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_RARE_PARENT_CHILD)
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get()
            ->map(fn ($f) => [
                'finding_id'  => $f->finding_id,
                'title'       => $f->title,
                'confidence'  => $f->confidence,
                'evidence'    => $f->evidence,
                'detected_at' => $f->detected_at?->toIso8601String(),
            ])->all();
    }

    public function getPersistenceCorrelationFindings(EndpointAgent $agent, int $limit = 20): array
    {
        return EndpointBehavioralFinding::where('agent_id', $agent->id)
            ->where('finding_type', EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION)
            ->orderByDesc('detected_at')
            ->limit($limit)
            ->get()
            ->map(fn ($f) => [
                'finding_id'  => $f->finding_id,
                'title'       => $f->title,
                'confidence'  => $f->confidence,
                'evidence'    => $f->evidence,
                'detected_at' => $f->detected_at?->toIso8601String(),
            ])->all();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function emitFinding(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        string $type,
        string $severity,
        float $confidence,
        string $title,
        string $description,
        array $evidence,
        string $traceId
    ): EndpointBehavioralFinding {
        return EndpointBehavioralFinding::create([
            'finding_id'  => EndpointBehavioralFinding::generateFindingId(),
            'agent_id'    => $agent->id,
            'snapshot_id' => $snapshot->id,
            'finding_type'=> $type,
            'severity'    => $severity,
            'confidence'  => $confidence,
            'title'       => substr($title, 0, 255),
            'description' => $description,
            'evidence'    => $evidence,
            'trace_id'    => $traceId,
            'detected_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // Entity graph projection for analytics findings
    // -----------------------------------------------------------------------

    private function projectAnalyticsRelationships(
        EndpointAgent $agent,
        EndpointProcessSnapshot $snapshot,
        $findings,
        string $traceId
    ): void {
        $entitySvc = app(EntityGraphService::class);
        $now  = now()->toIso8601String();
        $host = $agent->hostname ?? $agent->host_id ?? "host:{$agent->id}";

        foreach ($findings as $finding) {
            $evidence = $finding->evidence ?? [];

            if ($finding->finding_type === EndpointBehavioralFinding::TYPE_EXECUTION_CHAIN) {
                // suspicious_chain_involves_process
                $chainSteps = $evidence['chain_steps'] ?? [];
                foreach (array_slice($chainSteps, 0, 5) as $step) {
                    $procName = $step['process_name'] ?? '';
                    if (!$procName) continue;
                    $procKey = "process:{$host}:{$procName}";
                    $procId  = $entitySvc->upsertEntity('process', $procKey, "{$procName}@{$host}", $now);
                    $chainKey = "chain:{$finding->finding_id}";
                    $chainId  = $entitySvc->upsertEntity('detection_chain', $chainKey, "chain:{$finding->finding_id}", $now);
                    $entitySvc->upsertRelationship($chainId, $procId, 'suspicious_chain_involves_process', $now, null, null, null, $traceId);
                }
            }

            if ($finding->finding_type === EndpointBehavioralFinding::TYPE_BEACON_PATTERN) {
                // process_reused_destination
                $procName = $evidence['process'] ?? '';
                $dest     = $evidence['destination'] ?? '';
                if ($procName && $dest) {
                    $procKey  = "process:{$host}:{$procName}";
                    $procId   = $entitySvc->upsertEntity('process', $procKey, "{$procName}@{$host}", $now);
                    $destKey  = "network:{$dest}";
                    $destId   = $entitySvc->upsertEntity('network_destination', $destKey, $dest, $now);
                    $entitySvc->upsertRelationship($procId, $destId, 'process_reused_destination', $now, null, null, null, $traceId);
                }
            }

            if ($finding->finding_type === EndpointBehavioralFinding::TYPE_PERSISTENCE_CORRELATION) {
                // persistence_triggered_process
                $persistItems = $evidence['persistence_items'] ?? [];
                $shellProcs   = $evidence['shell_processes'] ?? [];
                foreach (array_slice($persistItems, 0, 3) as $itemName) {
                    foreach (array_slice($shellProcs, 0, 3) as $shellName) {
                        $persKey = "persistence:{$host}:{$itemName}";
                        $persId  = $entitySvc->upsertEntity('persistence', $persKey, "{$itemName}@{$host}", $now);
                        $procKey = "process:{$host}:{$shellName}";
                        $procId  = $entitySvc->upsertEntity('process', $procKey, "{$shellName}@{$host}", $now);
                        $entitySvc->upsertRelationship($persId, $procId, 'persistence_triggered_process', $now, null, null, null, $traceId);
                    }
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // LOLBin/shell classification (static, testable)
    // -----------------------------------------------------------------------

    public static function isLolbin(string $processName): bool
    {
        return in_array(strtolower($processName), self::LOLBIN_NAMES, true);
    }

    public static function isShell(string $processName): bool
    {
        return in_array(strtolower($processName), self::SHELL_NAMES, true);
    }

    public static function isDownloader(string $processName): bool
    {
        return in_array(strtolower($processName), self::DOWNLOADER_NAMES, true);
    }

    public static function isSuspiciousParentChild(string $parent, string $child): bool
    {
        $parent = strtolower($parent);
        $child  = strtolower($child);
        return isset(self::SUSPICIOUS_PARENT_CHILD[$parent]) &&
               in_array($child, self::SUSPICIOUS_PARENT_CHILD[$parent], true);
    }

    public static function rarityScore(string $parent): float
    {
        return match (strtolower($parent)) {
            'mysqld', 'postgres', 'mongod' => 0.90,
            'nginx', 'apache2', 'apache', 'httpd', 'php-fpm', 'uwsgi' => 0.82,
            'gunicorn' => 0.78,
            default    => 0.70,
        };
    }
}
