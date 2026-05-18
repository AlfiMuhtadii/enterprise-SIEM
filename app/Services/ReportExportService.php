<?php

namespace App\Services;

use App\Models\ExportAuditLog;
use App\Support\TraceRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Report Export Service — documentation and audit evidence exports only.
 *
 * CONSTRAINTS:
 * - Read-only: no pipeline mutations, no infrastructure calls.
 * - TraceRedactor::deep() applied to ALL exported data before rendering.
 * - Secrets, tokens, passwords, cookies, authorization headers → [REDACTED].
 * - Every export creates an immutable append-only ExportAuditLog record.
 * - Disclaimer appears in every export format.
 */
class ReportExportService
{
    public const DISCLAIMER = 'Recommendations are advisory-only and were not automatically executed by the platform.';

    public const SUPPORTED_FORMATS = ['json', 'markdown', 'html'];

    // -------------------------------------------------------------------------
    // Public export entry points
    // -------------------------------------------------------------------------

    public function exportInvestigation(int $id, string $format, int $userId, ?string $reason = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildInvestigationReport($id, $userId, $reason);

        if (empty($data['investigation'])) {
            throw new \InvalidArgumentException("Investigation not found: {$id}");
        }

        return $this->finalize($data, 'investigation', $format, $userId, $reason, (string) $id);
    }

    public function exportResponsePlan(int $id, string $format, int $userId, ?string $reason = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildResponsePlanReport($id, $userId, $reason);

        if (empty($data['response_plan'])) {
            throw new \InvalidArgumentException("Response plan not found: {$id}");
        }

        return $this->finalize($data, 'response_plan', $format, $userId, $reason, (string) $id);
    }

    public function exportEntityRisk(int $entityId, string $format, int $userId, ?string $reason = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildEntityRiskReport($entityId, $userId, $reason);

        if (empty($data['entity'])) {
            throw new \InvalidArgumentException("Entity not found: {$entityId}");
        }

        return $this->finalize($data, 'entity_risk', $format, $userId, $reason, (string) $entityId);
    }

    public function exportTrace(string $traceId, string $format, int $userId, ?string $reason = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildTraceReport($traceId, $userId, $reason);

        if (empty($data['trace'])) {
            throw new \InvalidArgumentException("Trace not found: {$traceId}");
        }

        return $this->finalize($data, 'trace', $format, $userId, $reason, $traceId);
    }

    public function getHistory(array $filters = [], int $limit = 100): Collection
    {
        $query = DB::table('export_audit_logs as e')
            ->leftJoin('users', 'users.id', '=', 'e.exported_by')
            ->select('e.*', 'users.name as exported_by_name')
            ->orderByDesc('e.exported_at')
            ->limit($limit);

        if (!empty($filters['export_type'])) {
            $query->where('e.export_type', $filters['export_type']);
        }
        if (!empty($filters['exported_by'])) {
            $query->where('e.exported_by', $filters['exported_by']);
        }

        return $query->get();
    }

    public function getStatCounts(): Collection
    {
        return DB::table('export_audit_logs')
            ->select('export_type', DB::raw('count(*) as count'))
            ->groupBy('export_type')
            ->get()
            ->keyBy('export_type');
    }

    // -------------------------------------------------------------------------
    // Report builders — read-only
    // -------------------------------------------------------------------------

    private function buildInvestigationReport(int $id, int $userId, ?string $reason): array
    {
        $inv = DB::table('investigations as i')
            ->leftJoin('users as cr', 'cr.id', '=', 'i.created_by')
            ->leftJoin('users as as', 'as.id', '=', 'i.assigned_to')
            ->select('i.*', 'cr.name as creator_name', 'as.name as assignee_name')
            ->where('i.id', $id)
            ->first();

        if (!$inv) {
            return ['investigation' => null, 'export_meta' => $this->meta('investigation', 'Investigation Summary Report', $id, $userId, $reason)];
        }

        $events = DB::table('investigation_events as e')
            ->leftJoin('users', 'users.id', '=', 'e.actor_user_id')
            ->select('e.*', 'users.name as actor_name')
            ->where('e.investigation_id', $id)
            ->orderBy('occurred_at')
            ->get();

        $assignments = DB::table('investigation_assignments as a')
            ->leftJoin('users', 'users.id', '=', 'a.assigned_to_user_id')
            ->select('a.*', 'users.name as assignee_name')
            ->where('a.investigation_id', $id)
            ->orderBy('assigned_at')
            ->get();

        $notes     = DB::table('investigation_notes')->where('investigation_id', $id)->orderBy('created_at')->get();
        $artifacts = DB::table('investigation_artifacts')->where('investigation_id', $id)->orderBy('created_at')->get();

        $alertIds    = $this->jsonDecode($inv->alert_ids);
        $incidentIds = $this->jsonDecode($inv->incident_ids);
        $entityIds   = $this->jsonDecode($inv->entity_ids);

        $linkedAlerts    = !empty($alertIds)
            ? TraceRedactor::collection(DB::table('security_alerts')->whereIn('alert_id', $alertIds)->get())->toArray()
            : [];
        $linkedIncidents = !empty($incidentIds)
            ? TraceRedactor::collection(DB::table('security_incidents')->whereIn('incident_id', $incidentIds)->get())->toArray()
            : [];
        $linkedEntities  = !empty($entityIds)
            ? DB::table('entities')->whereIn('id', $entityIds)->get()->toArray()
            : [];

        $responsePlans = DB::table('response_plans')
            ->where('investigation_id', $id)
            ->orderBy('created_at')
            ->get();

        return [
            'export_meta'          => $this->meta('investigation', 'Investigation Summary Report', $id, $userId, $reason),
            'investigation'        => (array) $inv,
            'state_history'        => $events->where('event_type', 'state_transition')->values()->toArray(),
            'assignment_history'   => $assignments->toArray(),
            'analyst_notes'        => $notes->toArray(),
            'artifacts'            => $artifacts->toArray(),
            'timeline_summary'     => $events->toArray(),
            'linked_alerts'        => $linkedAlerts,
            'linked_incidents'     => $linkedIncidents,
            'linked_entities'      => $linkedEntities,
            'linked_response_plans'=> $responsePlans->toArray(),
        ];
    }

