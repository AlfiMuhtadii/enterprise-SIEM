<?php

namespace Tests\Unit;

use App\Services\SigmaImportService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SigmaImportServiceTest extends TestCase
{
    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../fixtures/sigma/'.$name);
    }

    public function test_parse_rejects_non_mapping_yaml(): void
    {
        $service = new SigmaImportService();
        $this->expectException(InvalidArgumentException::class);
        $service->parse("- just\n- a\n- list\n");
    }

    public function test_parse_rejects_missing_required_sigma_fields(): void
    {
        $service = new SigmaImportService();
        $this->expectException(InvalidArgumentException::class);
        $service->parse("title: Incomplete Rule\n");
    }

    public function test_compile_powershell_encoded_command_maps_to_endpoint_domain(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse($this->fixture('powershell_encoded_command.yml'));
        $entry = $service->compile($sigma);

        $this->assertSame('endpoint', $entry['domain']);
        $this->assertSame('shadow', $entry['status']);
        $this->assertTrue($entry['shadow_only']);
        $this->assertSame('high', $entry['severity']);
        $this->assertStringStartsWith('xdr.alerts.shadow.', $entry['output_topic']);
        $this->assertContains('T1059.001', $entry['mitre_attack']);
        $this->assertContains('T1027', $entry['mitre_attack']);
        $this->assertGreaterThanOrEqual(0.65, $entry['confidence']);
    }

    public function test_compile_okta_rule_maps_to_identity_domain_not_shadow_only(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse($this->fixture('okta_mfa_bypass_attempt.yml'));
        $entry = $service->compile($sigma);

        $this->assertSame('identity', $entry['domain']);
        $this->assertFalse($entry['shadow_only'], 'identity is not a protected domain — shadow_only should be false');
        $this->assertSame('medium', $entry['severity']);
        $this->assertContains('T1621', $entry['mitre_attack']);
    }

    public function test_compile_all_required_registry_fields_present(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse($this->fixture('powershell_encoded_command.yml'));
        $entry = $service->compile($sigma);

        foreach ([
            'rule_id', 'domain', 'status', 'version', 'title', 'description', 'severity',
            'confidence', 'mitre_attack', 'event_types', 'required_fields', 'output_topic',
            'shadow_only', 'suppression_key', 'false_positive_notes', 'suppression_guidance',
            'owner', 'validation_evidence', 'created_at', 'updated_at',
        ] as $field) {
            $this->assertArrayHasKey($field, $entry, "missing required registry field: {$field}");
        }
    }

    public function test_compile_never_produces_staged_active(): void
    {
        // Even if the source Sigma rule claims status: stable, this compiler
        // must never emit staged_active — that requires a domain-specific
        // 6h soak PASS, which an import can never satisfy on its own.
        $service = new SigmaImportService();
        $sigma = $service->parse($this->fixture('powershell_encoded_command.yml'));
        $this->assertSame('stable', $sigma['status']); // sanity: fixture does claim "stable"
        $entry = $service->compile($sigma);
        $this->assertSame('shadow', $entry['status']);
    }

    public function test_compile_deduplicates_rule_id_against_existing(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse($this->fixture('powershell_encoded_command.yml'));
        $first = $service->compile($sigma);
        $second = $service->compile($sigma, [$first['rule_id']]);

        $this->assertNotSame($first['rule_id'], $second['rule_id']);
    }

    public function test_compile_falls_back_to_medium_severity_for_unknown_level(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse("title: Weird Level Rule\nlogsource: {product: windows, category: process_creation}\ndetection: {selection: {Image: 'x'}, condition: selection}\nlevel: unheard_of\n");
        $entry = $service->compile($sigma);

        $this->assertSame('medium', $entry['severity']);
    }

    public function test_compile_handles_falsepositives_as_scalar_string(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse("title: Scalar FP Rule\nlogsource: {product: windows, category: process_creation}\ndetection: {selection: {Image: 'x'}, condition: selection}\nlevel: low\nfalsepositives: Unknown\n");
        $entry = $service->compile($sigma);

        $this->assertStringContainsString('Unknown', $entry['false_positive_notes']);
    }

    public function test_compile_preserves_sigma_source_for_future_hand_implementation(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse($this->fixture('powershell_encoded_command.yml'));
        $entry = $service->compile($sigma);

        $this->assertArrayHasKey('sigma_source', $entry);
        $this->assertSame('3d180a95-a350-4b6a-95e9-fbc38b6ffde3', $entry['sigma_source']['sigma_id']);
        $this->assertArrayHasKey('detection', $entry['sigma_source']);
    }

    public function test_compile_aws_product_maps_to_cloud_domain(): void
    {
        $service = new SigmaImportService();
        $sigma = $service->parse("title: Suspicious CloudTrail API Call\nlogsource: {product: aws, service: cloudtrail}\ndetection: {selection: {eventName: 'DeleteTrail'}, condition: selection}\nlevel: high\n");
        $entry = $service->compile($sigma);

        $this->assertSame('cloud', $entry['domain']);
    }
}
