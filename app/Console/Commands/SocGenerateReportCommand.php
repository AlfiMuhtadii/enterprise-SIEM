<?php

namespace App\Console\Commands;

use App\Http\Controllers\SocReportController;
use Illuminate\Console\Command;

class SocGenerateReportCommand extends Command
{
    protected $signature = 'soc:generate-report {period=weekly : weekly or monthly}';
    protected $description = 'Generate executive SOC security report.';

    public function handle(SocReportController $reports): int
    {
        $period = (string) $this->argument('period');
        if (!in_array($period, ['weekly', 'monthly'], true)) {
            $this->error('period must be weekly or monthly');
            return self::FAILURE;
        }
        $report = $reports->buildReport($period, 'scheduler');
        $this->info('report_id='.$report['report_id']);
        return self::SUCCESS;
    }
}