    private function buildResponsePlanReport(int $id, int $userId, ?string $reason): array
    {
        $plan = DB::table('response_plans as rp')
            ->leftJoin('users as cr', 'cr.id', '=', 'rp.created_by')
            ->select('rp.*', 'cr.name as creator_name')
            ->where('rp.id', $id)
            ->first();

        if (!$plan) {
            return ['response_plan' => null, 'export_meta' => $this->meta('response_plan', 'Response Plan Report', $id, $userId, $reason)];
        }

        $actions   = DB::table('response_plan_actions')->where('response_plan_id', $id)->orderBy('created_at')->get();
        $approvals = DB::table('response_plan_approvals as a')
            ->leftJoin('users', 'users.id', '=', 'a.approver_user_id')
            ->select('a.*', 'users.name as approver_name')
            ->where('a.response_plan_id', $id)
            ->orderBy('decision_at')
            ->get();
        $notes = DB::table('response_plan_notes')->where('response_plan_id', $id)->orderBy('created_at')->get();

        $alertIds  = $this->jsonDecode($plan->related_alert_ids);
        $entityIds = $this->jsonDecode($plan->entity_ids);

        $linkedAlerts   = !empty($alertIds)
            ? TraceRedactor::collection(DB::table('security_alerts')->whereIn('alert_id', $alertIds)->get())->toArray()
            : [];
        $linkedEntities = !empty($entityIds)
            ? DB::table('entities')->whereIn('id', $entityIds)->get()->toArray()
            : [];

        return [
            'export_meta'           => $this->meta('response_plan', 'Response Plan Report', $id, $userId, $reason),
            'response_plan'         => (array) $plan,
            'actions'               => $actions->toArray(),
            'approval_chain'        => $approvals->where('decision', 'approved')->values()->toArray(),
            'rejection_history'     => $approvals->where('decision', 'rejected')->values()->toArray(),
            'full_approval_history' => $approvals->toArray(),
            'analyst_notes'         => $notes->toArray(),
            'linked_alerts'         => $linkedAlerts,
            'linked_entities'       => $linkedEntities,
        ];
    }

    private function buildEntityRiskReport(int $entityId, int $userId, ?string $reason): array
    {
        $entity = DB::table('entities')->where('id', $entityId)->first();

        if (!$entity) {
            return ['entity' => null, 'export_meta' => $this->meta('entity_risk', 'Entity Risk Report', $entityId, $userId, $reason)];
        }

        $riskFactors = $this->jsonDecode($entity->risk_factors) ?? [];

        $snapshots = DB::table('entity_risk_snapshots')
            ->where('entity_id', $entityId)
            ->orderBy('calculated_at')
            ->get();

        $observations = DB::table('entity_observations')
            ->where('entity_id', $entityId)
            ->orderBy('observed_at')
            ->limit(50)
            ->get();

        $relationships = DB::table('entity_relationships as r')
            ->join('entities as t', 't.id', '=', 'r.target_entity_id')
            ->where('r.source_entity_id', $entityId)
            ->select('r.relationship_type', 'r.observation_count', 'r.trace_id',
                     't.entity_type as peer_type', 't.entity_key as peer_key')
            ->limit(20)
            ->get();

        $traceIds = $observations->pluck('trace_id')->filter()->unique()->values()->toArray();
        $alertIds = collect($riskFactors)->flatMap(fn ($f) => $f['alert_ids'] ?? [])->unique()->values()->toArray();

        $linkedAlerts = !empty($alertIds)
            ? TraceRedactor::collection(DB::table('security_alerts')->whereIn('alert_id', $alertIds)->limit(20)->get())->toArray()
            : [];

        return [
            'export_meta'         => $this->meta('entity_risk', 'Entity Risk Report', $entityId, $userId, $reason),
            'entity'              => (array) $entity,
            'current_risk'        => [
                'risk_score'   => $entity->risk_score,
                'risk_level'   => $entity->risk_level,
                'risk_factors' => $riskFactors,
                'calculated_at'=> $entity->last_risk_calculated_at,
            ],
            'historical_snapshots'=> $snapshots->toArray(),
            'observations'        => $observations->toArray(),
            'relationships'       => $relationships->toArray(),
            'linked_traces'       => $traceIds,
            'linked_alerts'       => $linkedAlerts,
        ];
    }

