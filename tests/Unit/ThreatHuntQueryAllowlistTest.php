<?php

namespace Tests\Unit;

use App\Services\ThreatHuntQueryAllowlist;
use PHPUnit\Framework\TestCase;

/**
 * CODE-STRUCT-DECOMPOSE: ThreatHuntQueryAllowlist is the security-critical
 * allowlist extracted from ThreatHuntingService (~1150 lines of
 * SUPPORTED_DOMAINS/DOMAIN_FIELDS constants + the validate() logic).
 *
 * Before this extraction, validate()'s branches were only reachable
 * indirectly through the full executeQuery()/RefreshDatabase path via
 * ThreatHuntingQueryEngineTest — this file covers the validation logic in
 * isolation, no DB/Eloquent dependency, matching the TotpServiceTest/
 * TraceparentServiceTest precedent for pure services in this codebase.
 */
class ThreatHuntQueryAllowlistTest extends TestCase
{
    public function test_validate_passes_for_allowlisted_field_and_operator(): void
    {
        // 'processes'.'process_name' allows '=' and 'contains' per the
        // moved DOMAIN_FIELDS constant.
        $this->expectNotToPerformAssertions();
        ThreatHuntQueryAllowlist::validate('processes', [
            ['field' => 'process_name', 'operator' => 'contains', 'value' => 'x'],
        ]);
    }

    public function test_validate_passes_for_empty_filter_list(): void
    {
        $this->expectNotToPerformAssertions();
        ThreatHuntQueryAllowlist::validate('processes', []);
    }

    public function test_validate_throws_for_unsupported_domain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported query domain: 'raw_sql_injection'.");
        ThreatHuntQueryAllowlist::validate('raw_sql_injection', []);
    }

    public function test_validate_throws_for_non_allowlisted_field(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Field 'not_a_real_field' is not allowlisted for domain 'processes'.");
        ThreatHuntQueryAllowlist::validate('processes', [
            ['field' => 'not_a_real_field', 'operator' => '=', 'value' => 'x'],
        ]);
    }

    public function test_validate_throws_for_disallowed_operator_on_allowlisted_field(): void
    {
        // 'processes'.'is_shell' only allows '=', not 'contains'.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Operator 'contains' is not allowed for field 'is_shell' in domain 'processes'.");
        ThreatHuntQueryAllowlist::validate('processes', [
            ['field' => 'is_shell', 'operator' => 'contains', 'value' => 'true'],
        ]);
    }

    public function test_validate_rejects_missing_field_key_as_empty_string(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ThreatHuntQueryAllowlist::validate('processes', [
            ['operator' => '='],
        ]);
    }

    public function test_validate_checks_every_filter_not_just_the_first(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("not allowlisted for domain 'processes'");
        ThreatHuntQueryAllowlist::validate('processes', [
            ['field' => 'process_name', 'operator' => '=', 'value' => 'ok'],
            ['field' => 'not_a_real_field', 'operator' => '=', 'value' => 'x'],
        ]);
    }

    public function test_supported_domains_contains_core_endpoint_domains(): void
    {
        $this->assertContains('processes', ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS);
        $this->assertContains('alerts', ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS);
        $this->assertContains('persistence_items', ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS);
    }

    public function test_supported_domains_has_no_duplicates(): void
    {
        $domains = ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS;
        $this->assertSame(count($domains), count(array_unique($domains)));
    }

    public function test_domain_fields_every_key_is_a_supported_domain(): void
    {
        // Every DOMAIN_FIELDS entry must correspond to a domain actually
        // reachable via SUPPORTED_DOMAINS -- dead/unreachable allowlist
        // entries would be a maintenance smell, not a security bug, but
        // worth pinning down now that this is an isolated, reviewable unit.
        foreach (array_keys(ThreatHuntQueryAllowlist::DOMAIN_FIELDS) as $domain) {
            $this->assertContains($domain, ThreatHuntQueryAllowlist::SUPPORTED_DOMAINS, "DOMAIN_FIELDS key '{$domain}' has no matching SUPPORTED_DOMAINS entry");
        }
    }

    public function test_domain_fields_operator_lists_are_never_empty(): void
    {
        // An empty operator list for a field would make that field
        // permanently unusable (in_array against [] is always false) --
        // a silent dead allowlist entry rather than a security issue, but
        // worth catching structurally.
        foreach (ThreatHuntQueryAllowlist::DOMAIN_FIELDS as $domain => $fields) {
            foreach ($fields as $field => $operators) {
                $this->assertNotEmpty($operators, "domain '{$domain}' field '{$field}' has an empty operator list");
            }
        }
    }
}
