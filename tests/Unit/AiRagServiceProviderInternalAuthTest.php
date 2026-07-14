<?php

namespace Tests\Unit;

use App\Support\AiRagServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AI-1: ai-rag-service's /v1/analyze, /v1/retrieve, /v1/embed endpoints had
 * zero authentication -- any caller with network access could invoke them.
 * Fixed with the same X-Internal-Service-Token pattern already used for
 * every other internal service; this covers the Laravel-side caller
 * attaching (or correctly omitting) that header.
 */
class AiRagServiceProviderInternalAuthTest extends TestCase
{
    private function fakeAiRagResponse(): void
    {
        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
            '*/v1/analyze' => Http::response([
                'summary' => 'test summary',
                'provider' => 'ai-rag-service',
            ], 200),
        ]);
    }

    public function test_sends_internal_token_header_when_configured(): void
    {
        Config::set('soc.ai_internal_token', 'test-internal-token');
        $this->fakeAiRagResponse();

        (new AiRagServiceProvider)->generate('investigation_steps', [
            'incident' => ['incident_id' => 'inc-1'],
            'alerts' => [],
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/analyze')
                && $request->hasHeader('X-Internal-Service-Token', 'test-internal-token');
        });
    }

    public function test_omits_internal_token_header_when_not_configured(): void
    {
        Config::set('soc.ai_internal_token', '');
        $this->fakeAiRagResponse();

        (new AiRagServiceProvider)->generate('investigation_steps', [
            'incident' => ['incident_id' => 'inc-1'],
            'alerts' => [],
        ]);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/analyze')
                && ! $request->hasHeader('X-Internal-Service-Token');
        });
    }

    public function test_falls_back_to_local_provider_on_401(): void
    {
        Config::set('soc.ai_internal_token', 'wrong-token');
        Http::fake([
            '*/health' => Http::response(['status' => 'ok'], 200),
            '*/v1/analyze' => Http::response(['detail' => 'unauthorized'], 401),
        ]);

        $result = (new AiRagServiceProvider)->generate('investigation_steps', [
            'incident' => ['incident_id' => 'inc-1'],
            'alerts' => [],
        ]);

        $this->assertSame('local-heuristic', $result['provider_fallback']);
    }
}
