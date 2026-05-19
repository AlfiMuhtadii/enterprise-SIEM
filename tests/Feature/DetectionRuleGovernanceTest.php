<?php

namespace Tests\Feature;

use App\Models\DetectionRule;
use App\Models\DetectionRuleNote;
use App\Services\RuleRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DetectionRuleGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'admin']);
    }

    private function engineer(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'detection_engineer']);
    }

    private function viewer(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => 'viewer']);
    }

    private function registry(): RuleRegistryService
    {
        return app(RuleRegistryService::class);
    }

    // -----------------------------------------------------------------------
    // Registry service
    // -----------------------------------------------------------------------

    public function test_registry_loads_all_42_rules(): void
    {
        $rules = $this->registry()->allRules();
        $this->assertCount(42, $rules);
    }

    public function test_registry_merges_db_state_on_first_load(): void
    {
        $rules = $this->registry()->allRules();
        foreach ($rules as $rule) {
            $this->assertInstanceOf(DetectionRule::class, $rule['db_state']);
            $this->assertNotEmpty($rule['db_state']->stage);
        }
        // DB rows created on first load
        $this->assertSame(42, DetectionRule::count());
    }

    public function test_registry_initialises_staged_active_rules_correctly(): void
    {
        $rules = $this->registry()->allRules();
        $activeRules = $rules->filter(fn ($r) => ($r['status'] ?? '') === 'staged_active');
        foreach ($activeRules as $rule) {
            $this->assertSame('staged_active', $rule['db_state']->stage);
            $this->assertTrue($rule['db_state']->replay_validated);
        }
    }

    public function test_registry_initialises_shadow_rules_correctly(): void
    {
        $rules = $this->registry()->allRules();
        $shadowRules = $rules->filter(fn ($r) => ($r['status'] ?? '') === 'shadow');
        foreach ($shadowRules as $rule) {
            $this->assertSame('shadow', $rule['db_state']->stage);
        }
    }

    public function test_find_rule_returns_null_for_unknown_id(): void
    {
        $this->assertNull($this->registry()->findRule('NONEXISTENT_RULE'));
    }

    public function test_find_rule_returns_merged_record(): void
    {
        $rule = $this->registry()->findRule('IDENTITY_MFA_FAILURE_BURST');
        $this->assertNotNull($rule);
        $this->assertSame('IDENTITY_MFA_FAILURE_BURST', $rule['rule_id']);
        $this->assertSame('identity', $rule['domain']);
        $this->assertInstanceOf(DetectionRule::class, $rule['db_state']);
        $this->assertArrayHasKey('gates', $rule);
        $this->assertArrayHasKey('checklist', $rule);
        $this->assertArrayHasKey('notes', $rule);
    }

    // -----------------------------------------------------------------------
    // MITRE coverage map
    // -----------------------------------------------------------------------

    public function test_mitre_coverage_map_aggregates_all_techniques(): void
    {
        $rules  = $this->registry()->allRules();
        $mitre  = $this->registry()->mitreCoverageMap($rules);
        $this->assertNotEmpty($mitre);
        foreach ($mitre as $entry) {
            $this->assertArrayHasKey('technique', $entry);
            $this->assertArrayHasKey('rules', $entry);
            $this->assertArrayHasKey('domains', $entry);
        }
    }

    public function test_mitre_coverage_includes_known_techniques(): void
    {
        $rules     = $this->registry()->allRules();
        $mitre     = $this->registry()->mitreCoverageMap($rules);
        $techniques = array_column($mitre, 'technique');
        $this->assertContains('T1110', $techniques);
        $this->assertContains('T1530', $techniques);
        $this->assertContains('T1059', $techniques);
    }

    // -----------------------------------------------------------------------
    // Gate validation
    // -----------------------------------------------------------------------

    public function test_gates_pass_for_staged_active_identity_rule(): void
    {
        $rule = $this->registry()->findRule('IDENTITY_MFA_FAILURE_BURST');
        // staged_active → deprecated transition; gates are trivially allowed
        $result = $this->registry()->validatePromotion($rule, 'deprecated');
        $this->assertTrue($result['passed']);
    }

    public function test_gates_block_invalid_stage_skip(): void
    {
        $rule = $this->registry()->findRule('IDENTITY_MFA_FAILURE_BURST');
        // Cannot go staged_active → shadow
        $result = $this->registry()->validatePromotion($rule, 'shadow');
        $this->assertFalse($result['passed']);
        $this->assertStringContainsString('Cannot advance', $result['reason']);
    }

    public function test_gates_block_endpoint_rule_promotion_to_active(): void
    {
        $rule = $this->registry()->findRule('suspicious_parent_child_process');
        // shadow → staged_active: domain_allowed gate must fail
        $result = $this->registry()->validatePromotion($rule, 'staged_active');
        $this->assertFalse($result['passed']);
        $domainGate = collect($result['gates'])->firstWhere('key', 'domain_allowed');
        $this->assertNotNull($domainGate);
        $this->assertFalse($domainGate['passed']);
    }

    public function test_gates_block_threat_intel_rule_promotion_to_active(): void
    {
        $rule = $this->registry()->findRule('ioc_ip_match');
        $result = $this->registry()->validatePromotion($rule, 'staged_active');
        $this->assertFalse($result['passed']);
        $domainGate = collect($result['gates'])->firstWhere('key', 'domain_allowed');
        $this->assertFalse($domainGate['passed']);
    }

    public function test_gates_pass_for_confidence_above_threshold(): void
    {
        $rule = $this->registry()->findRule('IDENTITY_MFA_FAILURE_BURST');
        // Manually set stage to draft to test draft→shadow gates
        $rule['db_state']->update(['stage' => 'draft']);
        $rule['db_state']->refresh();

        $result = $this->registry()->validatePromotion($rule, 'shadow');
        $confGate = collect($result['gates'])->firstWhere('key', 'confidence_minimum');
        $this->assertTrue($confGate['passed'], 'Confidence 0.71 should pass the ≥0.65 gate');
    }

    public function test_checklist_keys_defined_for_draft_to_shadow(): void
    {
        $keys = RuleRegistryService::checklistKeysFor('draft', 'shadow');
        $this->assertNotEmpty($keys);
        $this->assertContains('confidence_minimum', $keys);
        $this->assertContains('mitre_mapping', $keys);
        $this->assertContains('fp_notes', $keys);
        $this->assertContains('owner_assigned', $keys);
    }

    public function test_checklist_keys_defined_for_shadow_to_active(): void
    {
        $keys = RuleRegistryService::checklistKeysFor('shadow', 'staged_active');
        $this->assertNotEmpty($keys);
        $this->assertContains('replay_validated', $keys);
        $this->assertContains('shadow_soak_48h', $keys);
        $this->assertContains('owner_reviewed', $keys);
    }

    // -----------------------------------------------------------------------
    // Access control
    // -----------------------------------------------------------------------

    public function test_detection_index_requires_authentication(): void
    {
        $this->get(route('detection.index'))->assertRedirect(route('login'));
    }

    public function test_viewer_cannot_access_detection_governance(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('detection.index'))
            ->assertForbidden();
    }

    public function test_admin_can_access_detection_governance(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.index'))
            ->assertOk()
            ->assertSee('Detection Rule Registry');
    }

    public function test_detection_engineer_can_access_detection_governance(): void
    {
        $this->actingAs($this->engineer())
            ->get(route('detection.index'))
            ->assertOk();
    }

    // -----------------------------------------------------------------------
    // Index view
    // -----------------------------------------------------------------------

    public function test_index_shows_all_42_rules(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('detection.index'))
            ->assertOk();

        $response->assertSee('42');
        $response->assertSee('IDENTITY_MFA_FAILURE_BURST');
        $response->assertSee('suspicious_parent_child_process');
        $response->assertSee('ioc_ip_match');
        $response->assertSee('scheduled_task_persistence');
        $response->assertSee('c2_beacon_pattern');
    }

    public function test_index_shows_stage_stats(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.index'))
            ->assertOk()
            ->assertSee('Staged Active')
            ->assertSee('Shadow');
    }

    public function test_index_shows_mitre_coverage_map(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(route('detection.index'))
            ->assertOk();

        // Page contains MITRE section header and known technique IDs
        $content = $response->getContent();
        $this->assertStringContainsString('MITRE', $content);
        $this->assertStringContainsString('T1110', $content);
        $this->assertStringContainsString('T1530', $content);
        $this->assertStringContainsString('T1059', $content);
        $this->assertStringContainsString('25', $content); // technique count
    }

    public function test_index_filters_by_domain(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.index', ['domain' => 'endpoint']))
            ->assertOk()
            ->assertSee('suspicious_parent_child_process')
            ->assertDontSee('IDENTITY_MFA_FAILURE_BURST');
    }

    public function test_index_filters_by_stage(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.index', ['stage' => 'staged_active']))
            ->assertOk()
            ->assertSee('IDENTITY_MFA_FAILURE_BURST')
            ->assertDontSee('suspicious_parent_child_process');
    }

    // -----------------------------------------------------------------------
    // Show view
    // -----------------------------------------------------------------------

    public function test_show_displays_rule_metadata(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'))
            ->assertOk()
            ->assertSee('MFA Failure Burst')
            ->assertSee('identity')
            ->assertSee('T1110')
            ->assertSee('False-Positive Notes')
            ->assertSee('Lifecycle');
    }

    public function test_show_returns_404_for_unknown_rule(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.show', 'NONEXISTENT_RULE'))
            ->assertNotFound();
    }

    public function test_show_displays_promotion_gates(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.show', 'suspicious_parent_child_process'))
            ->assertOk()
            ->assertSee('Promotion Gates')
            ->assertSee('shadow_only');
    }

    public function test_show_displays_checklist_for_shadow_rule(): void
    {
        $this->actingAs($this->admin())
            ->get(route('detection.show', 'suspicious_parent_child_process'))
            ->assertOk()
            ->assertSee('Promotion Checklist');
    }

    public function test_show_suppressed_badge_visible_when_active(): void
    {
        // Pre-create DB row so the update finds it
        $this->registry()->findRule('IDENTITY_MFA_FAILURE_BURST');

        DetectionRule::where('rule_id', 'IDENTITY_MFA_FAILURE_BURST')
            ->update(['suppression_active' => true, 'suppression_reason' => 'test-suppression']);

        $this->actingAs($this->admin())
            ->get(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'))
            ->assertOk()
            ->assertSee('SUPPRESSED');
    }

    // -----------------------------------------------------------------------
    // Stage promotion
    // -----------------------------------------------------------------------

    public function test_promote_staged_active_to_deprecated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('detection.promote', 'IDENTITY_MFA_FAILURE_BURST'), [
                'target_stage' => 'deprecated',
            ])
            ->assertRedirect(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'));

        $this->assertSame('deprecated', DetectionRule::where('rule_id', 'IDENTITY_MFA_FAILURE_BURST')->value('stage'));
    }

    public function test_promote_rejects_invalid_stage_transition(): void
    {
        $this->actingAs($this->admin())
            ->post(route('detection.promote', 'IDENTITY_MFA_FAILURE_BURST'), [
                'target_stage' => 'shadow',
            ])
            ->assertRedirect(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'))
            ->assertSessionHasErrors('promotion');

        // Stage must be unchanged
        $this->assertSame('staged_active', DetectionRule::where('rule_id', 'IDENTITY_MFA_FAILURE_BURST')->value('stage'));
    }

    public function test_promote_blocks_endpoint_rule_to_staged_active(): void
    {
        // Endpoint rules must stay shadow-only
        $this->actingAs($this->admin())
            ->post(route('detection.promote', 'suspicious_parent_child_process'), [
                'target_stage' => 'staged_active',
            ])
            ->assertRedirect(route('detection.show', 'suspicious_parent_child_process'))
            ->assertSessionHasErrors('promotion');

        $this->assertSame('shadow', DetectionRule::where('rule_id', 'suspicious_parent_child_process')->value('stage'));
    }

    public function test_promote_blocks_threat_intel_rule_to_staged_active(): void
    {
        $this->actingAs($this->admin())
            ->post(route('detection.promote', 'ioc_ip_match'), [
                'target_stage' => 'staged_active',
            ])
            ->assertRedirect(route('detection.show', 'ioc_ip_match'))
            ->assertSessionHasErrors('promotion');

        $this->assertSame('shadow', DetectionRule::where('rule_id', 'ioc_ip_match')->value('stage'));
    }

    public function test_identity_cloud_staged_active_status_unchanged_after_module_load(): void
    {
        // Loading the registry must not change identity/cloud rule stages
        $this->registry()->allRules();

        $identityRules = DetectionRule::whereIn('rule_id', [
            'IDENTITY_MFA_FAILURE_BURST',
            'IDENTITY_FAILED_LOGIN_ACROSS_SERVICES',
            'CLOUD_UNUSUAL_API_ACTIVITY',
            'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
            'SAAS_UNUSUAL_ADMIN_ACTIVITY',
        ])->get();

        foreach ($identityRules as $rule) {
            $this->assertSame('staged_active', $rule->stage, "$rule->rule_id must remain staged_active");
        }
    }

    // -----------------------------------------------------------------------
    // Notes
    // -----------------------------------------------------------------------

    public function test_store_note_creates_record(): void
    {
        $this->actingAs($this->admin())
            ->post(route('detection.notes.store', 'IDENTITY_MFA_FAILURE_BURST'), [
                'note_type' => 'false_positive',
                'body'      => 'Observed FP from corp VPN exit node 203.0.113.5.',
            ])
            ->assertRedirect(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'));

        $this->assertDatabaseHas('detection_rule_notes', [
            'rule_id'   => 'IDENTITY_MFA_FAILURE_BURST',
            'note_type' => 'false_positive',
        ]);
    }

    public function test_store_note_validates_note_type(): void
    {
        $this->actingAs($this->admin())
            ->post(route('detection.notes.store', 'IDENTITY_MFA_FAILURE_BURST'), [
                'note_type' => 'invalid_type',
                'body'      => 'some body',
            ])
            ->assertSessionHasErrors('note_type');
    }

    public function test_notes_appear_on_show_page(): void
    {
        $rule = $this->registry()->findRule('IDENTITY_MFA_FAILURE_BURST');
        DetectionRuleNote::create([
            'rule_id'   => 'IDENTITY_MFA_FAILURE_BURST',
            'user_id'   => $this->admin()->id,
            'note_type' => 'promotion',
            'body'      => 'Approved for staged_active after 6h soak.',
        ]);

        $this->actingAs($this->admin())
            ->get(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'))
            ->assertOk()
            ->assertSee('Approved for staged_active after 6h soak.');
    }

    // -----------------------------------------------------------------------
    // Checklist
    // -----------------------------------------------------------------------

    public function test_checklist_update_saves_state(): void
    {
        // suspicious_parent_child_process is shadow stage → checklist is shadow→staged_active
        $this->actingAs($this->admin())
            ->post(route('detection.checklist.update', 'suspicious_parent_child_process'), [
                'checked' => ['replay_validated', 'owner_reviewed', 'peer_review_complete'],
            ])
            ->assertRedirect(route('detection.show', 'suspicious_parent_child_process'));

        // value() goes through Eloquent casts → returns PHP array directly
        $state = DetectionRule::where('rule_id', 'suspicious_parent_child_process')->value('checklist_state');
        $state = is_array($state) ? $state : (json_decode($state, true) ?? []);
        $this->assertTrue($state['replay_validated']);
        $this->assertTrue($state['owner_reviewed']);
        $this->assertTrue($state['peer_review_complete']);
        // Items not in checked[] are set to false
        $this->assertFalse($state['contract_validation']);
        $this->assertFalse($state['shadow_soak_48h']);
    }

    // -----------------------------------------------------------------------
    // Suppression
    // -----------------------------------------------------------------------

    public function test_suppression_can_be_applied(): void
    {
        $this->actingAs($this->admin())
            ->post(route('detection.suppression.update', 'IDENTITY_MFA_FAILURE_BURST'), [
                'suppression_active'     => '1',
                'suppression_reason'     => 'actor=svc-account-ci AND cloud_account=test',
                'suppression_expires_at' => now()->addDays(14)->format('Y-m-d'),
            ])
            ->assertRedirect(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'));

        $rule = DetectionRule::where('rule_id', 'IDENTITY_MFA_FAILURE_BURST')->first();
        $this->assertTrue($rule->suppression_active);
        $this->assertStringContainsString('svc-account-ci', $rule->suppression_reason);
    }

    public function test_suppression_can_be_cleared(): void
    {
        DetectionRule::where('rule_id', 'IDENTITY_MFA_FAILURE_BURST')
            ->update(['suppression_active' => true, 'suppression_reason' => 'old reason']);

        $this->actingAs($this->admin())
            ->post(route('detection.suppression.update', 'IDENTITY_MFA_FAILURE_BURST'), [
                'suppression_active' => '0',
            ])
            ->assertRedirect(route('detection.show', 'IDENTITY_MFA_FAILURE_BURST'));

        $this->assertFalse(DetectionRule::where('rule_id', 'IDENTITY_MFA_FAILURE_BURST')->value('suppression_active'));
    }

    // -----------------------------------------------------------------------
    // Replay validation
    // -----------------------------------------------------------------------

    public function test_replay_validation_can_be_marked(): void
    {
        // Reset a rule so replay_validated=false
        $rule = DetectionRule::firstOrCreate(
            ['rule_id' => 'suspicious_parent_child_process'],
            ['stage' => 'shadow', 'version' => 'v1', 'replay_validated' => false]
        );
        $rule->update(['replay_validated' => false]);

        $this->actingAs($this->admin())
            ->post(route('detection.replay.mark', 'suspicious_parent_child_process'))
            ->assertRedirect(route('detection.show', 'suspicious_parent_child_process'));

        $this->assertTrue(DetectionRule::where('rule_id', 'suspicious_parent_child_process')->value('replay_validated'));
    }

    // -----------------------------------------------------------------------
    // Gates API
    // -----------------------------------------------------------------------

    public function test_gates_api_returns_json(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('detection.gates.api', 'IDENTITY_MFA_FAILURE_BURST'))
            ->assertOk()
            ->assertJsonStructure(['rule_id', 'stage', 'gates'])
            ->assertJsonPath('rule_id', 'IDENTITY_MFA_FAILURE_BURST');
    }

    public function test_gates_api_returns_404_for_unknown_rule(): void
    {
        $this->actingAs($this->admin())
            ->getJson(route('detection.gates.api', 'UNKNOWN_RULE'))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------------
    // Rule versioning
    // -----------------------------------------------------------------------

    public function test_rules_have_version_field(): void
    {
        $rules = $this->registry()->allRules();
        foreach ($rules as $rule) {
            $this->assertNotEmpty($rule['db_state']->version, "Rule {$rule['rule_id']} must have a version");
        }
    }

    public function test_identity_cloud_rules_are_version_v1(): void
    {
        $rules = $this->registry()->allRules()->filter(fn ($r) => $r['domain'] === 'identity');
        foreach ($rules as $rule) {
            $this->assertSame('v1', $rule['db_state']->version);
        }
    }

    // -----------------------------------------------------------------------
    // Rule owner
    // -----------------------------------------------------------------------

    public function test_all_rules_have_owner_field(): void
    {
        $rules = $this->registry()->allRules();
        foreach ($rules as $rule) {
            $this->assertNotEmpty($rule['owner'] ?? $rule['db_state']->owner, "Rule {$rule['rule_id']} must have an owner");
        }
    }

    // -----------------------------------------------------------------------
    // Domain isolation — staged_active identity/cloud unchanged
    // -----------------------------------------------------------------------

    public function test_endpoint_rules_remain_shadow_after_full_registry_load(): void
    {
        $this->registry()->allRules();

        $endpointRules = DetectionRule::whereIn('rule_id', [
            'suspicious_parent_child_process',
            'powershell_encoded_command',
            'suspicious_temp_file_write',
            'failed_login_burst',
            'suspicious_dns_query',
            'suspicious_outbound_connection',
        ])->get();

        foreach ($endpointRules as $r) {
            $this->assertSame('shadow', $r->stage, "$r->rule_id must remain shadow");
        }
    }

    public function test_threat_intel_rules_remain_shadow(): void
    {
        $this->registry()->allRules();

        $iocRules = DetectionRule::whereIn('rule_id', [
            'ioc_ip_match', 'ioc_domain_match', 'ioc_file_hash_match',
        ])->get();

        foreach ($iocRules as $r) {
            $this->assertSame('shadow', $r->stage, "$r->rule_id must remain shadow");
        }
    }
}
