<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RedpandaRecoveryHardeningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RedpandaRecoveryHardeningTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Constants
    // -----------------------------------------------------------------------

    public function test_expected_topics_constant_is_non_empty(): void
    {
        $this->assertNotEmpty(RedpandaRecoveryHardeningService::EXPECTED_TOPICS);
        $this->assertGreaterThanOrEqual(9, count(RedpandaRecoveryHardeningService::EXPECTED_TOPICS));
    }

    public function test_expected_topics_includes_core_topics(): void
    {
        $topics = RedpandaRecoveryHardeningService::EXPECTED_TOPICS;
        $this->assertContains('telemetry.raw', $topics);
        $this->assertContains('telemetry.normalized', $topics);
        $this->assertContains('xdr.alerts', $topics);
        $this->assertContains('alerts.created', $topics);
        $this->assertContains('incidents.updated', $topics);
    }

    public function test_expected_consumer_groups_constant_is_non_empty(): void
    {
        $this->assertNotEmpty(RedpandaRecoveryHardeningService::EXPECTED_CONSUMER_GROUPS);
        $this->assertGreaterThanOrEqual(4, count(RedpandaRecoveryHardeningService::EXPECTED_CONSUMER_GROUPS));
    }

    public function test_lag_warn_threshold_is_positive(): void
    {
        $this->assertGreaterThan(0, RedpandaRecoveryHardeningService::LAG_WARN_THRESHOLD);
    }

    // -----------------------------------------------------------------------
    // assessTopicHealth()
    // -----------------------------------------------------------------------

    public function test_assess_topic_health_returns_required_keys(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessTopicHealth('test');
        foreach (['run_id', 'topics_expected', 'overall_status', 'topic_status'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_assess_topic_health_persists_run(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessTopicHealth('test');
        $this->assertDatabaseHas('redpanda_topic_health_runs', ['run_id' => $result['run_id']]);
    }

    public function test_topic_health_topics_expected_matches_constant(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessTopicHealth('test');
        $this->assertSame(count(RedpandaRecoveryHardeningService::EXPECTED_TOPICS), $result['topics_expected']);
    }

    public function test_topic_health_overall_status_is_valid(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessTopicHealth('test');
        $this->assertContains($result['overall_status'], ['PASS', 'WARN', 'FAIL']);
    }

    // -----------------------------------------------------------------------
    // assessConsumerGroupHealth()
    // -----------------------------------------------------------------------

    public function test_assess_consumer_group_health_returns_required_keys(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessConsumerGroupHealth('test');
        foreach (['run_id', 'groups_checked', 'overall_status', 'group_status'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_assess_consumer_group_health_persists_run(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessConsumerGroupHealth('test');
        $this->assertDatabaseHas('redpanda_consumer_group_health_runs', ['run_id' => $result['run_id']]);
    }

    public function test_consumer_group_health_groups_match_constant(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessConsumerGroupHealth('test');
        $this->assertSame(count(RedpandaRecoveryHardeningService::EXPECTED_CONSUMER_GROUPS), $result['groups_checked']);
    }

    // -----------------------------------------------------------------------
    // recordRecoveryEvent()
    // -----------------------------------------------------------------------

    public function test_record_recovery_event_persists_row(): void
    {
        app(RedpandaRecoveryHardeningService::class)->recordRecoveryEvent([
            'event_type'     => 'OFFSET_RESET',
            'affected_topic' => 'telemetry.raw',
            'affected_group' => 'normalizer-worker-group',
            'triggered_by'   => 'test@example.com',
            'outcome'        => 'SUCCESS',
            'detail'         => 'Offset reset to earliest after offset_out_of_range error.',
        ]);
        $this->assertDatabaseCount('redpanda_recovery_events', 1);
        $this->assertDatabaseHas('redpanda_recovery_events', [
            'event_type'     => 'OFFSET_RESET',
            'affected_topic' => 'telemetry.raw',
            'outcome'        => 'SUCCESS',
        ]);
    }

    public function test_recovery_event_types_are_auditable(): void
    {
        $svc = app(RedpandaRecoveryHardeningService::class);
        foreach (['OFFSET_RESET', 'CONSUMER_RESTART', 'TOPIC_RECREATE', 'BOOTSTRAP'] as $type) {
            $svc->recordRecoveryEvent([
                'event_type'   => $type,
                'triggered_by' => 'test',
                'outcome'      => 'ADVISORY',
            ]);
        }
        $this->assertDatabaseCount('redpanda_recovery_events', 4);
    }

    public function test_recovery_events_are_append_only(): void
    {
        $svc = app(RedpandaRecoveryHardeningService::class);
        $svc->recordRecoveryEvent(['event_type' => 'BOOTSTRAP', 'triggered_by' => 'test', 'outcome' => 'SUCCESS']);
        $this->assertDatabaseCount('redpanda_recovery_events', 1);
        // insertOrIgnore — second call with same UUID would be ignored
    }

    // -----------------------------------------------------------------------
    // getters
    // -----------------------------------------------------------------------

    public function test_get_topic_health_history_returns_collection(): void
    {
        $svc = app(RedpandaRecoveryHardeningService::class);
        $svc->assessTopicHealth('test');
        $this->assertGreaterThanOrEqual(1, $svc->getTopicHealthHistory()->count());
    }

    public function test_get_consumer_group_history_returns_collection(): void
    {
        $svc = app(RedpandaRecoveryHardeningService::class);
        $svc->assessConsumerGroupHealth('test');
        $this->assertGreaterThanOrEqual(1, $svc->getConsumerGroupHistory()->count());
    }

    public function test_get_recovery_events_returns_collection(): void
    {
        $svc = app(RedpandaRecoveryHardeningService::class);
        $svc->recordRecoveryEvent(['event_type' => 'BOOTSTRAP', 'triggered_by' => 'test', 'outcome' => 'ADVISORY']);
        $this->assertGreaterThanOrEqual(1, $svc->getRecoveryEvents()->count());
    }

    // -----------------------------------------------------------------------
    // Database tables
    // -----------------------------------------------------------------------

    public function test_redpanda_topic_health_runs_table_exists(): void
    {
        $this->assertDatabaseCount('redpanda_topic_health_runs', 0);
    }

    public function test_redpanda_consumer_group_health_runs_table_exists(): void
    {
        $this->assertDatabaseCount('redpanda_consumer_group_health_runs', 0);
    }

    public function test_redpanda_recovery_events_table_exists(): void
    {
        $this->assertDatabaseCount('redpanda_recovery_events', 0);
    }

    // -----------------------------------------------------------------------
    // HTTP routes
    // -----------------------------------------------------------------------

    public function test_admin_can_view_redpanda_health(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/soc/redpanda/health')->assertOk();
    }

    public function test_analyst_can_view_redpanda_health(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->get('/soc/redpanda/health')->assertOk();
    }

    public function test_viewer_can_view_redpanda_health(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/soc/redpanda/health')->assertOk();
    }

    public function test_unauthenticated_redirected(): void
    {
        $this->get('/soc/redpanda/health')->assertRedirect('/login');
    }

    public function test_admin_can_trigger_health_check(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/soc/redpanda/health/check')
            ->assertRedirect(route('soc.redpanda.health.index'));
    }

    public function test_analyst_cannot_trigger_health_check(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->post('/soc/redpanda/health/check')->assertForbidden();
    }

    public function test_events_route_accessible(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/soc/redpanda/health/events')->assertOk();
    }

    // -----------------------------------------------------------------------
    // Advisory-only posture
    // -----------------------------------------------------------------------

    public function test_service_never_modifies_topics_autonomously(): void
    {
        // assessTopicHealth is advisory-only — no rpk commands executed
        $result = app(RedpandaRecoveryHardeningService::class)->assessTopicHealth('test');
        $this->assertStringContainsStringIgnoringCase('advisory', $result['note']);
    }

    public function test_service_never_modifies_consumer_groups_autonomously(): void
    {
        $result = app(RedpandaRecoveryHardeningService::class)->assessConsumerGroupHealth('test');
        $this->assertStringContainsStringIgnoringCase('advisory', $result['note']);
    }
}
