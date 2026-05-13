<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SocTuningController extends Controller
{
    public function index(Request $request): View
    {
        $minutes = max(15, min(10080, (int) $request->query('minutes', 1440)));
        $since = now()->subMinutes($minutes);

        $feedbackSummary = DB::table('alert_feedback')
            ->select('verdict', DB::raw('count(*) as total'))
            ->where('marked_at', '>=', $since)
            ->groupBy('verdict')
            ->orderByDesc('total')
            ->get();

        $ruleMetrics = DB::table('security_alerts as a')
            ->leftJoin('alert_feedback as f', 'a.alert_id', '=', 'f.alert_id')
            ->selectRaw("COALESCE(a.detector_name, a.alert_type) as rule_key")
            ->selectRaw('count(a.id) as alerts')
            ->selectRaw("sum(case when f.verdict = 'true_positive' then 1 else 0 end) as true_positive")
            ->selectRaw("sum(case when f.verdict = 'false_positive' then 1 else 0 end) as false_positive")
            ->where('a.detected_at', '>=', $since)
            ->groupBy('rule_key')
            ->orderByDesc('alerts')
            ->limit(50)
            ->get()
            ->map(function ($row) {
                $tp = (int) $row->true_positive;
                $fp = (int) $row->false_positive;
                $row->effectiveness = ($tp + $fp) > 0 ? round($tp / ($tp + $fp), 3) : null;
                $row->suggestion = $fp > $tp && $fp >= 3 ? 'review threshold or add suppression' : ($tp >= 3 ? 'rule effective' : 'needs more feedback');
                return $row;
            });

        return view('soc.tuning.index', [
            'minutes' => $minutes,
            'alerts' => DB::table('security_alerts')->where('detected_at', '>=', $since)->orderByDesc('detected_at')->limit(100)->get(),
            'feedback' => DB::table('alert_feedback')->orderByDesc('marked_at')->limit(100)->get(),
            'suppressions' => DB::table('alert_suppression_rules')->orderByDesc('created_at')->limit(100)->get(),
            'suppressionHistory' => DB::table('alert_suppression_history')->orderByDesc('suppressed_at')->limit(100)->get(),
            'notes' => DB::table('rule_tuning_notes')->orderByDesc('created_at')->limit(100)->get(),
            'feedbackSummary' => $feedbackSummary,
            'ruleMetrics' => $ruleMetrics,
        ]);
    }

    public function mark(Request $request, string $alertId): RedirectResponse
    {
        $data = $request->validate([
            'verdict' => ['required', 'in:true_positive,false_positive,benign,needs_review'],
            'reason' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'rule_id' => ['nullable', 'string', 'max:120'],
        ]);
        abort_unless(DB::table('security_alerts')->where('alert_id', $alertId)->exists(), 404);

        DB::table('alert_feedback')->insert([
            'alert_id' => $alertId,
            'verdict' => $data['verdict'],
            'reason' => $data['reason'] ?? null,
            'notes' => $data['notes'] ?? null,
            'rule_id' => $data['rule_id'] ?? null,
            'analyst' => $request->user()->email,
            'marked_at' => now(),
            'metadata' => json_encode(['source' => 'soc-tuning-ui']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'alert.feedback', 'alert', $alertId, null, $data);

        return back()->with('status', 'Alert feedback recorded.');
    }

    public function suppress(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'scope' => ['required', 'in:alert_type,actor_key,ip,rule_id'],
            'match_value' => ['required', 'string', 'max:200'],
            'rule_id' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
        ]);
        $suppressionId = 'sup-'.Str::uuid();
        $expiresAt = isset($data['expires_in_hours']) ? now()->addHours((int) $data['expires_in_hours']) : null;
        DB::table('alert_suppression_rules')->insert([
            'suppression_id' => $suppressionId,
            'scope' => $data['scope'],
            'match_value' => $data['match_value'],
            'rule_id' => $data['rule_id'] ?? null,
            'reason' => $data['reason'] ?? null,
            'enabled' => true,
            'expires_at' => $expiresAt,
            'created_by' => $request->user()->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'alert.suppression.create', 'suppression', $suppressionId, null, $data);

        return back()->with('status', 'Suppression rule created.');
    }

    public function applySuppressions(Request $request): RedirectResponse
    {
        $count = $this->applyActiveSuppressions($request->user()->email);
        return back()->with('status', "Suppression applied to {$count} alerts.");
    }

    public function note(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'rule_id' => ['required', 'string', 'max:120'],
            'suggestion_type' => ['nullable', 'string', 'max:80'],
            'note' => ['required', 'string', 'max:5000'],
        ]);
        DB::table('rule_tuning_notes')->insert([
            'rule_id' => $data['rule_id'],
            'suggestion_type' => $data['suggestion_type'] ?? null,
            'note' => $data['note'],
            'analyst' => $request->user()->email,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'rule.tuning_note', 'rule', $data['rule_id'], null, $data);

        return back()->with('status', 'Rule tuning note saved.');
    }

    private function applyActiveSuppressions(string $actor): int
    {
        $rules = DB::table('alert_suppression_rules')
            ->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->get();
        $count = 0;
        foreach ($rules as $rule) {
            $query = DB::table('security_alerts')->whereRaw('COALESCE(is_suppressed, false)=false');
            if ($rule->scope === 'alert_type') {
                $query->where('alert_type', $rule->match_value);
            } elseif ($rule->scope === 'actor_key') {
                $query->where('actor_key', $rule->match_value);
            } elseif ($rule->scope === 'ip') {
                $query->where('ip', $rule->match_value);
            } elseif ($rule->scope === 'rule_id') {
                $query->where('detector_name', $rule->match_value);
            }
            $alerts = $query->limit(500)->get();
            foreach ($alerts as $alert) {
                DB::table('security_alerts')->where('alert_id', $alert->alert_id)->update([
                    'is_suppressed' => true,
                    'suppressed_until' => $rule->expires_at,
                    'updated_at' => now(),
                ]);
                DB::table('alert_suppression_history')->insert([
                    'suppression_id' => $rule->suppression_id,
                    'alert_id' => $alert->alert_id,
                    'suppressed_by' => $actor,
                    'suppressed_at' => now(),
                    'reason' => $rule->reason,
                    'metadata' => json_encode(['scope' => $rule->scope, 'match_value' => $rule->match_value]),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $count++;
            }
        }
        AuditLogger::log($actor, 'alert.suppression.apply', 'suppression', null, null, ['suppressed_alerts' => $count]);
        return $count;
    }
}
