<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * ENTERPRISE-045: Detection Domain Promotion Readiness
 *
 * Classifies all 133 detection rules into 5 unambiguous promotion readiness categories.
 * Classification is derived from the registry's existing `status` and `domain` fields.
 * NO rules are promoted. ACTIVE_ALLOWLIST remains empty. Go scope stays at identity/cloud/SaaS.
 */
class DetectionPromotionReadinessService
{
    public const ADVISORY_ONLY          = true;
    public const NO_AUTONOMOUS_PROMOTION = true;

    // Domains whose soak passed (identity/cloud/SaaS 6h soak PASS 2026-05-14).
    // Shadow rules in these domains are one soak run away from promotion eligibility.
    private const SOAKED_DOMAINS   = ['identity', 'cloud', 'saas'];

    // Domains blocked by an infrastructure prerequisite — cannot even schedule a soak yet.
    // network: DNS/proxy/firewall tap not production-ready.
    // threat-intel: requires live IOC feed.
    // xdr: cross-domain correlation requires multiple active domains simultaneously.
    private const DEFERRED_DOMAINS = ['network', 'threat-intel', 'xdr'];

    // Domains where telemetry flows but no domain-specific soak has been scheduled.
    private const SOAK_NEEDED_DOMAINS = ['endpoint'];

    public const READINESS_ACTIVE           = 'active';
    public const READINESS_SHADOW_READY     = 'shadow_ready';
    public const READINESS_SHADOW_NEEDS_SOAK = 'shadow_needs_soak';
    public const READINESS_DEFERRED         = 'deferred';
    public const READINESS_OUT_OF_SCOPE     = 'out_of_scope';

    private const REGISTRY_PATH = 'docs/detection/rules/registry.v1.json';

    private const RATIONALE_MAP = [
        self::READINESS_ACTIVE            => 'staged_active; 6h identity/cloud/SaaS soak PASS 2026-05-14',
        self::READINESS_SHADOW_READY      => 'shadow in active-domain (identity/cloud/saas); domain soak infrastructure exists; soak is the only remaining gate',
        self::READINESS_SHADOW_NEEDS_SOAK => 'shadow endpoint behavioral; agent telemetry flowing; endpoint domain 6h soak not yet scheduled',
        self::READINESS_DEFERRED          => 'shadow; infrastructure prerequisite missing — network tap / live IOC feed / multi-domain active data',
        self::READINESS_OUT_OF_SCOPE      => 'intentional architectural boundary; will not be promoted under current scope',
    ];

    private const GATE_REMAINING_MAP = [
        self::READINESS_ACTIVE            => null,
        self::READINESS_SHADOW_READY      => 'domain_specific_6h_soak',
        self::READINESS_SHADOW_NEEDS_SOAK => 'endpoint_domain_6h_soak',
        self::READINESS_DEFERRED          => 'infrastructure_prerequisite',
        self::READINESS_OUT_OF_SCOPE      => 'architectural_boundary',
    ];

    public function classifyRule(array $rule): string
    {
        $status = $rule['status'] ?? 'shadow';
        $domain = $rule['domain'] ?? '';

        if ($status === 'staged_active') {
            return self::READINESS_ACTIVE;
        }

        if (in_array($domain, self::DEFERRED_DOMAINS, true)) {
            return self::READINESS_DEFERRED;
        }

        if (in_array($domain, self::SOAKED_DOMAINS, true)) {
            return self::READINESS_SHADOW_READY;
        }

        return self::READINESS_SHADOW_NEEDS_SOAK;
    }

    public function classifyAll(): Collection
    {
        return $this->loadRules()->map(function (array $rule): array {
            $readiness = $this->classifyRule($rule);
            return [
                'rule_id'       => $rule['rule_id'],
                'status'        => $rule['status']     ?? 'shadow',
                'domain'        => $rule['domain']     ?? '',
                'confidence'    => $rule['confidence'] ?? null,
                'readiness'     => $readiness,
                'rationale'     => self::RATIONALE_MAP[$readiness],
                'gate_remaining'=> self::GATE_REMAINING_MAP[$readiness],
            ];
        });
    }

    public function getSummary(): array
    {
        $all = $this->classifyAll();

        return [
            'total'             => $all->count(),
            'active'            => $all->where('readiness', self::READINESS_ACTIVE)->count(),
            'shadow_ready'      => $all->where('readiness', self::READINESS_SHADOW_READY)->count(),
            'shadow_needs_soak' => $all->where('readiness', self::READINESS_SHADOW_NEEDS_SOAK)->count(),
            'deferred'          => $all->where('readiness', self::READINESS_DEFERRED)->count(),
            'out_of_scope'      => $all->where('readiness', self::READINESS_OUT_OF_SCOPE)->count(),
        ];
    }

    public function getRulesForReadiness(string $readiness): Collection
    {
        return $this->classifyAll()->where('readiness', $readiness)->values();
    }

    private function loadRules(): Collection
    {
        $path = base_path(self::REGISTRY_PATH);
        $raw  = json_decode(file_get_contents($path), true);
        return collect($raw['rules'] ?? []);
    }
}
