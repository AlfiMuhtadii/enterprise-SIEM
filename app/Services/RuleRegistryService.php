<?php

namespace App\Services;

use App\Models\DetectionRule;
use Illuminate\Support\Collection;

class RuleRegistryService
{
    private const REGISTRY_PATH = 'docs/detection/rules/registry.v1.json';

    // Gate definitions keyed by "currentStage|targetStage"
    private const GATE_SETS = [
        'draft|shadow' => [
            ['key' => 'confidence_minimum',   'label' => 'Confidence ≥ 0.65',                    'required' => true],
            ['key' => 'mitre_mapping',        'label' => 'MITRE ATT&CK mapping declared',         'required' => true],
            ['key' => 'fp_notes',             'label' => 'False-positive notes written',           'required' => true],
            ['key' => 'suppression_key',      'label' => 'Suppression key defined',               'required' => true],
            ['key' => 'owner_assigned',       'label' => 'Owner assigned',                        'required' => true],
            ['key' => 'output_topic_shadow',  'label' => 'Output topic is shadow channel',        'required' => true],
            ['key' => 'suppression_guidance', 'label' => 'Suppression guidance documented',       'required' => true],
        ],
        'shadow|staged_active' => [
            ['key' => 'domain_allowed',        'label' => 'Domain not endpoint or threat-intel',   'required' => true],
            ['key' => 'replay_validated',      'label' => 'Replay validation passed',              'required' => true],
            ['key' => 'validation_evidence',   'label' => 'Validation evidence reference present', 'required' => true],
            ['key' => 'fp_rate_assessed',      'label' => 'False-positive rate assessed',         'required' => true],
            ['key' => 'output_topic_active',   'label' => 'Output topic is xdr.alerts',           'required' => true],
            ['key' => 'shadow_only_false',     'label' => 'shadow_only flag is false',            'required' => true],
            ['key' => 'owner_signoff',         'label' => 'Owner has signed off on promotion',    'required' => true],
        ],
        'staged_active|deprecated' => [
            ['key' => 'owner_decision',  'label' => 'Owner decision documented',  'required' => false],
        ],
    ];

    private const CHECKLIST_DRAFT_TO_SHADOW = [
        'rule_registered', 'globally_unique', 'title_description', 'severity_justified',
        'confidence_minimum', 'mitre_mapping', 'fp_notes', 'suppression_key',
        'owner_assigned', 'detection_logic_implemented', 'output_topic_shadow',
        'shadow_only_flag', 'replay_fixture_created', 'expected_alert_count',
        'registry_validation_passes',
    ];

    private const CHECKLIST_SHADOW_TO_ACTIVE = [
        'contract_validation', 'docker_config_valid', 'output_schema_matches',
        'replay_validated', 'replay_idempotent', 'no_duplicate_alerts',
        'fp_scenarios_tested', 'fp_rate_acceptable', 'owner_fp_signoff',
        'shadow_soak_48h', 'shadow_volume_reviewed', 'no_goroutine_leak',
        'shadow_alerts_non_zero', 'validation_evidence_populated',
        'suppression_guidance_documented', 'rollback_plan_documented',
        'owner_reviewed', 'peer_review_complete', 'changelog_updated',
        'final_registry_validation',
    ];

    public static function checklistKeysFor(string $fromStage, string $toStage): array
    {
        return match ("$fromStage|$toStage") {
            'draft|shadow'          => self::CHECKLIST_DRAFT_TO_SHADOW,
            'shadow|staged_active'  => self::CHECKLIST_SHADOW_TO_ACTIVE,
            default                 => [],
        };
    }

    public function allRules(): Collection
    {
        $registry = $this->loadRegistry();
        $dbRules  = DetectionRule::all()->keyBy('rule_id');

        return collect($registry['rules'])->map(function (array $rule) use ($dbRules) {
            $dbState = $dbRules->get($rule['rule_id']) ?? $this->initializeRule($rule);
            $rule['db_state'] = $dbState;
            $rule['gates']    = $this->computeGates($rule);
            return $rule;
        });
    }

