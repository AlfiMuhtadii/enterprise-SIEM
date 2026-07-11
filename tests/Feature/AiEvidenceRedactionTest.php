<?php

namespace Tests\Feature;

use App\Support\AiAnalystManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AI-3 — AiAnalystManager::incidentContext() previously handed alert
 * `evidence`/`raw_event` straight through to every AI provider (local
 * heuristic, remote LLM API, standalone ai-rag-service) completely
 * unredacted -- a raw request/log capture can contain anything (passwords,
 * tokens, emails), and the standalone ai-rag-service path in particular
 * sends it over a real network call. Fixed by reusing the same
 * TraceRedactor primitive already used for trace investigation views/APIs.
 */
class AiEvidenceRedactionTest extends TestCase
{
    use RefreshDatabase;

    private function seedIncidentWithAlert(array $evidence, array $rawEvent = []): void
    {
        DB::table('security_incidents')->insert([
            'incident_id' => 'inc-redact-1',
            'title' => 'Redaction Test Incident',
            'status' => 'open',
            'severity' => 'high',
            'confidence' => 0.9,
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now(),
            'affected_entities' => json_encode([]),
            'timeline' => json_encode([]),
            'mitre_mapping' => json_encode([]),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('security_alerts')->insert([
            'alert_id' => 'alert-redact-1',
            'detected_at' => now(),
            'alert_type' => 'CREDENTIAL_LEAK_TEST',
            'detector_name' => 'test-detector',
            'detector_version' => 'v1',
            'severity' => 'high',
            'actor_key' => 'victim@example.com',
            'ip' => '203.0.113.5',
            'incident_id' => 'inc-redact-1',
            'score' => 0.9,
            'evidence' => json_encode($evidence),
            'raw_event' => json_encode($rawEvent),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_incident_context_redacts_sensitive_keys_in_evidence(): void
    {
        $this->seedIncidentWithAlert([
            'password' => 's3cr3t-value',
            'api_token' => 'abcd1234',
            'evidence_chain' => ['alert-redact-1'],
        ]);

        $context = app(AiAnalystManager::class)->incidentContext('inc-redact-1');
        $evidence = json_decode($context['alerts'][0]['evidence'], true);

        $this->assertSame('[REDACTED]', $evidence['password']);
        $this->assertSame('[REDACTED]', $evidence['api_token']);
        // Non-sensitive investigative content must survive redaction.
        $this->assertSame(['alert-redact-1'], $evidence['evidence_chain']);
    }

    public function test_incident_context_redacts_emails_in_evidence(): void
    {
        $this->seedIncidentWithAlert([
            'request_body' => 'login attempt for victim@example.com from source',
        ]);

        $context = app(AiAnalystManager::class)->incidentContext('inc-redact-1');
        $evidence = json_decode($context['alerts'][0]['evidence'], true);

        $this->assertStringNotContainsString('victim@example.com', $evidence['request_body']);
        $this->assertStringContainsString('[EMAIL]', $evidence['request_body']);
    }

    public function test_incident_context_redacts_raw_event_too(): void
    {
        $this->seedIncidentWithAlert([], ['headers' => ['Authorization' => 'Bearer real-secret-token']]);

        $context = app(AiAnalystManager::class)->incidentContext('inc-redact-1');
        $rawEvent = json_decode($context['alerts'][0]['raw_event'], true);

        $this->assertSame('[REDACTED]', $rawEvent['headers']['Authorization']);
    }

    public function test_incident_context_leaves_investigative_identifiers_untouched(): void
    {
        $this->seedIncidentWithAlert(['note' => 'benign evidence']);

        $context = app(AiAnalystManager::class)->incidentContext('inc-redact-1');
        $alert = $context['alerts'][0];

        // actor_key/ip are the analytic surface, not free-form content --
        // must survive untouched (matches TraceRedactor's own design: only
        // JSON payload field *values* are deep-redacted, not top-level
        // investigative identifiers).
        $this->assertSame('victim@example.com', $alert['actor_key']);
        $this->assertSame('203.0.113.5', $alert['ip']);
    }

    public function test_evidence_reaching_standalone_ai_rag_service_is_redacted(): void
    {
        $this->seedIncidentWithAlert(['password' => 'leaked-secret-value']);

        \Illuminate\Support\Facades\Http::fake([
            '*/health' => \Illuminate\Support\Facades\Http::response(['status' => 'ok'], 200),
            '*/v1/analyze' => \Illuminate\Support\Facades\Http::response(['summary' => 'ok'], 200),
        ]);

        $context = app(AiAnalystManager::class)->incidentContext('inc-redact-1');
        (new \App\Support\AiRagServiceProvider())->generate('incident_summary', $context);

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/v1/analyze')) {
                return true; // ignore the /health check
            }
            $body = json_encode($request->data());

            return !str_contains($body, 'leaked-secret-value');
        });
    }
}
