<?php

namespace App\Console\Commands;

use App\Services\AlertMitreService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TagAlertMitreTtpCommand extends Command
{
    protected $signature = 'alerts:tag-mitre
                            {--dry-run : Show how many rows would be updated without writing}';

    protected $description = 'Tag security_alerts with MITRE ATT&CK tactic/technique (advisory enrichment, ATTR-001)';

    public function handle(AlertMitreService $mitre): int
    {
        $dryRun = $this->option('dry-run');
        $updated = 0;

        foreach ($mitre->mappedAlertTypes() as $alertType) {
            $mapping = $mitre->lookup($alertType);
            if ($mapping === null) {
                continue;
            }

            $count = DB::table('security_alerts')
                ->where('alert_type', $alertType)
                ->whereNull('mitre_tactic')
                ->count();

            if ($count === 0) {
                continue;
            }

            if (!$dryRun) {
                DB::table('security_alerts')
                    ->where('alert_type', $alertType)
                    ->whereNull('mitre_tactic')
                    ->update([
                        'mitre_tactic'         => $mapping['tactic'],
                        'mitre_technique_id'   => $mapping['technique_id'],
                        'mitre_technique_name' => $mapping['technique_name'],
                    ]);
            }

            $updated += $count;
        }

        $verb = $dryRun ? 'would tag' : 'tagged';
        $this->info("alerts:tag-mitre {$verb} {$updated} rows.");

        return self::SUCCESS;
    }
}
