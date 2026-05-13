<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Daily TTL cleanup for local SIEM-lite storage.
        $schedule->command('security:retention --events-days=30 --alerts-days=90')->daily();
        $schedule->command('ops:heartbeat')->everyMinute();
        $schedule->command('soc:sla-escalate')->everyFifteenMinutes();
        $schedule->command('soc:notify-critical --minutes=15')->everyFifteenMinutes();
        $schedule->command('soc:sla-report')->hourly();
        $schedule->command('soc:agent-health-check')->everyFiveMinutes();
        $schedule->command('soc:generate-report weekly')->weekly();
        $schedule->command('soc:generate-report monthly')->monthly();
        $schedule->command('soc:detection-maturity')->hourly();
        $schedule->command('soc:ai-evaluate --days=7')->daily();
        $schedule->command('soc:env-validate local')->dailyAt('01:10');
        $schedule->command('soc:retention-cost --days=30')->weekly();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