    private function buildTraceReport(string $traceId, int $userId, ?string $reason): array
    {
        $alertCount = DB::table('security_alerts')->where('trace_id', $traceId)->count();
        $opCount    = DB::table('xdr_operational_events')->where('trace_id', $traceId)->count();
        $incCount   = DB::table('security_incidents')->where('trace_id', $traceId)->count();

        if ($alertCount === 0 && $opCount === 0 && $incCount === 0) {
            return ['trace' => null, 'export_meta' => $this->meta('trace', 'Trace Investigation Report', $traceId, $userId, $reason)];
        }

        $opEvents = DB::table('xdr_operational_events')
            ->where('trace_id', $traceId)
            ->orderBy('occurred_at')
            ->get();

        $alerts    = TraceRedactor::collection(
            DB::table('security_alerts')->where('trace_id', $traceId)->orderBy('detected_at')->get()
        )->toArray();

        $incidents = TraceRedactor::collection(
            DB::table('security_incidents')->where('trace_id', $traceId)->orderBy('first_seen_at')->get()
        )->toArray();

        $evidence = DB::table('scenario_evidence')
            ->where('trace_id', $traceId)
            ->orderBy('processed_at')
            ->get();

        $entityTraces = DB::table('entity_observations')
            ->where('trace_id', $traceId)
            ->select('entity_id', 'observation_type', 'source_table', 'observed_at')
            ->orderBy('observed_at')
            ->get();

        $investigations = DB::table('investigations')
            ->where('trace_id', $traceId)
            ->select('investigation_id', 'title', 'state', 'severity')
            ->get();

        $responsePlans = DB::table('response_plans')
            ->where('trace_id', $traceId)
            ->select('plan_id', 'title', 'state')
            ->get();

        return [
            'export_meta'             => $this->meta('trace', 'Trace Investigation Report', $traceId, $userId, $reason),
            'trace'                   => [
                'trace_id'         => $traceId,
                'first_seen'       => collect($opEvents)->min('occurred_at'),
                'last_seen'        => collect($opEvents)->max('occurred_at'),
                'alert_count'      => $alertCount,
                'incident_count'   => $incCount,
                'op_event_count'   => $opCount,
            ],
            'pipeline_stages'         => $opEvents->toArray(),
            'alerts_generated'        => $alerts,
            'incidents_linked'        => $incidents,
            'scenario_evidence'       => $evidence->toArray(),
            'entity_involvement'      => $entityTraces->toArray(),
            'linked_investigations'   => $investigations->toArray(),
            'linked_response_plans'   => $responsePlans->toArray(),
        ];
    }

    // -------------------------------------------------------------------------
    // Format renderers
    // -------------------------------------------------------------------------

    private function renderJson(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function renderMarkdown(array $data): string
    {
        $meta  = $data['export_meta'];
        $lines = [];

        $lines[] = "# {$meta['report_type_label']}";
        $lines[] = '';
        $lines[] = '> ⚠️ **Advisory Notice:** ' . self::DISCLAIMER;
        $lines[] = '';
        $lines[] = '## Export Metadata';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '|-------|-------|';
        $lines[] = '| Report Type | ' . $meta['report_type_label'] . ' |';
        $lines[] = '| Generated At | ' . $meta['generated_at'] . ' |';
        $lines[] = '| Generated By | ' . $meta['generated_by'] . ' |';

        if (!empty($meta['export_reason'])) {
            $lines[] = '| Export Reason | ' . $meta['export_reason'] . ' |';
        }

        $lines[] = '| Source ID | ' . $meta['source_id'] . ' |';
        $lines[] = '';

        switch ($meta['report_type']) {
            case 'investigation':
                $lines = array_merge($lines, $this->mdInvestigation($data));
                break;
            case 'response_plan':
                $lines = array_merge($lines, $this->mdResponsePlan($data));
                break;
            case 'entity_risk':
                $lines = array_merge($lines, $this->mdEntityRisk($data));
                break;
            case 'trace':
                $lines = array_merge($lines, $this->mdTrace($data));
                break;
        }

        $lines[] = '';
        $lines[] = '---';
        $lines[] = '_Generated by XDR Platform | ' . $meta['generated_at'] . '_';

        return implode("\n", $lines);
    }

    private function renderHtml(array $data): string
    {
        $meta  = $data['export_meta'];
        $style = $this->htmlStyles();
        $title = htmlspecialchars($meta['report_type_label']);
        $by    = htmlspecialchars($meta['generated_by']);
        $at    = htmlspecialchars($meta['generated_at']);
        $disc  = htmlspecialchars(self::DISCLAIMER);
        $srcId = htmlspecialchars($meta['source_id']);
        $reason = !empty($meta['export_reason']) ? htmlspecialchars($meta['export_reason']) : '—';

        $body = $this->htmlBody($data);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title}</title>
<style>{$style}</style>
</head>
<body>
<div class="report-header">
  <h1>{$title}</h1>
  <div class="meta-grid">
    <div><span class="meta-label">Generated</span>{$at}</div>
    <div><span class="meta-label">By</span>{$by}</div>
    <div><span class="meta-label">Source ID</span><code>{$srcId}</code></div>
    <div><span class="meta-label">Reason</span>{$reason}</div>
  </div>
</div>

<div class="disclaimer-box">
  <strong>⚠️ Advisory Notice:</strong> {$disc}
</div>

{$body}

<div class="report-footer">
  <p>XDR Platform | {$title} | Generated {$at} | Source: {$srcId}</p>
</div>
</body>
</html>
HTML;
    }

