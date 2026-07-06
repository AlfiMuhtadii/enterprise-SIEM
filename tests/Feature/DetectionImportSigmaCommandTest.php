<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Exercises the detection:import-sigma command end-to-end against an
 * isolated temp registry copy via --registry= — never the real
 * docs/detection/rules/registry.v1.json, so a test failure can't corrupt
 * the production catalog.
 */
class DetectionImportSigmaCommandTest extends TestCase
{
    private string $tempRegistryPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempRegistryPath = sys_get_temp_dir().'/sigma_import_test_registry_'.Str::uuid().'.json';
        file_put_contents($this->tempRegistryPath, json_encode([
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'registry_version' => 'v1',
            'generated_at' => now()->toIso8601String(),
            'rules' => [],
        ], JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempRegistryPath)) {
            unlink($this->tempRegistryPath);
        }
        parent::tearDown();
    }

    public function test_dry_run_does_not_modify_the_registry_file(): void
    {
        $before = file_get_contents($this->tempRegistryPath);

        $this->artisan('detection:import-sigma', [
            'files' => [base_path('tests/fixtures/sigma/powershell_encoded_command.yml')],
            '--registry' => $this->tempRegistryPath,
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame($before, file_get_contents($this->tempRegistryPath));
    }

    public function test_missing_file_fails_without_writing(): void
    {
        $before = file_get_contents($this->tempRegistryPath);

        $this->artisan('detection:import-sigma', [
            'files' => ['/no/such/sigma/file.yml'],
            '--registry' => $this->tempRegistryPath,
        ])->assertFailed();

        $this->assertSame($before, file_get_contents($this->tempRegistryPath));
    }

    public function test_real_import_writes_shadow_entries_to_the_registry(): void
    {
        $this->artisan('detection:import-sigma', [
            'files' => [
                base_path('tests/fixtures/sigma/powershell_encoded_command.yml'),
                base_path('tests/fixtures/sigma/okta_mfa_bypass_attempt.yml'),
            ],
            '--registry' => $this->tempRegistryPath,
        ])->assertSuccessful();

        $registry = json_decode(file_get_contents($this->tempRegistryPath), true);
        $this->assertCount(2, $registry['rules']);
        $importedIds = array_column($registry['rules'], 'rule_id');
        $this->assertContains('sigma_suspicious_powershell_encoded_command', $importedIds);
        $this->assertContains('sigma_okta_mfa_bypass_attempt', $importedIds);
        foreach ($registry['rules'] as $rule) {
            $this->assertSame('shadow', $rule['status']);
        }
    }

    public function test_imported_rules_pass_the_python_registry_validator(): void
    {
        // Copies the imported entries onto the REAL registry's rule array
        // (in-memory only, written to the temp file) so the validator's
        // domain/severity/status enums and cross-rule uniqueness checks run
        // against a realistic full registry, not just 2 rules in isolation —
        // while never touching the real file on disk.
        $realRegistryPath = base_path('docs/detection/rules/registry.v1.json');
        $realRegistry = json_decode(file_get_contents($realRegistryPath), true);

        $this->artisan('detection:import-sigma', [
            'files' => [
                base_path('tests/fixtures/sigma/powershell_encoded_command.yml'),
                base_path('tests/fixtures/sigma/okta_mfa_bypass_attempt.yml'),
            ],
            '--registry' => $this->tempRegistryPath,
        ])->assertSuccessful();

        $imported = json_decode(file_get_contents($this->tempRegistryPath), true);
        $realRegistry['rules'] = array_merge($realRegistry['rules'], $imported['rules']);
        file_put_contents($this->tempRegistryPath, json_encode($realRegistry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $process = proc_open(
            ['python', base_path('scripts/xdr_rule_registry_validate.py'), '--registry', $this->tempRegistryPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            base_path(),
        );
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertSame(0, $exitCode, "Validator failed on imported Sigma rules:\nSTDOUT: {$stdout}\nSTDERR: {$stderr}");
        $this->assertStringContainsString('status=PASS', $stdout);
        $this->assertStringContainsString('rules=135', $stdout);
    }
}
