<?php

namespace Tests\Feature;

use App\Models\AdvisoryFinding;
use App\Models\AdvisoryFindingEvent;
use App\Models\User;
use App\Services\AdvisoryFindingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdvisoryFindingsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // AdvisoryFindingService — upsert
    // -----------------------------------------------------------------------

    public function test_upsert_creates_new_finding(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $payload = $this->samplePayload();
        $finding = $svc->upsertFromShadow($payload);

        $this->assertDatabaseHas('advisory_findings', [
            'finding_id' => $finding->finding_id,
            'rule_id'    => 'ENDPOINT_PROCESS_INJECTION_DETECTED',
            'domain'     => 'endpoint',
            'status'     => 'new',
            'occurrence_count' => 1,
        ]);
    }

    public function test_upsert_increments_occurrence_on_duplicate_fingerprint(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $payload = $this->samplePayload();
        $svc->upsertFromShadow($payload);
        $svc->upsertFromShadow($payload);

        $this->assertDatabaseHas('advisory_findings', [
            'fingerprint'    => $payload['fingerprint'],
            'occurrence_count' => 2,
        ]);
        $this->assertSame(1, AdvisoryFinding::count());
    }

    public function test_upsert_creates_finding_created_event(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());

        $this->assertDatabaseHas('advisory_finding_events', [
            'finding_id' => $finding->finding_id,
            'event_type' => 'finding_created',
        ]);
    }

    public function test_upsert_creates_occurrence_incremented_event_on_second_call(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $payload = $this->samplePayload();
        $first   = $svc->upsertFromShadow($payload);
        $svc->upsertFromShadow($payload);

        $this->assertDatabaseHas('advisory_finding_events', [
            'finding_id' => $first->finding_id,
            'event_type' => 'occurrence_incremented',
        ]);
    }

    public function test_upsert_preserves_analyst_status_on_increment(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $payload = $this->samplePayload();
        $finding = $svc->upsertFromShadow($payload);
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc->review($finding, $analyst->id, 'Noted');
        $svc->upsertFromShadow($payload); // second occurrence

        $this->assertDatabaseHas('advisory_findings', [
            'fingerprint' => $payload['fingerprint'],
            'status'      => 'reviewed', // must not be reset to 'new'
        ]);
    }

    public function test_upsert_sets_promotion_candidate_true_for_high_confidence(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $payload = array_merge($this->samplePayload(), ['confidence' => 0.90, 'fingerprint' => 'fp-hc-001']);
        // Need occurrence >= 3 to flip candidate; seed two more
        $finding = $svc->upsertFromShadow($payload);
        $svc->upsertFromShadow($payload);
        $svc->upsertFromShadow($payload);

        $this->assertDatabaseHas('advisory_findings', [
            'fingerprint'        => 'fp-hc-001',
            'promotion_candidate' => true,
        ]);
    }

    public function test_upsert_sets_promotion_candidate_false_for_low_confidence(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $payload = array_merge($this->samplePayload(), ['confidence' => 0.40, 'fingerprint' => 'fp-lc-001']);
        $svc->upsertFromShadow($payload);

        $this->assertDatabaseHas('advisory_findings', [
            'fingerprint'        => 'fp-lc-001',
            'promotion_candidate' => false,
        ]);
    }

    public function test_promotion_blocker_always_contains_domain_soak_required(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());

        $this->assertStringContainsString('domain_soak_required', $finding->promotion_blocker);
    }

    // -----------------------------------------------------------------------
    // AdvisoryFindingService — review actions
    // -----------------------------------------------------------------------

    public function test_review_sets_status_and_appends_event(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc->review($finding, $analyst->id, 'Looks benign');

        $this->assertDatabaseHas('advisory_findings', [
            'finding_id' => $finding->finding_id,
            'status'     => 'reviewed',
            'reviewed_by' => $analyst->id,
            'review_note' => 'Looks benign',
        ]);
        $this->assertDatabaseHas('advisory_finding_events', [
            'finding_id' => $finding->finding_id,
            'event_type' => 'reviewed',
            'analyst_id' => $analyst->id,
        ]);
    }

    public function test_dismiss_sets_status_dismissed(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc->dismiss($finding, $analyst->id);

        $this->assertDatabaseHas('advisory_findings', [
            'finding_id' => $finding->finding_id,
            'status'     => 'dismissed',
        ]);
        $this->assertDatabaseHas('advisory_finding_events', [
            'finding_id' => $finding->finding_id,
            'event_type' => 'dismissed',
        ]);
    }

    public function test_nominate_sets_status_nominated_and_records_promotion_blocker(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc->nominate($finding, $analyst->id, 'Ready when soak passes');

        $this->assertDatabaseHas('advisory_findings', [
            'finding_id' => $finding->finding_id,
            'status'     => 'nominated',
        ]);
        $event = AdvisoryFindingEvent::where('finding_id', $finding->finding_id)
            ->where('event_type', 'nominated_for_promotion')->first();
        $this->assertNotNull($event);
        $this->assertArrayHasKey('promotion_blocker', $event->metadata);
    }

    // -----------------------------------------------------------------------
    // AdvisoryFindingService — getSummary
    // -----------------------------------------------------------------------

    public function test_summary_counts_by_status(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow(array_merge($this->samplePayload(), ['fingerprint' => 'fp-s1']));
        $f2 = $svc->upsertFromShadow(array_merge($this->samplePayload(), ['fingerprint' => 'fp-s2']));
        $svc->dismiss($f2, 1);

        $summary = $svc->getSummary();
        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['new']);
        $this->assertSame(1, $summary['dismissed']);
    }

    public function test_summary_groups_by_domain(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow(array_merge($this->samplePayload(), ['fingerprint' => 'fp-d1', 'domain' => 'endpoint']));
        $svc->upsertFromShadow(array_merge($this->samplePayload(), ['fingerprint' => 'fp-d2', 'domain' => 'network']));

        $summary = $svc->getSummary();
        $this->assertArrayHasKey('endpoint', $summary['by_domain']);
        $this->assertArrayHasKey('network', $summary['by_domain']);
    }

    // -----------------------------------------------------------------------
    // Advisory findings NEVER create incidents
    // -----------------------------------------------------------------------

    public function test_upsert_does_not_create_security_incidents(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow($this->samplePayload());

        $this->assertDatabaseEmpty('security_incidents');
    }

    public function test_upsert_does_not_create_security_alerts(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow($this->samplePayload());

        $this->assertDatabaseEmpty('security_alerts');
    }

    public function test_review_action_does_not_create_security_alerts(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc->nominate($finding, $analyst->id);

        $this->assertDatabaseEmpty('security_alerts');
        $this->assertDatabaseEmpty('security_incidents');
    }

    // -----------------------------------------------------------------------
    // HTTP — advisory findings routes
    // -----------------------------------------------------------------------

    public function test_index_requires_auth(): void
    {
        $this->get('/advisory/findings')->assertRedirect('/login');
    }

    public function test_index_requires_advisory_view_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'scenario_operator']);
        $this->actingAs($viewer)->get('/advisory/findings')->assertForbidden();
    }

    public function test_index_accessible_by_analyst(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->get('/advisory/findings')->assertOk();
    }

    public function test_index_accessible_by_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/advisory/findings')->assertOk();
    }

    public function test_index_shows_findings(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow($this->samplePayload());
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/advisory/findings');
        $response->assertOk()->assertSee('ENDPOINT_PROCESS_INJECTION_DETECTED');
    }

    public function test_index_filters_by_domain(): void
    {
        $svc = app(AdvisoryFindingService::class);
        $svc->upsertFromShadow(array_merge($this->samplePayload(), ['fingerprint' => 'fp-f1', 'domain' => 'endpoint']));
        $svc->upsertFromShadow(array_merge($this->samplePayload(), ['fingerprint' => 'fp-f2', 'domain' => 'network', 'rule_id' => 'NETWORK_BEACON_DETECTED']));
        $admin = User::factory()->create(['role' => 'admin']);
        $response = $this->actingAs($admin)->get('/advisory/findings?domain=network');
        $response->assertOk()->assertSee('NETWORK_BEACON_DETECTED')->assertDontSee('ENDPOINT_PROCESS_INJECTION_DETECTED');
    }

    public function test_show_returns_finding_detail(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/advisory/findings/' . $finding->finding_id)
            ->assertOk()
            ->assertSee($finding->rule_id);
    }

    public function test_show_displays_promotion_blocker(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $admin   = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/advisory/findings/' . $finding->finding_id)
            ->assertOk()
            ->assertSee('domain_soak_required');
    }

    public function test_review_post_requires_advisory_review_permission(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $viewer  = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->post('/advisory/findings/' . $finding->finding_id . '/review', ['action' => 'review'])
            ->assertForbidden();
    }

    public function test_review_post_updates_status_via_http(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->post('/advisory/findings/' . $finding->finding_id . '/review', [
                'action' => 'review',
                'note'   => 'HTTP review test',
            ])
            ->assertRedirect('/advisory/findings/' . $finding->finding_id);

        $this->assertDatabaseHas('advisory_findings', [
            'finding_id' => $finding->finding_id,
            'status'     => 'reviewed',
        ]);
    }

    public function test_dismiss_post_via_http(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->post('/advisory/findings/' . $finding->finding_id . '/review', ['action' => 'dismiss'])
            ->assertRedirect();

        $this->assertDatabaseHas('advisory_findings', [
            'finding_id' => $finding->finding_id,
            'status'     => 'dismissed',
        ]);
    }

    public function test_nominate_post_via_http(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->post('/advisory/findings/' . $finding->finding_id . '/review', ['action' => 'nominate'])
            ->assertRedirect();

        $this->assertDatabaseHas('advisory_findings', [
            'finding_id' => $finding->finding_id,
            'status'     => 'nominated',
        ]);
    }

    public function test_review_post_rejects_invalid_action(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->post('/advisory/findings/' . $finding->finding_id . '/review', ['action' => 'promote'])
            ->assertSessionHasErrors('action');
    }

    // -----------------------------------------------------------------------
    // advisory_finding_events is append-only
    // -----------------------------------------------------------------------

    public function test_advisory_finding_events_only_grow(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $analyst = User::factory()->create(['role' => 'analyst']);
        $svc->review($finding, $analyst->id, 'first');
        $svc->dismiss($finding, $analyst->id, 'second');

        $count = AdvisoryFindingEvent::where('finding_id', $finding->finding_id)->count();
        $this->assertGreaterThanOrEqual(3, $count); // created + reviewed + dismissed
    }

    // -----------------------------------------------------------------------
    // Model helpers
    // -----------------------------------------------------------------------

    public function test_finding_is_actionable_when_new_or_reviewed(): void
    {
        $f = new AdvisoryFinding(['status' => 'new']);
        $this->assertTrue($f->isActionable());
        $f->status = 'reviewed';
        $this->assertTrue($f->isActionable());
    }

    public function test_finding_is_not_actionable_when_dismissed_or_nominated(): void
    {
        $f = new AdvisoryFinding(['status' => 'dismissed']);
        $this->assertFalse($f->isActionable());
        $f->status = 'nominated';
        $this->assertFalse($f->isActionable());
    }

    public function test_severity_label_returns_abbreviated_form(): void
    {
        $f = new AdvisoryFinding(['severity' => 'critical']);
        $this->assertSame('CRIT', $f->severityLabel());
        $f->severity = 'medium';
        $this->assertSame('MED', $f->severityLabel());
    }

    // -----------------------------------------------------------------------
    // RBAC — viewer has advisory.view, scenario_operator does not
    // -----------------------------------------------------------------------

    public function test_viewer_can_access_findings_index(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get('/advisory/findings')->assertOk();
    }

    public function test_viewer_cannot_post_review(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $viewer  = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->post('/advisory/findings/' . $finding->finding_id . '/review', ['action' => 'review'])
            ->assertForbidden();
    }

    public function test_detection_engineer_can_review_findings(): void
    {
        $svc     = app(AdvisoryFindingService::class);
        $finding = $svc->upsertFromShadow($this->samplePayload());
        $de      = User::factory()->create(['role' => 'detection_engineer']);
        $this->actingAs($de)
            ->post('/advisory/findings/' . $finding->finding_id . '/review', ['action' => 'review'])
            ->assertRedirect();
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function samplePayload(array $overrides = []): array
    {
        return array_merge([
            'finding_id'          => 'adv-' . Str::random(8),
            'rule_id'             => 'ENDPOINT_PROCESS_INJECTION_DETECTED',
            'domain'              => 'endpoint',
            'source_topic'        => 'xdr.alerts.shadow.endpoint',
            'alert_type'          => 'ENDPOINT_PROCESS_INJECTION_DETECTED',
            'severity'            => 'high',
            'confidence'          => 0.82,
            'actor_key'           => 'host-001',
            'tenant_id'           => 'tenant-acme',
            'evidence'            => ['process' => 'powershell.exe', 'parent' => 'winword.exe'],
            'source_event_ids'    => ['evt-001', 'evt-002'],
            'fingerprint'         => 'fp-test-' . Str::random(6),
        ], $overrides);
    }
}
