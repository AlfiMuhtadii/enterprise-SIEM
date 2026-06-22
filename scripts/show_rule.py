#!/usr/bin/env python3
"""Show a specific detection rule from the registry by rule_id.

Usage:
    python scripts/show_rule.py --rule-id IDENTITY_MFA_FAILURE_BURST
    python scripts/show_rule.py --rule-id XDR-WIN-PS-014
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

_DEFAULT_REGISTRY = "docs/detection/rules/registry.v1.json"
_SEP = "=" * 64


def main() -> int:
    parser = argparse.ArgumentParser(description="Show detection rule by ID from the rule registry")
    parser.add_argument("--rule-id", required=True, metavar="RULE_ID",
                        help="Rule ID exactly as it appears in registry.v1.json")
    parser.add_argument("--registry", default=_DEFAULT_REGISTRY,
                        help=f"Path to registry file (default: {_DEFAULT_REGISTRY})")
    args = parser.parse_args()

    registry_path = Path(args.registry)
    if not registry_path.exists():
        print(f"ERROR: Registry not found: {registry_path}", file=sys.stderr)
        return 1

    data = json.loads(registry_path.read_text(encoding="utf-8"))
    all_rules = data.get("rules", [])
    matches = [r for r in all_rules if r.get("rule_id") == args.rule_id]

    if not matches:
        print(f"ERROR: Rule '{args.rule_id}' not found.", file=sys.stderr)
        print(f"Registry contains {len(all_rules)} rules. Sample IDs:", file=sys.stderr)
        for r in all_rules[:5]:
            print(f"  {r.get('rule_id')}", file=sys.stderr)
        return 1

    rule = matches[0]

    print(f"\n{_SEP}")
    print(f"  Rule ID      : {rule.get('rule_id')}")
    print(f"  Title        : {rule.get('title')}")
    print(f"  Domain       : {rule.get('domain')} | Status: {rule.get('status')}")
    print(f"  Severity     : {rule.get('severity')} | Confidence: {rule.get('confidence', 'N/A')}")
    mitre = ", ".join(rule.get("mitre_attack", []))
    print(f"  MITRE ATT&CK : {mitre or 'N/A'}")
    print(f"  Output Topic : {rule.get('output_topic', 'N/A')} | Shadow-only: {rule.get('shadow_only', False)}")
    print(f"  Version      : {rule.get('version', 'N/A')} | Owner: {rule.get('owner', 'N/A')}")
    print(_SEP)

    desc = rule.get("description", "")
    if desc:
        print(f"\n  Description:\n    {desc}")

    event_types = rule.get("event_types", [])
    if event_types:
        print(f"\n  Triggers on: {', '.join(event_types)}")

    required = rule.get("required_fields", [])
    if required:
        print(f"  Required fields: {', '.join(required)}")

    fp_notes = rule.get("false_positive_notes", "")
    if fp_notes:
        print(f"\n  FP Notes:\n    {fp_notes}")

    suppression = rule.get("suppression_guidance", "")
    if suppression:
        print(f"\n  Suppression:\n    {suppression}")

    evidence = rule.get("validation_evidence", "")
    if evidence:
        print(f"\n  Validation evidence: {evidence}")

    print(f"\n{_SEP}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
