<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SocRuleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_rule_state_and_audit_is_recorded(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $path = storage_path('app/telemetry_rules.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'version' => 1,
            'rules' => [[
                'rule_id' => 'RULE-1',
                'rule_version' => '1.0.0',
                'enabled' => true,
                'name' => 'Rule one',
                'alert_type' => 'TEST_ALERT',
                'severity' => 'medium',
                'time_window_seconds' => 60,
                'required_event_types' => ['test_event'],
                'metadata' => ['owner' => 'old', 'status' => 'production'],
            ]],
        ]));

        $this->actingAs($admin)->post('/soc/rules/RULE-1', [
            'enabled' => '0',
            'severity_override' => 'high',
            'metadata_owner' => 'team-blue',
            'metadata_status' => 'testing',
        ])->assertRedirect(route('soc.rules'));

        $payload = json_decode(File::get($path), true);
        $this->assertFalse($payload['rules'][0]['enabled']);
        $this->assertSame('high', $payload['rules'][0]['severity_override']);
        $this->assertDatabaseHas('security_audit_trails', [
            'actor' => $admin->email,
            'action' => 'rule.update',
            'target_id' => 'RULE-1',
        ]);
    }
}