    // -------------------------------------------------------------------------
    // Markdown section builders
    // -------------------------------------------------------------------------

    private function mdInvestigation(array $data): array
    {
        $inv   = $data['investigation'] ?? [];
        $lines = [];

        $lines[] = '## Investigation Details';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '|-------|-------|';

        foreach (['investigation_id', 'title', 'state', 'severity', 'priority', 'creator_name', 'assignee_name'] as $f) {
            if (isset($inv[$f])) {
                $lines[] = '| ' . ucwords(str_replace('_', ' ', $f)) . ' | ' . $inv[$f] . ' |';
            }
        }

        $lines[] = '';
        $lines[] = '## State History';
        $lines[] = '';

        foreach ($data['state_history'] ?? [] as $evt) {
            $evt = (array) $evt;
            $lines[] = '- `' . ($evt['occurred_at'] ?? '') . '` **' . ($evt['from_state'] ?? '?') . '** → **' . ($evt['to_state'] ?? '?') . '** by ' . ($evt['actor_name'] ?? 'system');
        }

        $lines[] = '';
        $lines[] = '## Assignment History';
        $lines[] = '';

        foreach ($data['assignment_history'] ?? [] as $a) {
            $a = (array) $a;
            $lines[] = '- `' . ($a['assigned_at'] ?? '') . '` → **' . ($a['assignee_name'] ?? 'unknown') . '** (' . ($a['is_active'] ? 'active' : 'past') . ')';
        }

        $lines[] = '';
        $lines[] = '## Analyst Notes';
        $lines[] = '';

        foreach ($data['analyst_notes'] ?? [] as $note) {
            $note = (array) $note;
            $lines[] = '### ' . ($note['note_type'] ?? 'note') . ' — `' . ($note['created_at'] ?? '') . '`';
            $lines[] = '';
            $lines[] = ($note['body'] ?? '');
            $lines[] = '';
        }

        $lines[] = '## Linked Alerts';
        $lines[] = '';
        $lines[] = '| Alert ID | Type | Severity | Detected At |';
        $lines[] = '|---------|------|----------|-------------|';

        foreach ($data['linked_alerts'] ?? [] as $al) {
            $al = (array) $al;
            $lines[] = '| ' . ($al['alert_id'] ?? '') . ' | ' . ($al['alert_type'] ?? '') . ' | ' . ($al['severity'] ?? '') . ' | ' . ($al['detected_at'] ?? '') . ' |';
        }

        $lines[] = '';

        return $lines;
    }

    private function mdResponsePlan(array $data): array
    {
        $plan  = $data['response_plan'] ?? [];
        $lines = [];

        $lines[] = '## Response Plan Details';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '|-------|-------|';

        foreach (['plan_id', 'title', 'state', 'risk_level', 'creator_name'] as $f) {
            if (isset($plan[$f])) {
                $lines[] = '| ' . ucwords(str_replace('_', ' ', $f)) . ' | ' . $plan[$f] . ' |';
            }
        }

        $lines[] = '';
        $lines[] = '## Recommended Actions';
        $lines[] = '';
        $lines[] = '| Action | Target | Status | Advisory Only | Reasoning |';
        $lines[] = '|--------|--------|--------|---------------|-----------|';

        foreach ($data['actions'] ?? [] as $act) {
            $act = (array) $act;
            $advisory = $act['advisory_only'] ? '✓ yes' : 'no';
            $lines[] = '| `' . ($act['action_type'] ?? '') . '` | ' . ($act['target_entity_key'] ?? '') . ' | ' . ($act['status'] ?? '') . ' | ' . $advisory . ' | ' . Str_limit($act['reasoning'] ?? '', 60) . ' |';
        }

        $lines[] = '';
        $lines[] = '## Approval Chain';
        $lines[] = '';

        foreach ($data['full_approval_history'] ?? [] as $appr) {
            $appr = (array) $appr;
            $lines[] = '- `' . ($appr['decision_at'] ?? '') . '` **' . ($appr['decision'] ?? '') . '** by ' . ($appr['approver_name'] ?? 'unknown');
            if (!empty($appr['notes'])) {
                $lines[] = '  - Note: ' . $appr['notes'];
            }
        }

        $lines[] = '';

        return $lines;
    }

