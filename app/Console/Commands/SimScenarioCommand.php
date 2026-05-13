<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class SimScenarioCommand extends Command
{
    protected $signature = 'sim:scenario
                            {--base-url=http://127.0.0.1:8000 : Target app base URL}
                            {--rounds=2 : Number of scenario rounds}
                            {--profile=balanced : Profile: balanced|fast}';

    protected $description = 'Run mixed attack scenarios (burst + low-and-slow + new scan patterns)';

    public function handle(): int
    {
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $rounds = max(1, (int) $this->option('rounds'));
        $profile = (string) $this->option('profile');

        $sleepBruteLow = $profile === 'fast' ? 80 : 350;
        $sleepScanLow = $profile === 'fast' ? 60 : 250;
        $sleepInjLow = $profile === 'fast' ? 80 : 300;

        for ($r = 1; $r <= $rounds; $r++) {
            $tagBase = "scenario_r{$r}";

            $this->line("Round {$r}/{$rounds}: bruteforce burst");
            $this->call('sim:bruteforce', [
                '--base-url' => $baseUrl,
                '--attempts' => 60,
                '--ip' => '203.0.113.10',
                '--sleep-ms' => 0,
                '--tag' => "{$tagBase}_brute_burst",
            ]);

            $this->line("Round {$r}/{$rounds}: bruteforce low-and-slow");
            $this->call('sim:bruteforce', [
                '--base-url' => $baseUrl,
                '--attempts' => 24,
                '--ip' => '203.0.113.11',
                '--sleep-ms' => $sleepBruteLow,
                '--tag' => "{$tagBase}_brute_low",
            ]);

            $this->line("Round {$r}/{$rounds}: scan burst (classic)");
            $this->call('sim:scan', [
                '--base-url' => $baseUrl,
                '--count' => 70,
                '--ip' => '198.51.100.77',
                '--prefix' => '/scan',
                '--include-sensitive' => 1,
                '--sleep-ms' => 0,
                '--tag' => "{$tagBase}_scan_burst",
            ]);

            $this->line("Round {$r}/{$rounds}: scan low-and-slow (new path family)");
            $this->call('sim:scan', [
                '--base-url' => $baseUrl,
                '--count' => 35,
                '--ip' => '198.51.100.88',
                '--prefix' => '/probe',
                '--include-sensitive' => 0,
                '--sleep-ms' => $sleepScanLow,
                '--tag' => "{$tagBase}_scan_low_newpattern",
            ]);

            $this->line("Round {$r}/{$rounds}: injection burst");
            $this->call('sim:injection', [
                '--base-url' => $baseUrl,
                '--ip' => '192.0.2.55',
                '--repeats' => 3,
                '--sleep-ms' => 0,
                '--tag' => "{$tagBase}_inj_burst",
            ]);

            $this->line("Round {$r}/{$rounds}: injection low-and-slow");
            $this->call('sim:injection', [
                '--base-url' => $baseUrl,
                '--ip' => '192.0.2.66',
                '--repeats' => 2,
                '--sleep-ms' => $sleepInjLow,
                '--tag' => "{$tagBase}_inj_low",
            ]);
        }

        $this->info("Scenario completed: {$rounds} rounds, profile={$profile}");

        return self::SUCCESS;
    }
}
