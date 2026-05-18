# Suppression Policy

Defines how alerts are deduplicated and suppressed in the detection pipeline. Suppression is applied per-rule via `suppression_key`, not globally.

---

## Suppression Key Design

Every rule declares a `suppression_key` — a pipe-delimited template of normalized event or alert fields used to identify duplicate alerts.

Examples:

| Rule | suppression_key |
|---|---|
| IDENTITY_MFA_FAILURE_BURST | `rule_id\|actor` |
| IDENTITY_PRIVILEGE_ESCALATION | `rule_id\|actor\|event_type` |
| CLOUD_MASS_DOWNLOAD | `rule_id\|actor\|cloud_account` |
| suspicious_parent_child_process | `rule_id\|host\|process.name\|process.ppid` |
| suspicious_outbound_connection | `rule_id\|host\|network.destination_ip\|network.destination_port` |
| ioc_ip_match | `rule_id\|host\|ioc_id` |

### Key Design Rules

- The key must contain `rule_id` as the first component — this prevents cross-rule suppression collisions
- The key must be narrow enough to suppress genuine duplicates but wide enough to allow distinct true-positive instances
- Actor-only keys (`rule_id|actor`) will suppress ALL alerts for that actor from that rule — use only when the rule is inherently per-actor
- Host + process keys are preferred for endpoint rules
- IOC match keys must include `ioc_id` to allow the same host to match different IOCs independently

---

## Deduplication Window

Suppression windows define how long a matching suppression key suppresses subsequent alerts.

| Rule status | Default window | Configurable |
|---|---|---|
| `shadow` | 15 minutes | Yes |
| `staged_active` | 60 minutes | Yes |

The deduplication window is applied by the alert consumer (alert-writer-service or shadow analytics). It is not applied inside the correlation-worker.

Rationale: the correlation-worker processes batches and applies alert-level deduplication (`dedupeEndpointAlerts`, `dedupe`). Temporal deduplication is downstream of the correlation worker.

---

## False-Positive Tracking

False-positive reports must be filed before any suppression is applied to a staged_active rule.

Process:
1. Alert observed in SOC dashboard
2. Analyst determines it is a false positive
3. False-positive report filed (rule_id, actor/host, evidence, proposed suppression scope)
4. Rule owner reviews within 5 business days
5. If confirmed FP: suppression added or confidence adjusted
6. If confirmed TP: no suppression; alert handling procedure reviewed

All suppression decisions must be recorded in the rule's `changelog` entry in `registry.v1.json`.

---

## Suppression Expiration

All suppressions must have an expiration date:

| Suppression type | Maximum duration | Renewal |
|---|---|---|
| Per-actor (specific user/host) | 30 days | Requires owner re-approval |
| Per-environment (known test system) | 90 days | Requires owner re-approval |
| Per-version (rule under revision) | Until next rule version | Automatic on version bump |

Suppressions without expiration are forbidden for `staged_active` rules.

---

## Safe Suppression Rules

Safe suppression narrows the scope to the minimum required:

- Suppress by specific `actor` + `rule_id` — not by actor alone
- Suppress by specific `host` + `rule_id` + `process.hash` for known-safe tools
- Suppress by IOC `source` for feed-level noise — not all IOC match rules
- Suppress by `cloud_account` for known automation accounts

Example of a safe suppression:
```
rule_id=CLOUD_NEW_ACCESS_KEY AND actor=terraform-ci-service-account
```

Example of an unsafe suppression:
```
actor=terraform-ci-service-account  (no rule_id — suppresses all rules for this actor)
```

---

## Forbidden Global Suppression

The following are forbidden regardless of justification:

- Suppressing an entire rule for a domain (effectively disabling the rule in production)
- Suppressing based on source IP or ASN ranges without rule_id scoping
- Global suppress by telemetry_type or event_type alone
- Suppressions with no expiration date on staged_active rules
- Shadow-mode suppression that also suppresses staged_active outputs (shadow and active suppressions are managed independently)

---

## Suppression and Shadow Rules

Shadow rules (`shadow_only=true`) have independent suppression from active rules:
- Shadow alert deduplication is applied by the shadow analytics layer (not alert-writer-service)
- Suppressing a shadow alert does NOT suppress the corresponding active rule if one exists
- Suppression of shadow rules is managed through the shadow validation validator configuration

---

## Suppression Review Cadence

| Frequency | Action |
|---|---|
| Weekly | Review new suppression requests |
| Monthly | Audit active suppressions for expired or unnecessary entries |
| Per-rule-version-bump | Clear suppressions tied to the old version |
| Per-staged_active-promotion | Re-evaluate all suppression scope for the newly promoted rule |
