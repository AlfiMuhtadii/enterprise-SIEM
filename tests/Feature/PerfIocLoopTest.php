<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PERF-IOC-LOOP — batched IOC enrichment (bulk ioc_hits insert + one evidence
 * UPDATE per matched alert) with preserved match semantics.
 */
class PerfIocLoopTest extends TestCase
{
    use RefreshDatabase;

    private function seedAlert(string $alertId, string $ip, array $evidence = []): void
    {
        DB::table('security_alerts')->insert([
            'alert_id' => $alertId,
            'alert_fingerprint' => 'fp-'.$alertId,
            'dedup_group' => 'g|'.$ip,
            'is_suppressed' => false,
            'detected_at' => now(),
            'alert_type' => 'NETWORK_IOC',
            'detector_name' => 'NETWORK_IOC',
            'detector_version' => 'v1',
            'severity' => 'medium',
            'ip' => $ip,
            'actor_key' => $ip,
            'score' => 0.8,
            'evidence' => json_encode($evidence ?: ['ip' => $ip]),
            'raw_event' => json_encode(['src_ip' => $ip]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedIoc(string $type, string $value, string $reputation = 'malicious'): void
    {
        DB::table('threat_iocs')->insert([
            'ioc_id' => 'ioc-'.$type.'-'.md5($value),
            'ioc_type' => $type,
            'ioc_value' => $value,
            'source' => 'unit',
            'reputation' => $reputation,
            'threat_label' => 'test',
            'expires_at' => null,
            'enabled' => true,
            'metadata' => json_encode([]),
            'created_by' => 'unit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function enrich(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)->post('/soc/threat-intel/enrich')->assertRedirect();
    }

    public function test_single_match_inserts_hit_and_enriches_evidence(): void
    {
        $this->seedAlert('alert-1', '203.0.113.10');
        $this->seedIoc('ip', '203.0.113.10');

        $this->enrich();

        $this->assertDatabaseHas('ioc_hits', [
            'alert_id' => 'alert-1',
            'matched_value' => '203.0.113.10',
        ]);
        $evidence = json_decode(DB::table('security_alerts')->where('alert_id', 'alert-1')->value('evidence'), true);
        $this->assertCount(1, $evidence['ioc_matches']);
        $this->assertSame('203.0.113.10', $evidence['ioc_matches'][0]['value']);
    }

    public function test_alert_matching_multiple_iocs_persists_all_matches(): void
    {
        // alert evidence contains both the IP and a domain so two IOCs match it
        $this->seedAlert('alert-multi', '203.0.113.20', ['ip' => '203.0.113.20', 'domain' => 'evil.example']);
        $this->seedIoc('ip', '203.0.113.20');
        $this->seedIoc('domain', 'evil.example', 'suspicious');

        $this->enrich();

        // both ioc_hits rows recorded
        $this->assertSame(2, DB::table('ioc_hits')->where('alert_id', 'alert-multi')->count());

        // both matches persisted in evidence (previously only the last survived)
        $evidence = json_decode(DB::table('security_alerts')->where('alert_id', 'alert-multi')->value('evidence'), true);
        $this->assertCount(2, $evidence['ioc_matches']);
        $values = array_column($evidence['ioc_matches'], 'value');
        $this->assertContains('203.0.113.20', $values);
        $this->assertContains('evil.example', $values);
    }

    public function test_no_iocs_produces_no_hits(): void
    {
        $this->seedAlert('alert-none', '203.0.113.30');

        $this->enrich();

        $this->assertSame(0, DB::table('ioc_hits')->count());
    }

    public function test_non_matching_ioc_does_not_enrich(): void
    {
        $this->seedAlert('alert-x', '203.0.113.40');
        $this->seedIoc('ip', '198.51.100.99'); // not present in the alert

        $this->enrich();

        $this->assertSame(0, DB::table('ioc_hits')->count());
        $evidence = json_decode(DB::table('security_alerts')->where('alert_id', 'alert-x')->value('evidence'), true);
        $this->assertArrayNotHasKey('ioc_matches', $evidence);
    }

    public function test_repeated_enrich_appends_hits_no_unique_dedup(): void
    {
        // ioc_hits has no unique key — behavior is plain insert, not de-dup.
        $this->seedAlert('alert-rep', '203.0.113.50');
        $this->seedIoc('ip', '203.0.113.50');

        $this->enrich();
        $this->enrich();

        $this->assertSame(2, DB::table('ioc_hits')->where('alert_id', 'alert-rep')->count());
    }
}