    public function findRule(string $ruleId): ?array
    {
        $registry = $this->loadRegistry();
        $rule     = collect($registry['rules'])->firstWhere('rule_id', $ruleId);
        if ($rule === null) {
            return null;
        }
        $rule['db_state'] = DetectionRule::firstOrCreate(
            ['rule_id' => $ruleId],
            $this->defaultDbState($rule)
        );
        $rule['notes']    = $rule['db_state']->notes()->with('user')->latest()->get();
        $rule['gates']    = $this->computeGates($rule);
        $rule['checklist']= $this->buildChecklist($rule);
        return $rule;
    }

    public function mitreCoverageMap(Collection $rules): array
    {
        $techniques = [];
        foreach ($rules as $rule) {
            foreach ($rule['mitre_attack'] ?? [] as $tech) {
                if (!isset($techniques[$tech])) {
                    $techniques[$tech] = ['technique' => $tech, 'rules' => [], 'domains' => []];
                }
                $techniques[$tech]['rules'][]   = $rule['rule_id'];
                $techniques[$tech]['domains'][] = $rule['domain'];
            }
        }
        foreach ($techniques as &$t) {
            $t['domains'] = array_values(array_unique($t['domains']));
            $t['rules']   = array_values(array_unique($t['rules']));
        }
        unset($t);
        ksort($techniques);
        return array_values($techniques);
    }

    public function validatePromotion(array $rule, string $targetStage): array
    {
        $current = $rule['db_state']->stage;
        $validNext = ['draft' => 'shadow', 'shadow' => 'staged_active', 'staged_active' => 'deprecated'];

        if (($validNext[$current] ?? null) !== $targetStage) {
            return ['passed' => false, 'reason' => "Cannot advance from $current to $targetStage directly.", 'gates' => []];
        }

        $gateKey = "$current|$targetStage";
        $gates   = $this->evaluateGates($rule, $gateKey);
        $passed  = collect($gates)->filter(fn ($g) => $g['required'])->every(fn ($g) => $g['passed']);

        return ['passed' => $passed, 'reason' => $passed ? '' : 'One or more required gates did not pass.', 'gates' => $gates];
    }

    private function evaluateGates(array $rule, string $gateKey): array
    {
        $defs  = self::GATE_SETS[$gateKey] ?? [];
        $dbState = $rule['db_state'];
        $result  = [];

        foreach ($defs as $def) {
            $passed = match ($def['key']) {
                'confidence_minimum'   => ($rule['confidence'] ?? 0) >= 0.65,
                'mitre_mapping'        => !empty($rule['mitre_attack']),
                'fp_notes'             => !empty($rule['false_positive_notes']),
                'suppression_key'      => !empty($rule['suppression_key']),
                'owner_assigned'       => !empty($rule['owner']),
                'output_topic_shadow'  => str_contains($rule['output_topic'] ?? '', 'shadow'),
                'suppression_guidance' => !empty($rule['suppression_guidance']),
                'domain_allowed'       => !in_array($rule['domain'] ?? '', ['endpoint', 'threat-intel'], true),
                'replay_validated'     => (bool) ($dbState->replay_validated ?? false),
                'validation_evidence'  => !empty($rule['validation_evidence']),
                'fp_rate_assessed'     => $dbState->false_positive_rate !== null || !empty($rule['false_positive_notes']),
                'output_topic_active'  => ($rule['output_topic'] ?? '') === 'xdr.alerts',
                'shadow_only_false'    => ($rule['shadow_only'] ?? true) === false,
                'owner_signoff'        => !empty($dbState->promotion_approved_by),
                'owner_decision'       => true,
                default                => false,
            };
            $result[] = array_merge($def, ['passed' => $passed]);
        }
        return $result;
    }

    private function computeGates(array $rule): array
    {
        $stage   = $rule['db_state']->stage ?? 'draft';
        $nextMap = ['draft' => 'shadow', 'shadow' => 'staged_active', 'staged_active' => 'deprecated'];
        $next    = $nextMap[$stage] ?? null;
        if ($next === null) {
            return [];
        }
        return $this->evaluateGates($rule, "$stage|$next");
    }

