<?php

namespace App\Services;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/**
 * CAP-DETECT-AS-CODE-SIGMA: compiles a standard SigmaHQ rule (YAML) into a
 * shadow-only entry for docs/detection/rules/registry.v1.json.
 *
 * Scope, explicitly: this compiler produces a GOVERNANCE/CATALOG entry
 * (metadata: MITRE mapping, severity, field-mapping hints, suppression
 * scaffolding) that passes every check in xdr_rule_registry_validate.py. It
 * does NOT generate executable detection logic — the registry itself is a
 * metadata catalog everywhere in this codebase (every one of the existing
 * 133 rules has its actual match logic hand-written in Go/Python elsewhere,
 * not driven by the JSON), so a Sigma import is consistent with that
 * existing pattern, not a regression from some higher bar. The imported
 * entry's `sigma_source` field preserves the original detection/condition
 * so a human can hand-implement the real shadow-rule logic from it later —
 * this service bootstraps the catalog side of onboarding a community/vendor
 * ruleset quickly, not the correlation-engine side.
 *
 * Always compiles to status=shadow (never staged_active — that requires a
 * domain-specific 6h soak PASS per CLAUDE.md, which an import can never
 * satisfy on its own).
 */
class SigmaImportService
{
    private const CONFIDENCE_FLOOR = 0.65;

    private const LEVEL_TO_SEVERITY = [
        'critical' => 'critical',
        'high' => 'high',
        'medium' => 'medium',
        'low' => 'low',
        'informational' => 'info',
    ];

    /**
     * logsource (product/category/service) -> platform domain. Checked in
     * order; first match wins. Falls back to 'endpoint' (the most common
     * Sigma logsource: process_creation/windows) if nothing matches.
     */
    private const LOGSOURCE_DOMAIN_RULES = [
        ['product' => 'aws', 'domain' => 'cloud'],
        ['product' => 'azure', 'domain' => 'cloud'],
        ['product' => 'gcp', 'domain' => 'cloud'],
        ['product' => 'okta', 'domain' => 'identity'],
        ['product' => 'azuread', 'domain' => 'identity'],
        ['product' => 'entra_id', 'domain' => 'identity'],
        ['product' => 'm365', 'domain' => 'saas'],
        ['product' => 'microsoft365', 'domain' => 'saas'],
        ['product' => 'github', 'domain' => 'saas'],
        ['category' => 'dns', 'domain' => 'network'],
        ['category' => 'proxy', 'domain' => 'network'],
        ['category' => 'firewall', 'domain' => 'network'],
        ['category' => 'network_connection', 'domain' => 'network'],
        ['product' => 'windows', 'domain' => 'endpoint'],
        ['product' => 'linux', 'domain' => 'endpoint'],
        ['category' => 'process_creation', 'domain' => 'endpoint'],
    ];

    private const PROTECTED_DOMAINS = ['endpoint', 'threat-intel', 'network'];

    /**
     * @throws InvalidArgumentException if the YAML doesn't parse or is missing Sigma's own required fields.
     */
    public function parse(string $yaml): array
    {
        $parsed = Yaml::parse($yaml);
        if (!is_array($parsed)) {
            throw new InvalidArgumentException('Sigma YAML did not parse to a mapping.');
        }
        foreach (['title', 'logsource', 'detection', 'level'] as $required) {
            if (!array_key_exists($required, $parsed)) {
                throw new InvalidArgumentException("Sigma rule missing required field: {$required}");
            }
        }

        return $parsed;
    }

    /**
     * Compiles a parsed Sigma rule into a registry.v1.json rule entry.
     * $existingRuleIds is used to guarantee a unique rule_id (dedup check
     * in xdr_rule_registry_validate.py).
     */
    public function compile(array $sigma, array $existingRuleIds = []): array
    {
        $domain = $this->mapDomain($sigma['logsource'] ?? []);
        $ruleId = $this->deriveRuleId($sigma['title'], $existingRuleIds);
        $shadowOnly = in_array($domain, self::PROTECTED_DOMAINS, true);
        $now = now()->toIso8601String();

        return [
            'rule_id' => $ruleId,
            'domain' => $domain,
            'status' => 'shadow',
            'version' => 'v1',
            'title' => (string) $sigma['title'],
            'description' => (string) ($sigma['description'] ?? $sigma['title']),
            'severity' => $this->mapSeverity($sigma['level']),
            'confidence' => self::CONFIDENCE_FLOOR,
            'mitre_attack' => $this->mapMitre($sigma['tags'] ?? []),
            'event_types' => $this->mapEventTypes($sigma['logsource'] ?? []),
            'required_fields' => $this->mapRequiredFields($sigma['detection'] ?? []),
            'output_topic' => "xdr.alerts.shadow.{$domain}",
            'shadow_only' => $shadowOnly,
            'suppression_key' => "{$ruleId}|host",
            'replay_fixture' => null,
            'expected_alert_count' => null,
            'false_positive_notes' => $this->mapFalsePositives($sigma['falsepositives'] ?? []),
            'suppression_guidance' => 'Imported from Sigma — review and tune suppression scope before relying on this in triage.',
            'owner' => null,
            'validation_evidence' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'sigma_source' => [
                'sigma_id' => $sigma['id'] ?? null,
                'author' => $sigma['author'] ?? null,
                'logsource' => $sigma['logsource'] ?? null,
                'detection' => $sigma['detection'] ?? null,
                'references' => $sigma['references'] ?? [],
                'imported_at' => $now,
                'note' => 'Catalog entry only — no executable detection logic generated. Hand-implement the shadow-rule match logic from this detection block before this entry can fire in the correlation engine.',
            ],
        ];
    }

