<?php

namespace Database\Seeders;

use App\Models\DemoReadinessSnapshot;
use App\Models\DemoScenarioRun;
use App\Models\PlatformShowcaseExport;
use App\Services\DemoPlatformPackagingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DemoScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $svc = new DemoPlatformPackagingService();

        // Seed one readiness snapshot
        if (DemoReadinessSnapshot::count() === 0) {
            $svc->generateDemoReadinessSnapshot();
        }

        // Seed demo scenario runs for each scenario (idempotent — skip if already seeded)
        foreach (DemoScenarioRun::DEMO_SCENARIOS as $scenario) {
            $exists = DemoScenarioRun::where('scenario_name', $scenario)->exists();
            if (!$exists) {
                $svc->launchDemoScenario($scenario);
            }
        }

        // Seed capability matrix export
        if (PlatformShowcaseExport::where('export_type', 'capability_matrix')->doesntExist()) {
            $svc->exportPlatformShowcase('capability_matrix', 'json');
        }

        // Seed platform showcase export
        if (PlatformShowcaseExport::where('export_type', 'platform_showcase')->doesntExist()) {
            $svc->exportPlatformShowcase('platform_showcase', 'json');
        }
    }
}