    private function mdEntityRisk(array $data): array
    {
        $entity = $data['entity'] ?? [];
        $risk   = $data['current_risk'] ?? [];
        $lines  = [];

        $lines[] = '## Entity';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '|-------|-------|';
        $lines[] = '| Type | ' . ($entity['entity_type'] ?? '') . ' |';
        $lines[] = '| Key | `' . ($entity['entity_key'] ?? '') . '` |';
        $lines[] = '| Risk Score | **' . number_format((float) ($risk['risk_score'] ?? 0), 1) . ' / 10** |';
        $lines[] = '| Risk Level | **' . strtoupper($risk['risk_level'] ?? 'unknown') . '** |';
        $lines[] = '| Calculated At | ' . ($risk['calculated_at'] ?? '—') . ' |';
        $lines[] = '';
        $lines[] = '## Contributing Risk Factors';
        $lines[] = '';
        $lines[] = '| Factor | Value | Weight | Contribution | Advisory Only |';
        $lines[] = '|--------|-------|--------|--------------|---------------|';

        foreach ($risk['risk_factors'] ?? [] as $f) {
            $f = (array) $f;
            $adv = empty($f['advisory_only']) ? 'no' : '✓ yes (shadow domain)';
            $lines[] = '| `' . ($f['factor'] ?? '') . '` | ' . ($f['value'] ?? 0) . ' | ' . number_format((float) ($f['weight'] ?? 0), 1) . ' | ' . number_format((float) ($f['contribution'] ?? 0), 2) . ' | ' . $adv . ' |';
        }

        $lines[] = '';
        $lines[] = '## Historical Snapshots';
        $lines[] = '';
        $lines[] = '| Calculated At | Score | Level |';
        $lines[] = '|---------------|-------|-------|';

        foreach ($data['historical_snapshots'] ?? [] as $snap) {
            $snap = (array) $snap;
            $lines[] = '| ' . ($snap['calculated_at'] ?? '') . ' | ' . number_format((float) ($snap['risk_score'] ?? 0), 1) . ' | ' . ($snap['risk_level'] ?? '') . ' |';
        }

        $lines[] = '';

        return $lines;
    }

    private function mdTrace(array $data): array
    {
        $trace = $data['trace'] ?? [];
        $lines = [];

        $lines[] = '## Trace';
        $lines[] = '';
        $lines[] = '| Field | Value |';
        $lines[] = '|-------|-------|';
        $lines[] = '| Trace ID | `' . ($trace['trace_id'] ?? '') . '` |';
        $lines[] = '| First Seen | ' . ($trace['first_seen'] ?? '—') . ' |';
        $lines[] = '| Last Seen | ' . ($trace['last_seen'] ?? '—') . ' |';
        $lines[] = '| Alerts | ' . ($trace['alert_count'] ?? 0) . ' |';
        $lines[] = '| Incidents | ' . ($trace['incident_count'] ?? 0) . ' |';
        $lines[] = '| Pipeline Events | ' . ($trace['op_event_count'] ?? 0) . ' |';
        $lines[] = '';
        $lines[] = '## Alerts Generated';
        $lines[] = '';
        $lines[] = '| Alert Type | Severity | Detected At |';
        $lines[] = '|------------|----------|-------------|';

        foreach ($data['alerts_generated'] ?? [] as $al) {
            $al = (array) $al;
            $lines[] = '| ' . ($al['alert_type'] ?? '') . ' | ' . ($al['severity'] ?? '') . ' | ' . ($al['detected_at'] ?? '') . ' |';
        }

        $lines[] = '';
        $lines[] = '## Pipeline Event Chain';
        $lines[] = '';

        foreach ($data['pipeline_stages'] ?? [] as $evt) {
            $evt = (array) $evt;
            $lines[] = '- `' . ($evt['occurred_at'] ?? '') . '` [' . ($evt['source_service'] ?? '') . '] ' . ($evt['event_type'] ?? '');
        }

        $lines[] = '';

        return $lines;
    }

    // -------------------------------------------------------------------------
    // HTML body builder
    // -------------------------------------------------------------------------

    private function htmlBody(array $data): string
    {
        $type = $data['export_meta']['report_type'];
        $h = '';

        switch ($type) {
            case 'investigation':
                $h .= $this->htmlInvestigation($data);
                break;
            case 'response_plan':
                $h .= $this->htmlResponsePlan($data);
                break;
            case 'entity_risk':
                $h .= $this->htmlEntityRisk($data);
                break;
            case 'trace':
                $h .= $this->htmlTrace($data);
                break;
        }

        return $h;
    }

