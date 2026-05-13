<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiGuardrails
{
    private array $blockedTerms = [
        'exploit code',
        'reverse shell',
        'payload to bypass',
        'privilege escalation steps',
        'persistence mechanism',
        'c2 setup',
        'stealth evasion',
        'credential theft',
    ];

    public function validatePrompt(string $prompt, ?string $traceId = null): array
    {
        return $this->validateText($prompt, 'prompt', $traceId);
    }

    public function validateOutput(array $output, ?string $traceId = null): array
    {
        $text = strtolower(json_encode($output));
        $result = $this->validateText($text, 'output', $traceId);
        $result['hallucination_warnings'] = $this->hallucinationWarnings($output);
        if ($result['hallucination_warnings']) {
            $result['status'] = $result['status'] === 'blocked' ? 'blocked' : 'warning';
        }
        return $result;
    }

    private function validateText(string $text, string $scope, ?string $traceId): array
    {
        $lower = strtolower($text);
        $matches = array_values(array_filter($this->blockedTerms, fn ($term) => str_contains($lower, $term)));
        $status = $matches ? 'blocked' : 'passed';
        if ($matches) {
            $this->event($traceId, 'unsafe_'.$scope, 'high', 'AI content contains unsafe/offensive instruction markers.', ['matches' => $matches]);
        }
        return [
            'status' => $status,
            'scope' => $scope,
            'unsafe_matches' => $matches,
            'policy' => 'defensive-security-analysis-only',
        ];
    }

    private function hallucinationWarnings(array $output): array
    {
        $warnings = [];
        if (empty($output['retrieval_citations'] ?? []) && !empty($output['summary'] ?? null)) {
            $warnings[] = 'No retrieval citations attached; treat as analyst-assistive, not authoritative.';
        }
        if (($output['confidence'] ?? 0) < 0.5) {
            $warnings[] = 'Low confidence output; requires manual validation.';
        }
        return $warnings;
    }

    private function event(?string $traceId, string $type, string $severity, string $message, array $evidence): void
    {
        DB::table('ai_guardrail_events')->insert([
            'event_id' => 'guard-'.Str::uuid(),
            'trace_id' => $traceId,
            'event_type' => $type,
            'severity' => $severity,
            'message' => $message,
            'evidence' => json_encode($evidence),
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
