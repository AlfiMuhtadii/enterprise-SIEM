<?php

namespace App\Console\Commands;

use App\Services\DataResidencyErasureService;
use App\Services\SecurityRetentionArchiveService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SecurityRetentionCommand extends Command
{
    protected $signature = 'security:retention
                            {--events-days=30 : Keep raw security_events for N days (global — security_events has no tenant_id)}
                            {--alerts-days=90 : Default security_alerts retention for tenants without a per-tenant override}
                            {--incidents-days=180 : Default security_incidents retention for tenants without a per-tenant override}
                            {--archive-dir=storage/app/archives : Base directory for gzip JSONL archives written before delete}
                            {--no-archive : Skip archiving and delete directly (old behavior)}';

    protected $description = 'Archive-then-prune security_events/security_alerts/security_incidents, per-tenant where applicable (DATA-TIERING phase 1, DATA-RESIDENCY-ERASURE)';

    public function handle(DataResidencyErasureService $residency): int
    {
        $eventsDays = max(1, (int) $this->option('events-days'));
        $alertsDefaultDays = max(1, (int) $this->option('alerts-days'));
        $incidentsDefaultDays = max(1, (int) $this->option('incidents-days'));
        $archive = $this->option('no-archive')
            ? null
            : new SecurityRetentionArchiveService($this->option('archive-dir'));

        // security_events has no tenant_id (documented gap — see
        // TenantBoundaryService::UNISOLATED_TABLES) so it is pruned globally.
        $eventsCutoff = now()->subDays($eventsDays);
        $deletedEvents = $this->pruneRows($archive, 'security_events', 'ts', $eventsCutoff, null, hasTenantColumn: false);
        $this->info("Deleted security_events: {$deletedEvents} rows older than {$eventsCutoff->toIso8601String()} (global — no tenant scoping)");

        $this->pruneTenantScoped('security_alerts', 'detected_at', 'alerts', $alertsDefaultDays, $residency, $archive);
        $this->pruneTenantScoped('security_incidents', 'created_at', 'incidents', $incidentsDefaultDays, $residency, $archive);

        return self::SUCCESS;
    }

    private function pruneTenantScoped(
        string $table,
        string $column,
        string $type,
        int $defaultDays,
        DataResidencyErasureService $residency,
        ?SecurityRetentionArchiveService $archive,
    ): void {
        $tenantIds = DB::table($table)->whereNotNull('tenant_id')->distinct()->pluck('tenant_id');
        $totalDeleted = 0;

        foreach ($tenantIds as $tenantId) {
            $days = $residency->resolveRetentionDays($tenantId, $type, $defaultDays);
            $cutoff = now()->subDays($days);
            $deleted = $this->pruneRows($archive, $table, $column, $cutoff, $tenantId);
            $totalDeleted += $deleted;
            if ($deleted > 0) {
                $this->info("Deleted {$table} (tenant={$tenantId}): {$deleted} rows older than {$days}d");
            }
        }

        // Legacy/untagged rows (tenant_id null) fall back to the global default.
        $legacyCutoff = now()->subDays($defaultDays);
        $legacyDeleted = $this->pruneRows($archive, $table, $column, $legacyCutoff, null);
        $totalDeleted += $legacyDeleted;
        if ($legacyDeleted > 0) {
            $this->info("Deleted {$table} (no tenant_id, legacy default): {$legacyDeleted} rows older than {$defaultDays}d");
        }

        $this->info("Total deleted {$table}: {$totalDeleted} rows.");
    }

    private function pruneRows(?SecurityRetentionArchiveService $archive, string $table, string $column, \Illuminate\Support\Carbon $cutoff, ?string $tenantId, bool $hasTenantColumn = true): int
    {
        if ($archive !== null) {
            return $archive->archiveAndDelete($table, $column, $cutoff, $tenantId, $hasTenantColumn);
        }

        $query = DB::table($table)->where($column, '<', $cutoff);
        if ($hasTenantColumn) {
            $query = $tenantId !== null ? $query->where('tenant_id', $tenantId) : $query->whereNull('tenant_id');
        }

        return $query->delete();
    }
}
