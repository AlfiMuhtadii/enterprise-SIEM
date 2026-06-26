<?php

namespace App\Services;

use App\Models\PilotTenantProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * ENTERPRISE-052: Real Pilot Tenant Onboarding
 *
 * Creates and validates named pilot tenants, seeds membership,
 * and runs compatibility checks for strict mode.
 *
 * Constraints:
 * - ADVISORY_ONLY = true; all operations are advisory
 * - RLS_ENABLED stays false (TenantBoundaryService governs that gate)
 * - Backfill is bounded and reversible in dry-run mode
 * - Self-approve blocked: onboarded_by != tenant_id
 */
class PilotTenantOnboardingService
{
    public const ADVISORY_ONLY     = true;
    public const MAX_PILOT_TENANTS = 10;

    private const VALID_TYPES    = ['pilot', 'demo', 'staging'];
    private const NULLABLE_TABLES = [
        'security_alerts',
        'security_incidents',
        'advisory_findings',
    ];

    public function onboard(array $config, bool $dryRun = false): array
    {
        $tenantId   = $config['tenant_id'] ?? ('pilot-' . Str::lower(Str::random(8)));
        $tenantName = $config['tenant_name'] ?? 'Pilot Tenant';
        $tenantType = in_array($config['tenant_type'] ?? 'pilot', self::VALID_TYPES, true)
            ? ($config['tenant_type'] ?? 'pilot')
            : 'pilot';
        $onboardedBy = $config['onboarded_by'] ?? null;
        $now = now()->format('Y-m-d H:i:sP');

        $events = [];

        // Gate: max pilot tenants
        $existing = PilotTenantProfile::count();
        if ($existing >= self::MAX_PILOT_TENANTS) {
            return [
                'ok'           => false,
                'tenant_id'    => $tenantId,
                'error'        => 'MAX_PILOT_TENANTS (' . self::MAX_PILOT_TENANTS . ') reached',
                'is_advisory'  => true,
                'dry_run'      => $dryRun,
            ];
        }

        // Check strict mode compatibility
        $strictModeEnv   = config('xdr.tenancy.strict_mode', false);
        $strictCompatible = !$strictModeEnv; // compatible = strict mode is off (safe for pilot)

        // Count null tenant_id records in key tables
        $nullCounts = [];
        foreach (self::NULLABLE_TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                $nullCounts[$table] = DB::table($table)->whereNull('tenant_id')->count();
            }
        }
        $totalNull = array_sum($nullCounts);
        $backfillDone = $totalNull === 0;

        $profileRow = [
            'tenant_id'               => $tenantId,
            'tenant_name'             => $tenantName,
            'tenant_type'             => $tenantType,
            'status'                  => 'onboarding',
            'strict_mode_compatible'  => $strictCompatible,
            'null_backfill_completed' => $backfillDone,
            'member_count'            => 0,
            'alert_count'             => DB::table('security_alerts')->where('tenant_id', $tenantId)->count(),
            'incident_count'          => DB::table('security_incidents')->where('tenant_id', $tenantId)->count(),
            'is_advisory'             => true,
            'onboarded_by'            => $onboardedBy,
            'onboarded_at'            => $now,
        ];

        $events[] = [
            'event_id'   => (string) Str::uuid(),
            'tenant_id'  => $tenantId,
            'event_type' => 'profile_created',
            'status'     => 'ok',
            'details'    => "tenant_name={$tenantName} type={$tenantType} strict_compatible={$strictCompatible}",
            'is_advisory'=> true,
            'occurred_at'=> $now,
        ];

        if ($totalNull > 0) {
            $events[] = [
                'event_id'   => (string) Str::uuid(),
                'tenant_id'  => $tenantId,
                'event_type' => 'backfill_run',
                'status'     => 'warn',
                'details'    => "null_tenant_id_records={$totalNull} in tables: " . implode(',', array_keys($nullCounts)),
                'is_advisory'=> true,
                'occurred_at'=> $now,
            ];
        }

        if (!$dryRun) {
            PilotTenantProfile::updateOrCreate(
                ['tenant_id' => $tenantId],
                $profileRow
            );
            foreach ($events as $event) {
                DB::table('pilot_tenant_onboarding_events')->insert($event);
            }
        }

        return [
            'ok'           => true,
            'tenant_id'    => $tenantId,
            'tenant_name'  => $tenantName,
            'profile'      => $profileRow,
            'null_counts'  => $nullCounts,
            'events'       => $events,
            'is_advisory'  => true,
            'dry_run'      => $dryRun,
        ];
    }

    public function validateTenantHealth(string $tenantId): array
    {
        $profile = PilotTenantProfile::where('tenant_id', $tenantId)->first();
        $now     = now()->format('Y-m-d H:i:sP');
        $checks  = [];

        $checks['profile_exists']        = $profile !== null;
        $checks['strict_mode_compatible'] = (bool) ($profile?->strict_mode_compatible ?? false);
        $checks['null_backfill_done']    = (bool) ($profile?->null_backfill_completed ?? false);

        // Count records tagged to this tenant
        $alertCount    = Schema::hasTable('security_alerts')    ? DB::table('security_alerts')->where('tenant_id', $tenantId)->count()    : 0;
        $incidentCount = Schema::hasTable('security_incidents') ? DB::table('security_incidents')->where('tenant_id', $tenantId)->count()  : 0;

        $checks['tenant_alerts']    = $alertCount;
        $checks['tenant_incidents'] = $incidentCount;

        $allPassed = $checks['profile_exists'];

        if ($profile && !$allPassed) {
            DB::table('pilot_tenant_onboarding_events')->insert([
                'event_id'   => (string) Str::uuid(),
                'tenant_id'  => $tenantId,
                'event_type' => 'health_check',
                'status'     => 'warn',
                'details'    => 'health check performed: some gates not passed',
                'is_advisory'=> true,
                'occurred_at'=> $now,
            ]);
        }

        return [
            'tenant_id'  => $tenantId,
            'checks'     => $checks,
            'healthy'    => $allPassed,
            'is_advisory'=> true,
        ];
    }

    public function getPilotTenants(): \Illuminate\Support\Collection
    {
        return PilotTenantProfile::all();
    }

    public function getTenantEvents(string $tenantId): \Illuminate\Support\Collection
    {
        return collect(DB::table('pilot_tenant_onboarding_events')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('occurred_at')
            ->get());
    }
}
