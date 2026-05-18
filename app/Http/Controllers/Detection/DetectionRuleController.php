<?php

namespace App\Http\Controllers\Detection;

use App\Http\Controllers\Controller;
use App\Models\DetectionRule;
use App\Models\DetectionRuleNote;
use App\Services\RuleRegistryService;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DetectionRuleController extends Controller
{
    public function __construct(private RuleRegistryService $registry) {}

    public function index(Request $request): View
    {
        $domain = $request->get('domain');
        $stage  = $request->get('stage');

        $rules = $this->registry->allRules();

        if ($domain) {
            $rules = $rules->filter(fn ($r) => $r['domain'] === $domain);
        }
        if ($stage) {
            $rules = $rules->filter(fn ($r) => ($r['db_state']->stage ?? '') === $stage);
        }

        $all     = $this->registry->allRules();
        $mitre   = $this->registry->mitreCoverageMap($all);
        $domains = $all->pluck('domain')->unique()->sort()->values();
        $stages  = $all->map(fn ($r) => $r['db_state']->stage)->unique()->sort()->values();

        $stats = [
            'total'         => $all->count(),
            'staged_active' => $all->filter(fn ($r) => ($r['db_state']->stage ?? '') === 'staged_active')->count(),
            'shadow'        => $all->filter(fn ($r) => ($r['db_state']->stage ?? '') === 'shadow')->count(),
            'draft'         => $all->filter(fn ($r) => ($r['db_state']->stage ?? '') === 'draft')->count(),
            'deprecated'    => $all->filter(fn ($r) => ($r['db_state']->stage ?? '') === 'deprecated')->count(),
            'mitre_count'   => count($mitre),
        ];

        return view('detection.index', compact('rules', 'domains', 'stages', 'stats', 'mitre', 'domain', 'stage'));
    }

    public function show(string $ruleId): View
    {
        $rule = $this->registry->findRule($ruleId);
        abort_if($rule === null, 404);

        return view('detection.show', compact('rule'));
    }

    public function promote(Request $request, string $ruleId): RedirectResponse
    {
        $rule   = $this->registry->findRule($ruleId);
        abort_if($rule === null, 404);

        $targetStage = $request->input('target_stage');
        $result      = $this->registry->validatePromotion($rule, $targetStage);

        if (!$result['passed']) {
            return redirect()
                ->route('detection.show', $ruleId)
                ->withErrors(['promotion' => $result['reason']]);
        }

        $dbRule = $rule['db_state'];

        // Forbidden: endpoint / threat-intel → staged_active
        if ($targetStage === 'staged_active' && in_array($rule['domain'], ['endpoint', 'threat-intel'], true)) {
            return redirect()
                ->route('detection.show', $ruleId)
                ->withErrors(['promotion' => 'Endpoint and threat-intel rules may not be promoted to staged_active. Domain soak not approved.']);
        }

        $before = $dbRule->toArray();
        $dbRule->update([
            'stage'                  => $targetStage,
            'promotion_approved_by'  => $targetStage !== 'deprecated' ? $request->user()->email : $dbRule->promotion_approved_by,
            'promotion_approved_at'  => $targetStage !== 'deprecated' ? now() : $dbRule->promotion_approved_at,
            'gate_results'           => $result['gates'],
        ]);

        AuditLogger::log($request->user()->email, 'detection_rule.promote', 'detection_rule', $ruleId, $before, $dbRule->fresh()->toArray());

        return redirect()->route('detection.show', $ruleId)->with('status', "Rule advanced to $targetStage.");
    }

    public function storeNote(Request $request, string $ruleId): RedirectResponse
    {
        $rule = $this->registry->findRule($ruleId);
        abort_if($rule === null, 404);

        $validated = $request->validate([
            'note_type' => ['required', 'in:false_positive,promotion,suppression,general'],
            'body'      => ['required', 'string', 'max:2000'],
        ]);

        DetectionRuleNote::create([
            'rule_id'   => $ruleId,
            'user_id'   => $request->user()->id,
            'note_type' => $validated['note_type'],
            'body'      => $validated['body'],
        ]);

        AuditLogger::log($request->user()->email, 'detection_rule.note', 'detection_rule', $ruleId, [], ['note_type' => $validated['note_type']]);

        return redirect()->route('detection.show', $ruleId)->with('status', 'Note added.');
    }

    public function updateChecklist(Request $request, string $ruleId): RedirectResponse
    {
        $rule = $this->registry->findRule($ruleId);
        abort_if($rule === null, 404);

        $dbRule       = $rule['db_state'];
        $currentState = $dbRule->checklist_state ?? [];
        $checked      = $request->input('checked', []);
        $stage        = $dbRule->stage;
        $nextMap      = ['draft' => 'shadow', 'shadow' => 'staged_active'];
        $next         = $nextMap[$stage] ?? null;

        if ($next !== null) {
            $keys = RuleRegistryService::checklistKeysFor($stage, $next);
            foreach ($keys as $key) {
                $currentState[$key] = in_array($key, (array) $checked, true);
            }
        }

        $dbRule->update(['checklist_state' => $currentState]);

        return redirect()->route('detection.show', $ruleId)->with('status', 'Checklist updated.');
    }

    public function updateSuppression(Request $request, string $ruleId): RedirectResponse
    {
        $rule = $this->registry->findRule($ruleId);
        abort_if($rule === null, 404);

        $validated = $request->validate([
            'suppression_active'     => ['required', 'boolean'],
            'suppression_reason'     => ['nullable', 'string', 'max:500'],
            'suppression_expires_at' => ['nullable', 'date'],
        ]);

        $dbRule = $rule['db_state'];
        $before = $dbRule->toArray();
        $dbRule->update($validated);

        AuditLogger::log($request->user()->email, 'detection_rule.suppression', 'detection_rule', $ruleId, $before, $dbRule->fresh()->toArray());

        $msg = $validated['suppression_active'] ? 'Suppression applied.' : 'Suppression cleared.';
        return redirect()->route('detection.show', $ruleId)->with('status', $msg);
    }

    public function markReplayValidated(Request $request, string $ruleId): RedirectResponse
    {
        $rule = $this->registry->findRule($ruleId);
        abort_if($rule === null, 404);

        $rule['db_state']->update([
            'replay_validated'    => true,
            'replay_validated_at' => now(),
        ]);

        AuditLogger::log($request->user()->email, 'detection_rule.replay_validated', 'detection_rule', $ruleId, [], []);

        return redirect()->route('detection.show', $ruleId)->with('status', 'Replay validation marked as passed.');
    }

    public function gatesApi(string $ruleId): JsonResponse
    {
        $rule = $this->registry->findRule($ruleId);
        if ($rule === null) {
            return response()->json(['error' => 'not_found'], 404);
        }
        return response()->json([
            'rule_id' => $ruleId,
            'stage'   => $rule['db_state']->stage,
            'gates'   => $rule['gates'],
        ]);
    }
}
