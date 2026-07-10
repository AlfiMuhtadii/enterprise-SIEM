<?php

namespace App\Console\Commands;

use App\Services\SoakOverviewService;
use Illuminate\Console\Command;

/**
 * META-MODULE-RATIONALIZE: read-only overview across the 7 soak services.
 * Never writes anything.
 */
class SoakOverviewCommand extends Command
{
    protected $signature = 'soak:overview';

    protected $description = 'Show each soak service\'s current status, honestly labelled — read-only';

    public function handle(SoakOverviewService $service): int
    {
        $result = $service->overview();

        $this->line('');
        $this->line('<fg=cyan>Soak Overview (read-only, advisory)</>');
        $this->line('');

        $rows = [];
        foreach ($result['services'] as $name => $entry) {
            $rows[] = [
                $name,
                $entry['kind'],
                $entry['data'] === null ? '(never run)' : 'present',
            ];
        }

        $this->table(['Service', 'Kind', 'Status'], $rows);

        $this->line('');
        $this->line("<fg=gray>{$result['note']}</>");
        $this->line('');

        return self::SUCCESS;
    }
}
