<?php

namespace App\Console\Commands;

use App\Services\PilotTenantOnboardingService;
use Illuminate\Console\Command;

class TenantOnboardPilotCommand extends Command
{
    protected $signature = 'tenant:onboard-pilot
                            {--tenant-id=    : Explicit tenant ID (auto-generated if omitted)}
                            {--tenant-name=  : Display name for the tenant}
                            {--tenant-type=pilot : Type: pilot|demo|staging}
                            {--validate      : Run health validation after onboarding}
                            {--dry-run       : Preview without persisting}';

    protected $description = 'Onboard a real pilot tenant (ENTERPRISE-052)';

    public function handle(PilotTenantOnboardingService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $config = [
            'tenant_id'   => $this->option('tenant-id') ?: null,
            'tenant_name' => $this->option('tenant-name') ?: 'Pilot Tenant',
            'tenant_type' => $this->option('tenant-type') ?: 'pilot',
        ];

        $this->info($dryRun ? '[DRY-RUN] Onboarding pilot tenant' : 'Onboarding pilot tenant');

        $result = $service->onboard($config, $dryRun);

        if (!$result['ok']) {
            $this->error('Onboarding failed: ' . ($result['error'] ?? 'unknown'));
            return 1;
        }

        $this->line("  tenant_id       : {$result['tenant_id']}");
        $this->line("  tenant_name     : {$result['tenant_name']}");
        $this->line("  strict_compat   : " . ($result['profile']['strict_mode_compatible'] ? 'true' : 'false'));
        $this->line("  null_backfill   : " . ($result['profile']['null_backfill_completed'] ? 'completed' : 'pending'));
        $this->line('  is_advisory     : true');

        if (!empty($result['null_counts'])) {
            $this->warn('  WARN: null tenant_id records found — run tenant:null-backfill to resolve.');
        }

        if ($this->option('validate')) {
            $health = $service->validateTenantHealth($result['tenant_id']);
            $this->info('Health check:');
            foreach ($health['checks'] as $key => $val) {
                $this->line("  {$key}: " . (is_bool($val) ? ($val ? 'true' : 'false') : $val));
            }
        }

        if (!$dryRun) {
            $this->info('Persisted to pilot_tenant_profiles.');
        }

        return 0;
    }
}
