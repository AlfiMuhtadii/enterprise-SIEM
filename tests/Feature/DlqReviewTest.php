<?php

namespace Tests\Feature;

use App\Models\DlqNormalizationEvent;
use App\Models\DlqRecord;
use App\Models\User;
use App\Services\DlqReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DLQ Review Workflow — BACKLOG-DLQ-014
 *
 * Validates:
 * - DlqReviewService upsert, review, ignore, requestReplay logic
 * - Boundary: never creates security_incidents or security_alerts
 * - RBAC: dlq.view / dlq.review per role
 * - Audit trail append-only behaviour
 * - Replay gating (raw_payload required; poison records rejected)
 * - Terminal state enforcement
 */
class DlqReviewTest extends TestCase
{
    use RefreshDatabase;

    private DlqReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DlqReviewService();
    }

    // -----------------------------------------------------------------------
    // Service — upsert
    // -----------------------------------------------------------------------

    public function test_upsert_creates_new_record(): void
    {
        $data    = $this->makeRecordData();
        $outcome = $this->service->upsertFromConsumer($data);

        $this->assertSame('created', $outcome);
        $this->assertDatabaseHas('dlq_records', ['fingerprint' => $data['fingerprint'], 'status' => 'new']);
    }

    public function test_upsert_increments_occurrence_on_conflict(): void
    {
        $data = $this->makeRecordData();
        $this->service->upsertFromConsumer($data);
        $outcome = $this->service->upsertFromConsumer($data);

        $this->assertSame('updated', $outcome);
        $this->assertDatabaseHas('dlq_records', ['fingerprint' => $data['fingerprint'], 'occurrence_count' => 2]);
    }

    public function test_upsert_preserves_status_on_increment(): void
    {
        $data   = $this->makeRecordData();
        $record = DlqRecord::create(array_merge($data, [
            'record_id'    => 'dlq-' . Str::uuid(),
            'status'       => 'reviewed',
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]));

        $this->service->upsertFromConsumer($data); // second occurrence
        $record->refresh();

        $this->assertSame('reviewed', $record->status, 'Status must not be reset on occurrence increment');
    }

    public function test_upsert_appends_record_created_event(): void
    {
        $data = $this->makeRecordData();
        $this->service->upsertFromConsumer($data);

        $this->assertDatabaseHas('dlq_normalization_events', ['event_type' => 'record_created']);
    }

    public function test_upsert_appends_occurrence_incremented_event(): void
    {
        $data = $this->makeRecordData();
        $this->service->upsertFromConsumer($data);
        $this->service->upsertFromConsumer($data);

        $this->assertDatabaseHas('dlq_normalization_events', ['event_type' => 'occurrence_incremented']);
    }

    // -----------------------------------------------------------------------
    // Service — review / ignore
    // -----------------------------------------------------------------------

    public function test_review_transitions_status(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord();

        $this->service->review($record, $analyst, 'looks like expected traffic');

        $record->refresh();
        $this->assertSame('reviewed', $record->status);
        $this->assertSame($analyst->id, $record->reviewed_by);
        $this->assertDatabaseHas('dlq_normalization_events', ['event_type' => 'reviewed']);
    }

    public function test_ignore_transitions_status(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord();

        $this->service->ignore($record, $analyst, 'false alarm');

        $record->refresh();
        $this->assertSame('ignored', $record->status);
        $this->assertDatabaseHas('dlq_normalization_events', ['event_type' => 'ignored']);
    }

    public function test_review_on_ignored_record_throws(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord(['status' => 'ignored']);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->review($record, $analyst);
    }

    // -----------------------------------------------------------------------
    // Service — requestReplay
    // -----------------------------------------------------------------------

    public function test_request_replay_succeeds_with_raw_payload(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'raw_payload' => ['ts' => '2026-06-23', 'telemetry_type' => 'endpoint', 'event_type' => 'login'],
            'replayable'  => true,
        ]);

        $this->service->requestReplay($record, $analyst);

        $record->refresh();
        $this->assertSame('replay_requested', $record->status);
        $this->assertDatabaseHas('dlq_normalization_events', ['event_type' => 'replay_requested']);
    }

    public function test_request_replay_rejected_for_poison_record(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord(['raw_payload' => null, 'dlq_event_type' => 'poison_message_isolated']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not classified as replayable/i');
        $this->service->requestReplay($record, $analyst);
    }

    public function test_request_replay_rejected_from_ignored_status(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'status'      => 'ignored',
            'raw_payload' => ['ts' => '2026-06-23', 'telemetry_type' => 'endpoint', 'event_type' => 'login'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->requestReplay($record, $analyst);
    }

    public function test_request_replay_allowed_from_replay_failed(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'status'      => 'replay_failed',
            'raw_payload' => ['ts' => '2026-06-23', 'telemetry_type' => 'endpoint', 'event_type' => 'login'],
            'replayable'  => true,
        ]);

        $this->service->requestReplay($record, $analyst, 'retrying after normalizer restart');

        $record->refresh();
        $this->assertSame('replay_requested', $record->status);
    }

    // -----------------------------------------------------------------------
    // Service — markReplayed / markReplayFailed
    // -----------------------------------------------------------------------

    public function test_mark_replayed_sets_status_and_result(): void
    {
        $record = $this->createRecord(['status' => 'replay_requested', 'raw_payload' => []]);
        $this->service->markReplayed($record, ['enqueued' => 1]);

        $record->refresh();
        $this->assertSame('replayed', $record->status);
        $this->assertNotNull($record->replayed_at);
        $this->assertDatabaseHas('dlq_normalization_events', ['event_type' => 'replayed']);
    }

    public function test_mark_replay_failed_sets_status_and_error(): void
    {
        $record = $this->createRecord(['status' => 'replay_requested', 'raw_payload' => []]);
        $this->service->markReplayFailed($record, 'normalizer_rejected http=422');

        $record->refresh();
        $this->assertSame('replay_failed', $record->status);
        $this->assertDatabaseHas('dlq_normalization_events', ['event_type' => 'replay_failed']);
    }

    // -----------------------------------------------------------------------
    // Boundary: never creates security_incidents or security_alerts
    // -----------------------------------------------------------------------

    public function test_no_security_incidents_created(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'raw_payload' => ['ts' => '2026-06-23', 'telemetry_type' => 'endpoint', 'event_type' => 'login'],
            'replayable'  => true,
        ]);

        $this->service->requestReplay($record, $analyst);

        $this->assertDatabaseMissing('security_incidents', []);
    }

    public function test_no_security_alerts_created(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord();

        $this->service->review($record, $analyst);

        $this->assertDatabaseMissing('security_alerts', []);
    }

    // -----------------------------------------------------------------------
    // getSummary
    // -----------------------------------------------------------------------

    public function test_get_summary_returns_correct_counts(): void
    {
        $this->createRecord(['status' => 'new']);
        $this->createRecord(['status' => 'new', 'fingerprint' => 'fp-2']);
        $this->createRecord(['status' => 'reviewed', 'fingerprint' => 'fp-3']);
        $this->createRecord(['status' => 'ignored', 'fingerprint' => 'fp-4']);

        $summary = $this->service->getSummary();

        $this->assertSame(4, $summary['total']);
        $this->assertSame(2, $summary['new']);
        $this->assertSame(1, $summary['reviewed']);
        $this->assertSame(1, $summary['ignored']);
    }

    public function test_get_summary_replayable_counts_correctly(): void
    {
        $this->createRecord(['raw_payload' => ['a' => 1], 'replayable' => true,  'status' => 'new',     'fingerprint' => 'fp-r1']);
        $this->createRecord(['raw_payload' => null,       'replayable' => false, 'status' => 'new',     'fingerprint' => 'fp-r2']);
        $this->createRecord(['raw_payload' => ['b' => 2], 'replayable' => true,  'status' => 'ignored', 'fingerprint' => 'fp-r3']);

        $this->assertSame(1, $this->service->getSummary()['replayable']);
    }

    // -----------------------------------------------------------------------
    // HTTP — auth required
    // -----------------------------------------------------------------------

    public function test_index_requires_auth(): void
    {
        $this->get(route('dlq.records.index'))->assertRedirect(route('login'));
    }

    public function test_show_requires_auth(): void
    {
        $record = $this->createRecord();
        $this->get(route('dlq.records.show', $record->record_id))->assertRedirect(route('login'));
    }

    public function test_review_post_requires_auth(): void
    {
        $record = $this->createRecord();
        $this->post(route('dlq.records.review', $record->record_id), ['action' => 'reviewed'])->assertRedirect(route('login'));
    }

    // -----------------------------------------------------------------------
    // HTTP — RBAC
    // -----------------------------------------------------------------------

    public function test_admin_can_view_and_review(): void
    {
        $admin  = $this->makeUser('admin');
        $record = $this->createRecord();

        $this->actingAs($admin)->get(route('dlq.records.index'))->assertOk();
        $this->actingAs($admin)->get(route('dlq.records.show', $record->record_id))->assertOk();
        $this->actingAs($admin)
            ->post(route('dlq.records.review', $record->record_id), ['action' => 'reviewed'])
            ->assertRedirect(route('dlq.records.show', $record->record_id));
    }

    public function test_detection_engineer_can_view_and_review(): void
    {
        $de     = $this->makeUser('detection_engineer');
        $record = $this->createRecord();

        $this->actingAs($de)->get(route('dlq.records.index'))->assertOk();
        $this->actingAs($de)
            ->post(route('dlq.records.review', $record->record_id), ['action' => 'ignored'])
            ->assertRedirect(route('dlq.records.show', $record->record_id));
    }

    public function test_viewer_can_view_but_not_review(): void
    {
        $viewer = $this->makeUser('viewer');
        $record = $this->createRecord();

        $this->actingAs($viewer)->get(route('dlq.records.index'))->assertOk();
        $this->actingAs($viewer)
            ->post(route('dlq.records.review', $record->record_id), ['action' => 'reviewed'])
            ->assertForbidden();
    }

    public function test_analyst_can_view_but_not_review(): void
    {
        $analyst = $this->makeUser('analyst');
        $record  = $this->createRecord();

        $this->actingAs($analyst)->get(route('dlq.records.index'))->assertOk();
        $this->actingAs($analyst)
            ->post(route('dlq.records.review', $record->record_id), ['action' => 'reviewed'])
            ->assertForbidden();
    }

    public function test_scenario_operator_cannot_view(): void
    {
        $op     = $this->makeUser('scenario_operator');
        $record = $this->createRecord();

        $this->actingAs($op)->get(route('dlq.records.index'))->assertForbidden();
    }

    // -----------------------------------------------------------------------
    // HTTP — replay_requested validation
    // -----------------------------------------------------------------------

    public function test_replay_requested_via_http_requires_raw_payload(): void
    {
        $admin  = $this->makeUser('admin');
        $record = $this->createRecord(['raw_payload' => null, 'dlq_event_type' => 'poison_message_isolated']);

        $this->actingAs($admin)
            ->post(route('dlq.records.review', $record->record_id), ['action' => 'replay_requested'])
            ->assertSessionHasErrors('action');
    }

    public function test_replay_requested_via_http_succeeds_with_payload(): void
    {
        $admin  = $this->makeUser('admin');
        $record = $this->createRecord([
            'raw_payload' => ['ts' => '2026-06-23', 'event_type' => 'login'],
            'replayable'  => true,
        ]);

        $this->actingAs($admin)
            ->post(route('dlq.records.review', $record->record_id), ['action' => 'replay_requested'])
            ->assertRedirect(route('dlq.records.show', $record->record_id));

        $this->assertDatabaseHas('dlq_records', ['record_id' => $record->record_id, 'status' => 'replay_requested']);
    }

    // -----------------------------------------------------------------------
    // Audit trail
    // -----------------------------------------------------------------------

    public function test_dlq_normalization_events_only_grows(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord();

        $before = DlqNormalizationEvent::count();
        $this->service->review($record, $analyst, 'ok');
        $this->service->ignore($record, $analyst, 'noise');

        // Can't call review again after ignore — just check count grew
        $this->assertGreaterThan($before, DlqNormalizationEvent::count());
    }

    public function test_show_page_displays_audit_trail(): void
    {
        $admin   = $this->makeUser('admin');
        $analyst = User::factory()->create();
        $record  = $this->createRecord();

        $this->service->review($record, $analyst);

        $this->actingAs($admin)
            ->get(route('dlq.records.show', $record->record_id))
            ->assertOk()
            ->assertSee('reviewed');
    }

    // -----------------------------------------------------------------------
    // BACKLOG-017: Pipeline failure event types
    // -----------------------------------------------------------------------

    public function test_pipeline_event_types_are_registered(): void
    {
        $this->assertContains('correlation_parse_error',   DlqRecord::EVENT_TYPES);
        $this->assertContains('correlation_publish_error', DlqRecord::EVENT_TYPES);
        $this->assertContains('alert_write_failed',        DlqRecord::EVENT_TYPES);
    }

    // -----------------------------------------------------------------------
    // BACKLOG-017: Replayability classification
    // -----------------------------------------------------------------------

    public function test_replayable_false_blocks_replay(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'raw_payload'    => ['ts' => '2026-06-23', 'event_type' => 'login'],
            'replayable'     => false,
            'dlq_event_type' => 'correlation_parse_error',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not classified as replayable/i');
        $this->service->requestReplay($record, $analyst);
    }

    public function test_replayable_true_with_no_payload_throws_no_raw_payload(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'raw_payload'    => null,
            'replayable'     => true,
            'dlq_event_type' => 'correlation_publish_error',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no raw_payload/i');
        $this->service->requestReplay($record, $analyst);
    }

    public function test_replayable_false_record_can_be_reviewed(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'replayable'     => false,
            'dlq_event_type' => 'correlation_parse_error',
        ]);

        $this->service->review($record, $analyst, 'acknowledged');

        $record->refresh();
        $this->assertSame('reviewed', $record->status);
    }

    public function test_replayable_false_record_can_be_ignored(): void
    {
        $analyst = User::factory()->create();
        $record  = $this->createRecord([
            'replayable'     => false,
            'dlq_event_type' => 'correlation_parse_error',
        ]);

        $this->service->ignore($record, $analyst, 'noise');

        $record->refresh();
        $this->assertSame('ignored', $record->status);
    }

    public function test_is_replayable_class_returns_true_when_set(): void
    {
        $record = $this->createRecord([
            'replayable'     => true,
            'dlq_event_type' => 'correlation_publish_error',
        ]);
        $this->assertTrue($record->isReplayableClass());
    }

    public function test_is_replayable_class_returns_false_by_default(): void
    {
        $record = $this->createRecord(['dlq_event_type' => 'correlation_parse_error']);
        $this->assertFalse($record->isReplayableClass());
    }

    // -----------------------------------------------------------------------
    // BACKLOG-017: getSummary() new fields
    // -----------------------------------------------------------------------

    public function test_get_summary_includes_replayable_classified(): void
    {
        $this->createRecord(['replayable' => true,  'fingerprint' => 'fp-rc1']);
        $this->createRecord(['replayable' => true,  'fingerprint' => 'fp-rc2']);
        $this->createRecord(['replayable' => false, 'fingerprint' => 'fp-rc3']);

        $summary = $this->service->getSummary();

        $this->assertArrayHasKey('replayable_classified', $summary);
        $this->assertSame(2, $summary['replayable_classified']);
    }

    public function test_get_summary_includes_by_source_topic(): void
    {
        $summary = $this->service->getSummary();
        $this->assertArrayHasKey('by_source_topic', $summary);
        $this->assertIsArray($summary['by_source_topic']);
    }

    public function test_get_summary_by_source_topic_groups_correctly(): void
    {
        $this->createRecord(['source_topic' => 'xdr.correlation_failed', 'fingerprint' => 'fp-st1']);
        $this->createRecord(['source_topic' => 'xdr.correlation_failed', 'fingerprint' => 'fp-st2']);
        $this->createRecord(['source_topic' => 'xdr.alert_write_failed', 'fingerprint' => 'fp-st3']);

        $summary = $this->service->getSummary();

        $this->assertSame(2, $summary['by_source_topic']['xdr.correlation_failed'] ?? 0);
        $this->assertSame(1, $summary['by_source_topic']['xdr.alert_write_failed'] ?? 0);
    }

    public function test_replayable_classified_counts_regardless_of_payload(): void
    {
        $this->createRecord(['replayable' => true, 'raw_payload' => null,    'fingerprint' => 'fp-rnp1']);
        $this->createRecord(['replayable' => true, 'raw_payload' => ['a' => 1], 'fingerprint' => 'fp-rnp2']);

        $summary = $this->service->getSummary();
        $this->assertSame(2, $summary['replayable_classified']);
    }

    // -----------------------------------------------------------------------
    // BACKLOG-017: getRecords() source_topic filter
    // -----------------------------------------------------------------------

    public function test_get_records_filter_by_source_topic(): void
    {
        $this->createRecord(['source_topic' => 'xdr.correlation_failed', 'fingerprint' => 'fp-grf1']);
        $this->createRecord(['source_topic' => 'xdr.alert_write_failed', 'fingerprint' => 'fp-grf2']);
        $this->createRecord(['source_topic' => 'telemetry.raw',          'fingerprint' => 'fp-grf3']);

        $result = $this->service->getRecords(['source_topic' => 'xdr.correlation_failed']);

        $this->assertCount(1, $result);
        $this->assertSame('xdr.correlation_failed', $result->first()->source_topic);
    }

    public function test_upsert_stores_replayable_and_error_reason(): void
    {
        $data = $this->makeRecordData([
            'dlq_event_type' => 'correlation_publish_error',
            'source_topic'   => 'xdr.correlation_failed',
            'replayable'     => true,
            'error_reason'   => 'alert_publish_failed',
        ]);

        $this->service->upsertFromConsumer($data);

        $this->assertDatabaseHas('dlq_records', [
            'fingerprint'  => $data['fingerprint'],
            'replayable'   => true,
            'error_reason' => 'alert_publish_failed',
        ]);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeRecordData(array $overrides = []): array
    {
        return array_merge([
            'dlq_event_type' => 'normalization_failure',
            'source_topic'   => 'telemetry.raw',
            'source_partition' => null,
            'source_offset'    => null,
            'consumer_group'  => 'normalizer-worker-v1',
            'error_code'      => null,
            'error_message'   => 'missing_required_fields',
            'raw_payload'     => null,
            'isolated_at'     => null,
            'tenant_id'       => null,
            'replayable'      => false,
            'error_reason'    => null,
            'fingerprint'     => 'fp-' . Str::random(12),
            'occurrence_count' => 1,
        ], $overrides);
    }

    private function createRecord(array $overrides = []): DlqRecord
    {
        $data = $this->makeRecordData($overrides);
        return DlqRecord::create(array_merge($data, [
            'record_id'    => 'dlq-' . Str::uuid(),
            'status'       => $overrides['status'] ?? 'new',
            'first_seen_at' => now(),
            'last_seen_at'  => now(),
        ]));
    }

    private function makeUser(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();
        return $user;
    }
}
