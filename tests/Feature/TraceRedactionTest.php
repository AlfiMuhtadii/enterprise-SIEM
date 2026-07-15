<?php

namespace Tests\Feature;

use App\Support\TraceRedactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TraceRedactionTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function admin(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'admin']);
    }

    private function seedAlert(string $traceId, array $override = []): string
    {
        $alertId = 'alert-'.substr($traceId, 0, 12).'-'.uniqid();
        DB::table('security_alerts')->insert(array_merge([
            'alert_id' => $alertId,
            'alert_fingerprint' => md5($alertId),
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity' => 'high',
            'detected_at' => now()->toIsoString(),
            'actor_key' => 'scenario-actor@test.local',
            'ip' => '10.0.0.1',
            'score' => 0.9,
            'detector_name' => 'xdr-correlation',
            'detector_version' => 'go-shadow',
            'evidence' => '{}',
            'raw_event' => '{}',
            'trace_id' => $traceId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $override));

        return $alertId;
    }

    private function seedOpEvent(string $traceId, string $payloadJson = '{}'): void
    {
        DB::table('xdr_operational_events')->insert([
            'event_id' => 'evt-'.uniqid(),
            'event_type' => 'alert.created',
            'schema_version' => 1,
            'source_topic' => 'alerts.created',
            'source_service' => 'alert-writer-service',
            'trace_id' => $traceId,
            'occurred_at' => now()->toIsoString(),
            'payload' => $payloadJson,
            'metadata' => '{}',
            'replayable' => true,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedEvidence(string $traceId, \App\Models\User $user, string $payloadJson = '{}'): void
    {
        DB::table('scenario_runs')->insert([
            'scenario_id' => 'failed_login_burst',
            'user_id' => $user->id,
            'status' => 'completed',
            'run_mode' => 'stub',
            'trace_id' => $traceId,
            'config' => '{}',
            'results' => '{}',
            'alerts_detected' => 0,
            'detection_passed' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $runId = DB::getPdo()->lastInsertId();

        DB::table('scenario_evidence')->insert([
            'scenario_run_id' => $runId,
            'stage' => 'ingestion',
            'stage_type' => 'real_pipeline_stage',
            'event_id' => 'ev-'.uniqid(),
            'trace_id' => $traceId,
            'status' => 'detected',
            'payload' => $payloadJson,
            'processed_at' => now()->toIsoString(),
            'latency_ms' => 12,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -----------------------------------------------------------------------
    // TraceRedactor unit tests
    // -----------------------------------------------------------------------

    public function test_redactor_masks_password_key(): void
    {
        $result = TraceRedactor::deep(['password' => 'hunter2', 'user' => 'bob']);
        $this->assertSame(TraceRedactor::REDACTED, $result['password']);
        $this->assertSame('bob', $result['user']);
    }

    public function test_redactor_masks_nested_password(): void
    {
        $result = TraceRedactor::deep(['request' => ['body' => ['user_password' => 's3cr3t']]]);
        $this->assertSame(TraceRedactor::REDACTED, $result['request']['body']['user_password']);
    }

    public function test_redactor_masks_token_key(): void
    {
        $result = TraceRedactor::deep(['access_token' => 'eyJhbGciOiJSUzI1NiJ9', 'scope' => 'read']);
        $this->assertSame(TraceRedactor::REDACTED, $result['access_token']);
        $this->assertSame('read', $result['scope']);
    }

    public function test_redactor_masks_authorization_header(): void
    {
        $result = TraceRedactor::deep(['headers' => ['Authorization' => 'Bearer abc123', 'Content-Type' => 'application/json']]);
        $this->assertSame(TraceRedactor::REDACTED, $result['headers']['Authorization']);
        $this->assertSame('application/json', $result['headers']['Content-Type']);
    }

    public function test_redactor_masks_cookie_header(): void
    {
        $result = TraceRedactor::deep(['cookie' => 'session=abc; path=/', 'host' => 'example.com']);
        $this->assertSame(TraceRedactor::REDACTED, $result['cookie']);
        $this->assertSame('example.com', $result['host']);
    }

    public function test_redactor_masks_secret_key(): void
    {
        $result = TraceRedactor::deep(['ingest_secret' => 'dev-secret', 'service' => 'gateway']);
        $this->assertSame(TraceRedactor::REDACTED, $result['ingest_secret']);
        $this->assertSame('gateway', $result['service']);
    }

    public function test_redactor_masks_email_key(): void
    {
        $result = TraceRedactor::deep(['user_email' => 'victim@corp.com', 'alert_type' => 'MFA']);
        $this->assertSame(TraceRedactor::REDACTED, $result['user_email']);
        $this->assertSame('MFA', $result['alert_type']);
    }

    public function test_redactor_masks_email_value_in_deep_mode(): void
    {
        $result = TraceRedactor::deep(['sender' => 'victim@example.com', 'subject' => 'hello'], redactEmails: true);
        $this->assertSame(TraceRedactor::EMAIL_PLACEHOLDER, $result['sender']);
        $this->assertSame('hello', $result['subject']);
    }

    public function test_redactor_does_not_mask_email_value_without_deep_mode(): void
    {
        $result = TraceRedactor::deep(['sender' => 'victim@example.com'], redactEmails: false);
        $this->assertSame('victim@example.com', $result['sender']);
    }

    public function test_redactor_preserves_investigative_identifiers(): void
    {
        $row = (object) [
            'actor_key' => 'scenario-actor@test.local',
            'ip' => '10.0.0.1',
            'alert_type' => 'IDENTITY_MFA_FAILURE_BURST',
            'severity' => 'high',
            'trace_id' => 'abc-123',
        ];
        $out = TraceRedactor::row($row);
        $this->assertSame('scenario-actor@test.local', $out->actor_key);
        $this->assertSame('10.0.0.1', $out->ip);
        $this->assertSame('IDENTITY_MFA_FAILURE_BURST', $out->alert_type);
        $this->assertSame('high', $out->severity);
        $this->assertSame('abc-123', $out->trace_id);
    }

    public function test_redactor_decodes_and_redacts_json_payload_field(): void
    {
        $row = (object) [
            'payload' => '{"authorization":"Bearer tok","safe_field":"ok"}',
            'stage' => 'ingestion',
        ];
        $out = TraceRedactor::row($row);
        // payload decoded from JSON string → array, sensitive key redacted
        $this->assertIsArray($out->payload);
        $this->assertSame(TraceRedactor::REDACTED, $out->payload['authorization']);
        $this->assertSame('ok', $out->payload['safe_field']);
        // non-payload field untouched
        $this->assertSame('ingestion', $out->stage);
    }

    // -----------------------------------------------------------------------
    // PERF-REDACTION-OVERHEAD: TraceRedactor::row() now redacts
    // JSON_PAYLOAD_FIELDS that arrive already-decoded (array/stdClass)
    // without an encode-then-decode round trip -- same output as the
    // JSON-string path above, just without the wasted serialization.
    // -----------------------------------------------------------------------

    public function test_redactor_redacts_already_decoded_array_payload_field(): void
    {
        $row = (object) [
            'evidence' => ['authorization' => 'Bearer tok', 'safe_field' => 'ok'],
            'stage' => 'ingestion',
        ];
        $out = TraceRedactor::row($row);

        $this->assertIsArray($out->evidence);
        $this->assertSame(TraceRedactor::REDACTED, $out->evidence['authorization']);
        $this->assertSame('ok', $out->evidence['safe_field']);
        $this->assertSame('ingestion', $out->stage);
    }

    public function test_redactor_redacts_emails_inside_already_decoded_array_payload(): void
    {
        $row = (object) ['evidence' => ['sender' => 'victim@corp.com', 'score' => 0.9]];
        $out = TraceRedactor::row($row);

        $this->assertSame(TraceRedactor::EMAIL_PLACEHOLDER, $out->evidence['sender']);
        $this->assertSame(0.9, $out->evidence['score']);
    }

    public function test_redactor_redacts_already_decoded_nested_array_payload_field(): void
    {
        $row = (object) [
            'evidence' => ['request' => ['headers' => ['Authorization' => 'Bearer nested-tok']]],
        ];
        $out = TraceRedactor::row($row);

        $this->assertSame(TraceRedactor::REDACTED, $out->evidence['request']['headers']['Authorization']);
    }

    public function test_redactor_array_payload_field_does_not_mutate_input(): void
    {
        $original = (object) ['evidence' => ['password' => 'secret', 'user' => 'bob']];
        $before = $original->evidence;

        TraceRedactor::row($original);

        $this->assertSame($before, $original->evidence);
    }

    public function test_redactor_masks_email_value_without_at_sign_is_a_noop(): void
    {
        // No '@' at all -- must short-circuit before the regex, and produce
        // the exact same (unchanged) output as running the regex would.
        $result = TraceRedactor::deep(['note' => 'no email here'], redactEmails: true);
        $this->assertSame('no email here', $result['note']);
    }

    public function test_redactor_redacts_emails_inside_json_payload(): void
    {
        $row = (object) [
            'evidence' => '{"sender":"victim@corp.com","score":0.9}',
        ];
        $out = TraceRedactor::row($row);
        $this->assertSame(TraceRedactor::EMAIL_PLACEHOLDER, $out->evidence['sender']);
        $this->assertSame(0.9, $out->evidence['score']);
    }

    public function test_redactor_row_does_not_mutate_input(): void
    {
        $original = (object) [
            'payload' => '{"password":"secret","user":"bob"}',
            'evidence' => '{"authorization":"Bearer x"}',
        ];
        $payloadBefore = $original->payload;
        $evidenceBefore = $original->evidence;

        TraceRedactor::row($original);

        $this->assertSame($payloadBefore, $original->payload);
        $this->assertSame($evidenceBefore, $original->evidence);
    }

    public function test_redactor_deep_does_not_mutate_input_array(): void
    {
        $input = ['password' => 'secret', 'user' => 'bob'];
        $before = $input['password'];

        TraceRedactor::deep($input);

        $this->assertSame($before, $input['password']);
    }

    public function test_redactor_handles_null_payload_gracefully(): void
    {
        $row = (object) ['payload' => null, 'stage' => 'ok'];
        $out = TraceRedactor::row($row);
        $this->assertNull($out->payload);
        $this->assertSame('ok', $out->stage);
    }

    public function test_decode_and_redact_returns_null_for_empty_input(): void
    {
        $this->assertNull(TraceRedactor::decodeAndRedact(null));
        $this->assertNull(TraceRedactor::decodeAndRedact(''));
    }

    public function test_decode_and_redact_returns_null_for_invalid_json(): void
    {
        $this->assertNull(TraceRedactor::decodeAndRedact('not-json'));
    }

    public function test_sensitive_key_detection(): void
    {
        $this->assertTrue(TraceRedactor::isSensitiveKey('password'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('user_password'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('access_token'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('Authorization'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('Cookie'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('X-Auth-Token'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('signing_key'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('user_email'));
        $this->assertTrue(TraceRedactor::isSensitiveKey('email_hash'));

        $this->assertFalse(TraceRedactor::isSensitiveKey('actor_key'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('alert_type'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('severity'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('trace_id'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('ip'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('source_ip'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('user'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('host'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('score'));
        $this->assertFalse(TraceRedactor::isSensitiveKey('evidence_ids'));
    }

    // -----------------------------------------------------------------------
    // Trace alerts API — evidence / raw_event redaction
    // -----------------------------------------------------------------------

    public function test_trace_alerts_api_redacts_sensitive_evidence_fields(): void
    {
        $traceId = 'redact-test-'.uniqid();
        $evidence = json_encode([
            'authorization' => 'Bearer supersecret-tok',
            'risk_score' => 0.95,
            'involved_users' => ['alice'],
        ]);
        $this->seedAlert($traceId, ['evidence' => $evidence]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.alerts', $traceId))
            ->assertOk();

        $alert = $response->json('alerts.0');
        // sensitive key → [REDACTED]
        $this->assertSame(TraceRedactor::REDACTED, $alert['evidence']['authorization']);
        // safe field preserved
        $this->assertSame(0.95, $alert['evidence']['risk_score']);
        // raw sensitive value must not appear anywhere in the response body
        $this->assertStringNotContainsString('supersecret-tok', $response->getContent());
    }

    public function test_trace_alerts_api_redacts_raw_event_tokens(): void
    {
        $traceId = 'redact-raw-'.uniqid();
        $rawEvent = json_encode([
            'x_api_key' => 'apikey-raw-value',
            'method' => 'POST',
        ]);
        $this->seedAlert($traceId, ['raw_event' => $rawEvent]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.alerts', $traceId))
            ->assertOk();

        $alert = $response->json('alerts.0');
        $this->assertSame(TraceRedactor::REDACTED, $alert['raw_event']['x_api_key']);
        $this->assertSame('POST', $alert['raw_event']['method']);
        $this->assertStringNotContainsString('apikey-raw-value', $response->getContent());
    }

    public function test_trace_alerts_api_redacts_email_values_in_evidence(): void
    {
        $traceId = 'redact-email-'.uniqid();
        $evidence = json_encode([
            'sender' => 'victim@corp.example.com',
            'alert_type' => 'PHISHING',
        ]);
        $this->seedAlert($traceId, ['evidence' => $evidence]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.alerts', $traceId))
            ->assertOk();

        $alert = $response->json('alerts.0');
        // value is email → [EMAIL] (key 'sender' is not a sensitive key itself)
        $this->assertSame(TraceRedactor::EMAIL_PLACEHOLDER, $alert['evidence']['sender']);
        $this->assertStringNotContainsString('victim@corp.example.com', $response->getContent());
    }

    public function test_trace_alerts_api_preserves_actor_key_and_ip(): void
    {
        $traceId = 'redact-preserve-'.uniqid();
        $this->seedAlert($traceId, [
            'actor_key' => 'soc-analyst@company.com',
            'ip' => '192.168.99.1',
        ]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.alerts', $traceId))
            ->assertOk();

        $alert = $response->json('alerts.0');
        $this->assertSame('soc-analyst@company.com', $alert['actor_key']);
        $this->assertSame('192.168.99.1', $alert['ip']);
    }

    // -----------------------------------------------------------------------
    // Trace show API — combined alert + incident redaction
    // -----------------------------------------------------------------------

    public function test_trace_show_api_redacts_alert_evidence(): void
    {
        $traceId = 'show-redact-'.uniqid();
        $evidence = json_encode(['password' => 'plaintext-pwd', 'score' => 0.8]);
        $this->seedAlert($traceId, ['evidence' => $evidence]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.show', $traceId))
            ->assertOk();

        $this->assertSame(TraceRedactor::REDACTED, $response->json('alerts.0.evidence.password'));
        $this->assertStringNotContainsString('plaintext-pwd', $response->getContent());
    }

    // -----------------------------------------------------------------------
    // Trace timeline API — operational event payload redaction
    // -----------------------------------------------------------------------

    public function test_trace_timeline_api_redacts_operational_event_payload(): void
    {
        $traceId = 'timeline-redact-'.uniqid();
        $payload = json_encode([
            'secret' => 'pipeline-secret-value',
            'source' => 'normalizer-worker',
            'event_count' => 42,
        ]);
        $this->seedOpEvent($traceId, $payload);
        $this->seedAlert($traceId);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.timeline', $traceId))
            ->assertOk();

        // find the operational_events entry
        $opEntry = collect($response->json('timeline'))
            ->firstWhere('source', 'operational_events');

        $this->assertNotNull($opEntry, 'operational_events entry must appear in timeline');
        $this->assertSame(TraceRedactor::REDACTED, $opEntry['payload']['secret']);
        $this->assertSame(42, $opEntry['payload']['event_count']);
        $this->assertStringNotContainsString('pipeline-secret-value', $response->getContent());
    }

    public function test_trace_timeline_api_redacts_alert_evidence_inline(): void
    {
        $traceId = 'timeline-alert-redact-'.uniqid();
        $evidence = json_encode(['authorization' => 'Bearer inline-tok', 'rule' => 'BURST']);
        $this->seedAlert($traceId, ['evidence' => $evidence]);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.timeline', $traceId))
            ->assertOk();

        $alertEntry = collect($response->json('timeline'))
            ->firstWhere('source', 'security_alerts');

        $this->assertNotNull($alertEntry);
        $this->assertSame(TraceRedactor::REDACTED, $alertEntry['evidence']['authorization']);
        $this->assertStringNotContainsString('inline-tok', $response->getContent());
    }

    // -----------------------------------------------------------------------
    // Trace evidence API — scenario_evidence payload redaction
    // -----------------------------------------------------------------------

    public function test_trace_evidence_api_redacts_scenario_payload(): void
    {
        $traceId = 'evidence-redact-'.uniqid();
        $payload = json_encode([
            'x_auth_token' => 'evidence-token-xyz',
            'telemetry_type' => 'cloud',
            'event_count' => 5,
        ]);
        $this->seedEvidence($traceId, $this->admin(), $payload);
        $this->seedAlert($traceId);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.evidence', $traceId))
            ->assertOk();

        $ev = $response->json('evidence.0');
        $this->assertSame(TraceRedactor::REDACTED, $ev['payload']['x_auth_token']);
        $this->assertSame('cloud', $ev['payload']['telemetry_type']);
        $this->assertSame(5, $ev['payload']['event_count']);
        $this->assertStringNotContainsString('evidence-token-xyz', $response->getContent());
    }

    public function test_trace_evidence_api_redacts_email_in_payload(): void
    {
        $traceId = 'evidence-email-'.uniqid();
        $payload = json_encode([
            'recipient' => 'target@corp.example.com',
            'action' => 'objectAccess',
        ]);
        $this->seedEvidence($traceId, $this->admin(), $payload);
        $this->seedAlert($traceId);

        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.evidence', $traceId))
            ->assertOk();

        $ev = $response->json('evidence.0');
        $this->assertSame(TraceRedactor::EMAIL_PLACEHOLDER, $ev['payload']['recipient']);
        $this->assertStringNotContainsString('target@corp.example.com', $response->getContent());
    }

    // -----------------------------------------------------------------------
    // Trace search — search results do not expose raw sensitive payloads
    // -----------------------------------------------------------------------

    public function test_trace_search_results_do_not_expose_evidence_payloads(): void
    {
        $traceId = 'search-safe-'.uniqid();
        $evidence = json_encode(['secret' => 'should-never-appear-in-search']);
        $this->seedAlert($traceId, ['evidence' => $evidence]);

        // The search result page only returns aggregated stats (counts, severity).
        // The raw evidence JSON must not appear.
        $response = $this->actingAs($this->admin())
            ->getJson(route('api.traces.index', ['q' => $traceId, 'by' => 'trace_id']))
            ->assertOk();

        $this->assertStringNotContainsString('should-never-appear-in-search', $response->getContent());
        $this->assertStringNotContainsString('secret', $response->getContent());
        // Aggregated stats are present
        $this->assertSame(1, $response->json('traces.0.alerts_count'));
    }

    public function test_trace_search_ui_page_does_not_expose_evidence_payloads(): void
    {
        $traceId = 'search-ui-safe-'.uniqid();
        $evidence = json_encode(['password' => 'must-not-appear-on-search-page']);
        $this->seedAlert($traceId, ['evidence' => $evidence]);

        $response = $this->actingAs($this->admin())
            ->get(route('traces.index', ['q' => $traceId, 'by' => 'trace_id']))
            ->assertOk();

        $this->assertStringNotContainsString('must-not-appear-on-search-page', $response->getContent());
    }

    // -----------------------------------------------------------------------
    // Immutability guarantee — raw DB data must survive API calls unchanged
    // -----------------------------------------------------------------------

    public function test_raw_database_data_unchanged_after_alerts_api_call(): void
    {
        $traceId = 'immutable-'.uniqid();
        $rawEvidence = json_encode(['authorization' => 'Bearer original-secret', 'score' => 1.0]);
        $alertId = $this->seedAlert($traceId, ['evidence' => $rawEvidence]);

        // Call the API (which applies redaction)
        $this->actingAs($this->admin())
            ->getJson(route('api.traces.alerts', $traceId))
            ->assertOk();

        // Re-query DB directly — original value must be intact
        $stored = DB::table('security_alerts')->where('alert_id', $alertId)->value('evidence');
        $this->assertSame($rawEvidence, $stored, 'DB evidence must not be mutated by the API layer');
        $this->assertStringContainsString('original-secret', $stored);
    }

    public function test_raw_database_data_unchanged_after_evidence_api_call(): void
    {
        $traceId = 'immutable-ev-'.uniqid();
        $rawPayload = json_encode(['x_auth_token' => 'raw-token-value', 'safe' => 'data']);
        $user = $this->admin();
        $this->seedEvidence($traceId, $user, $rawPayload);
        $this->seedAlert($traceId);

        $this->actingAs($user)
            ->getJson(route('api.traces.evidence', $traceId))
            ->assertOk();

        $stored = DB::table('scenario_evidence')->where('trace_id', $traceId)->value('payload');
        $this->assertSame($rawPayload, $stored, 'DB payload must not be mutated by the API layer');
        $this->assertStringContainsString('raw-token-value', $stored);
    }

    public function test_raw_database_data_unchanged_after_timeline_api_call(): void
    {
        $traceId = 'immutable-tl-'.uniqid();
        $rawPayload = json_encode(['secret' => 'pipeline-raw-secret']);
        $this->seedOpEvent($traceId, $rawPayload);
        $this->seedAlert($traceId);

        $this->actingAs($this->admin())
            ->getJson(route('api.traces.timeline', $traceId))
            ->assertOk();

        $stored = DB::table('xdr_operational_events')->where('trace_id', $traceId)->value('payload');
        // PostgreSQL may normalise jsonb whitespace; compare decoded structure
        $this->assertSame(
            json_decode($rawPayload, true),
            json_decode($stored, true),
            'DB payload must not be mutated by the API layer'
        );
        $this->assertStringContainsString('pipeline-raw-secret', $stored);
    }
}
