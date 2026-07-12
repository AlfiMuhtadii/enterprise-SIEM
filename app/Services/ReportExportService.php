<?php

namespace App\Services;

use App\Models\ExportAuditLog;
use App\Support\TraceRedactor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Query\Builder;

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

    public function exportInvestigation(int $id, string $format, int $userId, ?string $reason = null, ?string $tenantId = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildInvestigationReport($id, $userId, $reason, $tenantId);

        if (empty($data['investigation'])) {
            throw new \InvalidArgumentException("Investigation not found: {$id}");
        }

        return $this->finalize($data, 'investigation', $format, $userId, $reason, (string) $id, $tenantId);
    }

    public function exportResponsePlan(int $id, string $format, int $userId, ?string $reason = null, ?string $tenantId = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildResponsePlanReport($id, $userId, $reason, $tenantId);

        if (empty($data['response_plan'])) {
            throw new \InvalidArgumentException("Response plan not found: {$id}");
        }

        return $this->finalize($data, 'response_plan', $format, $userId, $reason, (string) $id, $tenantId);
    }

    public function exportEntityRisk(int $entityId, string $format, int $userId, ?string $reason = null, ?string $tenantId = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildEntityRiskReport($entityId, $userId, $reason, $tenantId);

        if (empty($data['entity'])) {
            throw new \InvalidArgumentException("Entity not found: {$entityId}");
        }

        return $this->finalize($data, 'entity_risk', $format, $userId, $reason, (string) $entityId, $tenantId);
    }

    public function exportTrace(string $traceId, string $format, int $userId, ?string $reason = null, ?string $tenantId = null): array
    {
        $this->assertFormat($format);
        $data = $this->buildTraceReport($traceId, $userId, $reason, $tenantId);

        if (empty($data['trace'])) {
            throw new \InvalidArgumentException("Trace not found: {$traceId}");
        }

        return $this->finalize($data, 'trace', $format, $userId, $reason, $traceId, $tenantId);
    }

    public function getHistory(array $filters = [], int $limit = 100, ?string $tenantId = null): Collection
    {
        $query = DB::table('export_audit_logs as e')
            ->leftJoin('users', 'users.id', '=', 'e.exported_by')
            ->select('e.*', 'users.name as exported_by_name')
            ->orderByDesc('e.exported_at')
            ->limit($limit);

        $this->scopeTenant($query, $tenantId, 'e.tenant_id');

        if (!empty($filters['export_type'])) {
            $query->where('e.export_type', $filters['export_type']);
        }
        if (!empty($filters['exported_by'])) {
            $query->where('e.exported_by', $filters['exported_by']);
        }

        return $query->get();
    }

    public function getStatCounts(?string $tenantId = null): Collection
    {
        $query = DB::table('export_audit_logs')
            ->select('export_type', DB::raw('count(*) as count'))
            ->groupBy('export_type');

        $this->scopeTenant($query, $tenantId);

        return $query
            ->get()
            ->keyBy('export_type');
    }

    // -------------------------------------------------------------------------
    // Report builders — read-only
    // -------------------------------------------------------------------------

    private function buildInvestigationReport(int $id, int $userId, ?string $reason, ?string $tenantId): array
    {
        $query = DB::table('investigations as i')
            ->leftJoin('users as cr', 'cr.id', '=', 'i.created_by')
            ->leftJoin('users as as', 'as.id', '=', 'i.assigned_to')
            ->select('i.*', 'cr.name as creator_name', 'as.name as assignee_name')
            ->where('i.id', $id);
        $this->scopeTenant($query, $tenantId, 'i.tenant_id');
        $inv = $query->first();

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
            ? TraceRedactor::collection($this->tenantRows('security_alerts', $tenantId)->whereIn('alert_id', $alertIds)->get())->toArray()
            : [];
        $linkedIncidents = !empty($incidentIds)
            ? TraceRedactor::collection($this->tenantRows('security_incidents', $tenantId)->whereIn('incident_id', $incidentIds)->get())->toArray()
            : [];
        $linkedEntities  = !empty($entityIds)
            ? $this->tenantRows('entities', $tenantId)->whereIn('id', $entityIds)->get()->toArray()
            : [];

        $responsePlans = $this->tenantRows('response_plans', $tenantId)
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

    private function buildResponsePlanReport(int $id, int $userId, ?string $reason, ?string $tenantId): array
    {
        $query = DB::table('response_plans as rp')
            ->leftJoin('users as cr', 'cr.id', '=', 'rp.created_by')
            ->select('rp.*', 'cr.name as creator_name')
            ->where('rp.id', $id);
        $this->scopeTenant($query, $tenantId, 'rp.tenant_id');
        $plan = $query->first();

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
            ? TraceRedactor::collection($this->tenantRows('security_alerts', $tenantId)->whereIn('alert_id', $alertIds)->get())->toArray()
            : [];
        $linkedEntities = !empty($entityIds)
            ? $this->tenantRows('entities', $tenantId)->whereIn('id', $entityIds)->get()->toArray()
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

    private function buildEntityRiskReport(int $entityId, int $userId, ?string $reason, ?string $tenantId): array
    {
        $entity = $this->tenantRows('entities', $tenantId)->where('id', $entityId)->first();

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
            ->when($tenantId !== null, fn (Builder $query) => $query->where('r.tenant_id', $tenantId)->where('t.tenant_id', $tenantId))
            ->select('r.relationship_type', 'r.observation_count', 'r.trace_id',
                     't.entity_type as peer_type', 't.entity_key as peer_key')
            ->limit(20)
            ->get();

        $traceIds = $observations->pluck('trace_id')->filter()->unique()->values()->toArray();
        $alertIds = collect($riskFactors)->flatMap(fn ($f) => $f['alert_ids'] ?? [])->unique()->values()->toArray();

        $linkedAlerts = !empty($alertIds)
            ? TraceRedactor::collection($this->tenantRows('security_alerts', $tenantId)->whereIn('alert_id', $alertIds)->limit(20)->get())->toArray()
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

    private function buildTraceReport(string $traceId, int $userId, ?string $reason, ?string $tenantId): array
    {
        $alertCount = $this->tenantRows('security_alerts', $tenantId)->where('trace_id', $traceId)->count();
        $opCount    = DB::table('xdr_operational_events')->where('trace_id', $traceId)->count();
        $incCount   = $this->tenantRows('security_incidents', $tenantId)->where('trace_id', $traceId)->count();

        if ($alertCount === 0 && $incCount === 0 && ($tenantId !== null || $opCount === 0)) {
            return ['trace' => null, 'export_meta' => $this->meta('trace', 'Trace Investigation Report', $traceId, $userId, $reason)];
        }

        $opEvents = DB::table('xdr_operational_events')
            ->where('trace_id', $traceId)
            ->orderBy('occurred_at')
            ->get();

        $alerts    = TraceRedactor::collection(
            $this->tenantRows('security_alerts', $tenantId)->where('trace_id', $traceId)->orderBy('detected_at')->get()
        )->toArray();

        $incidents = TraceRedactor::collection(
            $this->tenantRows('security_incidents', $tenantId)->where('trace_id', $traceId)->orderBy('first_seen_at')->get()
        )->toArray();

        $evidence = DB::table('scenario_evidence')
            ->where('trace_id', $traceId)
            ->orderBy('processed_at')
            ->get();

        $entityTraces = DB::table('entity_observations as eo')
            ->join('entities as en', 'en.id', '=', 'eo.entity_id')
            ->where('trace_id', $traceId)
            ->when($tenantId !== null, fn (Builder $query) => $query->where('en.tenant_id', $tenantId))
            ->select('eo.entity_id', 'eo.observation_type', 'eo.source_table', 'eo.observed_at')
            ->orderBy('observed_at')
            ->get();

        $investigations = $this->tenantRows('investigations', $tenantId)
            ->where('trace_id', $traceId)
            ->select('investigation_id', 'title', 'state', 'severity')
            ->get();

        $responsePlans = $this->tenantRows('response_plans', $tenantId)
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
    // Rendering — CODE-STRUCT-DECOMPOSE: moved to ReportRenderer (renderJson/
    // renderMarkdown/renderHtml, the md*/html* section builders, and the
    // render() format dispatcher) so pure template logic is decoupled from
    // these Eloquent-querying report builders.
    // -------------------------------------------------------------------------

    private function finalize(array $data, string $type, string $format, int $userId, ?string $reason, string $sourceId, ?string $tenantId): array
    {
        $redacted = TraceRedactor::deep($data, true);
        $content  = ReportRenderer::render($redacted, $format);

        $this->recordAudit([
            'export_type'       => $type,
            'export_format'     => $format,
            'exported_by'       => $userId,
            'export_reason'     => $reason,
            'source_id'         => $sourceId,
            'source_type'       => $type,
            'export_size_bytes' => strlen($content),
            'tenant_id'         => $tenantId,
        ]);

        return [
            'content'  => $content,
            'filename' => $this->filename($type, $sourceId, $format),
            'mime'     => $this->mime($format),
        ];
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
            'tenant_id'         => $data['tenant_id'] ?? null,
        ]);
    }

    private function tenantRows(string $table, ?string $tenantId): Builder
    {
        $query = DB::table($table);
        return $this->scopeTenant($query, $tenantId);
    }

    private function scopeTenant(Builder $query, ?string $tenantId, string $column = 'tenant_id'): Builder
    {
        if ($tenantId !== null) {
            $query->where($column, $tenantId);
        }

        return $query;
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
