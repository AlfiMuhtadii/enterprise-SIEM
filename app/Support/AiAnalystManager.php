<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiAnalystManager
{
    public function provider(): AiAnalystProvider
    {
        if ((bool) config('soc.ai_service_enabled', false)) {
            return new AiRagServiceProvider();
        }

        return match ((string) config('soc.ai_provider', 'local')) {
            'openai-compatible' => new RemoteLlmProvider('openai-compatible'),
            'ollama' => new RemoteLlmProvider('ollama'),
            default => new LocalAiAnalystProvider(),
        };
    }

    public function incidentContext(string $incidentId): array
    {
        $incident = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        if (!$incident) {
            return [];
        }
        $alerts = DB::table('security_alerts')->where('incident_id', $incidentId)->orderByDesc('detected_at')->limit(50)->get();
        $alertIds = $alerts->pluck('alert_id')->filter()->values();

        return [
            'incident' => (array) $incident,
            'alerts' => $alerts->map(fn ($row) => (array) $row)->values()->all(),
            'affected_entities' => json_decode($incident->affected_entities ?: '[]', true) ?: [],
            'mitre_mapping' => json_decode($incident->mitre_mapping ?: '[]', true) ?: [],
            'timeline' => json_decode($incident->timeline ?: '[]', true) ?: [],
            'ioc_hits' => $alertIds->isEmpty() ? [] : DB::table('ioc_hits')->whereIn('alert_id', $alertIds)->get()->map(fn ($row) => (array) $row)->values()->all(),
            'knowledge_refs' => DB::table('soc_knowledge_base')
                ->where('related_incident_id', $incidentId)
                ->orWhereIn('related_rule_id', $alerts->pluck('detector_name')->filter()->unique()->values())
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->values()
                ->all(),
        ];
    }

    public function generateForIncident(string $incidentId, string $type, string $actor): array
    {
        $started = microtime(true);
        $context = $this->incidentContext($incidentId);
        $retriever = new SocKnowledgeRetriever();
        $citations = $retriever->retrieve($context);
        $context['retrieval_citations'] = $citations;
        $prompt = $this->renderPrompt($type, $context);
        $traceId = 'trace-'.Str::uuid();
        $guardrails = new AiGuardrails();
        $promptGuardrail = $guardrails->validatePrompt($prompt, $traceId);
        $provider = $this->provider();
        $context['_prompt'] = $prompt;
        $context['_trace_id'] = $traceId;
        $output = $provider->generate($type, $context);
        $output['retrieval_citations'] = $citations;
        $output['confidence_label'] = $this->confidenceLabel((float) ($output['confidence'] ?? 0.5));
        $output['hallucination_warning'] = 'AI output is analyst-assistive and must be validated against cited evidence.';
        $outputGuardrail = $guardrails->validateOutput($output, $traceId);
        if (($promptGuardrail['status'] ?? 'passed') === 'blocked' || ($outputGuardrail['status'] ?? 'passed') === 'blocked') {
            $output = $this->safeBlockedOutput($type, $citations);
        }
        $suggestionId = 'ai-'.Str::uuid();
        $latencyMs = (int) ($output['latency_ms'] ?? ((microtime(true) - $started) * 1000));
        $model = (string) ($output['model'] ?? config('soc.ai_model', $provider->providerName()));
        $tokenUsage = $output['token_usage'] ?? null;
        $guardrailStatus = ($promptGuardrail['status'] ?? 'passed') === 'blocked' || ($outputGuardrail['status'] ?? 'passed') === 'blocked'
            ? 'blocked'
            : (($outputGuardrail['status'] ?? 'passed') === 'warning' ? 'warning' : 'passed');

        DB::table('ai_analyst_suggestions')->insert([
            'suggestion_id' => $suggestionId,
            'target_type' => 'incident',
            'target_id' => $incidentId,
            'suggestion_type' => $type,
            'provider' => $provider->providerName(),
            'model' => $model,
            'latency_ms' => $latencyMs,
            'token_usage' => $tokenUsage ? json_encode($tokenUsage) : null,
            'confidence_label' => $output['confidence_label'] ?? 'medium',
            'guardrail_status' => $guardrailStatus,
            'retrieval_citations' => json_encode($citations),
            'trace_id' => $traceId,
            'input_context' => json_encode($this->compactContext($context)),
            'output' => json_encode($output),
            'status' => 'pending_review',
            'requested_by' => $actor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_execution_history')->insert([
            'trace_id' => $traceId,
            'suggestion_id' => $suggestionId,
            'provider' => $provider->providerName(),
            'model' => $model,
            'prompt_template_id' => $this->templateId($type),
            'status' => $guardrailStatus === 'blocked' ? 'blocked' : 'completed',
            'latency_ms' => $latencyMs,
            'token_usage' => $tokenUsage ? json_encode($tokenUsage) : null,
            'provider_metadata' => json_encode(['configured_provider' => config('soc.ai_provider', 'local'), 'fallback' => $output['provider_fallback'] ?? null]),
            'prompt_trace' => json_encode(['template_id' => $this->templateId($type), 'prompt_sha256' => hash('sha256', $prompt)]),
            'response_validation' => json_encode($this->validateResponseShape($output)),
            'guardrail_result' => json_encode(['prompt' => $promptGuardrail, 'output' => $outputGuardrail]),
            'executed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        XdrOperationalEventStore::append(
            eventType: 'ai.analysis.completed',
            payload: [
                'suggestion_id' => $suggestionId,
                'incident_id' => $incidentId,
                'suggestion_type' => $type,
                'provider' => $provider->providerName(),
                'model' => $model,
                'status' => $guardrailStatus === 'blocked' ? 'blocked' : 'completed',
                'confidence_label' => $output['confidence_label'] ?? 'medium',
                'latency_ms' => $latencyMs,
                'retrieval_citations' => $citations,
            ],
            sourceService: 'soc-control-plane',
            sourceTopic: 'ai.analysis.completed',
            aggregateType: 'ai_suggestion',
            aggregateId: $suggestionId,
            traceId: $traceId,
            metadata: [
                'incident_id' => $incidentId,
                'actor' => $actor,
                'published_at' => now(),
            ],
        );

        AuditLogger::log($actor, 'ai.generate', 'incident', $incidentId, null, ['suggestion_id' => $suggestionId, 'type' => $type]);

        return array_merge($output, ['suggestion_id' => $suggestionId]);
    }

    private function compactContext(array $context): array
    {
        $alerts = array_values($context['alerts'] ?? []);
        $iocHits = array_values($context['ioc_hits'] ?? []);
        $citations = array_values($context['retrieval_citations'] ?? []);

        return [
            'incident_id' => $context['incident']['incident_id'] ?? null,
            'title' => $context['incident']['title'] ?? null,
            'severity' => $context['incident']['severity'] ?? null,
            'status' => $context['incident']['status'] ?? null,
            'mitre_mapping' => $context['mitre_mapping'] ?? [],
            'alert_count' => count($alerts),
            'ioc_hit_count' => count($iocHits),
            'retrieval_citation_count' => count($citations),
            // AI-CONTEXT-EMPTY: include bounded alert details so the model reasons
            // over actual evidence rather than only a count (prevents LLM blindness).
            'alerts' => array_map(fn ($a) => [
                'alert_type' => $a['alert_type'] ?? null,
                'severity' => $a['severity'] ?? null,
                'detector' => $a['detector_name'] ?? null,
                'score' => $a['score'] ?? null,
                'ip' => $a['ip'] ?? null,
                'evidence' => isset($a['evidence']) ? mb_substr((string) $a['evidence'], 0, 400) : null,
            ], array_slice($alerts, 0, 8)),
            'ioc_hits' => array_map(fn ($h) => [
                'type' => $h['matched_field'] ?? null,
                'value' => $h['matched_value'] ?? null,
            ], array_slice($iocHits, 0, 8)),
            // AI-CONTEXT-EMPTY: include the retrieved knowledge text (title +
            // excerpt) so the model can ground its answer in cited evidence.
            'knowledge' => array_map(fn ($c) => [
                'title' => $c['title'] ?? null,
                'excerpt' => $c['excerpt'] ?? null,
                'score' => $c['score'] ?? null,
                'related_rule_id' => $c['related_rule_id'] ?? null,
            ], array_slice($citations, 0, 6)),
        ];
    }

    private function renderPrompt(string $type, array $context): string
    {
        $template = DB::table('ai_prompt_templates')->where('task_type', $type)->where('enabled', true)->orderByDesc('version')->first();
        $system = $template->system_prompt ?? 'You are a defensive SOC analyst assistant. Do not provide offensive instructions. Use only supplied evidence and citations.';
        $user = $template->user_template ?? 'Task: {{type}}. Incident context JSON: {{context}}. Return JSON with summary, explanation, recommended_steps, recommended_responses, confidence.';
        return $system."\n\n".str_replace(['{{type}}', '{{context}}'], [$type, json_encode($this->compactContext($context))], $user);
    }

    private function templateId(string $type): string
    {
        return DB::table('ai_prompt_templates')->where('task_type', $type)->where('enabled', true)->orderByDesc('version')->value('template_id') ?: 'default-'.$type;
    }

    private function validateResponseShape(array $output): array
    {
        $required = ['summary', 'recommended_steps', 'recommended_responses'];
        $missing = array_values(array_filter($required, fn ($key) => !array_key_exists($key, $output)));
        return ['valid' => empty($missing), 'missing' => $missing];
    }

    private function confidenceLabel(float $confidence): string
    {
        return $confidence >= 0.75 ? 'high' : ($confidence >= 0.5 ? 'medium' : 'low');
    }

    private function safeBlockedOutput(string $type, array $citations): array
    {
        return [
            'summary' => 'AI output was blocked by guardrails because it did not meet defensive-analysis safety policy.',
            'explanation' => ['guardrail' => 'Blocked content was not shown. Generate again with evidence-only defensive scope.'],
            'recommended_steps' => ['Review evidence manually.', 'Use existing playbook and cited knowledge entries.'],
            'recommended_responses' => ['No automated response recommended from blocked AI output.'],
            'confidence' => 0.1,
            'confidence_label' => 'low',
            'retrieval_citations' => $citations,
            'suggestion_type' => $type,
        ];
    }
}