    private function buildChecklist(array $rule): array
    {
        $stage   = $rule['db_state']->stage;
        $nextMap = ['draft' => 'shadow', 'shadow' => 'staged_active'];
        $next    = $nextMap[$stage] ?? null;
        if ($next === null) {
            return [];
        }

        $keys       = self::checklistKeysFor($stage, $next);
        $savedState = $rule['db_state']->checklist_state ?? [];
        $labels     = $this->checklistLabels();

        return array_map(fn ($key) => [
            'key'     => $key,
            'label'   => $labels[$key] ?? ucwords(str_replace('_', ' ', $key)),
            'checked' => (bool) ($savedState[$key] ?? false),
        ], $keys);
    }

    private function checklistLabels(): array
    {
        return [
            'rule_registered'             => 'Rule ID registered in registry.v1.json',
            'globally_unique'             => 'Rule ID is globally unique (no collision)',
            'title_description'           => 'Title and description written',
            'severity_justified'          => 'Severity set and justified per governance policy',
            'confidence_minimum'          => 'Confidence ≥ 0.65',
            'mitre_mapping'               => 'MITRE ATT&CK mapping declared',
            'fp_notes'                    => 'False-positive notes written (≥ 1 scenario)',
            'suppression_key'             => 'Suppression key defined and reviewed by owner',
            'owner_assigned'              => 'Owner assigned',
            'detection_logic_implemented' => 'Detection logic implemented in appropriate service',
            'output_topic_shadow'         => 'Output topic confirmed as xdr.alerts.shadow.*',
            'shadow_only_flag'            => 'shadow_only: true set for endpoint/threat-intel rules',
            'replay_fixture_created'      => 'Replay fixture created and path registered',
            'expected_alert_count'        => 'expected_alert_count declared and validated',
            'registry_validation_passes'  => 'python scripts/xdr_rule_registry_validate.py PASS',
            'contract_validation'         => 'Event contract validation passes',
            'docker_config_valid'         => 'docker compose config --quiet exit 0',
            'output_schema_matches'       => 'Alert output schema matches event contract',
            'replay_validated'            => 'Replay fixture produces exact expected_alert_count',
            'replay_idempotent'           => 'Replay is idempotent — identical output on re-run',
            'no_duplicate_alerts'         => 'No duplicate alert IDs from same fixture',
            'fp_scenarios_tested'         => 'FP scenarios tested on real or representative telemetry',
            'fp_rate_acceptable'          => 'FP rate assessed and acceptable for staged_active',
            'owner_fp_signoff'            => 'Owner sign-off on false-positive rate',
            'shadow_soak_48h'             => 'Shadow soak ≥ 48 hours on representative traffic',
            'shadow_volume_reviewed'      => 'Shadow alert volume reviewed (not implausible)',
            'no_goroutine_leak'           => 'No goroutine leak or memory growth during shadow soak',
            'shadow_alerts_non_zero'      => 'shadow_alerts_published metric is non-zero',
            'validation_evidence_populated' => 'validation_evidence field populated in registry',
            'suppression_guidance_documented' => 'Suppression guidance documented in registry',
            'rollback_plan_documented'    => 'Rollback plan documented (how to revert to shadow)',
            'owner_reviewed'              => 'Owner reviewed promotion request',
            'peer_review_complete'        => 'Peer review of detection logic complete',
            'changelog_updated'           => 'Status transition documented in rule changelog',
            'final_registry_validation'   => 'Final python scripts/xdr_rule_registry_validate.py PASS',
        ];
    }

    private function initializeRule(array $rule): DetectionRule
    {
        return DetectionRule::firstOrCreate(
            ['rule_id' => $rule['rule_id']],
            $this->defaultDbState($rule)
        );
    }

    private function defaultDbState(array $rule): array
    {
        $isActive = ($rule['status'] ?? '') === 'staged_active';
        return [
            'stage'            => $rule['status'] ?? 'draft',
            'version'          => $rule['version'] ?? 'v1',
            'owner'            => $rule['owner'] ?? null,
            'replay_validated' => $isActive || !empty($rule['validation_evidence']),
        ];
    }

    public function registryVersion(): string
    {
        return $this->loadRegistry()['registry_version'] ?? 'v1';
    }

    private function loadRegistry(): array
    {
        $path = base_path(self::REGISTRY_PATH);
        return json_decode(file_get_contents($path), true) ?? ['rules' => []];
    }
}