    private function deriveRuleId(string $title, array $existingRuleIds): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '_', trim($title)));
        $slug = trim($slug, '_');
        $ruleId = "sigma_{$slug}";
        $candidate = $ruleId;
        $suffix = 2;
        $existing = array_flip($existingRuleIds);
        while (isset($existing[$candidate])) {
            $candidate = "{$ruleId}_{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function mapDomain(array $logsource): string
    {
        $product = strtolower((string) ($logsource['product'] ?? ''));
        $category = strtolower((string) ($logsource['category'] ?? ''));
        $service = strtolower((string) ($logsource['service'] ?? ''));

        foreach (self::LOGSOURCE_DOMAIN_RULES as $rule) {
            if (isset($rule['product']) && $rule['product'] === $product) {
                return $rule['domain'];
            }
            if (isset($rule['category']) && ($rule['category'] === $category || $rule['category'] === $service)) {
                return $rule['domain'];
            }
        }

        return 'endpoint';
    }

    private function mapSeverity(string $level): string
    {
        $level = strtolower(trim($level));

        return self::LEVEL_TO_SEVERITY[$level] ?? 'medium';
    }

    private function mapMitre(array $tags): array
    {
        $techniques = [];
        foreach ($tags as $tag) {
            $tag = strtolower((string) $tag);
            if (!str_starts_with($tag, 'attack.t')) {
                continue;
            }
            $techniqueId = strtoupper(substr($tag, strlen('attack.')));
            $techniques[] = $techniqueId;
        }

        return array_values(array_unique($techniques));
    }

    private function mapEventTypes(array $logsource): array
    {
        $category = strtolower((string) ($logsource['category'] ?? ''));

        return match ($category) {
            'process_creation' => ['process_start'],
            'network_connection' => ['network_connection'],
            'dns' => ['dns_query'],
            'proxy' => ['proxy_request'],
            'firewall' => ['firewall_flow'],
            default => ['process_start'],
        };
    }

    /**
     * Best-effort extraction of field names referenced by Sigma's detection
     * selections, mapped to this platform's normalized field names where a
     * direct equivalent is known. Unmapped Sigma fields are dropped rather
     * than guessed — required_fields must only list fields the correlation
     * engine actually knows how to read.
     */
    private function mapRequiredFields(array $detection): array
    {
        $sigmaToNormalized = [
            'image' => 'process.name',
            'commandline' => 'process.command_line',
            'parentimage' => 'process.parent_name',
            'user' => 'user',
            'destinationip' => 'network.destination_ip',
            'sourceip' => 'network.source_ip',
        ];

        $fields = ['telemetry_type'];
        foreach ($detection as $key => $selection) {
            if ($key === 'condition' || !is_array($selection)) {
                continue;
            }
            foreach (array_keys($selection) as $sigmaField) {
                $normalizedKey = strtolower(explode('|', (string) $sigmaField)[0]);
                if (isset($sigmaToNormalized[$normalizedKey])) {
                    $fields[] = $sigmaToNormalized[$normalizedKey];
                }
            }
        }

        return array_values(array_unique($fields));
    }

    private function mapFalsePositives(array|string $falsepositives): string
    {
        if (is_string($falsepositives)) {
            $falsepositives = [$falsepositives];
        }
        $falsepositives = array_filter($falsepositives, fn ($fp) => trim((string) $fp) !== '');
        if (empty($falsepositives)) {
            return 'Imported from Sigma — no false-positive notes provided by the source rule; review before relying on this in triage.';
        }

        return 'Imported from Sigma. Documented false positives: '.implode('; ', $falsepositives).'.';
    }
}