    private function htmlInvestigation(array $data): string
    {
        $inv = $data['investigation'] ?? [];
        $h   = $this->htmlSection('Investigation Details', $this->htmlKvTable([
            'ID'         => $inv['investigation_id'] ?? '—',
            'Title'      => $inv['title'] ?? '—',
            'State'      => '<code>' . ($inv['state'] ?? '—') . '</code>',
            'Severity'   => strtoupper($inv['severity'] ?? '—'),
            'Priority'   => 'P' . ($inv['priority'] ?? '—'),
            'Created By' => $inv['creator_name'] ?? '—',
            'Assigned To'=> $inv['assignee_name'] ?? 'Unassigned',
        ]));

        if (!empty($inv['description'])) {
            $h .= $this->htmlSection('Description', '<p>' . htmlspecialchars($inv['description']) . '</p>');
        }

        if (!empty($data['state_history'])) {
            $rows = array_map(fn($e) => [
                'Timestamp' => ((array)$e)['occurred_at'] ?? '',
                'From' => ((array)$e)['from_state'] ?? '',
                'To'   => ((array)$e)['to_state'] ?? '',
                'By'   => ((array)$e)['actor_name'] ?? 'system',
            ], $data['state_history']);
            $h .= $this->htmlSection('State History', $this->htmlTable(['Timestamp','From','To','By'], $rows));
        }

        if (!empty($data['assignment_history'])) {
            $rows = array_map(fn($a) => [
                'Assigned At' => ((array)$a)['assigned_at'] ?? '',
                'Assignee'    => ((array)$a)['assignee_name'] ?? '',
                'Status'      => ((array)$a)['is_active'] ? 'active' : 'past',
            ], $data['assignment_history']);
            $h .= $this->htmlSection('Assignment History', $this->htmlTable(['Assigned At','Assignee','Status'], $rows));
        }

        if (!empty($data['analyst_notes'])) {
            $noteHtml = '';
            foreach ($data['analyst_notes'] as $note) {
                $note = (array) $note;
                $noteHtml .= '<div class="note-card"><div class="note-meta"><span class="badge">' . htmlspecialchars($note['note_type'] ?? 'note') . '</span> ' . htmlspecialchars($note['created_at'] ?? '') . '</div><p>' . htmlspecialchars($note['body'] ?? '') . '</p></div>';
            }
            $h .= $this->htmlSection('Analyst Notes', $noteHtml);
        }

        if (!empty($data['linked_alerts'])) {
            $rows = array_map(fn($a) => [
                'Alert Type' => ((array)$a)['alert_type'] ?? '',
                'Severity'   => ((array)$a)['severity'] ?? '',
                'Actor'      => ((array)$a)['actor_key'] ?? '—',
                'Detected At'=> ((array)$a)['detected_at'] ?? '',
            ], $data['linked_alerts']);
            $h .= $this->htmlSection('Linked Alerts', $this->htmlTable(['Alert Type','Severity','Actor','Detected At'], $rows));
        }

        return $h;
    }

    private function htmlResponsePlan(array $data): string
    {
        $plan = $data['response_plan'] ?? [];
        $h    = $this->htmlSection('Response Plan Details', $this->htmlKvTable([
            'Plan ID'   => $plan['plan_id'] ?? '—',
            'Title'     => $plan['title'] ?? '—',
            'State'     => '<code>' . ($plan['state'] ?? '—') . '</code>',
            'Risk Level'=> strtoupper($plan['risk_level'] ?? '—'),
            'Created By'=> $plan['creator_name'] ?? '—',
        ]));

        if (!empty($data['actions'])) {
            $rows = array_map(function ($a) {
                $a = (array) $a;
                return [
                    'Action'        => '<code>' . ($a['action_type'] ?? '') . '</code>',
                    'Target'        => htmlspecialchars($a['target_entity_key'] ?? ''),
                    'Status'        => $a['status'] ?? '',
                    'Advisory Only' => !empty($a['advisory_only']) ? '<span class="advisory-badge">advisory_only</span>' : 'standard',
                    'Reasoning'     => htmlspecialchars(substr($a['reasoning'] ?? '', 0, 80)),
                ];
            }, $data['actions']);
            $h .= $this->htmlSection('Recommended Actions (Planning Only)', $this->htmlTable(['Action','Target','Status','Advisory Only','Reasoning'], $rows));
        }

        if (!empty($data['full_approval_history'])) {
            $rows = array_map(fn($a) => [
                'Decision'    => ((array)$a)['decision'] ?? '',
                'Approver'    => ((array)$a)['approver_name'] ?? '',
                'Decision At' => ((array)$a)['decision_at'] ?? '',
                'Notes'       => htmlspecialchars(substr(((array)$a)['notes'] ?? '', 0, 80)),
            ], $data['full_approval_history']);
            $h .= $this->htmlSection('Approval Chain', $this->htmlTable(['Decision','Approver','Decision At','Notes'], $rows));
        }

        return $h;
    }

