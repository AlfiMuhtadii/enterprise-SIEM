<?php

namespace App\Console\Commands;

use App\Services\AdvisoryFindingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AdvisoryReviewSummaryCommand extends Command
{
    protected $signature = 'advisory:review-summary
        {--tenant= : Exact tenant identifier}
        {--rule= : Exact rule identifier}
        {--start= : Inclusive UTC first-seen timestamp (YYYY-MM-DDTHH:MM:SSZ)}
        {--end= : Exclusive UTC first-seen timestamp (YYYY-MM-DDTHH:MM:SSZ)}';

    protected $description = 'Report analyst verdict coverage for one bounded advisory cohort without changing findings';

    public function handle(AdvisoryFindingService $findings): int
    {
        $tenant = (string) $this->option('tenant');
        $rule = (string) $this->option('rule');
        $start = $this->readTimestamp((string) $this->option('start'));
        $end = $this->readTimestamp((string) $this->option('end'));
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,254}\z/', $tenant)
            || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{0,254}\z/', $rule)
            || !$start || !$end || $end <= $start || $end > $start->addDays(7)) {
            $this->error('A tenant, rule, and explicit UTC window of at most seven days are required.');
            return self::INVALID;
        }

        $this->line(json_encode($findings->getReviewOutcomes($tenant, $rule, $start, $end), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    private function readTimestamp(string $input): ?CarbonImmutable
    {
        try {
            $timestamp = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $input, 'UTC');
            return $timestamp && $timestamp->format('Y-m-d\TH:i:s\Z') === $input ? $timestamp : null;
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
