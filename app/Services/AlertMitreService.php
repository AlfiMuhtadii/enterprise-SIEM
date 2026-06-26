<?php

namespace App\Services;

/**
 * ATTR-001: Static MITRE ATT&CK TTP tagging for security_alerts.
 *
 * Advisory enrichment only — no active change, no rule promotion, no detection modification.
 * Mapping is derived from docs/detection/rules/registry.v1.json (staged_active rules, primary technique).
 */
class AlertMitreService
{
    public const ADVISORY_ONLY = true;

    /**
     * Static map: alert_type → [tactic, technique_id, technique_name]
     *
     * Keys match alert_type values emitted by the Go correlation-worker.
     * Covers the 12 staged_active rules plus known cross-domain alert types.
     */
    private const RULE_MAP = [
        // Identity domain (staged_active)
        'IDENTITY_MFA_FAILURE_BURST' => [
            'tactic'         => 'Credential Access',
            'technique_id'   => 'T1110',
            'technique_name' => 'Brute Force',
        ],
        'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES' => [
            'tactic'         => 'Credential Access',
            'technique_id'   => 'T1110.003',
            'technique_name' => 'Brute Force: Password Spraying',
        ],
        'IDENTITY_RISKY_IP_LOGIN' => [
            'tactic'         => 'Initial Access',
            'technique_id'   => 'T1078',
            'technique_name' => 'Valid Accounts',
        ],
        'IDENTITY_IMPOSSIBLE_TRAVEL' => [
            'tactic'         => 'Initial Access',
            'technique_id'   => 'T1078',
            'technique_name' => 'Valid Accounts',
        ],
        'IDENTITY_PRIVILEGE_ESCALATION' => [
            'tactic'         => 'Privilege Escalation',
            'technique_id'   => 'T1078.004',
            'technique_name' => 'Valid Accounts: Cloud Accounts',
        ],
        'IDENTITY_UNUSUAL_LOGIN_SOURCE' => [
            'tactic'         => 'Credential Access',
            'technique_id'   => 'T1110.003',
            'technique_name' => 'Brute Force: Password Spraying',
        ],
        // Cloud domain (staged_active)
        'CLOUD_UNUSUAL_API_ACTIVITY' => [
            'tactic'         => 'Initial Access',
            'technique_id'   => 'T1078.004',
            'technique_name' => 'Valid Accounts: Cloud Accounts',
        ],
        'CLOUD_SUSPICIOUS_OBJECT_ACCESS' => [
            'tactic'         => 'Collection',
            'technique_id'   => 'T1530',
            'technique_name' => 'Data from Cloud Storage Object',
        ],
        'CLOUD_MASS_DOWNLOAD' => [
            'tactic'         => 'Collection',
            'technique_id'   => 'T1530',
            'technique_name' => 'Data from Cloud Storage Object',
        ],
        'CLOUD_NEW_ACCESS_KEY' => [
            'tactic'         => 'Persistence',
            'technique_id'   => 'T1098.001',
            'technique_name' => 'Account Manipulation: Additional Cloud Credentials',
        ],
        'CLOUD_SECURITY_SETTING_MODIFIED' => [
            'tactic'         => 'Defense Evasion',
            'technique_id'   => 'T1562',
            'technique_name' => 'Impair Defenses',
        ],
        // SaaS domain (staged_active)
        'SAAS_UNUSUAL_ADMIN_ACTIVITY' => [
            'tactic'         => 'Initial Access',
            'technique_id'   => 'T1078',
            'technique_name' => 'Valid Accounts',
        ],
        // Cross-domain alert types (Go correlation-worker)
        'XDR_PHISHING_TO_ENDPOINT_EXECUTION' => [
            'tactic'         => 'Initial Access',
            'technique_id'   => 'T1566',
            'technique_name' => 'Phishing',
        ],
        'XDR_IMPOSSIBLE_LOGIN_PRIVILEGE_CLOUD' => [
            'tactic'         => 'Initial Access',
            'technique_id'   => 'T1078',
            'technique_name' => 'Valid Accounts',
        ],
        'XDR_IOC_DNS_ENDPOINT_CHAIN' => [
            'tactic'         => 'Command and Control',
            'technique_id'   => 'T1071.004',
            'technique_name' => 'Application Layer Protocol: DNS',
        ],
        'XDR_PROXY_ENDPOINT_ESCALATION' => [
            'tactic'         => 'Defense Evasion',
            'technique_id'   => 'T1090',
            'technique_name' => 'Proxy',
        ],
    ];

    /**
     * Lookup the ATT&CK mapping for an alert type.
     * Returns null if the alert type is not mapped (e.g. shadow/unlabelled rules).
     *
     * @return array{tactic: string, technique_id: string, technique_name: string}|null
     */
    public function lookup(string $alertType): ?array
    {
        return self::RULE_MAP[$alertType] ?? null;
    }

    /**
     * All known alert types that have an ATT&CK mapping.
     */
    public function mappedAlertTypes(): array
    {
        return array_keys(self::RULE_MAP);
    }

    /**
     * Total number of mapped alert types.
     */
    public function mappedCount(): int
    {
        return count(self::RULE_MAP);
    }
}
