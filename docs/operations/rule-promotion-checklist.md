# Rule Promotion Checklist

Step-by-step gates for advancing a rule through the promotion stages. All gates must pass before promotion. No gate may be bypassed.

```
draft → shadow → staged_active → deprecated
```

---

## Gate Set 1: draft → shadow

All must be true before a rule may enter shadow mode.

- [ ] `rule_id` registered in `docs/detection/rules/registry.v1.json` as `status: draft`
- [ ] `rule_id` is globally unique (validator confirms no collision)
- [ ] `title` and `description` written
- [ ] `severity` set and justified (see governance policy)
- [ ] `confidence` set and justified (must be ≥ 0.65 to advance beyond draft)
- [ ] `mitre_attack` populated (required for endpoint and threat-intel rules; recommended for all)
- [ ] `false_positive_notes` written with at least one scenario
- [ ] `suppression_key` defined and reviewed by owner
- [ ] `owner` assigned
- [ ] Detection logic implemented in the appropriate service
- [ ] `output_topic` confirmed: shadow rules must use `xdr.alerts.shadow.*`, NOT `xdr.alerts`
- [ ] `shadow_only: true` set for endpoint and threat-intel rules
- [ ] `replay_fixture` created and path registered in registry
- [ ] `expected_alert_count` declared and matches fixture replay output
- [ ] Registry validation passes: `python scripts/xdr_rule_registry_validate.py`
- [ ] Status updated to `shadow` in registry, `updated_at` updated

---

## Gate Set 2: shadow → staged_active

All must be true before a rule may enter staged active mode.

### Contract and Schema
- [ ] Event contract validation passes: `python scripts/xdr_contract_validate.py`
- [ ] Docker compose config valid: `docker compose config --quiet`
- [ ] Rule output schema matches event contract (alert fields documented)

### Replay Validation
- [ ] Replay fixture produces exactly `expected_alert_count` alerts (no regression)
- [ ] Replay is idempotent: replaying the same fixture twice produces the same alert set
- [ ] No duplicate alert IDs from the same fixture input

### False-Positive Review
- [ ] False-positive scenarios from `false_positive_notes` tested against real or representative telemetry
- [ ] False-positive rate assessed and acceptable for `staged_active`
- [ ] Owner sign-off on false-positive rate

### Shadow Soak
- [ ] Rule has run in shadow mode for a minimum observation period (recommended: 48 hours on representative traffic)
- [ ] Shadow alert volume reviewed (not generating implausible alert volumes)
- [ ] No goroutine leak or memory growth observed during shadow soak
- [ ] `shadow_alerts_published` metric is non-zero (confirms rule is firing)

### Documentation
- [ ] `validation_evidence` field populated in registry (reference to soak report or validation artifact)
- [ ] `suppression_guidance` documented in registry
- [ ] Rollback plan documented: how to revert this rule to shadow or draft if issues arise

### Owner and Approval
- [ ] Owner has reviewed the promotion request
- [ ] At least one peer review of the detection logic
- [ ] Status transition documented in `changelog` in registry

### Final Registry Validation
- [ ] `python scripts/xdr_rule_registry_validate.py` — all checks PASS
- [ ] `output_topic` confirmed as `xdr.alerts` for staged_active
- [ ] `shadow_only: false` set
- [ ] Status updated to `staged_active` in registry, `updated_at` updated

---

## Gate Set 3: staged_active → deprecated

All must be true before a rule is deprecated.

- [ ] Owner decision documented (reason for deprecation)
- [ ] Replacement rule (if any) registered and promoted to at least `shadow`
- [ ] All existing suppressions referencing this rule_id cleared or transferred to replacement
- [ ] Downstream consumers notified (SOC team, any automated processes reading this rule's alerts)
- [ ] Rollback plan: if the replacement rule is insufficient, how to restore this rule
- [ ] Status updated to `deprecated` in registry, `updated_at` updated
- [ ] Detection logic disabled or guarded (rule must not fire after deprecation)
- [ ] `changelog` entry added with reason and date

---

## STOP Conditions

Stop and do not promote if any of the following are true:

- Replay validation fails (alert count mismatch or unexpected alerts)
- Any contract validation fails
- `confidence` < 0.65 (cannot advance to shadow)
- `confidence` < 0.75 without documented false-positive rate review
- `mitre_attack` is empty for endpoint or threat-intel rules
- `validation_evidence` is missing for staged_active promotion
- Owner has not reviewed
- `xdr_rule_registry_validate.py` returns any failure

---

## Endpoint-Specific Gates

Endpoint rules have additional constraints:
- May not advance to `staged_active` until endpoint domain soak completes
- See `docs/architecture/endpoint-shadow-correlation-plan.md` for all required gates
- `output_topic` must remain `xdr.alerts.shadow.endpoint` until full endpoint promotion

## Threat-Intel Enrichment Gates

IOC enrichment rules have additional constraints:
- IOC store must be operational and loaded with validated threat feeds
- IOC match rules must not produce alerts for known-benign values (tested via benign fixture)
- `output_topic` must remain `xdr.alerts.shadow.endpoint` until integration with active alert pipeline is approved
