<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-ALERT-TUNE — bulk suppression apply (single UPDATE + batched history
 * insert per rule) with preserved match semantics.
 */
class PerfAlertTuneTest extends TestCase
{
    use RefreshDatabase;

    private function seedAlert(string $alertId, string $type, string $ip, bool $suppressed = false): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_fingerprint' => 'fp-'.$alertId,
            'dedup_group' => $type.'|'.$ip,
            'is_suppressed' => $suppressed,
            'detected_at' => now(),
            'alert_type' => $type,
            'detector_name' => $type,
            'detector_version' => 'v1',
            'severity' => 'medium',
            'ip' => $ip,
            'actor_key' => $ip,
            'score' => 0.8,
            'evidence' => json_encode(['ip' => $ip]),
            'raw_event' => json_encode(['src_ip' => $ip]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedRule(string $scope, string $matchValue): void
    {
        DB::table('alert_suppression_rules')->insert([
            'suppression_id' => 'sup-'.$scope.'-'.md5($matchValue),
            'scope' => $scope,
            'match_value' => $matchValue,
            'rule_id' => null,
            'reason' => 'unit suppression',
            'enabled' => true,
            'expires_at' => null,
            'created_by' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function applySuppressions(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->post('/soc/tuning/suppressions/apply')->assertRedirect();
    }

    public function test_rule_suppresses_all_matching_alerts_with_history(): void
    {
        $this->seedAlert('a1', 'NOISY_RULE', '10.0.0.1');
        $this->seedAlert('a2', 'NOISY_RULE', '10.0.0.2');
        $this->seedAlert('a3', 'NOISY_RULE', '10.0.0.3');
        $this->seedRule('alert_type', 'NOISY_RULE');

        $this->applySuppressions();

        $this->assertSame(3, DB::table('security_alerts')->where('is_suppressed', true)->count());
        $this->assertSame(3, DB::table('alert_suppression_history')->count());
        foreach (['a1', 'a2', 'a3'] as $id) {
            $this->assertDatabaseHas('alert_suppression_history', ['alert_id' => $id]);
        }
    }

    public function test_already_suppressed_alerts_are_not_reprocessed(): void
    {
        $this->seedAlert('done', 'NOISY_RULE', '10.0.0.9', suppressed: true);
        $this->seedAlert('fresh', 'NOISY_RULE', '10.0.0.10');
        $this->seedRule('alert_type', 'NOISY_RULE');

        $this->applySuppressions();

        // only the previously-unsuppressed alert gets a history row
        $this->assertSame(1, DB::table('alert_suppression_history')->count());
        $this->assertDatabaseHas('alert_suppression_history', ['alert_id' => 'fresh']);
        $this->assertDatabaseMissing('alert_suppression_history', ['alert_id' => 'done']);
    }

    public function test_non_matching_alerts_are_untouched(): void
    {
        $this->seedAlert('match', 'NOISY_RULE', '10.0.0.1');
        $this->seedAlert('other', 'CLEAN_RULE', '10.0.0.2');
        $this->seedRule('alert_type', 'NOISY_RULE');

        $this->applySuppressions();

        $this->assertDatabaseHas('security_alerts', ['alert_id' => 'match', 'is_suppressed' => true]);
        $this->assertDatabaseHas('security_alerts', ['alert_id' => 'other', 'is_suppressed' => false]);
        $this->assertSame(1, DB::table('alert_suppression_history')->count());
    }

    public function test_ip_scope_rule_matches_by_ip(): void
    {
        $this->seedAlert('ip-a', 'ANY', '198.51.100.7');
        $this->seedAlert('ip-b', 'ANY', '198.51.100.8');
        $this->seedRule('ip', '198.51.100.7');

        $this->applySuppressions();

        $this->assertDatabaseHas('security_alerts', ['alert_id' => 'ip-a', 'is_suppressed' => true]);
        $this->assertDatabaseHas('security_alerts', ['alert_id' => 'ip-b', 'is_suppressed' => false]);
    }
}
