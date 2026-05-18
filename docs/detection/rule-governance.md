# Rule Governance

Defines how detection rules are created, versioned, promoted, and retired in this platform. Applies to all rule domains: identity, cloud, SaaS, endpoint, and threat-intel enrichment.

---

## Rule ID Convention

Format: `DOMAIN_NOUN_VERB` or `domain_noun_verb`

- **Identity/cloud/SaaS active rules (legacy format):** `SCREAMING_SNAKE_CASE` (e.g., `IDENTITY_MFA_FAILURE_BURST`)
- **Endpoint shadow rules (current standard):** `lowercase_snake_case` (e.g., `suspicious_parent_child_process`)
- **IOC enrichment rules:** `ioc_type_match` pattern (e.g., `ioc_ip_match`)
- New rules must use `lowercase_snake_case`

Rules are unique across all domains. The same `rule_id` must not appear in two different domains or status stages. Rule IDs are immutable once registered — they cannot be renamed.

---

## Versioning Policy

Each rule has a `version` field (e.g., `v1`, `v2`). Versioning rules:

- **Breaking changes** (change in alert criteria, suppression key, evidence fields, or event requirements): increment the major version (v1 → v2) and keep the old rule in `deprecated` status until all downstream consumers are updated
- **Non-breaking changes** (documentation updates, false-positive notes, confidence adjustments ≤ 0.05): no version bump required; update `updated_at`
- **Confidence adjustments > 0.05**: requires a version bump
- **ATT&CK mapping additions**: non-breaking; update `updated_at`

Version transitions must be documented in the registry entry under `changelog`.

---

## Severity Policy

| Severity | Use |
|---|---|
| `critical` | High-confidence, high-impact multi-stage attacks (e.g., phishing chain + credential use + lateral movement). Reserved for compound rules only. |
| `high` | Strong single-stage indicators with low false-positive rate (e.g., privilege escalation, mass download). |
| `medium` | Anomalous behavior with meaningful false-positive rate (e.g., impossible travel, failed login burst). |
| `low` | Informational signals, wide-scope enrichment, or rules under active tuning. |
| `info` | Pure telemetry collection rules with no direct threat signal. |

Severity must reflect the expected false-positive rate at the declared confidence threshold. A rule rated `high` must have a false-positive rate < 10% in production telemetry.

---

## Confidence Scoring Policy

`confidence` is a float in [0.0, 1.0] representing the estimated probability that an alert from this rule represents a true positive in the target environment.

| Range | Interpretation |
|---|---|
| 0.90 – 1.00 | Near-certain indicator. Very low false-positive rate. |
| 0.75 – 0.89 | High confidence. Suitable for staged_active without additional enrichment. |
| 0.65 – 0.74 | Moderate confidence. Requires context (user role, host profile) to act on. |
| 0.50 – 0.64 | Low confidence. Suitable for shadow/hunting only. |
| < 0.50 | Experimental. Must remain `draft` status. |

Confidence is not a detection rate metric — it is a false-positive inverse estimate. Rules with confidence < 0.65 must remain in `draft` or `shadow` status.

---

## ATT&CK Mapping Requirements

- **Required for:** endpoint domain rules, threat-intel enrichment rules
- **Strongly recommended for:** identity, cloud, SaaS domain rules
- **Format:** MITRE ATT&CK technique IDs (e.g., `["T1059.001", "T1140"]`)
- At least one technique ID is required for all non-draft rules in endpoint and threat-intel domains
- Sub-technique IDs are preferred over parent technique IDs where applicable

---

## False-Positive Notes Requirement

Every rule must include a `false_positive_notes` field in `docs/detection/rules/registry.v1.json` with at least one documented false-positive scenario. Rules without false-positive notes may not advance beyond `shadow` status.

---

## Suppression Policy

See `docs/detection/suppression-policy.md` for the full suppression design.

Every rule must declare a `suppression_key` — the combination of fields used to deduplicate alerts within a deduplication window. The suppression key must be defined before a rule advances to `shadow` status.

---

## Replay Validation Requirement

Every rule must declare a `replay_fixture` pointing to a JSON fixture file containing events that should trigger the rule. The fixture must produce exactly `expected_alert_count` alerts when processed by the rule engine.

Replay validation is required for advancement beyond `draft` status.

---

## Owner/Maintainer Field

Every rule must have an `owner` field identifying the team or individual responsible for:
- Maintaining the detection logic
- Reviewing false-positive reports
- Approving suppression requests
- Authorizing promotion or deprecation

---

## Promotion Stages

```
draft → shadow → staged_active → deprecated
```

| Stage | Description |
|---|---|
| `draft` | Rule is under development. Not running in any environment. Logic may change without notice. |
| `shadow` | Rule runs in shadow mode. Alerts publish to `xdr.alerts.shadow.*` only. Never enters incident workflow. |
| `staged_active` | Rule is active for the declared domain. Alerts enter the incident pipeline. Requires full gate evidence. |
| `deprecated` | Rule is retired. Kept in registry for historical reference. Not running. |

Transitions:
- `draft` → `shadow`: replay fixture exists, ATT&CK mapping declared, suppression key defined, owner assigned
- `shadow` → `staged_active`: all shadow gates PASS (see `docs/operations/rule-promotion-checklist.md`), validation evidence reference required
- `staged_active` → `deprecated`: owner decision, rollback plan documented
- Skipping stages is forbidden

---

## Forbidden Practices

- Hardcoded suppression of entire host or user classes (blocks signals for that population permanently)
- Rules with `confidence < 0.50` in any status other than `draft`
- Rules without a `suppression_key`
- `staged_active` rules without `validation_evidence` reference
- Changing `rule_id` on an existing rule (breaks historical alert correlation)
- Shadow-only rules outputting to `xdr.alerts`
- Any rule triggering automated containment, isolation, or host-level response action
