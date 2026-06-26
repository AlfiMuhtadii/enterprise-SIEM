<?php

namespace Tests\Feature;

use App\Models\RealEndpointEnrollment;
use App\Models\User;
use App\Services\RealEndpointEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ENTERPRISE-053: Real Endpoint Telemetry Enrollment
 *
 * Validates: token issuance, enrollment recording, max cap,
 * advisory safety, dry-run, validate flow, routes.
 */
class RealEndpointEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    private RealEndpointEnrollmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RealEndpointEnrollmentService::class);
    }

    // ── Safety constants ───────────────────────────────────────────────────────

    public function test_advisory_only_is_true(): void
    {
        $this->assertTrue(RealEndpointEnrollmentService::ADVISORY_ONLY);
    }

    public function test_max_enrollments_is_20(): void
    {
        $this->assertSame(20, RealEndpointEnrollmentService::MAX_ENROLLMENTS);
    }

    // ── issueToken ────────────────────────────────────────────────────────────

    public function test_issue_token_returns_required_fields(): void
    {
        $token = $this->service->issueToken('my-host', 'pilot-001', 'windows');
        $this->assertArrayHasKey('enrollment_id', $token);
        $this->assertArrayHasKey('enrollment_token', $token);
        $this->assertArrayHasKey('hostname', $token);
        $this->assertSame('my-host', $token['hostname']);
        $this->assertSame('windows', $token['os_platform']);
        $this->assertTrue($token['is_advisory']);
    }

    public function test_issue_token_has_xdr_enroll_prefix(): void
    {
        $token = $this->service->issueToken('host');
        $this->assertStringStartsWith('xdr-enroll-', $token['enrollment_token']);
    }

    public function test_issue_token_invalid_platform_defaults_to_unknown(): void
    {
        $token = $this->service->issueToken('host', '', 'bsd');
        $this->assertSame('unknown', $token['os_platform']);
    }

    // ── recordEnrollment dry-run ──────────────────────────────────────────────

    public function test_dry_run_returns_ok_true(): void
    {
        $result = $this->service->recordEnrollment(['hostname' => 'test-host'], true);
        $this->assertTrue($result['ok']);
    }

    public function test_dry_run_does_not_persist(): void
    {
        $this->service->recordEnrollment(['hostname' => 'test-host'], true);
        $this->assertDatabaseCount('real_endpoint_enrollments', 0);
    }

    public function test_dry_run_result_is_advisory_true(): void
    {
        $result = $this->service->recordEnrollment([], true);
        $this->assertTrue($result['is_advisory']);
    }

    // ── recordEnrollment with persistence ────────────────────────────────────

    public function test_persist_creates_enrollment_row(): void
    {
        $this->service->recordEnrollment([
            'hostname'    => 'my-win-host',
            'os_platform' => 'windows',
            'is_real'     => true,
        ], false);
        $this->assertDatabaseCount('real_endpoint_enrollments', 1);
        $this->assertDatabaseHas('real_endpoint_enrollments', ['hostname' => 'my-win-host']);
    }

    public function test_persist_stores_is_real_true(): void
    {
        $this->service->recordEnrollment(['hostname' => 'real-host', 'is_real' => true], false);
        $e = RealEndpointEnrollment::first();
        $this->assertTrue($e->is_real);
    }

    public function test_max_enrollment_cap(): void
    {
        for ($i = 0; $i < RealEndpointEnrollmentService::MAX_ENROLLMENTS; $i++) {
            $this->service->recordEnrollment(['hostname' => "host-{$i}"], false);
        }
        $result = $this->service->recordEnrollment(['hostname' => 'overflow'], false);
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('MAX_ENROLLMENTS', $result['error']);
    }

    // ── validateEnrollment ────────────────────────────────────────────────────

    public function test_validate_unknown_token_returns_invalid(): void
    {
        $result = $this->service->validateEnrollment('xdr-enroll-bogus');
        $this->assertFalse($result['valid']);
        $this->assertSame('token_not_found', $result['reason']);
    }

    public function test_validate_known_token_returns_valid(): void
    {
        $token = $this->service->issueToken('my-host', '', 'linux');
        $this->service->recordEnrollment($token, false);
        $result = $this->service->validateEnrollment($token['enrollment_token']);
        $this->assertTrue($result['valid']);
        $this->assertSame('my-host', $result['hostname']);
        $this->assertTrue($result['is_advisory']);
    }

    // ── Routes ────────────────────────────────────────────────────────────────

    public function test_route_requires_auth(): void
    {
        $this->get('/endpoint-enrollments')
            ->assertRedirect('/login');
    }

    public function test_admin_can_access_enrollment_index(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/endpoint-enrollments')
            ->assertStatus(200)
            ->assertSeeText('Real Endpoint Enrollments');
    }

    public function test_json_api_returns_advisory_true(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->withHeaders(['Accept' => 'application/json'])
            ->getJson('/endpoint-enrollments')
            ->assertStatus(200)
            ->assertJsonPath('advisory_only', true);
    }
}
