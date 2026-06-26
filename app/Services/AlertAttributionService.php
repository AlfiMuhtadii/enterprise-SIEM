<?php

namespace App\Services;

use App\Models\AlertAttributionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ATTR-002: Advisory OSINT enrichment per security alert.
 *
 * Append-only table. No UPDATE/DELETE. Advisory-only — never triggers automated response.
 * Offline-first: uses GeoAsnLookupService + static heuristics; no live external API calls.
 */
class AlertAttributionService
{
    public const ADVISORY_ONLY    = true;
    public const OFFLINE_ONLY     = true;
    public const NO_AUTO_RESPONSE = true;

    // Confidence when both geo and reputation hint are available.
    private const CONFIDENCE_FULL    = 0.75;
    // Confidence when only geo is available.
    private const CONFIDENCE_GEO     = 0.50;
    // Confidence for internal/private IPs (no meaningful OSINT).
    private const CONFIDENCE_PRIVATE = 0.20;

    // Heuristic threat-actor hints keyed by country_code (advisory, not authoritative).
    private const COUNTRY_THREAT_HINTS = [
        'DOCUMENTATION' => null,
        'PRIVATE'       => null,
        'LOOPBACK'      => null,
    ];

    public function __construct(private readonly GeoAsnLookupService $geo)
    {
    }

    /**
     * Create an attribution context record for a given alert.
     * Idempotent per alert_id: returns the existing record if one already exists.
     */
    public function enrichAlert(
        string  $alertId,
        string  $alertType,
        ?string $ip,
        ?string $tenantId = null,
    ): AlertAttributionContext {
        $existing = AlertAttributionContext::where('alert_id', $alertId)->first();
        if ($existing) {
            return $existing;
        }

        $geo   = $ip ? $this->geo->lookup($ip) : null;
        $priv  = $ip ? $this->geo->isPrivate($ip) : true;

        $confidence       = $this->deriveConfidence($geo, $priv);
        $reputationHint   = $geo['reputation_hint'] ?? null;
        $threatActorHint  = $this->deriveThreatActor($geo);

        return AlertAttributionContext::create([
            'attribution_id'   => Str::uuid()->toString(),
            'alert_id'         => $alertId,
            'alert_type'       => $alertType,
            'ip'               => $ip,
            'country_code'     => $geo['country_code'] ?? null,
            'country_name'     => $geo['country_name'] ?? null,
            'asn'              => $geo['asn'] ?? null,
            'asn_org'          => $geo['asn_org'] ?? null,
            'ip_type'          => $geo['ip_type'] ?? null,
            'ip_reputation_hint' => $reputationHint,
            'threat_actor_hint'  => $threatActorHint,
            'confidence'         => $confidence,
            'enrichment_source'  => 'offline_fixture',
            'is_advisory'        => true,
            'tenant_id'          => $tenantId,
        ]);
    }

    /**
     * Enrich all recent alerts that have no attribution context yet.
     * Returns the count of records created.
     */
    public function enrichRecentAlerts(int $minutes = 60): int
    {
        $since = now()->subMinutes($minutes);

        $alerts = DB::table('security_alerts')
            ->select('alert_id', 'alert_type', 'ip', 'tenant_id')
            ->where('detected_at', '>=', $since)
            ->whereNotIn('alert_id', function ($q) {
                $q->select('alert_id')->from('alert_attribution_context');
            })
            ->limit(500)
            ->get();

        $created = 0;
        foreach ($alerts as $alert) {
            $this->enrichAlert(
                $alert->alert_id,
                $alert->alert_type,
                $alert->ip,
                $alert->tenant_id ?? null,
            );
            $created++;
        }

        return $created;
    }

    /**
     * Latest attribution records for display, newest first.
     */
    public function latest(int $limit = 50): \Illuminate\Support\Collection
    {
        return DB::table('alert_attribution_context')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function deriveConfidence(?array $geo, bool $isPrivate): float
    {
        if ($isPrivate || $geo === null) {
            return self::CONFIDENCE_PRIVATE;
        }
        if (!empty($geo['reputation_hint'])) {
            return self::CONFIDENCE_FULL;
        }
        return self::CONFIDENCE_GEO;
    }

    private function deriveThreatActor(?array $geo): ?string
    {
        if ($geo === null) {
            return null;
        }
        return self::COUNTRY_THREAT_HINTS[$geo['country_code']] ?? null;
    }
}
