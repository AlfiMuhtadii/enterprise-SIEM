<?php

namespace App\Http\Controllers;

use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SocIncidentController extends Controller
{
    public function show(string $incidentId): View
    {
        $incident = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        abort_if(!$incident, 404);

        return view('soc.incident', [
            'incident' => $incident,
            'alerts' => DB::table('security_alerts')->where('incident_id', $incidentId)->orderByDesc('detected_at')->get(),
            'notes' => DB::table('security_incident_notes')->where('incident_id', $incidentId)->orderByDesc('created_at')->get(),
            'activities' => DB::table('security_incident_activities')->where('incident_id', $incidentId)->orderByDesc('created_at')->get(),
            'audit' => DB::table('security_audit_trails')->where('target_type', 'incident')->where('target_id', $incidentId)->orderByDesc('occurred_at')->limit(50)->get(),
            'aiSuggestions' => DB::table('ai_analyst_suggestions')->where('target_type', 'incident')->where('target_id', $incidentId)->orderByDesc('created_at')->limit(20)->get(),
            'knowledgeRefs' => DB::table('soc_knowledge_base')
                ->where('related_incident_id', $incidentId)
                ->orWhereIn('related_rule_id', DB::table('security_alerts')->where('incident_id', $incidentId)->pluck('detector_name')->filter()->unique()->values())
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(),
            'playbooks' => DB::table('incident_playbooks')->where('incident_id', $incidentId)->orderByDesc('created_at')->get(),
            'playbookTasks' => DB::table('incident_playbook_tasks')
                ->whereIn('playbook_id', DB::table('incident_playbooks')->where('incident_id', $incidentId)->pluck('playbook_id'))
                ->orderBy('sort_order')
                ->get()
                ->groupBy('playbook_id'),
            'affectedEntities' => json_decode($incident->affected_entities ?: '[]', true) ?: [],
            'timeline' => json_decode($incident->timeline ?: '[]', true) ?: [],
            'mitreMapping' => json_decode($incident->mitre_mapping ?: '[]', true) ?: [],
        ]);
    }

    public function update(Request $request, string $incidentId): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'in:open,triaged,investigating,resolved,false_positive'],
            'severity' => ['nullable', 'in:low,medium,high,critical'],
            'assigned_analyst' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:5000'],
            'resolution_summary' => ['nullable', 'string', 'max:5000'],
            'action' => ['nullable', 'string', 'max:80'],
        ]);

        $before = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        abort_if(!$before, 404);

        $updates = ['updated_at' => now()];
        foreach (['status', 'severity', 'assigned_analyst', 'resolution_summary'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $data[$field];
            }
        }
        if (($data['status'] ?? null) === 'resolved' || ($data['status'] ?? null) === 'false_positive') {
            $updates['resolved_at'] = now();
        }
        if (($data['action'] ?? null) === 'escalate') {
            $updates['escalation_level'] = DB::raw('escalation_level + 1');
        }

        DB::table('security_incidents')->where('incident_id', $incidentId)->update($updates);

        if (!empty($data['note'])) {
            DB::table('security_incident_notes')->insert([
                'incident_id' => $incidentId,
                'author' => $request->user()->email,
                'note_type' => $data['action'] ?: 'note',
                'body' => $data['note'],
                'metadata' => json_encode(['source' => 'incident-detail']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $after = DB::table('security_incidents')->where('incident_id', $incidentId)->first();
        DB::table('security_incident_activities')->insert([
            'incident_id' => $incidentId,
            'actor' => $request->user()->email,
            'activity_type' => $data['action'] ?: 'update',
            'before_state' => json_encode($before),
            'after_state' => json_encode($after),
            'metadata' => json_encode(['source' => 'incident-detail']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        AuditLogger::log($request->user()->email, 'incident.update', 'incident', $incidentId, $before, $after);

        return redirect()->route('soc.incidents.show', $incidentId)->with('status', 'Incident updated.');
    }
}
