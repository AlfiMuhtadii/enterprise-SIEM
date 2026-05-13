<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SocSlaReportCommand extends Command
{
    protected $signature = 'soc:sla-report';

    protected $description = 'Report SLA breach summary for SOC incidents.';

    public function handle(): int
    {
        $rows = DB::table('security_incidents')
            ->select('severity', DB::raw('count(*) as total'))
            ->whereIn('status', ['open', 'triaged', 'investigating'])
            ->whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->groupBy('severity')
            ->orderByDesc('total')
            ->get();

        $this->table(['severity', 'overdue'], $rows->map(fn ($r) => [$r->severity, $r->total])->all());
        $this->info('total_overdue='.$rows->sum('total'));

        return self::SUCCESS;
    }
}
