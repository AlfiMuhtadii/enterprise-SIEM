#!/usr/bin/env python3
"""XDR detection rule registry validator.

CI-safe: exits 0 on PASS, 1 on FAIL (validation errors), 2 on ERROR (cannot parse / file missing).
No side effects on the running platform. Read-only.

Checks performed:
  1.  registry.v1.json is valid JSON
  2.  all rule_ids are unique
  3.  required fields present on every rule
  4.  lifecycle stage is valid (draft / shadow / staged_active / deprecated)
  5.  MITRE ATT&CK mapping declared for all non-draft/non-deprecated rules
  6.  confidence is in [0.0, 1.0]
  7.  confidence >= 0.65 for non-draft/non-deprecated rules (governance floor)
  8.  endpoint and threat-intel rules CANNOT be staged_active (hard gate, allowlist is empty)
  9.  output_topic matches lifecycle stage exactly:
        staged_active → xdr.alerts
        shadow        → starts with xdr.alerts.shadow
  10. shadow_only=true enforced for endpoint and threat-intel domain rules
  11. suppression_key declared for all non-draft rules
  12. owner declared for staged_active rules
  13. validation_evidence present for staged_active rules (replay_validated proxy)
  14. false_positive_notes non-empty for non-draft rules
  15. deprecated rules declare deprecation_reason or replacement_rule_id
  16. replay_fixture file exists when path is declared
  17. expected_alert_count >= 0 when declared

Output:
  reports/xdr_rule_registry_validation.json  (created by default, skip with --no-report)
"""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

# ---------------------------------------------------------------------------
# Constants
# ---------------------------------------------------------------------------

VALID_STATUSES = frozenset({"draft", "shadow", "staged_active", "deprecated"})
VALID_SEVERITIES = frozenset({"critical", "high", "medium", "low", "info"})
VALID_DOMAINS = frozenset({"identity", "cloud", "saas", "endpoint", "threat-intel", "xdr", "network"})

ACTIVE_ALERT_TOPIC = "xdr.alerts"
SHADOW_TOPIC_PREFIX = "xdr.alerts.shadow"

# Domains that must remain shadow-only until an explicit domain soak gate passes.
# Promotion of these domains to staged_active is forbidden in CI.
# network domain: DNS/proxy/firewall analytics — shadow-only until domain-specific 6h soak PASS
PROTECTED_DOMAINS = frozenset({"endpoint", "threat-intel", "network"})

# Intentionally empty: no rule in a PROTECTED_DOMAIN may be staged_active.
# To allowlist a rule, a separate governance gate review and pull request is required.
ACTIVE_ALLOWLIST: frozenset[str] = frozenset()

# Fields that every rule must have (key must exist — value may be null only where noted).
REQUIRED_FIELDS = (
    "rule_id", "domain", "status", "version", "title", "description",
    "severity", "confidence", "mitre_attack", "event_types", "required_fields",
    "output_topic", "shadow_only", "suppression_key",
    "false_positive_notes", "suppression_guidance",
    "owner", "created_at", "updated_at",
)

VALID_EVENT_TYPES = frozenset({
    "process_start", "process_exit", "network_connection", "dns_query",
    "file_write", "login_event", "scheduled_task_create", "service_install",
    "heartbeat", "object_access", "GetObject", "ListObject", "download",
    "CreateAccessKey", "access_key_create", "cloud_api_call", "api_request",
    "security_setting_modified", "policy_change", "PutBucketPolicy",
    "admin_action", "admin_login", "privilege_escalated", "role_assigned",
    "login_success", "login_failed", "mfa_failed",
    "dns_query", "proxy_request", "firewall_flow",
    "process_started", "process_terminated", "outbound_connection_opened",
    "persistence_item_created", "persistence_item_modified",
    "shell_execution_detected", "response_execution_ack", "response_execution_rollback",
    "any",
})

# ---------------------------------------------------------------------------
# Validation helpers
# ---------------------------------------------------------------------------

Finding = tuple[str, str]  # (rule_id, message)


