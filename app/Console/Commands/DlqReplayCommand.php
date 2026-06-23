<?php

namespace App\Console\Commands;

use App\Models\DlqRecord;
use App\Services\DlqReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Replay normalization DLQ records that have been marked replay_requested.
 *
 * Safe path only: sends raw_payload to normalizer /v1/normalize.
 * Never auto-triggered from HTTP. Always explicit engineer invocation.
 * Never feeds directly into xdr.alerts or security_incidents.
 *
 * Usage:
 *   php artisan dlq:replay                 -- process all replay_requested records
 *   php artisan dlq:replay {record_id}     -- replay a specific record by record_id
 *   php artisan dlq:replay --dry-run       -- show what would be replayed without doing it
 */
class DlqReplayCommand extends Command
{
    protected $signature = 'dlq:replay {record_id? : Optional specific record_id to replay} {--dry-run : Show records without replaying}';
    protected $description = 'Replay normalization DLQ records (only records with raw_payload and status=replay_requested)';

    public function handle(DlqReviewService $service): int
    {
        $normalizerUrl = config('services.normalizer_url', env('XDR_NORMALIZER_URL', 'http://127.0.0.1:8092'));
        $internalToken = env('XDR_NORMALIZER_INTERNAL_TOKEN', '');
        $dryRun        = $this->option('dry-run');
        $specificId    = $this->argument('record_id');

        $query = DlqRecord::where('status', 'replay_requested')
            ->whereNotNull('raw_payload');

        if ($specificId) {
            $query->where('record_id', $specificId);
        }

        $records = $query->get();

        if ($records->isEmpty()) {
            $this->line('No replay_requested records with raw_payload found.');
            return 0;
        }

        $this->line("Found {$records->count()} record(s) to replay" . ($dryRun ? ' [DRY RUN]' : '') . '.');

        $succeeded = 0;
        $failed    = 0;

        foreach ($records as $record) {
            $payload = is_array($record->raw_payload) ? $record->raw_payload : [];

            $this->line("  → {$record->record_id}  type={$record->dlq_event_type}  topic={$record->source_topic}");

            if ($dryRun) {
                $this->line("    [DRY RUN] would POST to {$normalizerUrl}/v1/normalize");
                continue;
            }

            try {
                $headers = ['Accept' => 'application/json'];
                if ($internalToken !== '') {
                    $headers['X-Internal-Service-Token'] = $internalToken;
                }

                $response = Http::withHeaders($headers)
                    ->timeout(15)
                    ->post("{$normalizerUrl}/v1/normalize", [$payload]);

                if ($response->successful()) {
                    $service->markReplayed($record, $response->json() ?? ['status' => 'accepted']);
                    $this->line("    ✓ replayed  status={$response->status()}");
                    $succeeded++;
                } else {
                    $error = "normalizer_rejected  http={$response->status()}  body={$response->body()}";
                    $service->markReplayFailed($record, $error);
                    $this->warn("    ✗ failed  {$error}");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $service->markReplayFailed($record, $e->getMessage());
                $this->warn("    ✗ exception  {$e->getMessage()}");
                $failed++;
            }
        }

        if (!$dryRun) {
            $this->line("Replay complete: {$succeeded} succeeded, {$failed} failed.");
        }

        return $failed > 0 ? 1 : 0;
    }
}
