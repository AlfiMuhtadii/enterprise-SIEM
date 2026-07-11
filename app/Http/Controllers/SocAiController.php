<?php

namespace App\Http\Controllers;

use App\Services\TenantContextAuthority;
use App\Support\AiAnalystManager;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SocAiController extends Controller
{
    public function __construct(private readonly TenantContextAuthority $tenantAuthority)
    {
    }

    /**
     * AI-2: null-permissive tenant ownership check -- a null tenant_id on
     * either side is treated as "unscoped/legacy" and always allowed, matching
     * every other ENT-TENANCY-* fix's convention (assertAccess/resolveEntity).
     */
    private function ownedByTenant(?string $recordTenantId, ?string $requestTenantId): bool
    {
        return $recordTenantId === null || $requestTenantId === null || $recordTenantId === $requestTenantId;
    }

    public function generate(Request $request, string $incidentId, AiAnalystManager $ai): RedirectResponse
    {
        $data = $request->validate([
            'suggestion_type' => ['required', 'in:incident_summary,evidence_explanation,alert_context,investigation_steps,response_actions,playbook_suggestion,executive_narrative,analyst_assist'],
        ]);
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $incident = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        abort_if(!$incident || !$this->ownedByTenant($incident->tenant_id, $tenantId), 404);

        $result = $ai->generateForIncident($incidentId, $data['suggestion_type'], $request->user()->email, $tenantId);
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

    public function review(Request $request, string $suggestionId, AiAnalystManager $ai): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:accepted,rejected'],
            'review_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $tenantId = $this->tenantAuthority->validateAndResolve($request, $request->user(), requireTenantContext: false);
        $before = DB::table('ai_analyst_suggestions')->where('suggestion_id', $suggestionId)->first();
        abort_if(!$before || !$this->ownedByTenant($before->tenant_id, $tenantId), 404);
        DB::table('ai_analyst_suggestions')->where('suggestion_id', $suggestionId)->update([
            'status' => $data['status'],
            'reviewed_by' => $request->user()->email,
            'reviewed_at' => now(),
            'review_note' => $data['review_note'] ?? null,
            'updated_at' => now(),
        ]);
        $after = DB::table('ai_analyst_suggestions')->where('suggestion_id', $suggestionId)->first();
        AuditLogger::log($request->user()->email, 'ai.review', 'ai_suggestion', $suggestionId, $before, $after);

        if ($data['status'] === 'accepted') {
            $ai->ingestApprovedFeedback($suggestionId, $request->user()->email, $data['review_note'] ?? null);
        }

        return back()->with('status', 'AI suggestion reviewed.');
    }
}
