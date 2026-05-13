<?php

namespace App\Console\Commands;

use App\Support\XdrSoakReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class XdrSoakAnalyzeCommand extends Command
{
    protected $signature = 'xdr:soak-analyze
        {--report= : Soak report path}
        {--json : Print JSON output}';

    protected $description = 'Analyze XDR correlation soak report and produce cutover decision.';

    public function handle(): int
    {
        $reportPath = $this->option('report') ?: null;
        if ($reportPath) {
            $fullPath = base_path($reportPath);
            if (!File::exists($fullPath)) {
                $this->error("Report not found: {$reportPath}");
                return self::FAILURE;
            }
            $decoded = json_decode(File::get($fullPath), true);
            if (!is_array($decoded)) {
                $this->error("Invalid JSON report: {$reportPath}");
                return self::FAILURE;
            }
            $analysis = XdrSoakReport::decision($decoded, $reportPath);
        } else {
            $analysis = XdrSoakReport::latest();
        }

        if ($this->option('json')) {
            $this->line(json_encode($analysis, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->line('Decision: '.$analysis['decision']);
        $this->line('Reason: '.$analysis['reason']);
        $this->line('Report: '.($analysis['report_path'] ?? 'n/a'));
        $this->line('Status: '.$analysis['status']);
        $this->line('Duration minutes: '.($analysis['duration_minutes'] ?? 'n/a'));
        foreach (($analysis['metrics'] ?? []) as $key => $value) {
            $this->line("{$key}: {$value}");
        }
        $this->line('Gate summary: '.$analysis['gate_summary']);

        return self::SUCCESS;
    }
}
