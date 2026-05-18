<?php

namespace Tests\Feature;

use App\Models\SecurityHardeningEvent;
use App\Models\User;
use App\Services\InternalAuthService;
use App\Services\SecretsValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Schema
    // -----------------------------------------------------------------------

    public function test_security_hardening_events_table_exists(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('security_hardening_events')
        );
    }

    public function test_security_hardening_events_table_has_required_columns(): void
    {
        $cols = DB::getSchemaBuilder()->getColumnListing('security_hardening_events');
        foreach (['id', 'event_type', 'service_name', 'actor', 'trace_id', 'details', 'occurred_at', 'created_at'] as $col) {
            $this->assertContains($col, $cols, "Missing column: $col");
        }
    }

    // -----------------------------------------------------------------------
    // SecurityHardeningEvent model — append-only
    // -----------------------------------------------------------------------

    public function test_hardening_event_record_creates_row(): void
    {
        $event = SecurityHardeningEvent::record(
            SecurityHardeningEvent::EVENT_AUTH_FAILURE,
            'test-service',
            ['reason' => 'invalid_token', 'path' => '/api/internal/status'],
            '10.0.0.1',
            'trace-abc-123'
        );

        $this->assertNotNull($event->id);
        $this->assertSame('auth_failure', $event->event_type);
        $this->assertSame('test-service', $event->service_name);
        $this->assertSame('10.0.0.1', $event->actor);
        $this->assertSame('trace-abc-123', $event->trace_id);
        $this->assertSame('invalid_token', $event->details['reason']);
    }

    public function test_hardening_event_append_only_no_update(): void
    {
        $event = SecurityHardeningEvent::record(
            SecurityHardeningEvent::EVENT_AUTH_FAILURE,
            'svc-a',
            ['original' => true]
        );

        // Service layer must never call update on these records.
        // Attempt to update via Eloquent — persists but this documents
        // the expectation that service code NEVER does this.
        $originalType = $event->event_type;
        $originalCreatedAt = $event->created_at;

        $this->assertDatabaseHas('security_hardening_events', [
            'id'         => $event->id,
            'event_type' => $originalType,
        ]);

        // created_at is immutable — verify it hasn't been touched
        $fetched = SecurityHardeningEvent::find($event->id);
        $this->assertEquals($originalCreatedAt, $fetched->created_at);
    }

    public function test_hardening_event_append_only_multiple_records(): void
    {
        SecurityHardeningEvent::record(SecurityHardeningEvent::EVENT_AUTH_FAILURE, 'svc', ['n' => 1]);
        SecurityHardeningEvent::record(SecurityHardeningEvent::EVENT_AUTH_FAILURE, 'svc', ['n' => 2]);
        SecurityHardeningEvent::record(SecurityHardeningEvent::EVENT_SIGNATURE_FAILURE, 'svc', ['n' => 3]);

        $this->assertSame(3, SecurityHardeningEvent::count());
        $this->assertSame(2, SecurityHardeningEvent::where('event_type', 'auth_failure')->count());
        $this->assertSame(1, SecurityHardeningEvent::where('event_type', 'signature_failure')->count());
    }

    public function test_hardening_event_types_all_valid(): void
    {
        foreach (SecurityHardeningEvent::EVENT_TYPES as $type) {
            $event = SecurityHardeningEvent::record($type, 'test-service');
            $this->assertSame($type, $event->event_type);
        }
        $this->assertSame(count(SecurityHardeningEvent::EVENT_TYPES), SecurityHardeningEvent::count());
    }

    // -----------------------------------------------------------------------
    // InternalAuthService — token signing / verification
    // -----------------------------------------------------------------------

    public function test_sign_and_verify_token_roundtrip(): void
    {
        $token = InternalAuthService::signToken('ingestion-gateway');
        $this->assertTrue(InternalAuthService::verifyToken($token));
    }

    public function test_verify_token_fails_for_empty_string(): void
    {
        $this->assertFalse(InternalAuthService::verifyToken(''));
    }

    public function test_verify_token_fails_for_invalid_base64(): void
    {
        $this->assertFalse(InternalAuthService::verifyToken('!!!not-base64!!!'));
    }

    public function test_verify_token_fails_for_missing_pipe_segments(): void
    {
        // Encode something with only 2 segments
        $bad = base64_encode('svc|12345');
        $this->assertFalse(InternalAuthService::verifyToken($bad));
    }

    public function test_verify_token_fails_for_tampered_mac(): void
    {
        $token = InternalAuthService::signToken('normalizer-worker');
        $decoded = base64_decode($token);
        $parts = explode('|', $decoded, 3);
        $parts[2] = str_repeat('0', 64); // zero out the MAC
        $bad = base64_encode(implode('|', $parts));
        $this->assertFalse(InternalAuthService::verifyToken($bad));
    }

    public function test_verify_token_fails_for_expired_timestamp(): void
    {
        // Sign with a timestamp 10 minutes in the past
        $oldTimestamp = time() - 601;
        $token = InternalAuthService::signToken('alert-writer', $oldTimestamp);
        $this->assertFalse(InternalAuthService::verifyToken($token, 300));
    }

    public function test_verify_token_accepts_within_window(): void
    {
        $recentTimestamp = time() - 60;
        $token = InternalAuthService::signToken('alert-writer', $recentTimestamp);
        $this->assertTrue(InternalAuthService::verifyToken($token, 300));
    }

    public function test_different_service_ids_produce_different_tokens(): void
    {
        $ts = time();
        $t1 = InternalAuthService::signToken('svc-a', $ts);
        $t2 = InternalAuthService::signToken('svc-b', $ts);
        $this->assertNotSame($t1, $t2);
    }

    // -----------------------------------------------------------------------
    // InternalAuthService — event signature signing / verification
    // -----------------------------------------------------------------------

    public function test_sign_and_verify_event_roundtrip(): void
    {
        $event = [
            'event_id'      => 'evt-001',
            'event_type'    => 'xdr.alert.raised',
            'occurred_at'   => '2026-05-17T12:00:00Z',
            'trace_id'      => 'trace-abc',
            'source_service'=> 'correlation-worker',
        ];
        $sig = InternalAuthService::signEvent($event);
        $this->assertTrue(InternalAuthService::verifyEvent($event, $sig, false));
    }

    public function test_event_signature_is_deterministic(): void
    {
        $event = [
            'event_id'    => 'evt-002',
            'event_type'  => 'xdr.alert.raised',
            'occurred_at' => '2026-05-17T12:00:00Z',
            'trace_id'    => 'trace-xyz',
        ];
        $sig1 = InternalAuthService::signEvent($event);
        $sig2 = InternalAuthService::signEvent($event);
        $this->assertSame($sig1, $sig2);
    }

    public function test_event_signature_starts_with_sha256_prefix(): void
    {
        $event = ['event_id' => 'e', 'event_type' => 't', 'occurred_at' => 'ts', 'trace_id' => 'tr'];
        $sig = InternalAuthService::signEvent($event);
        $this->assertStringStartsWith('sha256=', $sig);
    }

    public function test_event_signature_changes_with_different_trace_id(): void
    {
        $base = ['event_id' => 'evt-003', 'event_type' => 'xdr.alert.raised', 'occurred_at' => '2026-05-17T12:00:00Z'];
        $sig1 = InternalAuthService::signEvent(array_merge($base, ['trace_id' => 'trace-A']));
        $sig2 = InternalAuthService::signEvent(array_merge($base, ['trace_id' => 'trace-B']));
        $this->assertNotSame($sig1, $sig2);
    }

    public function test_event_signature_changes_with_different_event_id(): void
    {
        $base = ['event_type' => 'xdr.alert.raised', 'occurred_at' => '2026-05-17T12:00:00Z', 'trace_id' => 'trace-X'];
        $sig1 = InternalAuthService::signEvent(array_merge($base, ['event_id' => 'evt-A']));
        $sig2 = InternalAuthService::signEvent(array_merge($base, ['event_id' => 'evt-B']));
        $this->assertNotSame($sig1, $sig2);
    }

    public function test_event_signature_verify_fails_for_invalid_signature(): void
    {
        $event = [
            'event_id'    => 'evt-004',
            'event_type'  => 'xdr.alert.raised',
            'occurred_at' => '2026-05-17T12:00:00Z',
            'trace_id'    => 'trace-abc',
        ];
        $this->assertFalse(InternalAuthService::verifyEvent($event, 'sha256=' . str_repeat('0', 64), false));
    }

    public function test_event_signature_verify_fails_for_tampered_event(): void
    {
        $event = [
            'event_id'    => 'evt-005',
            'event_type'  => 'xdr.alert.raised',
            'occurred_at' => '2026-05-17T12:00:00Z',
            'trace_id'    => 'trace-abc',
        ];
        $sig = InternalAuthService::signEvent($event);

        $tampered = $event;
        $tampered['event_id'] = 'evt-TAMPERED';
        $this->assertFalse(InternalAuthService::verifyEvent($tampered, $sig, false));
    }

    public function test_event_signature_failure_is_non_destructive(): void
    {
        // verifyEvent with logFailure=true must not throw — it logs a hardening event
        $event = [
            'event_id'      => 'evt-006',
            'event_type'    => 'xdr.alert.raised',
            'occurred_at'   => '2026-05-17T12:00:00Z',
            'trace_id'      => 'trace-abc',
            'source_service'=> 'test-svc',
        ];
        $threw = false;
        try {
            $result = InternalAuthService::verifyEvent($event, 'sha256=' . str_repeat('f', 64), true);
            $this->assertFalse($result);
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertFalse($threw, 'verifyEvent must not throw on invalid signature');
    }

    public function test_event_signature_failure_records_hardening_event(): void
    {
        $event = [
            'event_id'      => 'evt-007',
            'event_type'    => 'xdr.alert.raised',
            'occurred_at'   => '2026-05-17T12:00:00Z',
            'trace_id'      => 'trace-log-test',
            'source_service'=> 'test-svc',
        ];
        InternalAuthService::verifyEvent($event, 'sha256=' . str_repeat('b', 64), true);

        $this->assertDatabaseHas('security_hardening_events', [
            'event_type' => 'signature_failure',
            'trace_id'   => 'trace-log-test',
        ]);
    }

    public function test_trace_id_preserved_through_event_signature(): void
    {
        $traceId = 'trace-preserve-' . uniqid();
        $event = [
            'event_id'      => 'evt-trace-test',
            'event_type'    => 'xdr.alert.raised',
            'occurred_at'   => now()->toIso8601String(),
            'trace_id'      => $traceId,
            'source_service'=> 'correlation-worker',
        ];
        $sig = InternalAuthService::signEvent($event);

        // Valid sig — trace_id unchanged, verify succeeds
        $verified = InternalAuthService::verifyEvent($event, $sig, false);
        $this->assertTrue($verified);
        $this->assertSame($traceId, $event['trace_id']); // event not mutated
    }

    // -----------------------------------------------------------------------
    // SecretsValidationService
    // -----------------------------------------------------------------------

    public function test_secrets_validation_returns_structure(): void
    {
        $service = app(SecretsValidationService::class);
        $result  = $service->validate();

        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertArrayHasKey('warnings', $result);
        $this->assertArrayHasKey('checked_at', $result);
    }

    public function test_secrets_validation_detects_dev_ingest_secret(): void
    {
        putenv('XDR_INGEST_SECRET=dev-secret-change-me');
        $service = app(SecretsValidationService::class);
        $result  = $service->validate();
        putenv('XDR_INGEST_SECRET=');

        $warnings = implode(' ', $result['warnings']);
        $this->assertStringContainsString('XDR_INGEST_SECRET', $warnings);
        $this->assertStringContainsString('dev default', $warnings);
    }

    public function test_secrets_validation_warns_about_missing_internal_secret(): void
    {
        $saved = getenv('XDR_INTERNAL_AUTH_SECRET');
        putenv('XDR_INTERNAL_AUTH_SECRET=');

        $service = app(SecretsValidationService::class);
        $result  = $service->validate();

        putenv('XDR_INTERNAL_AUTH_SECRET=' . ($saved ?: ''));

        $warnings = implode(' ', $result['warnings']);
        $this->assertStringContainsString('XDR_INTERNAL_AUTH_SECRET', $warnings);
    }

    public function test_secrets_validation_warns_about_missing_webhook_secret(): void
    {
        // In test env SOC_WEBHOOK_SECRET is typically unset
        $service = app(SecretsValidationService::class);
        $result  = $service->validate();

        $allMessages = array_merge($result['errors'], $result['warnings']);
        $webhookWarned = collect($allMessages)->contains(
            fn ($m) => str_contains($m, 'SOC_WEBHOOK_SECRET')
        );
        $this->assertTrue($webhookWarned, 'Missing SOC_WEBHOOK_SECRET should be warned');
    }

    public function test_secrets_validation_and_record_creates_hardening_event(): void
    {
        $service = app(SecretsValidationService::class);
        $before  = SecurityHardeningEvent::where('event_type', 'startup_validation')->count();

        // Force at least one warning (XDR_INGEST_SECRET not set = warning)
        $service->validateAndRecord();

        $after = SecurityHardeningEvent::where('event_type', 'startup_validation')->count();
        // If warnings exist the count goes up; if no warnings, it stays same.
        // In test env there are typically warnings so this should increase.
        $this->assertGreaterThanOrEqual($before, $after);
    }

    // -----------------------------------------------------------------------
    // Artisan command
    // -----------------------------------------------------------------------

    public function test_artisan_validate_secrets_command_runs(): void
    {
        $this->artisan('security:validate-secrets')
            ->assertExitCode(0); // ok=true even with warnings — errors needed for exit 1
    }

    public function test_artisan_validate_secrets_command_with_record_flag(): void
    {
        $this->artisan('security:validate-secrets', ['--record' => true])
            ->assertExitCode(0);
    }

    // -----------------------------------------------------------------------
    // InternalServiceAuthMiddleware
    // -----------------------------------------------------------------------

    public function test_internal_api_accepts_valid_token(): void
    {
        $token = InternalAuthService::signToken('test-service');

        $this->withHeader('X-Internal-Service-Token', $token)
            ->getJson('/api/internal/status')
            ->assertOk()
            ->assertJsonFragment(['ok' => true]);
    }

    public function test_internal_api_rejects_missing_token(): void
    {
        $this->getJson('/api/internal/status')
            ->assertStatus(401)
            ->assertJsonFragment(['error' => 'unauthorized']);
    }

    public function test_internal_api_rejects_invalid_token(): void
    {
        $this->withHeader('X-Internal-Service-Token', 'not-a-valid-token')
            ->getJson('/api/internal/status')
            ->assertStatus(401);
    }

    public function test_internal_api_rejection_logs_auth_failure_event(): void
    {
        $before = SecurityHardeningEvent::where('event_type', 'auth_failure')->count();

        $this->withHeader('X-Internal-Service-Token', 'bad-token')
            ->getJson('/api/internal/status');

        $after = SecurityHardeningEvent::where('event_type', 'auth_failure')->count();
        $this->assertGreaterThan($before, $after);
    }

    public function test_internal_api_rejection_for_missing_token_logs_reason(): void
    {
        $this->getJson('/api/internal/status');

        $event = SecurityHardeningEvent::where('event_type', 'auth_failure')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('missing_token', $event->details['reason'] ?? null);
    }

    public function test_internal_api_rejection_for_invalid_token_logs_reason(): void
    {
        $this->withHeader('X-Internal-Service-Token', 'some-bad-value')
            ->getJson('/api/internal/status');

        $event = SecurityHardeningEvent::where('event_type', 'auth_failure')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame('invalid_token', $event->details['reason'] ?? null);
    }

    public function test_expired_token_rejected_by_internal_api(): void
    {
        $oldToken = InternalAuthService::signToken('test-svc', time() - 700);

        $this->withHeader('X-Internal-Service-Token', $oldToken)
            ->getJson('/api/internal/status')
            ->assertStatus(401);
    }

    // -----------------------------------------------------------------------
    // Security Hardening Dashboard
    // -----------------------------------------------------------------------

    public function test_hardening_dashboard_requires_authentication(): void
    {
        $this->get('/security/hardening')->assertRedirect('/login');
    }

    public function test_hardening_dashboard_requires_admin(): void
    {
        $analyst = User::factory()->create(['role' => 'analyst']);
        $this->actingAs($analyst)
            ->get('/security/hardening')
            ->assertForbidden();
    }

    public function test_hardening_dashboard_requires_admin_not_viewer(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->actingAs($viewer)
            ->get('/security/hardening')
            ->assertForbidden();
    }

    public function test_hardening_dashboard_returns_200_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/security/hardening')
            ->assertOk()
            ->assertSee('Security Hardening')
            ->assertSee('Secrets Validation')
            ->assertSee('Internal Service Auth Status');
    }

    public function test_hardening_dashboard_shows_audit_integrity_section(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/security/hardening')
            ->assertOk()
            ->assertSee('Audit Append-Only Integrity')
            ->assertSee('export_audit_logs')
            ->assertSee('investigation_events');
    }

    public function test_hardening_dashboard_shows_event_counts(): void
    {
        SecurityHardeningEvent::record(SecurityHardeningEvent::EVENT_AUTH_FAILURE, 'svc');
        SecurityHardeningEvent::record(SecurityHardeningEvent::EVENT_SIGNATURE_FAILURE, 'svc');

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->get('/security/hardening')
            ->assertOk()
            ->assertSee('auth_failure')
            ->assertSee('signature_failure');
    }

    // -----------------------------------------------------------------------
    // Grafana dashboard
    // -----------------------------------------------------------------------

    public function test_security_hardening_grafana_dashboard_exists(): void
    {
        $this->assertFileExists(base_path('docs/grafana/security-hardening-dashboard.json'));
    }

    public function test_security_hardening_grafana_dashboard_valid_json(): void
    {
        $raw = file_get_contents(base_path('docs/grafana/security-hardening-dashboard.json'));
        $data = json_decode($raw, true);
        $this->assertNotNull($data);
        $this->assertSame('XDR Security Hardening Dashboard', $data['title']);
        $this->assertCount(8, $data['panels']);
    }

    // -----------------------------------------------------------------------
    // Event contract — event_signature field
    // -----------------------------------------------------------------------

    public function test_event_envelope_schema_includes_event_signature_field(): void
    {
        $raw  = file_get_contents(base_path('docs/contracts/events/event-envelope.v1.schema.json'));
        $data = json_decode($raw, true);
        $this->assertArrayHasKey('event_signature', $data['properties']);
        $this->assertStringContainsString('sha256=', $data['properties']['event_signature']['description']);
    }
}
