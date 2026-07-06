<?php

namespace App\Console\Commands;

use App\Services\DataResidencyErasureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityRetentionCommand extends Command
{
    protected $signature = 'security:retention
                            {--events-days=30 : Keep raw security_events for N days (global — security_events has no tenant_id)}
                            {--alerts-days=90 : Default security_alerts retention for tenants without a per-tenant override}
                            {--incidents-days=180 : Default security_incidents retention for tenants without a per-tenant override}';

    protected $description = 'Apply per-tenant retention to security_alerts/security_incidents, global retention to security_events (DATA-RESIDENCY-ERASURE)';

    public function handle(DataResidencyErasureService $residency): int
    {
        $eventsDays = max(1, (int) $this->option('events-days'));
        $alertsDefaultDays = max(1, (int) $this->option('alerts-days'));
        $incidentsDefaultDays = max(1, (int) $this->option('incidents-days'));

        // security_events has no tenant_id (documented gap — see
        // TenantBoundaryService::UNISOLATED_TABLES) so it is pruned globally.
        $eventsCutoff = now()->subDays($eventsDays);
        $deletedEvents = DB::table('security_events')->where('ts', '<', $eventsCutoff)->delete();
        $this->info("Deleted security_events: {$deletedEvents} rows older than {$eventsCutoff->toIso8601String()} (global — no tenant scoping)");

        $this->pruneTenantScoped('security_alerts', 'detected_at', 'alerts', $alertsDefaultDays, $residency);
        $this->pruneTenantScoped('security_incidents', 'created_at', 'incidents', $incidentsDefaultDays, $residency);

        return self::SUCCESS;
    }

    private function pruneTenantScoped(string $table, string $column, string $type, int $defaultDays, DataResidencyErasureService $residency): void
    {
        $tenantIds = DB::table($table)->whereNotNull('tenant_id')->distinct()->pluck('tenant_id');
        $totalDeleted = 0;

        foreach ($tenantIds as $tenantId) {
            $days = $residency->resolveRetentionDays($tenantId, $type, $defaultDays);
            $cutoff = now()->subDays($days);
            $deleted = DB::table($table)->where('tenant_id', $tenantId)->where($column, '<', $cutoff)->delete();
            $totalDeleted += $deleted;
            if ($deleted > 0) {
                $this->info("Deleted {$table} (tenant={$tenantId}): {$deleted} rows older than {$days}d");
            }
        }

        // Legacy/untagged rows (tenant_id null) fall back to the global default.
        $legacyCutoff = now()->subDays($defaultDays);
        $legacyDeleted = DB::table($table)->whereNull('tenant_id')->where($column, '<', $legacyCutoff)->delete();
        $totalDeleted += $legacyDeleted;
        if ($legacyDeleted > 0) {
            $this->info("Deleted {$table} (no tenant_id, legacy default): {$legacyDeleted} rows older than {$defaultDays}d");
        }

        $this->info("Total deleted {$table}: {$totalDeleted} rows.");
    }
}