    private function htmlEntityRisk(array $data): string
    {
        $entity = $data['entity'] ?? [];
        $risk   = $data['current_risk'] ?? [];

        $h = $this->htmlSection('Entity', $this->htmlKvTable([
            'Type'          => $entity['entity_type'] ?? '—',
            'Key'           => '<code>' . htmlspecialchars($entity['entity_key'] ?? '') . '</code>',
            'Risk Score'    => '<strong>' . number_format((float)($risk['risk_score'] ?? 0), 1) . ' / 10</strong>',
            'Risk Level'    => '<strong>' . strtoupper($risk['risk_level'] ?? '—') . '</strong>',
            'Calculated At' => $risk['calculated_at'] ?? '—',
        ]));

        if (!empty($risk['risk_factors'])) {
            $rows = array_map(function ($f) {
                $f = (array) $f;
                return [
                    'Factor'       => '<code>' . ($f['factor'] ?? '') . '</code>',
                    'Value'        => $f['value'] ?? 0,
                    'Weight'       => number_format((float)($f['weight'] ?? 0), 1),
                    'Contribution' => number_format((float)($f['contribution'] ?? 0), 2),
                    'Advisory'     => !empty($f['advisory_only']) ? '<span class="advisory-badge">shadow domain</span>' : '—',
                ];
            }, $risk['risk_factors']);
            $h .= $this->htmlSection('Contributing Risk Factors', $this->htmlTable(['Factor','Value','Weight','Contribution','Advisory'], $rows));
        }

        if (!empty($data['historical_snapshots'])) {
            $rows = array_map(fn($s) => [
                'Calculated At' => ((array)$s)['calculated_at'] ?? '',
                'Score'         => number_format((float)((array)$s)['risk_score'] ?? 0, 1),
                'Level'         => strtoupper(((array)$s)['risk_level'] ?? ''),
            ], $data['historical_snapshots']);
            $h .= $this->htmlSection('Risk History', $this->htmlTable(['Calculated At','Score','Level'], $rows));
        }

        return $h;
    }

    private function htmlTrace(array $data): string
    {
        $trace = $data['trace'] ?? [];
        $h     = $this->htmlSection('Trace Summary', $this->htmlKvTable([
            'Trace ID'       => '<code>' . htmlspecialchars($trace['trace_id'] ?? '') . '</code>',
            'First Seen'     => $trace['first_seen'] ?? '—',
            'Last Seen'      => $trace['last_seen'] ?? '—',
            'Alerts'         => $trace['alert_count'] ?? 0,
            'Incidents'      => $trace['incident_count'] ?? 0,
            'Pipeline Events'=> $trace['op_event_count'] ?? 0,
        ]));

        if (!empty($data['alerts_generated'])) {
            $rows = array_map(fn($a) => [
                'Alert Type'  => ((array)$a)['alert_type'] ?? '',
                'Severity'    => ((array)$a)['severity'] ?? '',
                'Detected At' => ((array)$a)['detected_at'] ?? '',
            ], $data['alerts_generated']);
            $h .= $this->htmlSection('Alerts Generated', $this->htmlTable(['Alert Type','Severity','Detected At'], $rows));
        }

        if (!empty($data['pipeline_stages'])) {
            $rows = array_map(fn($e) => [
                'Occurred At'   => ((array)$e)['occurred_at'] ?? '',
                'Service'       => ((array)$e)['source_service'] ?? '',
                'Event Type'    => ((array)$e)['event_type'] ?? '',
            ], $data['pipeline_stages']);
            $h .= $this->htmlSection('Pipeline Event Chain', $this->htmlTable(['Occurred At','Service','Event Type'], $rows));
        }

        return $h;
    }

    // -------------------------------------------------------------------------
    // HTML primitives
    // -------------------------------------------------------------------------

    private function htmlSection(string $title, string $content): string
    {
        return '<section><h2>' . htmlspecialchars($title) . '</h2>' . $content . '</section>';
    }

    private function htmlKvTable(array $pairs): string
    {
        $rows = '';
        foreach ($pairs as $k => $v) {
            $rows .= '<tr><th>' . htmlspecialchars($k) . '</th><td>' . $v . '</td></tr>';
        }
        return '<table>' . $rows . '</table>';
    }

    private function htmlTable(array $headers, array $rows): string
    {
        if (empty($rows)) {
            return '<p class="empty">No records.</p>';
        }
        $ths = implode('', array_map(fn($h) => '<th>' . htmlspecialchars($h) . '</th>', $headers));
        $trs = '';
        foreach ($rows as $row) {
            $tds = implode('', array_map(fn($v) => '<td>' . $v . '</td>', array_values($row)));
            $trs .= '<tr>' . $tds . '</tr>';
        }
        return '<table><thead><tr>' . $ths . '</tr></thead><tbody>' . $trs . '</tbody></table>';
    }

