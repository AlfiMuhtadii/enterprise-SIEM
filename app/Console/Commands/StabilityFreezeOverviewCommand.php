<?php

namespace App\Console\Commands;

use App\Services\StabilityFreezeOverviewService;
use Illuminate\Console\Command;

/**
 * META-MODULE-RATIONALIZE (bounded step): read-only overview across the
 * StabilityEvidenceFreeze V2/V3/V4 sprawl. Never writes anything.
 */
class StabilityFreezeOverviewCommand extends Command
{
    protected $signature = 'stability:freeze-overview';

    protected $description = 'Show the latest stability-freeze status per version (v2/v3/v4), honestly labelled — read-only';

    public function handle(StabilityFreezeOverviewService $service): int
    {
        $result = $service->overview();

        $this->line('');
        $this->line('<fg=cyan>Stability Evidence Freeze — Overview (read-only, advisory)</>');
        $this->line('');

        $rows = [];
        foreach ($result['versions'] as $version => $data) {
            if ($data === null) {
                $rows[] = [$version, '(never run)', '—', '—', '—'];
                continue;
            }
            $summary = $data['summary'];
            $rows[] = [
                $version,
                $summary['phase_range'] ?? '—',
                number_format((float) ($summary['pass_score'] ?? 0), 3),
                $summary['stability'] ?? '—',
                $summary['frozen_at'] ?? '—',
            ];
        }

        $this->table(['Version', 'Phase Range', 'Pass Score', 'Stability', 'Frozen At'], $rows);

        $this->line('');
        if ($result['current'] !== null) {
            $currentVersion = $result['current']['summary']['freeze_version'] ?? '?';
            $this->line("Most recent freeze overall: <fg=yellow>{$currentVersion}</> at {$result['current']['summary']['frozen_at']}");
        } else {
            $this->warn('No stability freeze has been run for any version yet.');
        }
        $this->line('');
        $this->line("<fg=gray>{$result['note']}</>");
        $this->line('');

        return self::SUCCESS;
    }
}
