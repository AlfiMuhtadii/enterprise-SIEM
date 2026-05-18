# Rule Registry

Human-readable reference for the rule registry. The machine-readable source of truth is `docs/detection/rules/registry.v1.json`.

---

## Registry Location

```
docs/detection/rules/registry.v1.json
```

---

## Registry Schema

Each rule entry in `registry.v1.json` contains:

| Field | Type | Required | Description |
|---|---|---|---|
| `rule_id` | string | yes | Unique rule identifier. Immutable once registered. |
| `domain` | string | yes | `identity`, `cloud`, `saas`, `endpoint`, `threat-intel`, `xdr` |
| `status` | string | yes | `draft`, `shadow`, `staged_active`, `deprecated` |
| `version` | string | yes | `v1`, `v2`, etc. Increment on breaking changes. |
| `title` | string | yes | Short human-readable title |
| `description` | string | yes | What the rule detects and why it matters |
| `severity` | string | yes | `critical`, `high`, `medium`, `low`, `info` |
| `confidence` | float | yes | 0.0–1.0 |
| `mitre_attack` | array | yes* | ATT&CK technique IDs. Required for endpoint and threat-intel rules. |
| `event_types` | array | yes | Telemetry event types this rule operates on |
| `required_fields` | array | yes | Normalized fields required to evaluate this rule |
| `output_topic` | string | yes | Redpanda topic where alerts are published |
| `shadow_only` | bool | yes | `true` for endpoint and threat-intel rules |
| `suppression_key` | string | yes | Field combination used to deduplicate alerts |
| `replay_fixture` | string | no | Path to fixture file that triggers this rule |
| `expected_alert_count` | int | no | Expected alerts from replay_fixture |
| `false_positive_notes` | string | yes | At least one documented false-positive scenario |
| `suppression_guidance` | string | no | How to suppress without blanket suppression |
| `owner` | string | yes | Team or individual responsible for this rule |
| `validation_evidence` | string | no* | Required for `staged_active` rules. Path to soak/validation evidence. |
| `changelog` | array | no | Version history entries |
| `created_at` | string | yes | ISO-8601 UTC |
| `updated_at` | string | yes | ISO-8601 UTC |

---

## Current Rule Summary

### staged_active — identity/cloud/SaaS (12 rules)

| rule_id | domain | severity | confidence |
|---|---|---|---|
| IDENTITY_MFA_FAILURE_BURST | identity | high | 0.71 |
| IDENTITY_FAILED_LOGIN_ACROSS_SERVICES | identity | high | 0.76 |
| IDENTITY_RISKY_IP_LOGIN | identity | high | 0.76 |
| IDENTITY_IMPOSSIBLE_TRAVEL | identity | medium | 0.66 |
| IDENTITY_PRIVILEGE_ESCALATION | identity | high | 0.78 |
| IDENTITY_UNUSUAL_LOGIN_SOURCE | identity | medium | 0.71 |
| CLOUD_UNUSUAL_API_ACTIVITY | cloud | high | 0.71 |
| CLOUD_SUSPICIOUS_OBJECT_ACCESS | cloud | high | 0.71 |
| CLOUD_MASS_DOWNLOAD | cloud | high | 0.76 |
| CLOUD_NEW_ACCESS_KEY | cloud | high | 0.73 |
| CLOUD_SECURITY_SETTING_MODIFIED | cloud | high | 0.73 |
| SAAS_UNUSUAL_ADMIN_ACTIVITY | saas | high | 0.76 |

### shadow — endpoint (6 rules)

| rule_id | domain | severity | confidence |
|---|---|---|---|
| suspicious_parent_child_process | endpoint | high | 0.80 |
| powershell_encoded_command | endpoint | high | 0.85 |
| suspicious_temp_file_write | endpoint | high | 0.78 |
| failed_login_burst | endpoint | medium | 0.72 |
| suspicious_dns_query | endpoint | medium | 0.68 |
| suspicious_outbound_connection | endpoint | medium | 0.65 |

### shadow — threat-intel (3 rules)

| rule_id | domain | severity | confidence |
|---|---|---|---|
| ioc_ip_match | threat-intel | varies | varies |
| ioc_domain_match | threat-intel | varies | varies |
| ioc_file_hash_match | threat-intel | varies | varies |

---

## Validation

```powershell
python scripts\xdr_rule_registry_validate.py `
    --registry docs/detection/rules/registry.v1.json `
    --output reports/xdr_rule_registry_validation.json
```

---

## Adding a New Rule

1. Create a `draft` entry in `registry.v1.json`
2. Implement detection logic in the appropriate service
3. Create replay fixture under `tests/fixtures/`
4. Run `xdr_rule_registry_validate.py` — all checks must pass
5. Follow `docs/operations/rule-promotion-checklist.md` for promotion
