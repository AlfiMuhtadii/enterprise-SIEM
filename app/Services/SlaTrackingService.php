<?php

namespace App\Services;

use App\Models\Investigation;
use App\Models\SlaBreach;
use App\Models\SlaEvent;
use App\Models\SlaPolicy;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SLA Tracking Service — SOC operational SLA governance.
 *
 * Analyst-driven collaborative workflows only. No autonomous SOC operations.
 * SLA breach detection is advisory — does NOT auto-close or auto-assign investigations.
 * All SLA events are append-only.
 */
class SlaTrackingService
{
    // -----------------------------------------------------------------------
    // SLA policy management
    // -----------------------------------------------------------------------

    /**
     * Seed default SLA policies if none exist.
     */
    public function seedDefaultPolicies(): int
    {
        if (SlaPolicy::where('active', true)->exists()) {
            return 0;
        }

        $count = 0;
        foreach (SlaPolicy::DEFAULTS as $type => $severities) {
            foreach ($severities as $severity => $maxSeconds) {
                SlaPolicy::create([
                    'policy_id'   => SlaPolicy::generatePolicyId(),
                    'policy_type' => $type,
                    'severity'    => $severity,
                    'max_seconds' => $maxSeconds,
                    'active'      => true,
                    'description' => "Default {$type} SLA for {$severity} severity",
                ]);
                $count++;
            }
        }
        return $count;
    }

    public function getPolicies(): Collection
    {
        return SlaPolicy::where('active', true)
            ->orderBy('policy_type')
            ->orderBy('severity')
            ->get();
    }

    // -----------------------------------------------------------------------
    // SLA event recording — append-only
    // -----------------------------------------------------------------------

    public function recordSlaStart(string $investigationId, string $policyId, ?int $analystId = null): SlaEvent
    {
        return SlaEvent::create([
            'event_id'        => SlaEvent::generateEventId(),
            'investigation_id'=> $investigationId,
            'sla_policy_id'   => $policyId,
            'event_type'      => SlaEvent::TYPE_STARTED,
            'analyst_id'      => $analystId,
            'trace_id'        => 'sla-' . Str::uuid(),
        ]);
    }

    public function recordSlaCompleted(string $investigationId, string $policyId, ?int $analystId = null): SlaEvent
    {
        return SlaEvent::create([
            'event_id'        => SlaEvent::generateEventId(),
            'investigation_id'=> $investigationId,
            'sla_policy_id'   => $policyId,
            'event_type'      => SlaEvent::TYPE_COMPLETED,
            'analyst_id'      => $analystId,
            'trace_id'        => 'sla-' . Str::uuid(),
        ]);
    }

    public function recordSlaBreach(
        string  $investigationId,
        string  $policyId,
        string  $breachType,
        string  $severity,
        int     $overdueSeconds,
        ?int    $analystId = null
    ): SlaBreach {
        // Record SLA event first
        SlaEvent::create([
            'event_id'        => SlaEvent::generateEventId(),
            'investigation_id'=> $investigationId,
            'sla_policy_id'   => $policyId,
            'event_type'      => SlaEvent::TYPE_BREACHED,
            'analyst_id'      => $analystId,
            'trace_id'        => 'sla-' . Str::uuid(),
        ]);

        return SlaBreach::create([
            'breach_id'       => SlaBreach::generateBreachId(),
            'investigation_id'=> $investigationId,
            'sla_policy_id'   => $policyId,
            'breach_type'     => $breachType,
            'severity'        => $severity,
            'overdue_seconds' => $overdueSeconds,
            'analyst_id'      => $analystId,
            'acknowledged'    => false,
            'trace_id'        => 'sla-' . Str::uuid(),
        ]);
    }

    // -----------------------------------------------------------------------
    // SLA breach detection — advisory only, no auto-action
    // -----------------------------------------------------------------------

    /**
     * Check for SLA breaches across active investigations.
     * Advisory only — does NOT auto-close or auto-assign.
     * Returns findings for operator review.
     */
    public function detectBreaches(): Collection
    {
        $findings = collect();
        $policies = $this->getPolicies();
        $openStates = ['new', 'triaged', 'investigating', 'escalated'];

        $investigations = Investigation::whereIn('state', $openStates)
            ->orderBy('created_at')
            ->get();

        foreach ($investigations as $inv) {
            foreach ($policies as $policy) {
                if ($policy->severity && $policy->severity !== $inv->severity) {
                    continue;
                }

                $ageSeconds = (int) $inv->created_at->diffInSeconds(now());
                if ($ageSeconds > $policy->max_seconds) {
                    $existing = SlaBreach::where('investigation_id', $inv->investigation_id)
                        ->where('sla_policy_id', $policy->policy_id)
                        ->exists();

                    if (!$existing) {
                        $breach = $this->recordSlaBreach(
                            $inv->investigation_id,
                            $policy->policy_id,
                            $policy->policy_type,
                            $inv->severity ?? 'medium',
                            $ageSeconds - $policy->max_seconds,
                            $inv->assigned_to
                        );
                        $findings->push(['investigation' => $inv, 'breach' => $breach, 'policy' => $policy]);
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * Calculate investigation age in seconds.
     */
    public function calculateInvestigationAge(string $investigationId): int
    {
        $inv = Investigation::where('investigation_id', $investigationId)->first();
        if (!$inv) return 0;
        return (int) $inv->created_at->diffInSeconds(now());
    }

    /**
     * Get overdue investigations — advisory, not auto-closed.
     */
    public function getOverdueInvestigations(): Collection
    {
        return SlaBreach::whereRaw('created_at > NOW() - INTERVAL \'24 hours\'')
            ->select('investigation_id', 'breach_type', 'severity', 'overdue_seconds')
            ->orderByDesc('overdue_seconds')
            ->get();
    }

    // -----------------------------------------------------------------------
    // Analyst workload metrics
    // -----------------------------------------------------------------------

    /**
     * Get workload metrics per analyst.
     * Advisory summary — no auto-routing based on workload.
     */
    public function getAnalystWorkloadMetrics(): Collection
    {
        return DB::table('investigations')
            ->whereNotIn('state', ['resolved', 'closed', 'false_positive'])
            ->whereNotNull('assigned_to')
            ->select('assigned_to', DB::raw('count(*) as open_count'), DB::raw('max(created_at) as newest_at'), DB::raw('min(created_at) as oldest_at'))
            ->groupBy('assigned_to')
            ->orderByDesc('open_count')
            ->get();
    }

    /**
     * Get SLA breach summary — advisory overview for SOC leads.
     */
    public function getBreachSummary(): array
    {
        $total      = SlaBreach::count();
        $unacked    = SlaBreach::where('acknowledged', false)->count();
        $critical   = SlaBreach::where('severity', 'critical')->count();
        $byType     = SlaBreach::select('breach_type', DB::raw('count(*) as count'))
            ->groupBy('breach_type')
            ->get()
            ->keyBy('breach_type')
            ->map->count;

        return compact('total', 'unacked', 'critical', 'byType');
    }

    public function getRecentBreaches(int $limit = 30): Collection
    {
        return SlaBreach::orderByDesc('id')->limit($limit)->get();
    }

    public function getRecentSlaEvents(int $limit = 50): Collection
    {
        return SlaEvent::orderByDesc('id')->limit($limit)->get();
    }

    public function acknowledgeBreaches(string $investigationId, User $analyst): int
    {
        return SlaBreach::where('investigation_id', $investigationId)
            ->where('acknowledged', false)
            ->update([
                'acknowledged'    => true,
                'acknowledged_at' => now(),
            ]);
    }
}
