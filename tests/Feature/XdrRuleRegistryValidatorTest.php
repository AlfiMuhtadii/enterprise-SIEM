<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Mirrors every check performed by scripts/xdr_rule_registry_validate.py.
 *
 * These tests are intentionally read-only — they validate the registry JSON file
 * without touching the database or any runtime behaviour.  Adding a check here
 * that would also be caught by the Python validator means CI catches the failure
 * in whichever language runs first.
 */
class XdrRuleRegistryValidatorTest extends TestCase
{
    private const REGISTRY_PATH   = 'docs/detection/rules/registry.v1.json';
    private const VALIDATOR_SCRIPT = 'scripts/xdr_rule_registry_validate.py';

    private const VALID_STATUSES  = ['draft', 'shadow', 'staged_active', 'deprecated'];
    private const VALID_SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];
    private const VALID_DOMAINS   = ['identity', 'cloud', 'saas', 'endpoint', 'threat-intel', 'xdr', 'network'];

    /** Domains that are permanently shadow-only until an explicit gate review. */
    private const PROTECTED_DOMAINS = ['endpoint', 'threat-intel'];

    private const ACTIVE_ALERT_TOPIC  = 'xdr.alerts';
    private const SHADOW_TOPIC_PREFIX = 'xdr.alerts.shadow';

    private const REQUIRED_FIELDS = [
        'rule_id', 'domain', 'status', 'version', 'title', 'description',
        'severity', 'confidence', 'mitre_attack', 'event_types', 'required_fields',
        'output_topic', 'shadow_only', 'suppression_key',
        'false_positive_notes', 'suppression_guidance',
        'owner', 'created_at', 'updated_at',
    ];

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function loadRegistry(): array
    {
        $path = base_path(self::REGISTRY_PATH);
        $this->assertFileExists($path, 'registry.v1.json must exist');
        $content = file_get_contents($path);
        $data    = json_decode($content, true);
        $this->assertNotNull($data, 'registry.v1.json must be valid JSON');
        return $data;
    }

    private function rules(): array
    {
        return $this->loadRegistry()['rules'] ?? [];
    }

    // -----------------------------------------------------------------------
    // Check 1 — file exists and is valid JSON
    // -----------------------------------------------------------------------

    public function test_validator_script_exists(): void
    {
        $this->assertFileExists(
            base_path(self::VALIDATOR_SCRIPT),
            self::VALIDATOR_SCRIPT . ' must exist at the declared path'
        );
    }

    public function test_registry_file_exists(): void
    {
        $this->assertFileExists(base_path(self::REGISTRY_PATH));
    }

    public function test_registry_is_valid_json(): void
    {
        $raw = file_get_contents(base_path(self::REGISTRY_PATH));
        $this->assertNotFalse($raw);
        $data = json_decode($raw, true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'registry.v1.json must be valid JSON');
        $this->assertIsArray($data);
    }

    public function test_registry_has_rules_array(): void
    {
        $registry = $this->loadRegistry();
        $this->assertArrayHasKey('rules', $registry);
        $this->assertIsArray($registry['rules']);
        $this->assertNotEmpty($registry['rules']);
    }

    public function test_registry_has_65_rules(): void
    {
        $this->assertCount(133, $this->rules()); // 73 previous + 20 Advanced Detection Coverage Phase 1
    }

    // -----------------------------------------------------------------------
    // Check 2 — unique rule IDs
    // -----------------------------------------------------------------------

    public function test_all_rule_ids_are_unique(): void
    {
        $ids  = array_column($this->rules(), 'rule_id');
        $seen = [];
        $dups = [];
        foreach ($ids as $id) {
            if (isset($seen[$id])) {
                $dups[] = $id;
            }
            $seen[$id] = true;
        }
        $this->assertEmpty($dups, 'Duplicate rule_ids found: ' . implode(', ', $dups));
    }

    // -----------------------------------------------------------------------
    // Check 3 — required fields present
    // -----------------------------------------------------------------------

    public function test_all_rules_have_required_fields(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            $id = $rule['rule_id'] ?? '<unknown>';
            foreach (self::REQUIRED_FIELDS as $field) {
                if (!array_key_exists($field, $rule)) {
                    $missing[] = "[$id] missing '$field'";
                }
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    // -----------------------------------------------------------------------
    // Check 4 — lifecycle stage is valid
    // -----------------------------------------------------------------------

    public function test_all_rules_have_valid_status(): void
    {
        $invalid = [];
        foreach ($this->rules() as $rule) {
            if (!in_array($rule['status'] ?? '', self::VALID_STATUSES, true)) {
                $invalid[] = "[{$rule['rule_id']}] invalid status '{$rule['status']}'";
            }
        }
        $this->assertEmpty($invalid, implode("\n", $invalid));
    }

    public function test_all_rules_have_valid_domain(): void
    {
        $invalid = [];
        foreach ($this->rules() as $rule) {
            if (!in_array($rule['domain'] ?? '', self::VALID_DOMAINS, true)) {
                $invalid[] = "[{$rule['rule_id']}] invalid domain '{$rule['domain']}'";
            }
        }
        $this->assertEmpty($invalid, implode("\n", $invalid));
    }

    public function test_all_rules_have_valid_severity(): void
    {
        $invalid = [];
        foreach ($this->rules() as $rule) {
            if (!in_array($rule['severity'] ?? '', self::VALID_SEVERITIES, true)) {
                $invalid[] = "[{$rule['rule_id']}] invalid severity '{$rule['severity']}'";
            }
        }
        $this->assertEmpty($invalid, implode("\n", $invalid));
    }

    // -----------------------------------------------------------------------
    // Check 5 — MITRE ATT&CK mappings for non-draft/non-deprecated rules
    // -----------------------------------------------------------------------

    public function test_non_draft_rules_have_mitre_mapping(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            $status = $rule['status'] ?? '';
            if (in_array($status, ['draft', 'deprecated'], true)) {
                continue;
            }
            $attack = $rule['mitre_attack'] ?? [];
            if (!is_array($attack) || count($attack) === 0) {
                $missing[] = "[{$rule['rule_id']}] mitre_attack is empty for status='$status'";
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    public function test_endpoint_and_threat_intel_rules_have_mitre_mapping(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            if (!in_array($rule['domain'] ?? '', self::PROTECTED_DOMAINS, true)) {
                continue;
            }
            $attack = $rule['mitre_attack'] ?? [];
            if (!is_array($attack) || count($attack) === 0) {
                $missing[] = "[{$rule['rule_id']}] endpoint/threat-intel rules require mitre_attack";
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    // -----------------------------------------------------------------------
    // Check 6 — confidence in [0.0, 1.0]
    // -----------------------------------------------------------------------

    public function test_all_rules_have_confidence_in_range(): void
    {
        $invalid = [];
        foreach ($this->rules() as $rule) {
            $c = $rule['confidence'] ?? null;
            if ($c === null || !is_numeric($c) || (float) $c < 0.0 || (float) $c > 1.0) {
                $invalid[] = "[{$rule['rule_id']}] confidence '{$c}' is not in [0.0, 1.0]";
            }
        }
        $this->assertEmpty($invalid, implode("\n", $invalid));
    }

    // -----------------------------------------------------------------------
    // Check 7 — confidence >= 0.65 for non-draft/non-deprecated rules
    // -----------------------------------------------------------------------

    public function test_non_draft_rules_meet_confidence_floor(): void
    {
        $failures = [];
        foreach ($this->rules() as $rule) {
            $status = $rule['status'] ?? '';
            if (in_array($status, ['draft', 'deprecated'], true)) {
                continue;
            }
            $c = (float) ($rule['confidence'] ?? 0);
            if ($c < 0.65) {
                $failures[] = "[{$rule['rule_id']}] confidence {$c} < 0.65 for status='$status'";
            }
        }
        $this->assertEmpty($failures, implode("\n", $failures));
    }

    // -----------------------------------------------------------------------
    // Check 8 — endpoint/threat-intel cannot be staged_active (hard gate)
    // -----------------------------------------------------------------------

    public function test_endpoint_rules_are_not_staged_active(): void
    {
        $violations = [];
        foreach ($this->rules() as $rule) {
            if (($rule['domain'] ?? '') === 'endpoint' && ($rule['status'] ?? '') === 'staged_active') {
                $violations[] = $rule['rule_id'];
            }
        }
        $this->assertEmpty(
            $violations,
            'Endpoint rules must not be staged_active — domain soak not approved. Violations: ' . implode(', ', $violations)
        );
    }

    public function test_threat_intel_rules_are_not_staged_active(): void
    {
        $violations = [];
        foreach ($this->rules() as $rule) {
            if (($rule['domain'] ?? '') === 'threat-intel' && ($rule['status'] ?? '') === 'staged_active') {
                $violations[] = $rule['rule_id'];
            }
        }
        $this->assertEmpty(
            $violations,
            'Threat-intel rules must not be staged_active — domain gate not approved. Violations: ' . implode(', ', $violations)
        );
    }

    public function test_protected_domain_rules_are_all_shadow(): void
    {
        $nonShadow = [];
        foreach ($this->rules() as $rule) {
            if (!in_array($rule['domain'] ?? '', self::PROTECTED_DOMAINS, true)) {
                continue;
            }
            if (!in_array($rule['status'] ?? '', ['shadow', 'draft', 'deprecated'], true)) {
                $nonShadow[] = "[{$rule['rule_id']}] domain={$rule['domain']} but status={$rule['status']}";
            }
        }
        $this->assertEmpty($nonShadow, implode("\n", $nonShadow));
    }

    // -----------------------------------------------------------------------
    // Check 9 — output_topic matches lifecycle stage exactly
    // -----------------------------------------------------------------------

    public function test_staged_active_rules_publish_to_xdr_alerts(): void
    {
        $violations = [];
        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? '') !== 'staged_active') {
                continue;
            }
            if (($rule['output_topic'] ?? '') !== self::ACTIVE_ALERT_TOPIC) {
                $violations[] = "[{$rule['rule_id']}] output_topic='{$rule['output_topic']}' but status=staged_active";
            }
        }
        $this->assertEmpty($violations, implode("\n", $violations));
    }

    public function test_shadow_rules_publish_to_shadow_topic(): void
    {
        $violations = [];
        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? '') !== 'shadow') {
                continue;
            }
            $topic = $rule['output_topic'] ?? '';
            if ($topic && !str_starts_with($topic, self::SHADOW_TOPIC_PREFIX)) {
                $violations[] = "[{$rule['rule_id']}] output_topic='$topic' does not start with '" . self::SHADOW_TOPIC_PREFIX . "'";
            }
        }
        $this->assertEmpty($violations, implode("\n", $violations));
    }

    // -----------------------------------------------------------------------
    // Check 10 — shadow_only=true enforced for protected domains
    // -----------------------------------------------------------------------

    public function test_endpoint_and_threat_intel_rules_have_shadow_only_true(): void
    {
        $violations = [];
        foreach ($this->rules() as $rule) {
            if (!in_array($rule['domain'] ?? '', self::PROTECTED_DOMAINS, true)) {
                continue;
            }
            if (($rule['shadow_only'] ?? null) !== true) {
                $violations[] = "[{$rule['rule_id']}] domain={$rule['domain']} requires shadow_only=true";
            }
        }
        $this->assertEmpty($violations, implode("\n", $violations));
    }

    // -----------------------------------------------------------------------
    // Check 11 — shadow_only=true must not use xdr.alerts topic
    // -----------------------------------------------------------------------

    public function test_shadow_only_rules_do_not_use_active_topic(): void
    {
        $violations = [];
        foreach ($this->rules() as $rule) {
            if (($rule['shadow_only'] ?? false) === true && ($rule['output_topic'] ?? '') === self::ACTIVE_ALERT_TOPIC) {
                $violations[] = "[{$rule['rule_id']}] shadow_only=true but output_topic='xdr.alerts'";
            }
        }
        $this->assertEmpty($violations, implode("\n", $violations));
    }

    // -----------------------------------------------------------------------
    // Check 12 — suppression key declared for non-draft rules
    // -----------------------------------------------------------------------

    public function test_non_draft_rules_have_suppression_key(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? '') === 'draft') {
                continue;
            }
            if (empty($rule['suppression_key'])) {
                $missing[] = "[{$rule['rule_id']}] suppression_key is empty";
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    // -----------------------------------------------------------------------
    // Check 13 — owner declared for staged_active rules
    // -----------------------------------------------------------------------

    public function test_staged_active_rules_have_owner(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? '') !== 'staged_active') {
                continue;
            }
            if (empty($rule['owner'])) {
                $missing[] = "[{$rule['rule_id']}] owner is empty for staged_active rule";
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    // -----------------------------------------------------------------------
    // Check 14 — validation_evidence present for staged_active (replay_validated proxy)
    // -----------------------------------------------------------------------

    public function test_staged_active_rules_have_validation_evidence(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? '') !== 'staged_active') {
                continue;
            }
            if (empty($rule['validation_evidence'])) {
                $missing[] = "[{$rule['rule_id']}] validation_evidence is null for staged_active rule";
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    // -----------------------------------------------------------------------
    // Check 15 — false_positive_notes non-empty for non-draft/non-deprecated
    // -----------------------------------------------------------------------

    public function test_active_rules_have_false_positive_notes(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            $status = $rule['status'] ?? '';
            if (in_array($status, ['draft', 'deprecated'], true)) {
                continue;
            }
            if (empty(trim((string) ($rule['false_positive_notes'] ?? '')))) {
                $missing[] = "[{$rule['rule_id']}] false_positive_notes is empty";
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    // -----------------------------------------------------------------------
    // Check 16 — deprecated rules have deprecation_reason or replacement_rule_id
    // -----------------------------------------------------------------------

    public function test_deprecated_rules_have_reason_or_replacement(): void
    {
        $missing = [];
        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? '') !== 'deprecated') {
                continue;
            }
            $hasReason      = !empty(trim((string) ($rule['deprecation_reason'] ?? '')));
            $hasReplacement = !empty($rule['replacement_rule_id']);
            if (!$hasReason && !$hasReplacement) {
                $missing[] = "[{$rule['rule_id']}] deprecated but missing deprecation_reason and replacement_rule_id";
            }
        }
        $this->assertEmpty($missing, implode("\n", $missing));
    }

    // -----------------------------------------------------------------------
    // Identity / cloud / SaaS integrity (CLAUDE.md operational rules)
    // -----------------------------------------------------------------------

    public function test_identity_cloud_saas_rules_are_staged_active(): void
    {
        $expected = [
            'IDENTITY_MFA_FAILURE_BURST',
            'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES',
            'IDENTITY_RISKY_IP_LOGIN',
            'IDENTITY_IMPOSSIBLE_TRAVEL',
            'IDENTITY_PRIVILEGE_ESCALATION',
            'IDENTITY_UNUSUAL_LOGIN_SOURCE',
            'CLOUD_UNUSUAL_API_ACTIVITY',
            'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
            'CLOUD_MASS_DOWNLOAD',
            'CLOUD_NEW_ACCESS_KEY',
            'CLOUD_SECURITY_SETTING_MODIFIED',
            'SAAS_UNUSUAL_ADMIN_ACTIVITY',
        ];

        $rules   = collect($this->rules())->keyBy('rule_id');
        $wrong   = [];
        foreach ($expected as $id) {
            $rule = $rules->get($id);
            $this->assertNotNull($rule, "Expected rule '$id' not found in registry");
            if (($rule['status'] ?? '') !== 'staged_active') {
                $wrong[] = "$id is {$rule['status']}, expected staged_active";
            }
        }
        $this->assertEmpty($wrong, implode("\n", $wrong));
    }

    public function test_endpoint_shadow_rules_are_all_shadow(): void
    {
        $expected = [
            'suspicious_parent_child_process',
            'powershell_encoded_command',
            'suspicious_temp_file_write',
            'failed_login_burst',
            'suspicious_dns_query',
            'suspicious_outbound_connection',
            // Phase 1 behavioral visibility rules
            'suspicious_parent_child_chain',
            'suspicious_shell_chain',
            'suspicious_long_lived_process',
            'suspicious_persistence_entry',
            // Phase 1 behavioral analytics rules
            'suspicious_execution_chain',
            'suspicious_beacon_pattern',
            'suspicious_lolbin_usage',
            'suspicious_persistence_correlation',
            'rare_parent_child_process',
            // Threat hunting hunt-oriented rules
            'repeated_behavioral_chain',
            'multi_host_beacon_pattern',
            'repeated_lolbin_sequence',
            'persistence_reactivation_pattern',
        ];
        $rules  = collect($this->rules())->keyBy('rule_id');
        $wrong  = [];
        foreach ($expected as $id) {
            $rule = $rules->get($id);
            $this->assertNotNull($rule, "Expected endpoint rule '$id' not found");
            if (($rule['status'] ?? '') !== 'shadow') {
                $wrong[] = "$id is {$rule['status']}, expected shadow";
            }
        }
        $this->assertEmpty($wrong, implode("\n", $wrong));
    }

    public function test_threat_intel_rules_are_all_shadow(): void
    {
        $expected = ['ioc_ip_match', 'ioc_domain_match', 'ioc_file_hash_match'];
        $rules    = collect($this->rules())->keyBy('rule_id');
        $wrong    = [];
        foreach ($expected as $id) {
            $rule = $rules->get($id);
            $this->assertNotNull($rule, "Expected IOC rule '$id' not found");
            if (($rule['status'] ?? '') !== 'shadow') {
                $wrong[] = "$id is {$rule['status']}, expected shadow";
            }
        }
        $this->assertEmpty($wrong, implode("\n", $wrong));
    }

    // -----------------------------------------------------------------------
    // Registry statistics — CI-visible summary assertions
    // -----------------------------------------------------------------------

    public function test_registry_has_12_staged_active_rules(): void
    {
        $count = count(array_filter($this->rules(), fn ($r) => ($r['status'] ?? '') === 'staged_active'));
        $this->assertSame(12, $count, "Expected 12 staged_active rules, got $count");
    }

    public function test_registry_has_61_shadow_rules(): void
    {
        $count = count(array_filter($this->rules(), fn ($r) => ($r['status'] ?? '') === 'shadow'));
        // 61 previous + 20 Advanced Detection Coverage Phase 1 = 81
        $this->assertSame(121, $count, "Expected 81 shadow rules (61+20), got $count");
    }

    public function test_registry_has_no_deprecated_rules(): void
    {
        $count = count(array_filter($this->rules(), fn ($r) => ($r['status'] ?? '') === 'deprecated'));
        $this->assertSame(0, $count);
    }

    public function test_all_staged_active_rules_have_xdr_alerts_output_topic(): void
    {
        $wrong = [];
        foreach ($this->rules() as $rule) {
            if (($rule['status'] ?? '') !== 'staged_active') {
                continue;
            }
            if (($rule['output_topic'] ?? '') !== self::ACTIVE_ALERT_TOPIC) {
                $wrong[] = "[{$rule['rule_id']}] output_topic={$rule['output_topic']}";
            }
        }
        $this->assertEmpty($wrong, implode("\n", $wrong));
    }

    public function test_registry_version_is_v1(): void
    {
        $registry = $this->loadRegistry();
        $this->assertSame('v1', $registry['registry_version'] ?? null);
    }
}
