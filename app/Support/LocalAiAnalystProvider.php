<?php

namespace App\Support;

class LocalAiAnalystProvider implements AiAnalystProvider
{
    public function providerName(): string
    {
        return 'local-heuristic';
    }

    public function generate(string $suggestionType, array $context): array
    {
        $incident = $context['incident'] ?? [];
        $alerts = $context['alerts'] ?? [];
        $mitre = $context['mitre_mapping'] ?? [];
        $iocHits = $context['ioc_hits'] ?? [];
        $topAlertTypes = collect($alerts)->pluck('alert_type')->filter()->countBy()->sortDesc()->take(5)->all();
        $severity = $incident['severity'] ?? 'medium';
        $status = $incident['status'] ?? 'open';
        $entities = $context['affected_entities'] ?? [];

        $summary = sprintf(
            'Incident %s is %s severity and currently %s. It contains %d related alerts across %d affected entities.',
            $incident['incident_id'] ?? 'unknown',
            $severity,
            $status,
            count($alerts),
            count($entities)
        );
        if ($topAlertTypes) {
            $summary .= ' Dominant alert types: '.implode(', ', array_keys($topAlertTypes)).'.';
        }
        if ($iocHits) {
            $summary .= ' IOC enrichment is present and should be prioritized.';
        }

        $steps = [
            'Validate the highest-severity alert evidence and timestamp order.',
            'Review affected host/user/process/IP entities against recent telemetry.',
            'Check whether IOC matches are expired, known-benign, or externally confirmed.',
            'Map evidence to MITRE techniques before selecting response actions.',
        ];
        if (in_array('T1110', $mitre, true) || isset($topAlertTypes['BRUTE_FORCE_IP'])) {
            $steps[] = 'Inspect authentication failures, source IP reuse, and account lockout impact.';
        }
        if (str_contains(strtolower(implode(' ', array_keys($topAlertTypes))), 'injection')) {
            $steps[] = 'Review request payloads, WAF/app logs, and database error traces.';
        }

        $responses = [
            'Preserve evidence before containment.',
            'Use approval-required response actions for endpoint commands.',
            'Escalate if multiple entities or malicious IOC hits are confirmed.',
        ];

        return [
            'summary' => $summary,
            'explanation' => [
                'mitre' => $mitre ? 'Observed mapping: '.implode(', ', $mitre) : 'No MITRE mapping is currently attached.',
                'ioc' => $iocHits ? 'IOC matches increase confidence and should be reviewed for reputation and source.' : 'No IOC hit is currently linked.',
                'evidence_chain' => 'Prioritize alerts with explicit evidence_chain fields because they preserve causal order.',
            ],
            'recommended_steps' => $steps,
            'recommended_responses' => $responses,
            'playbook_suggestion' => $this->playbookSuggestion($topAlertTypes, $mitre),
            'executive_narrative' => $this->executiveNarrative($summary, $severity, $iocHits),
            'confidence' => $iocHits || count($alerts) > 2 ? 0.78 : 0.58,
            'limitations' => ['Local provider uses deterministic heuristics and does not call an external LLM.'],
            'suggestion_type' => $suggestionType,
        ];
    }

    private function playbookSuggestion(array $alertTypes, array $mitre): string
    {
        $joined = strtolower(implode(' ', array_keys($alertTypes)).' '.implode(' ', $mitre));
        if (str_contains($joined, 'endpoint') || str_contains($joined, 'process')) {
            return 'endpoint_compromise';
        }
        if (str_contains($joined, 'injection') || str_contains($joined, 'brute') || str_contains($joined, 'scan')) {
            return 'web_attack';
        }
        return 'generic';
    }

    private function executiveNarrative(string $summary, string $severity, array $iocHits): string
    {
        $impact = in_array($severity, ['high', 'critical'], true) ? 'requires priority review' : 'is suitable for standard triage';
        $iocText = $iocHits ? ' Threat intelligence enrichment produced IOC context.' : ' No threat intelligence hit is currently attached.';
        return $summary.' Business impact assessment: '.$impact.'.'.$iocText;
    }
}
