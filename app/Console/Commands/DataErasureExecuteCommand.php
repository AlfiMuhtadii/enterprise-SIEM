<?php

namespace App\Console\Commands;

use App\Models\DataErasureRequest;
use App\Services\DataResidencyErasureService;
use Illuminate\Console\Command;

/**
 * DATA-RESIDENCY-ERASURE: erasure execution is CLI-only, mirroring the
 * platform's existing "destructive-ish operations are never HTTP-triggered"
 * pattern (php artisan dlq:replay). Real deletion requires the request to
 * already be status=approved (self-approve blocked at approval time).
 */
class DataErasureExecuteCommand extends Command
{
    protected $signature = 'data-erasure:execute {request_id} {--actor=cli}';

    protected $description = 'Execute (or dry-run preview) an approved GDPR erasure request';

    public function handle(DataResidencyErasureService $residency): int
    {
        $requestId = $this->argument('request_id');
        $request = DataErasureRequest::where('request_id', $requestId)
            ->when(ctype_digit((string) $requestId), fn ($q) => $q->orWhere('id', $requestId))
            ->first();
        if (!$request) {
            $this->error("Erasure request not found: {$requestId}");
            return self::FAILURE;
        }

        try {
            $summary = $residency->executeErasure($request->id, (string) $this->option('actor'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $mode = $request->dry_run ? 'DRY-RUN (counted only, nothing deleted)' : 'EXECUTED';
        $this->info("{$mode} — request {$request->request_id} (tenant={$request->tenant_id}):");
        foreach ($summary as $table => $count) {
            $this->info("  {$table}: {$count} rows");
        }

        return self::SUCCESS;
    }
}
