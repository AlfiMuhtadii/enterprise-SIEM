<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class XdrAttackReconstructCommand extends Command
{
    protected $signature = 'xdr:attack-reconstruct {--minutes=1440}';

    protected $description = 'Reconstruct multi-stage cross-domain attack graphs and timelines from XDR alerts/incidents.';

    public function handle(): int
    {
        $since = now()->subMinutes(max(15, (int) $this->option('minutes')));
        $alerts = DB::table('security_alerts')
            ->where('detected_at', '>=', $since)
            ->where(function ($q) {
                $q->where('detector_name', 'xdr-correlation')
                    ->orWhereRaw("evidence::text ilike '%xdr_domains%'");
            })
            ->orderBy('detected_at')
            ->get();

        if ($alerts->isEmpty()) {
            $this->warn('No XDR alerts found; creating empty reconstruction baseline.');
        }

        $groups = $alerts->groupBy(fn ($alert) => $alert->incident_id ?: $alert->actor_key ?: 'ungrouped');
        foreach ($groups as $key => $rows) {
            $nodes = [];
            $edges = [];
            $timeline = [];
            $domains = [];
            foreach ($rows as $alert) {
                $evidence = json_decode($alert->evidence ?: '{}', true) ?: [];
                foreach (($evidence['evidence_chain'] ?? []) as $event) {
                    $nodeId = $event['event_id'] ?? ('event-'.count($nodes));
                    $nodes[$nodeId] = [
                        'id' => $nodeId,
                        'domain' => $event['telemetry_type'] ?? 'unknown',
                        'user' => $event['user'] ?? null,
                        'host' => $event['host'] ?? null,
                        'ip' => $event['source_ip'] ?? null,
                    ];
                    $timeline[] = $event;
                    if (!empty($event['telemetry_type'])) {
                        $domains[] = $event['telemetry_type'];
                    }
                }
            }
            $orderedIds = array_keys($nodes);
            for ($i = 1; $i < count($orderedIds); $i++) {
                $edges[] = ['from' => $orderedIds[$i - 1], 'to' => $orderedIds[$i], 'type' => 'temporal_sequence'];
            }
            $uniqueDomains = array_values(array_unique($domains));
            $confidence = min(1, 0.35 + (count($uniqueDomains) * 0.12) + ($rows->count() * 0.08));

            DB::table('xdr_attack_reconstructions')->insert([
                'campaign_id' => 'camp-'.Str::uuid(),
                'incident_id' => $key === 'ungrouped' ? null : $key,
                'chain_confidence' => round($confidence, 3),
                'attack_graph' => json_encode(['nodes' => array_values($nodes), 'edges' => $edges]),
                'cross_domain_timeline' => json_encode($timeline),
                'linked_evidence' => json_encode($rows->pluck('alert_id')->values()),
                'visualization' => json_encode(['type' => 'attack_flow', 'domain_count' => count($uniqueDomains), 'domains' => $uniqueDomains]),
                'first_seen_at' => $rows->min('detected_at'),
                'last_seen_at' => $rows->max('detected_at'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info('reconstructions='.$groups->count());
        return self::SUCCESS;
    }
}
