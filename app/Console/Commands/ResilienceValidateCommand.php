<?php

namespace App\Console\Commands;

use App\Models\ResilienceRun;
use App\Services\ResilienceValidationService;
use Illuminate\Console\Command;

class ResilienceValidateCommand extends Command
{
    protected $signature = 'resilience:validate
        {--scenario= : Specific scenario ID to run (omit to run all)}
        {--list      : List available scenario IDs and exit}';

    protected $description = 'Run operational resilience validation scenarios';

    public function handle(ResilienceValidationService $service): int
    {
        if ($this->option('list')) {
            $this->table(
                ['Scenario ID', 'Name', 'Type'],
                collect($service->getScenarios())->map(fn ($s, $id) => [$id, $s['name'], $s['type']])->values()
            );
            return self::SUCCESS;
        }

        $scenarioId = $this->option('scenario');

        if ($scenarioId) {
            $this->info("Running scenario: {$scenarioId}");
            $run = $service->runScenario($scenarioId);
            $this->printRun($run);
            return $run->passed() ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Running all ' . count($service->getScenarios()) . ' resilience scenarios...');
        $runs   = $service->runAll();
        $passed = 0;
        $failed = 0;

        foreach ($runs as $run) {
            $this->printRun($run);
            $run->passed() ? $passed++ : $failed++;
        }

        $this->newLine();
        $this->info("Results: {$passed} passed, {$failed} failed");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function printRun(ResilienceRun $run): void
    {
        $icon   = $run->passed() ? '✓' : '✗';
        $status = strtoupper($run->status);
        $dur    = $run->durationSeconds() !== null ? round($run->durationSeconds(), 2) . 's' : 'n/a';
        $this->line("  {$icon} [{$status}] {$run->run_id} — {$run->scenario_name} ({$dur})");
    }
}
