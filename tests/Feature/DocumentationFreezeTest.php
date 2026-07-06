<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TEST-UNTRAITED: audited — intentionally DB-free (file-existence and
 * string-content assertions over docs/fixtures only). No DB trait needed.
 */
class DocumentationFreezeTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Core documentation files
    // -----------------------------------------------------------------------

    public function test_readme_exists(): void
    {
        $this->assertFileExists(base_path('README.md'));
    }

    public function test_readme_mentions_php_test_count(): void
    {
        $content = file_get_contents(base_path('README.md'));
        $this->assertStringContainsString('4744', $content);
    }

    public function test_readme_mentions_hunt_domains(): void
    {
        $content = file_get_contents(base_path('README.md'));
        $this->assertStringContainsString('181', $content);
    }

    public function test_readme_mentions_detection_rules(): void
    {
        $content = file_get_contents(base_path('README.md'));
        $this->assertStringContainsString('133', $content);
    }

    // -----------------------------------------------------------------------
    // Release notes package
    // -----------------------------------------------------------------------

    public function test_release_notes_exists(): void
    {
        $this->assertFileExists(base_path('docs/RELEASE_NOTES.md'));
    }

    public function test_release_candidate_summary_exists(): void
    {
        $this->assertFileExists(base_path('docs/RELEASE_CANDIDATE_SUMMARY.md'));
    }

    public function test_final_capability_matrix_exists(): void
    {
        $this->assertFileExists(base_path('docs/FINAL_CAPABILITY_MATRIX.md'));
    }

    public function test_changelog_exists(): void
    {
        $this->assertFileExists(base_path('docs/CHANGELOG.md'));
    }

    // -----------------------------------------------------------------------
    // Evaluator guides
    // -----------------------------------------------------------------------

    public function test_quickstart_evaluator_exists(): void
    {
        $this->assertFileExists(base_path('docs/QUICKSTART_EVALUATOR.md'));
    }

    public function test_demo_walkthrough_exists(): void
    {
        $this->assertFileExists(base_path('docs/DEMO_WALKTHROUGH.md'));
    }

    public function test_thesis_defense_guide_exists(): void
    {
        $this->assertFileExists(base_path('docs/THESIS_DEFENSE_GUIDE.md'));
    }

    public function test_interview_showcase_guide_exists(): void
    {
        $this->assertFileExists(base_path('docs/INTERVIEW_SHOWCASE_GUIDE.md'));
    }

    // -----------------------------------------------------------------------
    // Release candidate packaging
    // -----------------------------------------------------------------------

    public function test_release_candidate_checklist_exists(): void
    {
        $this->assertFileExists(base_path('docs/RELEASE_CANDIDATE_CHECKLIST.md'));
    }

    public function test_final_validation_summary_exists(): void
    {
        $this->assertFileExists(base_path('docs/FINAL_VALIDATION_SUMMARY.md'));
    }

    public function test_known_limitations_exists(): void
    {
        $this->assertFileExists(base_path('docs/KNOWN_LIMITATIONS.md'));
    }

    public function test_future_roadmap_exists(): void
    {
        $this->assertFileExists(base_path('docs/FUTURE_ROADMAP.md'));
    }

    public function test_demo_scenario_index_exists(): void
    {
        $this->assertFileExists(base_path('docs/DEMO_SCENARIO_INDEX.md'));
    }

    // -----------------------------------------------------------------------
    // Architecture diagrams (PlantUML)
    // -----------------------------------------------------------------------

    public function test_plantuml_event_flow_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/plantuml/event_flow.puml'));
    }

    public function test_plantuml_system_context_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/plantuml/system_context.puml'));
    }

    public function test_plantuml_replay_pipeline_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/plantuml/replay_pipeline.puml'));
    }

    public function test_plantuml_detection_lifecycle_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/plantuml/detection_lifecycle.puml'));
    }

    public function test_plantuml_threat_hunting_flow_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/plantuml/threat_hunting_flow.puml'));
    }

    public function test_plantuml_governance_workflow_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/plantuml/governance_workflow.puml'));
    }

    public function test_plantuml_ha_topology_exists(): void
    {
        $this->assertFileExists(base_path('docs/architecture/plantuml/ha_topology.puml'));
    }

    // -----------------------------------------------------------------------
    // Bootstrap scripts
    // -----------------------------------------------------------------------

    public function test_bootstrap_ps1_exists(): void
    {
        $this->assertFileExists(base_path('bootstrap-dev.ps1'));
    }

    public function test_bootstrap_sh_exists(): void
    {
        $this->assertFileExists(base_path('bootstrap-dev.sh'));
    }

    public function test_bootstrap_ps1_mentions_teardown(): void
    {
        $content = file_get_contents(base_path('bootstrap-dev.ps1'));
        $this->assertStringContainsString('Teardown', $content);
    }

    public function test_bootstrap_ps1_mentions_reset(): void
    {
        $content = file_get_contents(base_path('bootstrap-dev.ps1'));
        $this->assertStringContainsString('Reset', $content);
    }

    /** TEST-NO-SCHEMA-DUMP: RefreshDatabase auto-loads this instead of running every migration. */
    public function test_schema_dump_exists(): void
    {
        $this->assertFileExists(base_path('database/schema/pgsql-schema.sql'));
    }

    // -----------------------------------------------------------------------
    // Demo scenario fixtures
    // -----------------------------------------------------------------------

    public function test_phishing_attack_chain_fixture_is_valid_json(): void
    {
        $path = base_path('fixtures/demo/scenarios/phishing_attack_chain.json');
        $this->assertFileExists($path);
        $decoded = json_decode(file_get_contents($path), true);
        $this->assertNotNull($decoded);
        $this->assertArrayHasKey('scenario', $decoded);
    }

    public function test_phishing_fixture_has_lab_safe_metadata(): void
    {
        $decoded = json_decode(file_get_contents(base_path('fixtures/demo/scenarios/phishing_attack_chain.json')), true);
        $this->assertTrue($decoded['is_lab_safe'] ?? false);
        $this->assertFalse($decoded['is_destructive'] ?? true);
        $this->assertFalse($decoded['has_real_malware'] ?? true);
    }

    public function test_lolbin_fixture_has_lab_safe_metadata(): void
    {
        $decoded = json_decode(file_get_contents(base_path('fixtures/demo/scenarios/lolbin_execution_chain.json')), true);
        $this->assertTrue($decoded['is_lab_safe'] ?? false);
        $this->assertFalse($decoded['is_destructive'] ?? true);
    }

    // -----------------------------------------------------------------------
    // Portfolio directories
    // -----------------------------------------------------------------------

    public function test_screenshots_readme_exists(): void
    {
        $this->assertFileExists(base_path('docs/screenshots/README.md'));
    }

    public function test_demo_assets_readme_exists(): void
    {
        $this->assertFileExists(base_path('docs/demo-assets/README.md'));
    }

    public function test_exports_readme_exists(): void
    {
        $this->assertFileExists(base_path('docs/exports/README.md'));
    }
}
