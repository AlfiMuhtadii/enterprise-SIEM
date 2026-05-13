<?php

namespace App\Http\Controllers;

use App\Support\AiAnalystManager;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocAiController extends Controller
{
    public function generate(Request $request, string $incidentId, AiAnalystManager $ai): RedirectResponse
    {
        $data = $request->validate([
            'suggestion_type' => ['required', 'in:incident_summary,evidence_explanation,alert_context,investigation_steps,response_actions,playbook_suggestion,executive_narrative,analyst_assist'],
        ]);
        abort_unless(DB::table('security_incidents')->where('incident_id', $incidentId)->exists(), 404);

        $result = $ai->generateForIncident($incidentId, $data['suggestion_type'], $request->user()->email);
        if (in_array($data['suggestion_type'], ['incident_summary', 'analyst_assist'], true)) {
            DB::table('security_incident_notes')->insert([
                'incident_id' => $incidentId,
                'author' => 'ai:'.config('soc.ai_provider', 'local'),
                'note_type' => 'ai_suggestion',
                'body' => $result['summary'] ?? json_encode($result),
                'metadata' => json_encode(['suggestion_id' => $result['suggestion_id']]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return back()->with('status', 'AI suggestion generated.');
    }

    public function review(Request $request, string $suggestionId): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $before = DB::table('ai_analyst_suggestions')->where('suggestion_id', $suggestionId)->first();
        abort_if(!$before, 404);
        DB::table('ai_analyst_suggestions')->where('suggestion_id', $suggestionId)->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->email,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
            'updated_at' => now(),
        ]);
        $after = DB::table('ai_analyst_suggestions')->where('suggestion_id', $suggestionId)->first();
        AuditLogger::log($request->user()->email, 'ai.review', 'ai_suggestion', $suggestionId, $before, $after);

        return back()->with('status', 'AI suggestion reviewed.');
    }
}
