<?php

namespace Tests\Feature;

use App\Services\AlertMitreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ATTR-001: MITRE ATT&CK TTP tagging — AlertMitreService + migration + tag command.
 */
class AlertMitreTtpTest extends TestCase
{
    use RefreshDatabase;

    private AlertMitreService $mitre;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mitre = new AlertMitreService();
    }

    // ── Service unit tests ────────────────────────────────────────────────────

    public function test_advisory_only_flag_is_true(): void
    {
        $this->assertTrue(AlertMitreService::ADVISORY_ONLY);
    }

    public function test_lookup_returns_null_for_unknown_alert_type(): void
    {
        $this->assertNull($this->mitre->lookup('COMPLETELY_UNKNOWN_RULE'));
    }

    public function test_lookup_returns_mapping_for_mfa_failure_burst(): void
    {
        $m = $this->mitre->lookup('IDENTITY_MFA_FAILURE_BURST');
        $this->assertNotNull($m);
        $this->assertSame('Credential Access', $m['tactic']);
        $this->assertSame('T1110', $m['technique_id']);
        $this->assertSame('Brute Force', $m['technique_name']);
    }

    public function test_lookup_returns_mapping_for_cloud_new_access_key(): void
    {
        $m = $this->mitre->lookup('CLOUD_NEW_ACCESS_KEY');
        $this->assertNotNull($m);
        $this->assertSame('Persistence', $m['tactic']);
        $this->assertSame('T1098.001', $m['technique_id']);
    }

    public function test_lookup_returns_mapping_for_cloud_security_setting_modified(): void
    {
        $m = $this->mitre->lookup('CLOUD_SECURITY_SETTING_MODIFIED');
        $this->assertNotNull($m);
        $this->assertSame('Defense Evasion', $m['tactic']);
        $this->assertSame('T1562', $m['technique_id']);
    }

    public function test_lookup_returns_mapping_for_saas_unusual_admin(): void
    {
        $m = $this->mitre->lookup('SAAS_UNUSUAL_ADMIN_ACTIVITY');
        $this->assertNotNull($m);
        $this->assertSame('Initial Access', $m['tactic']);
        $this->assertSame('T1078', $m['technique_id']);
    }

    public function test_lookup_covers_all_12_staged_active_rules(): void
    {
        $stagedActive = [
            'IDENTITY_MFA_FAILURE_BURST',
            'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES',
            'IDENTITY_RISKY_IP_LOGIN',
            'IDENTITY_IMPOSSIBLE_TRAVEL',
            'IDENTITY_PRIVILEGE_ESCALATION',
            'IDENTITY_UNUSUAL_LOGIN_SOURCE',
            'CLOUD_UNUSUAL_API_ACTIVITY',
            'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
            'CLOUD_MASS_DOWNLOAD',
            'CLOUD_NEW_ACCESS_KEY',
            'CLOUD_SECURITY_SETTING_MODIFIED',
            'SAAS_UNUSUAL_ADMIN_ACTIVITY',
        ];
        foreach ($stagedActive as $ruleId) {
            $this->assertNotNull(
                $this->mitre->lookup($ruleId),
                "lookup() must return a mapping for staged_active rule {$ruleId}"
            );
        }
    }

    public function test_each_mapping_has_required_keys(): void
    {
        foreach ($this->mitre->mappedAlertTypes() as $alertType) {
            $m = $this->mitre->lookup($alertType);
            $this->assertIsArray($m, "mapping for {$alertType} must be an array");
            $this->assertArrayHasKey('tactic', $m);
            $this->assertArrayHasKey('technique_id', $m);
            $this->assertArrayHasKey('technique_name', $m);
            $this->assertNotEmpty($m['tactic']);
            $this->assertNotEmpty($m['technique_id']);
            $this->assertNotEmpty($m['technique_name']);
        }
    }

    public function test_mapped_count_covers_at_least_12_rules(): void
    {
        $this->assertGreaterThanOrEqual(12, $this->mitre->mappedCount());
    }

    public function test_mapped_alert_types_returns_array_of_strings(): void
    {
        $types = $this->mitre->mappedAlertTypes();
        $this->assertIsArray($types);
        foreach ($types as $t) {
            $this->assertIsString($t);
        }
    }

    // ── Migration: security_alerts columns ───────────────────────────────────

    public function test_security_alerts_has_mitre_columns(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('security_alerts', 'mitre_tactic')
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('security_alerts', 'mitre_technique_id')
        );
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasColumn('security_alerts', 'mitre_technique_name')
        );
    }

    // ── Artisan command: alerts:tag-mitre ────────────────────────────────────

    public function test_tag_mitre_command_dry_run_reports_zero_when_table_is_empty(): void
    {
        $this->artisan('alerts:tag-mitre --dry-run')
            ->expectsOutputToContain('0 rows')
            ->assertExitCode(0);
    }

    public function test_tag_mitre_command_tags_untagged_alerts(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'test-attr-001',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'IDENTITY_MFA_FAILURE_BURST',
            'severity'    => 'high',
        ]);

        $this->artisan('alerts:tag-mitre')
            ->expectsOutputToContain('tagged 1 rows')
            ->assertExitCode(0);

        $row = DB::table('security_alerts')->where('alert_id', 'test-attr-001')->first();
        $this->assertSame('Credential Access', $row->mitre_tactic);
        $this->assertSame('T1110', $row->mitre_technique_id);
        $this->assertSame('Brute Force', $row->mitre_technique_name);
    }

    public function test_tag_mitre_command_skips_already_tagged_alerts(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'           => 'test-attr-002',
            'detected_at'        => now()->format('Y-m-d H:i:sP'),
            'alert_type'         => 'CLOUD_MASS_DOWNLOAD',
            'severity'           => 'medium',
            'mitre_tactic'       => 'Collection',
            'mitre_technique_id' => 'T1530',
        ]);

        $this->artisan('alerts:tag-mitre')
            ->expectsOutputToContain('tagged 0 rows')
            ->assertExitCode(0);
    }

    public function test_tag_mitre_command_does_not_tag_unknown_alert_types(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'test-attr-003',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'UNKNOWN_SHADOW_RULE',
            'severity'    => 'low',
        ]);

        $this->artisan('alerts:tag-mitre')
            ->expectsOutputToContain('tagged 0 rows')
            ->assertExitCode(0);

        $row = DB::table('security_alerts')->where('alert_id', 'test-attr-003')->first();
        $this->assertNull($row->mitre_tactic);
    }

    public function test_tag_mitre_dry_run_does_not_write(): void
    {
        DB::table('security_alerts')->insert([
            'alert_id'    => 'test-attr-004',
            'detected_at' => now()->format('Y-m-d H:i:sP'),
            'alert_type'  => 'IDENTITY_RISKY_IP_LOGIN',
            'severity'    => 'high',
        ]);

        $this->artisan('alerts:tag-mitre --dry-run')
            ->expectsOutputToContain('would tag 1 rows')
            ->assertExitCode(0);

        $row = DB::table('security_alerts')->where('alert_id', 'test-attr-004')->first();
        $this->assertNull($row->mitre_tactic, 'dry-run must not write to database');
    }

    // ── Controller: MITRE columns fetched ────────────────────────────────────

    public function test_alerts_index_returns_200(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)
            ->get(route('security.alerts'))
            ->assertStatus(200);
    }

    public function test_alerts_index_view_contains_attack_header(): void
    {
        $user = \App\Models\User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)
            ->get(route('security.alerts'))
            ->assertSee('ATT&amp;CK', false);
    }
}
