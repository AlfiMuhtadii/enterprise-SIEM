<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

class RemoteLlmProvider implements AiAnalystProvider
{
    public function __construct(private readonly string $kind)
    {
    }

    public function providerName(): string
    {
        return $this->kind;
    }

    public function generate(string $suggestionType, array $context): array
    {
        $prompt = $context['_prompt'] ?? '';
        $started = microtime(true);
        $timeout = (int) config('soc.ai_timeout_seconds', 20);
        $attempts = max(1, (int) config('soc.ai_retry_attempts', 1));
        $lastError = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->kind === 'ollama'
                    ? $this->callOllama($prompt, $timeout)
                    : $this->callOpenAiCompatible($prompt, $timeout);
                $response['latency_ms'] = (int) ((microtime(true) - $started) * 1000);
                return $response;
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        $fallback = (new LocalAiAnalystProvider())->generate($suggestionType, $context);
        $fallback['provider_error'] = $lastError;
        $fallback['provider_fallback'] = 'local-heuristic';
        $fallback['latency_ms'] = (int) ((microtime(true) - $started) * 1000);
        return $fallback;
    }

    private function callOpenAiCompatible(string $prompt, int $timeout): array
    {
        $baseUrl = rtrim((string) config('soc.openai_compatible_base_url'), '/');
        $apiKey = (string) config('soc.openai_compatible_api_key');
        $model = (string) config('soc.ai_model', 'gpt-compatible');
        if ($baseUrl === '' || $apiKey === '') {
            throw new \RuntimeException('OpenAI-compatible provider is not configured.');
        }
        $response = Http::timeout($timeout)
            ->withToken($apiKey)
            ->post($baseUrl.'/chat/completions', [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are a defensive SOC analyst assistant. Return strict JSON only.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
            ])
            ->throw()
            ->json();
        $content = $response['choices'][0]['message']['content'] ?? '{}';
        return $this->normalizeRemoteJson($content, $model, $response['usage'] ?? null);
    }

    private function callOllama(string $prompt, int $timeout): array
    {
        $baseUrl = rtrim((string) config('soc.ollama_base_url'), '/');
        $model = (string) config('soc.ollama_model', 'llama3.1');
        $response = Http::timeout($timeout)
            ->post($baseUrl.'/api/generate', [
                'model' => $model,
                'prompt' => $prompt,
                'stream' => false,
                'format' => 'json',
            ])
            ->throw()
            ->json();
        return $this->normalizeRemoteJson($response['response'] ?? '{}', $model, [
            'prompt_eval_count' => $response['prompt_eval_count'] ?? null,
            'eval_count' => $response['eval_count'] ?? null,
        ]);
    }

    private function normalizeRemoteJson(string $content, string $model, ?array $usage): array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $decoded = ['summary' => trim($content), 'recommended_steps' => [], 'recommended_responses' => []];
        }
        $decoded['model'] = $model;
        $decoded['token_usage'] = $usage;
        return $decoded;
    }
}