def _check(rule: dict[str, Any], root: Path, seen_ids: set[str]) -> list[str]:
    """Return a list of failure messages for one rule. Empty list = pass."""
    failures: list[str] = []
    rule_id: str = rule.get("rule_id") or "<unknown>"
    domain: str = rule.get("domain", "")
    status: str = rule.get("status", "")
    p = f"[{rule_id}]"

    # ------------------------------------------------------------------
    # 1. Required fields
    # ------------------------------------------------------------------
    for field in REQUIRED_FIELDS:
        if field not in rule:
            failures.append(f"{p} missing required field '{field}'")

    # ------------------------------------------------------------------
    # 2. Unique rule_id
    # ------------------------------------------------------------------
    if rule_id in seen_ids:
        failures.append(f"{p} duplicate rule_id — rule_id must be globally unique")
    else:
        seen_ids.add(rule_id)

    # ------------------------------------------------------------------
    # 3. Valid domain
    # ------------------------------------------------------------------
    if domain not in VALID_DOMAINS:
        failures.append(f"{p} invalid domain '{domain}' — must be one of {sorted(VALID_DOMAINS)}")

    # ------------------------------------------------------------------
    # 4. Valid lifecycle stage
    # ------------------------------------------------------------------
    if status not in VALID_STATUSES:
        failures.append(f"{p} invalid status '{status}' — must be one of {sorted(VALID_STATUSES)}")

    # ------------------------------------------------------------------
    # 5. Valid severity
    # ------------------------------------------------------------------
    severity = rule.get("severity", "")
    if severity not in VALID_SEVERITIES:
        failures.append(f"{p} invalid severity '{severity}' — must be one of {sorted(VALID_SEVERITIES)}")

    # ------------------------------------------------------------------
    # 6. Confidence in [0.0, 1.0]
    # ------------------------------------------------------------------
    confidence = rule.get("confidence")
    if confidence is None:
        failures.append(f"{p} confidence is null — must be a float in [0.0, 1.0]")
    else:
        try:
            c = float(confidence)
            if not (0.0 <= c <= 1.0):
                failures.append(f"{p} confidence {c} is outside [0.0, 1.0]")
        except (TypeError, ValueError):
            failures.append(f"{p} confidence '{confidence}' is not a number")
            c = None

    # ------------------------------------------------------------------
    # 7. Confidence >= 0.65 for active/shadow rules (governance floor)
    # ------------------------------------------------------------------
    if status not in ("draft", "deprecated") and confidence is not None:
        try:
            if float(confidence) < 0.65:
                failures.append(
                    f"{p} confidence {confidence} < 0.65 — "
                    f"rules below the governance floor must remain 'draft'"
                )
        except (TypeError, ValueError):
            pass

    # ------------------------------------------------------------------
    # 8. MITRE ATT&CK required for non-draft, non-deprecated rules
    # ------------------------------------------------------------------
    if status not in ("draft", "deprecated"):
        attack = rule.get("mitre_attack")
        if not isinstance(attack, list) or len(attack) == 0:
            failures.append(
                f"{p} mitre_attack is required for status='{status}' — "
                f"must be a non-empty list of ATT&CK technique IDs"
            )

    # ------------------------------------------------------------------
    # 9. Hard gate: PROTECTED_DOMAINS may not be staged_active
    # ------------------------------------------------------------------
    if domain in PROTECTED_DOMAINS and status == "staged_active":
        if rule_id not in ACTIVE_ALLOWLIST:
            failures.append(
                f"{p} BLOCKED: domain='{domain}' rule cannot be status='staged_active'. "
                f"Endpoint and threat-intel domains require a separate domain soak gate "
                f"(see docs/operations/rule-promotion-checklist.md). "
                f"ACTIVE_ALLOWLIST is intentionally empty."
            )

    # ------------------------------------------------------------------
    # 10. Output topic matches lifecycle stage exactly
    # ------------------------------------------------------------------
    output_topic: str = rule.get("output_topic") or ""
    if status == "staged_active":
        if output_topic != ACTIVE_ALERT_TOPIC:
            failures.append(
                f"{p} status=staged_active requires output_topic='{ACTIVE_ALERT_TOPIC}' "
                f"(got '{output_topic}')"
            )
    elif status == "shadow":
        if output_topic and not output_topic.startswith(SHADOW_TOPIC_PREFIX):
            failures.append(
                f"{p} status=shadow requires output_topic starting with '{SHADOW_TOPIC_PREFIX}' "
                f"(got '{output_topic}')"
            )

    # ------------------------------------------------------------------
    # 11. shadow_only enforced for PROTECTED_DOMAINS
    # ------------------------------------------------------------------
    shadow_only = rule.get("shadow_only")
    if domain in PROTECTED_DOMAINS and shadow_only is not True:
        failures.append(
            f"{p} domain='{domain}' requires shadow_only=true "
            f"(got {shadow_only!r})"
        )

    # ------------------------------------------------------------------
    # 12. shadow_only=true must not publish to xdr.alerts
    # ------------------------------------------------------------------
    if shadow_only is True and output_topic == ACTIVE_ALERT_TOPIC:
        failures.append(
            f"{p} shadow_only=true but output_topic='{ACTIVE_ALERT_TOPIC}' — "
            f"shadow rules must use a shadow topic (e.g. xdr.alerts.shadow.*)"
        )

    # ------------------------------------------------------------------
    # 13. Suppression key for non-draft rules
    # ------------------------------------------------------------------
    suppression_key = rule.get("suppression_key")
    if status not in ("draft",) and not suppression_key:
        failures.append(
            f"{p} suppression_key is empty — required for all non-draft rules"
        )

    # ------------------------------------------------------------------
    # 14. Owner required for staged_active rules
    # ------------------------------------------------------------------
    owner = rule.get("owner")
    if status == "staged_active" and not owner:
        failures.append(
            f"{p} owner is empty — required for status=staged_active rules"
        )

    # ------------------------------------------------------------------
    # 15. Validation evidence (replay_validated proxy) for staged_active
    # ------------------------------------------------------------------
    if status == "staged_active":
        evidence = rule.get("validation_evidence")
        if not evidence:
            failures.append(
                f"{p} validation_evidence is null — required for staged_active rules "
                f"(reference to soak report or validation artifact; proxy for replay_validated=true)"
            )

    # ------------------------------------------------------------------
    # 16. false_positive_notes non-empty for non-draft rules
    # ------------------------------------------------------------------
    fp_notes = rule.get("false_positive_notes")
    if status not in ("draft", "deprecated") and not (fp_notes and str(fp_notes).strip()):
        failures.append(
            f"{p} false_positive_notes is empty — at least one FP scenario required "
            f"for non-draft rules"
        )

    # ------------------------------------------------------------------
    # 17. Deprecated rules must declare reason or replacement
    # ------------------------------------------------------------------
    if status == "deprecated":
        dep_reason = rule.get("deprecation_reason") or ""
        replacement = rule.get("replacement_rule_id") or ""
        if not str(dep_reason).strip() and not str(replacement).strip():
            failures.append(
                f"{p} status=deprecated but neither 'deprecation_reason' nor "
                f"'replacement_rule_id' is declared — at least one is required"
            )

    # ------------------------------------------------------------------
    # 18. replay_fixture path exists when declared
    # ------------------------------------------------------------------
    replay_fixture = rule.get("replay_fixture")
    if replay_fixture:
        fixture_path = root / replay_fixture
        if not fixture_path.exists():
            failures.append(
                f"{p} replay_fixture '{replay_fixture}' not found at {fixture_path}"
            )

    # ------------------------------------------------------------------
    # 19. expected_alert_count >= 0 when declared
    # ------------------------------------------------------------------
    expected = rule.get("expected_alert_count")
    if expected is not None:
        try:
            if int(expected) < 0:
                failures.append(
                    f"{p} expected_alert_count must be >= 0 (got {expected})"
                )
        except (TypeError, ValueError):
            failures.append(
                f"{p} expected_alert_count '{expected}' is not an integer"
            )

    return failures


