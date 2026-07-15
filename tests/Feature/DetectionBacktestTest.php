<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DetectionBacktestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CAP-DETECT-BACKTEST — ports the exact thresholds from
 * scripts/xdr_correlation_detector.py's detect_identity()/detect_cloud_saas()
 * into PHP, evaluated read-only against telemetry_events. Must never write
 * to security_alerts/security_incidents.
 */
class DetectionBacktestTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function seedEvent(string $telemetryType, array $overrides = []): void
    {
        $this->seq++;
        DB::table('telemetry_events')->insert(array_merge([
            'ts' => now(),
            'event_id' => 'evt-'.$this->seq,
            'telemetry_type' => $telemetryType,
            'event_type' => 'generic',
            'payload' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_mfa_failure_burst_fires_at_five_failed_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'alice']);
        }

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', [
            'run_id' => $run->run_id,
            'rule_id' => 'IDENTITY_MFA_FAILURE_BURST',
            'actor_key' => 'alice',
        ]);
    }

    public function test_mfa_failure_burst_does_not_fire_below_threshold(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'bob']);
        }

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 7);

        $this->assertSame(0, $run->matches()->count());
    }

    public function test_failed_login_across_services_requires_two_distinct_sources(): void
    {
        for ($i = 0; $i < 2; $i++) {
            $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'carol', 'event_source' => 'okta']);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'carol', 'event_source' => 'azuread']);
        }

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_FAILED_LOGIN_ACROSS_SERVICES'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => 'carol']);
    }

    public function test_risky_ip_login_fires_on_high_risk_success(): void
    {
        $this->seedEvent('identity', ['event_type' => 'login_success', 'xdr_user' => 'dave', 'risk_score' => 0.9]);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_RISKY_IP_LOGIN'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => 'dave']);
    }

    public function test_impossible_travel_requires_two_distinct_source_ips(): void
    {
        $this->seedEvent('identity', ['event_type' => 'login_success', 'xdr_user' => 'erin', 'source_ip' => '1.1.1.1']);
        $this->seedEvent('identity', ['event_type' => 'login_success', 'xdr_user' => 'erin', 'source_ip' => '2.2.2.2']);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_IMPOSSIBLE_TRAVEL'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => 'erin']);
    }

    public function test_unusual_login_source_groups_by_ip_not_user(): void
    {
        foreach (['frank', 'grace', 'heidi'] as $user) {
            $this->seedEvent('identity', ['xdr_user' => $user, 'source_ip' => '9.9.9.9']);
        }

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_UNUSUAL_LOGIN_SOURCE'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => '9.9.9.9']);
    }

    public function test_cloud_mass_download_fires_at_ten_events(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->seedEvent('cloud', ['event_type' => 'file_download', 'xdr_user' => 'ivan']);
        }

        $run = app(DetectionBacktestService::class)->run(['CLOUD_MASS_DOWNLOAD'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => 'ivan']);
    }

    public function test_cloud_new_access_key_fires_on_single_event(): void
    {
        $this->seedEvent('cloud', ['xdr_action' => 'CreateAccessKey', 'cloud_account' => 'acct-1']);

        $run = app(DetectionBacktestService::class)->run(['CLOUD_NEW_ACCESS_KEY'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => 'acct-1']);
    }

    public function test_saas_unusual_admin_activity_requires_saas_domain_event(): void
    {
        $this->seedEvent('saas', ['xdr_action' => 'admin_settings_changed', 'xdr_user' => 'judy']);

        $run = app(DetectionBacktestService::class)->run(['SAAS_UNUSUAL_ADMIN_ACTIVITY'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => 'judy']);
    }

    public function test_actors_without_matches_produce_no_rows(): void
    {
        $this->seedEvent('identity', ['event_type' => 'login_success', 'xdr_user' => 'quiet_user']);

        $run = app(DetectionBacktestService::class)->run(DetectionBacktestService::IDENTITY_RULE_IDS, 7);

        $this->assertSame(0, $run->matches()->count());
    }

    public function test_run_records_window_and_event_count(): void
    {
        $this->seedEvent('identity', ['xdr_user' => 'someone']);
        $this->seedEvent('cloud', ['cloud_account' => 'acct-x']);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 14);

        $this->assertSame(2, $run->telemetry_event_count);
        $this->assertNotNull($run->window_start);
        $this->assertNotNull($run->window_end);
    }

    public function test_events_outside_window_are_excluded(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedEvent('identity', [
                'event_type' => 'login_failed',
                'xdr_user' => 'old_user',
                'ts' => now()->subDays(30),
            ]);
        }

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 7);

        $this->assertSame(0, $run->telemetry_event_count);
        $this->assertSame(0, $run->matches()->count());
    }

    public function test_rejects_unsupported_rule_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DetectionBacktestService::class)->run(['SOME_MADE_UP_RULE'], 7);
    }

    public function test_backtest_never_writes_to_security_alerts_or_incidents(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'kate']);
        }
        for ($i = 0; $i < 10; $i++) {
            $this->seedEvent('cloud', ['xdr_action' => 'CreateAccessKey', 'cloud_account' => 'acct-y']);
        }

        app(DetectionBacktestService::class)->run(DetectionBacktestService::SUPPORTED_RULE_IDS, 7);

        $this->assertSame(0, DB::table('security_alerts')->count());
        $this->assertSame(0, DB::table('security_incidents')->count());
    }

    /**
     * PERF-BACKTEST-OOM: the actor's matching events straddle a Postgres
     * chunkById() page boundary (CHUNK_SIZE rows), proving the per-actor
     * accumulator correctly carries state across chunks instead of
     * resetting or double-counting at the boundary.
     */
    public function test_actor_events_spanning_a_chunk_boundary_still_accumulate_correctly(): void
    {
        $total = DetectionBacktestService::CHUNK_SIZE + 5;
        $rows = [];
        for ($i = 0; $i < $total; $i++) {
            $rows[] = [
                'ts' => now(),
                'event_id' => 'evt-chunk-'.$i,
                'telemetry_type' => 'identity',
                'event_type' => 'login_failed',
                'xdr_user' => 'chunk_actor',
                'payload' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('telemetry_events')->insert($rows);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_MFA_FAILURE_BURST'], 7);

        $this->assertSame($total, $run->telemetry_event_count);
        $match = DB::table('detection_backtest_matches')
            ->where('run_id', $run->run_id)
            ->where('actor_key', 'chunk_actor')
            ->first();
        $this->assertNotNull($match);
        $this->assertSame($total, $match->event_count);
    }

    /**
     * Same boundary concern as above, but for the distinct-value-SET
     * accumulator (failed_event_sources) rather than a plain counter:
     * nina's first distinct-source failed login lands in the first
     * chunk, padding events push the boundary past it, and her second
     * distinct-source failed login lands in a later chunk.
     */
    public function test_failed_login_across_services_accumulates_correctly_across_chunk_boundary(): void
    {
        $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'nina', 'event_source' => 'okta']);
        $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'nina', 'event_source' => 'okta']);

        $padding = [];
        for ($i = 0; $i < DetectionBacktestService::CHUNK_SIZE + 10; $i++) {
            $padding[] = [
                'ts' => now(),
                'event_id' => 'evt-pad-'.$i,
                'telemetry_type' => 'identity',
                'event_type' => 'login_success',
                'xdr_user' => 'padding_'.$i,
                'payload' => json_encode([]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        DB::table('telemetry_events')->insert($padding);

        $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'nina', 'event_source' => 'azuread']);
        $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'nina', 'event_source' => 'azuread']);

        $run = app(DetectionBacktestService::class)->run(['IDENTITY_FAILED_LOGIN_ACROSS_SERVICES'], 7);

        $this->assertDatabaseHas('detection_backtest_matches', ['run_id' => $run->run_id, 'actor_key' => 'nina']);
    }

    public function test_index_route_requires_permission(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('detection.backtest.index'))->assertForbidden();
    }

    public function test_admin_can_run_backtest_via_http(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->seedEvent('identity', ['event_type' => 'login_failed', 'xdr_user' => 'leo']);
        }
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('detection.backtest.store'), [
            'rule_ids' => ['IDENTITY_MFA_FAILURE_BURST'],
            'days' => 7,
        ]);

        $response->assertRedirect();
        $this->assertSame(1, DB::table('detection_backtest_runs')->count());
    }
}
