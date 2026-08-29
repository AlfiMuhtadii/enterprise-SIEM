<?php

namespace App\Support;

use App\Services\InternalMtlsHttpClient;

class AiRagServiceProvider implements AiAnalystProvider
{
    public function providerName(): string
    {
        return 'ai-rag-service';
    }

    public function generate(string $suggestionType, array $context): array
    {
        $started = microtime(true);
        $url = rtrim((string) config('soc.ai_service_url', 'http://127.0.0.1:8094'), '/');
        $timeout = (int) config('soc.ai_timeout_seconds', 20);
        $traceId = (string) ($context['_trace_id'] ?? '');
        $incidentId = (string) data_get($context, 'incident.incident_id', 'unknown');
        $evidence = collect($context['alerts'] ?? [])
            ->map(function (array $alert) {
                $decoded = json_decode($alert['evidence'] ?? '{}', true) ?: [];
                return array_merge($alert, [
                    'telemetry_type' => $decoded['xdr_domains'][0] ?? $alert['detector_name'] ?? 'alert',
                    'risk_score' => $alert['score'] ?? null,
                    'event_id' => $alert['alert_id'] ?? null,
                ]);
            })
            ->values()
            ->all();

        try {
            $healthUrl = $url.'/health';
            $health = InternalMtlsHttpClient::request($healthUrl, 2)->get($healthUrl);
            if (! $health->successful()) {
                throw new \RuntimeException('AI/RAG service health check failed.');
            }

            $headers = ['X-Trace-Id' => $traceId];
            $internalToken = (string) config('soc.ai_internal_token', '');
            if ($internalToken !== '') {
                $headers['X-Internal-Service-Token'] = $internalToken;
            }

            $analyzeUrl = $url.'/v1/analyze';
            $response = InternalMtlsHttpClient::request($analyzeUrl, $timeout)
                ->withHeaders($headers)
                ->post($analyzeUrl, [
                    'incident_id' => $incidentId,
                    'evidence' => $evidence,
                    'question' => $this->question($suggestionType),
                ])
                ->throw()
                ->json();

            return [
                'summary' => $response['summary'] ?? 'AI/RAG service returned no summary.',
                'explanation' => [
                    'provider' => $response['provider'] ?? 'ai-rag-service',
                    'citations' => $response['citations'] ?? [],
                    'safety' => $response['safety'] ?? [],
                ],
                'recommended_steps' => $response['recommended_steps'] ?? [],
                'recommended_responses' => $response['recommended_responses'] ?? ['No automated response recommended without analyst approval.'],
                'confidence' => $this->confidenceScore((string) ($response['confidence'] ?? 'medium')),
                'model' => $response['model'] ?? 'ai-rag-heuristic',
                'provider_metadata' => ['service_url' => $url, 'trace_id' => $traceId],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'suggestion_type' => $suggestionType,
            ];
        } catch (\Throwable $exception) {
            $fallback = (new LocalAiAnalystProvider())->generate($suggestionType, $context);
            $fallback['provider_error'] = $exception->getMessage();
            $fallback['provider_fallback'] = 'local-heuristic';
            $fallback['latency_ms'] = (int) ((microtime(true) - $started) * 1000);
            return $fallback;
        }
    }

    private function question(string $type): string
    {
        return match ($type) {
            'investigation_steps' => 'Recommend defensive investigation steps using only supplied evidence.',
            'response_actions' => 'Recommend safe approval-required response actions using only supplied evidence.',
            'executive_narrative' => 'Generate a concise executive defensive summary using supplied evidence.',
            default => 'Summarize defensive investigation context using only supplied evidence.',
        };
    }

    private function confidenceScore(string $label): float
    {
        return match (strtolower($label)) {
            'high' => 0.8,
            'low' => 0.35,
            default => 0.6,
        };
    }
}
