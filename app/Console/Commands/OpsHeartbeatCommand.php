<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class OpsHeartbeatCommand extends Command
{
    protected $signature = 'ops:heartbeat';

    protected $description = 'Write scheduler heartbeat status for health checks.';

    public function handle(): int
    {
        File::put(storage_path('app/scheduler_heartbeat.json'), json_encode([
            'last_run' => now()->toIso8601String(),
            'status' => 'ok',
        ], JSON_PRETTY_PRINT));

        $this->info('scheduler_heartbeat=ok');

        return self::SUCCESS;
    }
}
