<?php

namespace App\Console\Commands;

use App\Services\ShadowReadyPromotionDecisionService;
use Illuminate\Console\Command;

/**
 * ENTERPRISE-047: Evaluate shadow_ready rules for promotion readiness.
 *
 * Usage:
 *   php artisan shadow:evaluate-promotion
 *   php artisan shadow:evaluate-promotion --domain=identity
 *   php artisan shadow:evaluate-promotion --dry-run
 *
 * Produces per-rule decisions: promote_eligible | keep_shadow | defer.
 * promotion_approved is ALWAYS false — no rule is promoted by this command.
 * Decisions are persisted to shadow_promotion_decisions (append-only) unless --dry-run.
 */
class EvaluateShadowPromotionCommand extends Command
{
    protected $signature = 'shadow:evaluate-promotion
                            {--domain=  : Filter to a specific domain (identity|cloud|saas)}
                            {--dry-run  : Evaluate without persisting to DB}';

    protected $description = 'Evaluate shadow_ready rules for promotion readiness — advisory only, no promotion';

    public function handle(ShadowReadyPromotionDecisionService $service): int
    {
        $domain = (string) $this->option('domain');
        $dryRun = (bool)   $this->option('dry-run');

        $mode = $dryRun ? '<fg=yellow>[DRY-RUN]</>' : '<fg=cyan>[EVALUATE]</>';
        $this->line('');
        $this->line("{$mode} Shadow Promotion Readiness Evaluation");
        $this->line('  ADVISORY-ONLY. No rules are promoted. promotion_approved = false always.');
        if ($domain !== '') {
            $this->line("  domain filter : {$domain}");
        }
        $this->line('');

        $results = $service->evaluate($domain, $dryRun);
        $summary = $service->getSummary($results);

        $this->table(
            ['Rule ID', 'Domain', 'Confidence', 'DLQ Errors', 'FP Risk', 'Decision', 'Approved'],
            $results->map(fn ($r) => [
                $r['rule_id'],
                $r['domain'],
                number_format((float) $r['confidence'], 2),
                $r['dlq_errors_in_domain'],
                $r['false_positive_risk'],
                $r['decision'],
                'false',
            ])->all(),
        );

        $this->line('');
        $this->line('── Summary ────────────────────────────────────────');
        $this->line("  Total evaluated    : {$summary['total']}");
        $this->line("  promote_eligible   : {$summary['promote_eligible']}  (advisory; requires soak + ACTIVE_ALLOWLIST + sign-off)");
        $this->line("  keep_shadow        : {$summary['keep_shadow']}");
        $this->line("  defer              : {$summary['defer']}");
        $this->line("  promotion_approved : false (always)");
        $this->line('');

        if ($summary['promote_eligible'] > 0) {
            $this->warn("ADVISORY: {$summary['promote_eligible']} rule(s) meet the confidence threshold.");
            $this->warn('Next gate: schedule domain-specific 6h soak → review ACTIVE_ALLOWLIST → analyst sign-off.');
        } else {
            $this->info('No rules currently meet the promote_eligible threshold.');
        }

        if (!$dryRun) {
            $this->info("Decisions persisted to shadow_promotion_decisions ({$summary['total']} rows appended).");
        } else {
            $this->warn('Dry-run: no rows written.');
        }

        return self::SUCCESS;
    }
}