def _summarize(rules: list[dict[str, Any]]) -> dict[str, Any]:
    by_status: dict[str, int] = {}
    by_domain: dict[str, int] = {}
    by_severity: dict[str, int] = {}
    for r in rules:
        s = r.get("status", "?")
        d = r.get("domain", "?")
        v = r.get("severity", "?")
        by_status[s] = by_status.get(s, 0) + 1
        by_domain[d] = by_domain.get(d, 0) + 1
        by_severity[v] = by_severity.get(v, 0) + 1
    return {
        "total_rules": len(rules),
        "by_status": by_status,
        "by_domain": by_domain,
        "by_severity": by_severity,
    }


def _write_report(report: dict[str, Any], path: Path) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(report, indent=2, default=str), encoding="utf-8")


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def parse_args() -> argparse.Namespace:
    p = argparse.ArgumentParser(
        description="Validate the XDR detection rule registry (CI-safe, read-only)."
    )
    p.add_argument(
        "--registry",
        default="docs/detection/rules/registry.v1.json",
        help="Path to registry.v1.json relative to repo root",
    )
    p.add_argument(
        "--output",
        default="reports/xdr_rule_registry_validation.json",
        help="Report output path (relative to repo root)",
    )
    p.add_argument(
        "--no-report",
        action="store_true",
        help="Skip writing the JSON report (stdout only)",
    )
    p.add_argument(
        "--quiet",
        action="store_true",
        help="Only print the final status line (suppress per-failure lines)",
    )
    return p.parse_args()


