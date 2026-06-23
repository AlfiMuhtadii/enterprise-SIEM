<?php

namespace Tests\Feature;

use App\Models\DlqRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DlqReplayCommand safety — BACKLOG-DLQ-017/018
 *
 * Verifies that dlq:replay restricts forwarding to the normalizer to
 * normalization-safe event types only. Pipeline failure types
 * (correlation_parse_error, correlation_publish_error, alert_write_failed)
 * must never be forwarded to /v1/normalize even if they somehow acquire
 * a raw_payload and are marked replay_requested.
 */
class DlqReplayCommandTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Constant guard
    // -----------------------------------------------------------------------

    public function test_normalizer_safe_event_types_constant_exists(): void
    {
        $this->assertIsArray(DlqRecord::NORMALIZER_SAFE_EVENT_TYPES);
        $this->assertNotEmpty(DlqRecord::NORMALIZER_SAFE_EVENT_TYPES);
    }

    public function test_normalizer_safe_types_includes_normalization_failure(): void
    {
        $this->assertContains('normalization_failure', DlqRecord::NORMALIZER_SAFE_EVENT_TYPES);
    }

    public function test_normalizer_safe_types_includes_publish_failure(): void
    {
        $this->assertContains('publish_failure', DlqRecord::NORMALIZER_SAFE_EVENT_TYPES);
    }

    public function test_normalizer_safe_types_excludes_correlation_parse_error(): void
    {
        $this->assertNotContains('correlation_parse_error', DlqRecord::NORMALIZER_SAFE_EVENT_TYPES);
    }

    public function test_normalizer_safe_types_excludes_correlation_publish_error(): void
    {
        $this->assertNotContains('correlation_publish_error', DlqRecord::NORMALIZER_SAFE_EVENT_TYPES);
    }

    public function test_normalizer_safe_types_excludes_alert_write_failed(): void
    {
        $this->assertNotContains('alert_write_failed', DlqRecord::NORMALIZER_SAFE_EVENT_TYPES);
    }

    // -----------------------------------------------------------------------
    // Runtime filter — pipeline types never forwarded to normalizer
    // -----------------------------------------------------------------------

    public function test_pipeline_event_type_record_not_sent_to_normalizer(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        // Simulate a pipeline record with raw_payload set (defence-in-depth scenario)
        $this->makePipelineRecord('correlation_parse_error');

        $this->artisan('dlq:replay')
             ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_alert_write_failed_record_not_sent_to_normalizer(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        $this->makePipelineRecord('alert_write_failed');

        $this->artisan('dlq:replay')
             ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_normalization_failure_record_is_forwarded_to_normalizer(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        $this->makeNormalizationRecord('normalization_failure');

        $this->artisan('dlq:replay')
             ->assertExitCode(0);

        Http::assertSentCount(1);
    }

    // -----------------------------------------------------------------------
    // Dry-run never sends
    // -----------------------------------------------------------------------

    public function test_dry_run_never_sends_any_http_request(): void
    {
        Http::fake(['*' => Http::response(['status' => 'accepted'], 200)]);

        $this->makeNormalizationRecord('normalization_failure');

        $this->artisan('dlq:replay', ['--dry-run' => true])
             ->assertExitCode(0);

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makePipelineRecord(string $eventType): DlqRecord
    {
        return DlqRecord::create([
            'record_id'      => 'dlq-' . Str::uuid(),
            'dlq_event_type' => $eventType,
            'source_topic'   => 'xdr.correlation_failed',
            'consumer_group' => null,
            'error_message'  => 'test failure',
            'raw_payload'    => ['dlq_event_type' => $eventType, 'source_topic' => 'xdr.correlation_failed'],
            'replayable'     => true,
            'status'         => 'replay_requested',
            'fingerprint'    => 'fp-pipe-' . Str::random(12),
            'occurrence_count' => 1,
            'first_seen_at'  => now(),
            'last_seen_at'   => now(),
        ]);
    }

    private function makeNormalizationRecord(string $eventType): DlqRecord
    {
        return DlqRecord::create([
            'record_id'      => 'dlq-' . Str::uuid(),
            'dlq_event_type' => $eventType,
            'source_topic'   => 'telemetry.normalization_failed',
            'consumer_group' => 'normalizer-worker-v1',
            'error_message'  => 'missing required fields',
            'raw_payload'    => ['ts' => '2026-06-23', 'source_type' => 'endpoint', 'event_type' => 'process_start'],
            'replayable'     => true,
            'status'         => 'replay_requested',
            'fingerprint'    => 'fp-norm-' . Str::random(12),
            'occurrence_count' => 1,
            'first_seen_at'  => now(),
            'last_seen_at'   => now(),
        ]);
    }
}
