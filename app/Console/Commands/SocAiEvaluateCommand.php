<?php

namespace App\Console\Commands;

use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SocAiEvaluateCommand extends Command
{
    protected $signature = 'soc:ai-evaluate {--days=7}';
    protected $description = 'Evaluate AI summary quality, hallucination risk, citation coverage, and analyst acceptance rate.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);
        $suggestions = DB::table('ai_analyst_suggestions')->where('created_at', '>=', $since)->get();
        $total = $suggestions->count();
        $accepted = $suggestions->where('status', 'accepted')->count();
        $rejected = $suggestions->where('status', 'rejected')->count();
        $citationBacked = $suggestions->filter(fn ($row) => count(json_decode($row->retrieval_citations ?: '[]', true) ?: []) > 0)->count();
        $guardrailWarnings = DB::table('ai_guardrail_events')->where('detected_at', '>=', $since)->count();

        $summaryAccuracy = $this->summaryAccuracy($suggestions);
        $metrics = [
            'total_outputs' => $total,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'analyst_acceptance_rate' => $total > 0 ? round($accepted / $total, 4) : null,
            'citation_coverage' => $total > 0 ? round($citationBacked / $total, 4) : null,
            'hallucination_rate' => $total > 0 ? round($guardrailWarnings / $total, 4) : 0,
            'summary_accuracy_estimate' => $summaryAccuracy,
            'avg_latency_ms' => (int) DB::table('ai_execution_history')->where('executed_at', '>=', $since)->avg('latency_ms'),
        ];

        $evalId = 'eval-'.Str::uuid();
        DB::table('ai_evaluation_runs')->insert([
            'eval_id' => $evalId,
            'scope' => $days.'d',
            'evaluated_at' => now(),
            'metrics' => json_encode($metrics),
            'samples' => json_encode($suggestions->take(10)->pluck('suggestion_id')->values()),
            'generated_by' => 'cli',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log('cli', 'ai.evaluate', 'ai_evaluation', $evalId, null, $metrics);
        $this->info('eval_id='.$evalId.' total='.$total.' citation_coverage='.($metrics['citation_coverage'] ?? 'n/a'));
        return self::SUCCESS;
    }

    private function summaryAccuracy($suggestions): ?float
    {
        if ($suggestions->isEmpty()) {
            return null;
        }
        $scores = $suggestions->map(function ($row) {
            $output = json_decode($row->output ?: '{}', true) ?: [];
            $context = json_decode($row->input_context ?: '{}', true) ?: [];
            $summary = strtolower((string) ($output['summary'] ?? ''));
            $checks = 0;
            $hits = 0;
            foreach (['incident_id', 'severity', 'status'] as $field) {
                if (!empty($context[$field])) {
                    $checks++;
                    $hits += str_contains($summary, strtolower((string) $context[$field])) ? 1 : 0;
                }
            }
            return $checks > 0 ? $hits / $checks : 0.5;
        });
        return round((float) $scores->avg(), 4);
    }
}
