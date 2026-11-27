<?php

namespace Tests\Feature;

use App\Models\AdvisoryFinding;
use App\Models\AdvisoryFindingEvent;
use App\Models\User;
use App\Services\AdvisoryFindingService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class AdvisoryFindingReviewOutcomeTest extends TestCase
{
    use RefreshDatabase;

    private const RULE = 'dns_txt_query_pattern';

    private function finding(array $attributes = []): AdvisoryFinding
    {
        return app(AdvisoryFindingService::class)->upsertFromShadow($attributes + [
            'rule_id' => self::RULE,
            'domain' => 'network',
            'source_topic' => 'xdr.alerts.shadow.network',
            'alert_type' => self::RULE,
            'tenant_id' => 'tenant-a',
            'fingerprint' => fake()->uuid(),
            'confidence' => 0.90,
        ]);
    }

    public function test_http_review_records_false_positive_and_hides_or_rejects_nomination(): void
    {
        $finding = $this->finding();
        $uri = route('advisory.findings.review', $finding->finding_id);
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->withHeader('X-Tenant-ID', 'tenant-a');

        $this->post($uri, ['action' => 'review', 'review_outcome' => 'false_positive'])
            ->assertSessionHasErrors('note');
        $this->post($uri, ['action' => 'review', 'review_outcome' => 'unspecified', 'note' => 'Review'])
            ->assertSessionHasErrors('review_outcome');
        $this->post($uri, [
            'action' => 'review', 'review_outcome' => 'false_positive', 'note' => 'Documented resolver check.',
        ])->assertRedirect();

        $this->assertSame('false_positive', $finding->fresh()->review_outcome);
        $event = $finding->events()->where('event_type', 'reviewed')->sole();
        $this->assertSame('false_positive', $event->metadata['review_outcome']);
        $this->assertSame('tenant-a', $event->tenant_id);
        $this->assertSame($user->id, $event->analyst_id);
        $this->get(route('advisory.findings.show', $finding->finding_id))
            ->assertOk()
            ->assertSee('Documented resolver check.')
            ->assertDontSee('value="nominate"', false);
        $this->post($uri, ['action' => 'nominate'])->assertSessionHasErrors('action');
        $this->assertDatabaseMissing('advisory_finding_events', [
            'finding_id' => $finding->finding_id, 'event_type' => 'nominated_for_promotion',
        ]);
        $this->assertDatabaseCount('security_alerts', 0);
        $this->assertDatabaseCount('security_incidents', 0);
    }

    public function test_latest_explicit_review_is_used_even_on_a_stale_finding_and_recurrence(): void
    {
        $finding = $this->finding(['fingerprint' => 'recurring-dns']);
        $service = app(AdvisoryFindingService::class);
        $user = User::factory()->create();
        $service->review($finding, $user->id, 'Insufficient trace context.', 'inconclusive');
        $service->review($finding, $user->id, 'Additional note only.');
        foreach (range(1, 3) as $_) {
            $this->finding(['fingerprint' => 'recurring-dns']);
        }
        $this->assertSame('inconclusive', $finding->fresh()->review_outcome);
        $this->assertFalse($finding->fresh()->promotion_candidate);
        try {
            $service->nominate($finding, $user->id);
            $this->fail('Nomination used the stale model instead of the saved verdict');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('action', $exception->errors());
        }

        $service->review($finding, $user->id, 'Corroborated by reviewed scope.', 'confirmed_detection');
        $revised = $finding->events()->where('event_type', 'reviewed')->latest('id')->firstOrFail();
        $this->assertSame('inconclusive', $revised->metadata['previous_review_outcome']);
        $this->assertSame('confirmed_detection', $revised->metadata['review_outcome']);
        $this->assertSame(3, $finding->events()->where('event_type', 'reviewed')->count());
        $this->assertSame('nominated', $service->nominate($finding, $user->id)->status);
    }

    public function test_audit_write_failure_rolls_back_the_verdict(): void
    {
        $finding = $this->finding();
        AdvisoryFindingEvent::creating(function (AdvisoryFindingEvent $event): void {
            if ($event->event_type === 'reviewed') {
                throw new RuntimeException('simulated audit insert failure');
            }
        });

        try {
            app(AdvisoryFindingService::class)->review($finding, User::factory()->create()->id,
                'Known normal resolver traffic.', 'false_positive');
            $this->fail('Review unexpectedly succeeded without audit');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit insert failure', $exception->getMessage());
        }

        $this->assertSame('new', $finding->fresh()->status);
        $this->assertNull($finding->fresh()->review_outcome);
    }

    public function test_cli_reports_only_one_tenant_rule_and_first_seen_cohort(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-27T14:15:00Z'));
        $service = app(AdvisoryFindingService::class);
        $analyst = User::factory()->create();
        $service->review($this->finding(), $analyst->id, 'Resolver maintenance.', 'false_positive');
        $service->review($this->finding(), $analyst->id, 'Evidence corroborated.', 'confirmed_detection');
        $service->review($this->finding(), $analyst->id, 'Need more logs.', 'inconclusive');
        $service->dismiss($this->finding(), $analyst->id, 'No analyst verdict.');
        $service->review($this->finding(['evidence' => ['shadow_evidence_origin' => 'rehearsal']]),
            $analyst->id, 'Rehearsal exercise.', 'false_positive');
        $this->finding(['tenant_id' => 'tenant-b']);
        $this->finding(['rule_id' => 'different_rule']);
        $this->travelTo(CarbonImmutable::parse('2026-11-27T15:00:00Z'));
        $this->finding();

        $args = [
            '--tenant' => 'tenant-a', '--rule' => self::RULE,
            '--start' => '2026-11-27T14:00:00Z', '--end' => '2026-11-27T15:00:00Z',
        ];
        $this->assertSame(0, Artisan::call('advisory:review-summary', $args));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(4, $report['findings']);
        $this->assertEquals(['confirmed_detection' => 1, 'false_positive' => 1, 'inconclusive' => 1, 'unclassified' => 1],
            $report['outcomes']);
        $this->assertEquals(0.5, $report['definitive_review_coverage']);
        $this->assertEquals(0.5, $report['false_positive_share_of_definitive_reviews']);
        $this->assertFalse($report['promotion_evidence_verified']);
        $this->assertSame(0, Artisan::call('advisory:review-summary', ['--rule' => 'unknown'] + $args));
        $empty = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNull($empty['false_positive_share_of_definitive_reviews']);
        $this->assertSame(2, Artisan::call('advisory:review-summary', ['--start' => '2026-11-32T00:00:00Z'] + $args));
    }
}
