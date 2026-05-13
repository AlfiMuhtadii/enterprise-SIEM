<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class XdrRuleMaturityCommand extends Command
{
    protected $signature = 'xdr:rule-maturity {--pack=storage/app/xdr_rule_packs/identity_cloud_sigma_like.json} {--environment=staging}';

    protected $description = 'Load Sigma-like XDR rule packs and record lifecycle, drift, dependency, and quality metrics.';

    public function handle(): int
    {
        $path = base_path((string) $this->option('pack'));
        if (!is_file($path)) {
            $this->error("Rule pack not found: {$path}");
            return self::FAILURE;
        }
        $pack = json_decode(file_get_contents($path), true) ?: [];
        $environment = (string) $this->option('environment');
        foreach (($pack['rules'] ?? []) as $rule) {
            $confidence = (float) ($rule['confidence'] ?? 0.5);
            $dependencyCount = count($rule['dependencies'] ?? []);
            $drift = round(max(0, 0.18 - ($confidence * 0.08)), 3);
            $quality = round(min(1, ($confidence * 0.7) + (($dependencyCount > 1 ? 0.2 : 0.1)) - $drift), 3);
            DB::table('xdr_detection_rule_maturity')->updateOrInsert(
                ['rule_id' => $rule['rule_id'], 'environment' => $environment],
                [
                    'rule_pack' => $pack['pack_id'] ?? 'default-pack',
                    'status' => $rule['status'] ?? 'enabled',
                    'confidence' => $confidence,
                    'quality_score' => $quality,
                    'sigma_like_rule' => json_encode($rule),
                    'dependencies' => json_encode($rule['dependencies'] ?? []),
                    'drift_metrics' => json_encode(['rule_drift_score' => $drift, 'volume_shift' => $drift / 2]),
                    'regression_history' => json_encode([
                        ['version' => $pack['version'] ?? '1.0.0', 'passed' => $quality >= 0.6, 'quality_score' => $quality, 'at' => now()->toIso8601String()],
                    ]),
                    'evaluated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
            $this->line("{$rule['rule_id']} quality={$quality}");
        }

        return self::SUCCESS;
    }
}
