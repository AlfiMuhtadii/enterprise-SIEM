<?php

namespace Tests\Feature;

use App\Models\AdvisoryFinding;
use App\Services\DomainSoakHarnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ENTERPRISE-072 — Shadow Domain Soak Pre-Flight tests.
 */
class ShadowSoakPreflightTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------------
    // DomainSoakHarnessService::getPreflightStatus()
    // -------------------------------------------------------------------------

    public function test_get_preflight_status_returns_required_keys(): void
    {
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('endpoint');
        $this->assertArrayHasKey('domain', $result);
        $this->assertArrayHasKey('preflight_ready', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('note', $result);
    }

    public function test_get_preflight_status_returns_correct_domain(): void
    {
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('network');
        $this->assertSame('network', $result['domain']);
    }

    public function test_get_preflight_status_returns_five_checks(): void
    {
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('ueba');
        $this->assertCount(5, $result['checks']);
    }

    public function test_get_preflight_status_rejects_invalid_domain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DomainSoakHarnessService::class)->getPreflightStatus('invalid_domain');
    }

    public function test_get_preflight_status_advisory_note_present(): void
    {
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('endpoint');
        $this->assertStringContainsString('Advisory', $result['note']);
    }

    public function test_get_preflight_status_promotion_never_recommended(): void
    {
        // Preflight is read-only and never recommends promotion in its check results
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('endpoint');
        $this->assertArrayHasKey('CHK-05', $result['checks']);
        $this->assertSame('PASS', $result['checks']['CHK-05']['status']);
    }

    public function test_get_preflight_status_check01_domain_supported(): void
    {
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('endpoint');
        $this->assertArrayHasKey('CHK-01', $result['checks']);
        $this->assertSame('PASS', $result['checks']['CHK-01']['status']);
    }

    public function test_get_preflight_status_check02_shadow_rules(): void
    {
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('endpoint');
        $this->assertArrayHasKey('CHK-02', $result['checks']);
        $this->assertContains($result['checks']['CHK-02']['status'], ['PASS', 'WARN']);
    }

    public function test_get_preflight_status_check04_no_active_run(): void
    {
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('network');
        $this->assertArrayHasKey('CHK-04', $result['checks']);
        $this->assertSame('PASS', $result['checks']['CHK-04']['status']);
    }

    public function test_get_preflight_status_expected_shadow_rules_returned(): void
    {
        File::shouldReceive('get')->with('test-registry.json')->andReturn(json_encode(['rules' => [
            ['rule_id' => 'ep-a', 'status' => 'shadow', 'domain' => 'endpoint', 'output_topic' => 'xdr.alerts.shadow.endpoint'],
            ['rule_id' => 'ep-b', 'status' => 'shadow', 'domain' => 'endpoint', 'output_topic' => 'xdr.alerts.shadow.ueba'],
            ['rule_id' => 'net-a', 'status' => 'shadow', 'domain' => 'network', 'output_topic' => 'xdr.alerts.shadow.network'],
            ['rule_id' => 'ti-ep', 'status' => 'shadow', 'domain' => 'threat-intel', 'output_topic' => 'xdr.alerts.shadow.endpoint'],
            ['rule_id' => 'generic-ep', 'status' => 'shadow', 'domain' => 'endpoint', 'output_topic' => 'xdr.alerts.shadow'],
            ['rule_id' => 'cloud-ueba', 'status' => 'shadow', 'domain' => 'cloud', 'output_topic' => 'xdr.alerts.shadow.ueba'],
            ['rule_id' => 'non-shadow', 'status' => 'draft', 'domain' => 'endpoint', 'output_topic' => 'xdr.alerts.shadow.endpoint'],
        ]], JSON_THROW_ON_ERROR));
        $harness = new DomainSoakHarnessService('test-registry.json');

        foreach (['endpoint' => 2, 'network' => 1, 'ueba' => 2] as $domain => $count) {
            $result = $harness->getPreflightStatus($domain);
            $this->assertSame($count, $result['expected_shadow_rules']);
            $this->assertSame('PASS', $result['checks']['CHK-02']['status']);
        }
    }

    public function test_missing_or_invalid_registry_blocks_preflight_even_with_enough_findings(): void
    {
        for ($i = 0; $i < DomainSoakHarnessService::MIN_FINDINGS_FOR_SOAK; $i++) {
            AdvisoryFinding::create([
                'finding_id' => 'af-'.Str::uuid(),
                'rule_id' => 'test-rule',
                'domain' => 'endpoint',
                'source_topic' => 'xdr.alerts.shadow.endpoint',
                'alert_type' => 'test_alert',
                'severity' => 'medium',
                'confidence' => 0.8,
                'fingerprint' => Str::random(32),
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        File::shouldReceive('get')->with('invalid-registry.json')->once()->andReturn('{not valid json');
        File::shouldReceive('get')->with('missing-registry.json')->once()
            ->andThrow(\Illuminate\Contracts\Filesystem\FileNotFoundException::class);

        foreach (['invalid-registry.json', 'missing-registry.json'] as $path) {
            $result = (new DomainSoakHarnessService($path))->getPreflightStatus('endpoint');
            $this->assertSame('PASS', $result['checks']['CHK-03']['status']);
            $this->assertSame('FAIL', $result['checks']['CHK-02']['status']);
            $this->assertNull($result['expected_shadow_rules']);
            $this->assertFalse($result['preflight_ready']);
        }
    }

    // -------------------------------------------------------------------------
    // ShadowSoakPreflightCommand
    // -------------------------------------------------------------------------

    public function test_soak_preflight_command_class_exists(): void
    {
        $this->assertTrue(
            class_exists(\App\Console\Commands\ShadowSoakPreflightCommand::class),
            'ShadowSoakPreflightCommand should be registered'
        );
    }

    public function test_soak_preflight_command_runs_for_endpoint(): void
    {
        $this->artisan('domain:soak-preflight', ['domain' => 'endpoint'])
            ->assertExitCode(0);
    }

    public function test_soak_preflight_command_runs_for_network(): void
    {
        $this->artisan('domain:soak-preflight', ['domain' => 'network'])
            ->assertExitCode(0);
    }

    public function test_soak_preflight_command_runs_for_ueba(): void
    {
        $this->artisan('domain:soak-preflight', ['domain' => 'ueba'])
            ->assertExitCode(0);
    }

    public function test_soak_preflight_command_fails_for_invalid_domain(): void
    {
        $this->artisan('domain:soak-preflight', ['domain' => 'invalid'])
            ->assertExitCode(1);
    }

    // -------------------------------------------------------------------------
    // Advisory posture constants
    // -------------------------------------------------------------------------

    public function test_harness_advisory_only_constant(): void
    {
        $this->assertTrue(DomainSoakHarnessService::ADVISORY_ONLY);
    }

    public function test_harness_active_allowlist_mutation_forbidden(): void
    {
        $this->assertTrue(DomainSoakHarnessService::ACTIVE_ALLOWLIST_MUTATION_FORBIDDEN);
    }
}