def main() -> int:
    args = parse_args()
    root = Path(__file__).resolve().parents[1]
    registry_path = root / args.registry

    report: dict[str, Any] = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "registry_path": str(args.registry),
        "checks": {},
        "failures": [],
        "rule_failures": {},
        "summary": {},
        "errors": [],
        "validation_status": "PENDING",
    }

    # ------------------------------------------------------------------
    # Load registry
    # ------------------------------------------------------------------
    try:
        raw = registry_path.read_text(encoding="utf-8")
    except FileNotFoundError:
        msg = f"registry not found: {registry_path}"
        report["errors"].append(msg)
        report["validation_status"] = "ERROR"
        if not args.no_report:
            _write_report(report, root / args.output)
        print(f"ERROR  {msg}", file=sys.stderr)
        return 2

    try:
        registry = json.loads(raw)
    except json.JSONDecodeError as exc:
        msg = f"invalid JSON in registry: {exc}"
        report["errors"].append(msg)
        report["validation_status"] = "ERROR"
        if not args.no_report:
            _write_report(report, root / args.output)
        print(f"ERROR  {msg}", file=sys.stderr)
        return 2

    report["checks"]["registry_valid_json"] = True
    report["registry_version"] = registry.get("registry_version", "unknown")

    rules: list[dict[str, Any]] = registry.get("rules", [])
    if not rules:
        msg = "registry contains no rules"
        report["errors"].append(msg)
        report["checks"]["rules_present"] = False
        report["validation_status"] = "ERROR"
        if not args.no_report:
            _write_report(report, root / args.output)
        print(f"ERROR  {msg}", file=sys.stderr)
        return 2

    report["checks"]["rules_present"] = True

    # ------------------------------------------------------------------
    # Validate each rule
    # ------------------------------------------------------------------
    seen_ids: set[str] = set()
    all_failures: list[str] = []
    rule_failures: dict[str, list[str]] = {}

    for rule in rules:
        rule_id = rule.get("rule_id", "<unknown>")
        rf = _check(rule, root, seen_ids)
        if rf:
            rule_failures[rule_id] = rf
            all_failures.extend(rf)

    # ------------------------------------------------------------------
    # Aggregate named check results
    # ------------------------------------------------------------------
    def no_match(substring: str) -> bool:
        return not any(substring in f for f in all_failures)

    report["checks"].update({
        "unique_rule_ids":                  no_match("duplicate rule_id"),
        "required_fields_present":          no_match("missing required field"),
        "statuses_valid":                   no_match("invalid status"),
        "domains_valid":                    no_match("invalid domain"),
        "severities_valid":                 no_match("invalid severity"),
        "confidence_in_range":              no_match("is outside [0.0, 1.0]") and no_match("is not a number"),
        "confidence_floor_respected":       no_match("< 0.65"),
        "mitre_mapping_present":            no_match("mitre_attack is required"),
        "protected_domain_gate":            no_match("BLOCKED: domain="),
        "output_topic_matches_stage":       no_match("requires output_topic=") and no_match("does not start with"),
        "shadow_only_enforced":             no_match("requires shadow_only=true"),
        "shadow_only_topic_safe":           no_match("shadow_only=true but output_topic="),
        "suppression_keys_declared":        no_match("suppression_key is empty"),
        "owners_assigned":                  no_match("owner is empty"),
        "validation_evidence_present":      no_match("validation_evidence is null"),
        "fp_notes_present":                 no_match("false_positive_notes is empty"),
        "deprecated_rules_documented":      no_match("neither 'deprecation_reason'"),
        "replay_fixtures_exist":            no_match("not found at"),
        "alert_counts_non_negative":        no_match("must be >= 0"),
    })

    passed_checks = sum(1 for v in report["checks"].values() if v is True)
    total_checks = len(report["checks"])

    report["failures"] = all_failures
    report["rule_failures"] = rule_failures
    report["summary"] = _summarize(rules)
    report["validation_status"] = "PASS" if not all_failures else "FAIL"

    if not args.no_report:
        out_path = root / args.output
        _write_report(report, out_path)
        print(f"report={out_path}")

    status_line = (
        f"status={report['validation_status']}  "
        f"rules={len(rules)}  "
        f"checks={passed_checks}/{total_checks}  "
        f"failures={len(all_failures)}"
    )
    print(status_line)

    if all_failures and not args.quiet:
        for f in all_failures:
            print(f"  FAIL  {f}")

    # Per-check summary in non-quiet mode
    if not args.quiet:
        failing_checks = [k for k, v in report["checks"].items() if v is False]
        if failing_checks:
            print("  Failing checks: " + ", ".join(failing_checks))

    return 0 if report["validation_status"] == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())
