<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttackStageTimeline;
use App\Models\CrossDomainCorrelation;
use App\Services\CrossDomainCorrelationService;
use App\Services\ThreatHuntingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cross-Domain Correlation API — advisory-only, non-destructive.
 */
class CrossDomainApiController extends Controller
{
    public function __construct(
        private CrossDomainCorrelationService $correlationService,
        private ThreatHuntingService          $huntingService,
    ) {}

    public function listCorrelations(Request $request): JsonResponse
    {
        $type   = $request->query('type', '');
        $limit  = min((int) $request->query('limit', 50), 200);

        $corrs = $type && in_array($type, CrossDomainCorrelation::TYPES, true)
            ? $this->correlationService->getCorrelationsByType($type, $limit)
            : $this->correlationService->getRecentCorrelations($limit);

        return response()->json([
            'correlations' => $corrs->map(fn ($c) => [
                'correlation_id'   => $c->correlation_id,
                'correlation_type' => $c->correlation_type,
                'confidence_score' => $c->confidence_score,
                'attack_stages'    => $c->attack_stages,
                'actor_key'        => $c->actor_key,
                'created_at'       => $c->created_at?->toIso8601String(),
            ])->all(),
            'advisory_note' => 'All correlations are advisory-only and non-destructive.',
        ]);
    }

    public function getCorrelation(string $correlationId): JsonResponse
    {
        $corr = $this->correlationService->getCorrelation($correlationId);
        if (!$corr) {
            return response()->json(['error' => 'correlation_not_found'], 404);
        }

        return response()->json([
            'correlation_id'     => $corr->correlation_id,
            'correlation_type'   => $corr->correlation_type,
            'confidence_score'   => $corr->confidence_score,
            'attack_stages'      => $corr->attack_stages,
            'actor_key'          => $corr->actor_key,
            'involved_hosts'     => $corr->involved_hosts,
            'involved_users'     => $corr->involved_users,
            'involved_ips'       => $corr->involved_ips,
            'time_window_start'  => $corr->time_window_start?->toIso8601String(),
            'time_window_end'    => $corr->time_window_end?->toIso8601String(),
            'alert_ids'          => $corr->alert_ids,
            'trace_ids'          => $corr->trace_ids,
            'timelines'          => $corr->timelines->map(fn ($t) => [
                'timeline_id'      => $t->timeline_id,
                'stage'            => $t->stage,
                'stage_order'      => $t->stage_order,
                'stage_confidence' => $t->stage_confidence,
                'first_seen_at'    => $t->first_seen_at?->toIso8601String(),
                'last_seen_at'     => $t->last_seen_at?->toIso8601String(),
            ])->all(),
            'evidence_count'     => $corr->evidenceLinks->count(),
            'created_at'         => $corr->created_at?->toIso8601String(),
            'advisory_note'      => 'This correlation is advisory-only and non-destructive.',
        ]);
    }

    public function getAttackStages(): JsonResponse
    {
        return response()->json([
            'stages'       => AttackStageTimeline::STAGES,
            'stage_order'  => AttackStageTimeline::STAGE_ORDER,
            'distribution' => $this->correlationService->getAttackStageDistribution(),
        ]);
    }

    public function pivotIdentityHost(Request $request): JsonResponse
    {
        $actor = (string) $request->query('actor', '');
        if (!$actor) {
            return response()->json(['error' => 'actor_required'], 422);
        }
        return response()->json($this->huntingService->pivotIdentityToHost($actor));
    }

    public function pivotMultiHost(Request $request): JsonResponse
    {
        $ip = (string) $request->query('ip', '');
        if (!$ip) {
            return response()->json(['error' => 'ip_required'], 422);
        }
        return response()->json($this->huntingService->pivotMultiHostDestination($ip));
    }

    public function pivotAttackStage(Request $request): JsonResponse
    {
        $stage = (string) $request->query('stage', '');
        return response()->json($this->huntingService->pivotAttackStage($stage));
    }

    public function pivotTrace(Request $request): JsonResponse
    {
        $traceId = (string) $request->query('trace_id', '');
        if (!$traceId) {
            return response()->json(['error' => 'trace_id_required'], 422);
        }
        return response()->json($this->correlationService->stitchCrossDomainTrace($traceId));
    }
}