    private function htmlStyles(): string
    {
        return '
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;font-size:13px;line-height:1.5;color:#1a1a2e;max-width:1000px;margin:0 auto;padding:24px;background:#fff}
h1{font-size:22px;color:#0f3460;margin-bottom:12px;border-bottom:3px solid #0f3460;padding-bottom:8px}
h2{font-size:15px;color:#16213e;margin:24px 0 10px;border-bottom:1px solid #dee2e6;padding-bottom:4px}
.report-header{margin-bottom:20px}
.meta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;background:#f8f9fa;padding:12px;border-radius:4px;margin-top:12px}
.meta-label{display:block;font-size:11px;color:#6c757d;text-transform:uppercase;letter-spacing:.05em}
code{font-family:monospace;background:#e9ecef;padding:1px 4px;border-radius:3px;font-size:12px}
.disclaimer-box{background:#fff3cd;border:1px solid #ffc107;padding:12px 16px;border-radius:4px;margin:16px 0;font-size:13px}
section{margin-bottom:24px}
table{width:100%;border-collapse:collapse;margin:8px 0;font-size:12px}
th,td{border:1px solid #dee2e6;padding:6px 10px;text-align:left}
th{background:#f8f9fa;font-weight:600;white-space:nowrap}
tr:nth-child(even){background:#f8f9fa}
.advisory-badge{background:#fff3cd;border:1px solid #ffc107;padding:1px 6px;border-radius:3px;font-size:11px;white-space:nowrap}
.note-card{border:1px solid #dee2e6;border-radius:4px;padding:12px;margin-bottom:8px}
.note-meta{font-size:11px;color:#6c757d;margin-bottom:6px}
.badge{background:#e9ecef;padding:1px 6px;border-radius:10px;font-size:11px}
.empty{color:#6c757d;font-style:italic;padding:8px 0}
.report-footer{margin-top:32px;padding-top:12px;border-top:1px solid #dee2e6;font-size:11px;color:#6c757d;text-align:center}
@media print{.no-print{display:none}body{max-width:none;padding:10px}h1{font-size:18px}h2{font-size:13px}}
';
    }

    // -------------------------------------------------------------------------
    // Finalization
    // -------------------------------------------------------------------------

    private function finalize(array $data, string $type, string $format, int $userId, ?string $reason, string $sourceId): array
    {
        $redacted = TraceRedactor::deep($data, true);
        $content  = $this->render($redacted, $format);

        $this->recordAudit([
            'export_type'       => $type,
            'export_format'     => $format,
            'exported_by'       => $userId,
            'export_reason'     => $reason,
            'source_id'         => $sourceId,
            'source_type'       => $type,
            'export_size_bytes' => strlen($content),
        ]);

        return [
            'content'  => $content,
            'filename' => $this->filename($type, $sourceId, $format),
            'mime'     => $this->mime($format),
        ];
    }

    private function render(array $data, string $format): string
    {
        return match ($format) {
            'json'     => $this->renderJson($data),
            'markdown' => $this->renderMarkdown($data),
            'html'     => $this->renderHtml($data),
            default    => throw new \InvalidArgumentException("Unsupported format: {$format}"),
        };
    }

    private function meta(string $type, string $label, mixed $sourceId, int $userId, ?string $reason): array
    {
        $user = DB::table('users')->where('id', $userId)->first();

        return [
            'report_type'        => $type,
            'report_type_label'  => $label,
            'generated_at'       => now()->toIso8601String(),
            'generated_by'       => $user?->name ?? 'Unknown',
            'generated_by_id'    => $userId,
            'source_id'          => (string) $sourceId,
            'export_reason'      => $reason,
            'disclaimer'         => self::DISCLAIMER,
            'platform'           => 'XDR Platform',
        ];
    }

    private function recordAudit(array $data): void
    {
        ExportAuditLog::create([
            'export_id'         => $this->generateExportId(),
            'export_type'       => $data['export_type'],
            'export_format'     => $data['export_format'],
            'exported_by'       => $data['exported_by'],
            'exported_at'       => now()->toDateTimeString(),
            'export_reason'     => $data['export_reason'] ?? null,
            'source_id'         => $data['source_id'],
            'source_type'       => $data['source_type'],
            'export_size_bytes' => $data['export_size_bytes'] ?? null,
        ]);
    }

    private function generateExportId(): string
    {
        $year  = now()->year;
        $count = DB::table('export_audit_logs')->count() + 1;
        return sprintf('EXP-%d-%05d', $year, $count);
    }

    private function filename(string $type, string $sourceId, string $format): string
    {
        $ext = match ($format) { 'markdown' => 'md', default => $format };
        $slug = preg_replace('/[^a-z0-9\-]/', '-', strtolower($sourceId));
        return sprintf('%s-%s-%s.%s', $type, $slug, now()->format('Ymd-His'), $ext);
    }

    private function mime(string $format): string
    {
        return match ($format) {
            'json'     => 'application/json',
            'markdown' => 'text/markdown',
            'html'     => 'text/html',
            default    => 'application/octet-stream',
        };
    }

    private function assertFormat(string $format): void
    {
        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            throw new \InvalidArgumentException("Unsupported format: {$format}. Use: json, markdown, html");
        }
    }

    private function jsonDecode(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (is_string($value)) return json_decode($value, true) ?? [];
        return [];
    }
}

// Helper — avoids importing Str for simple truncation
function Str_limit(string $s, int $limit): string
{
    return mb_strlen($s) <= $limit ? $s : mb_substr($s, 0, $limit) . '…';
}
