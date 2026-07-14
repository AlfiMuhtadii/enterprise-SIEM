<?php

namespace Tests\Feature;

use App\Services\DomainSoakHarnessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $result = app(DomainSoakHarnessService::class)->getPreflightStatus('endpoint');
        $this->assertArrayHasKey('expected_shadow_rules', $result);
        $this->assertSame(
            DomainSoakHarnessService::DOMAIN_SHADOW_RULE_COUNTS['endpoint'],
            $result['expected_shadow_rules']
        );
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
