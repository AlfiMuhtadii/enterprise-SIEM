"""
PILOT-034 Controlled Enterprise Pilot Readiness Matrix Validator

Evaluates a pilot readiness matrix from a JSON spec. Advisory-only — no
autonomous promotion. SELF_APPROVE_BLOCKED enforced. All output is for
operator decision-making only.

Usage:
    python scripts/xdr_pilot_readiness_matrix.py --input matrix_spec.json --output report.json
    python scripts/xdr_pilot_readiness_matrix.py --template          # dump example spec
    python scripts/xdr_pilot_readiness_matrix.py --input spec.json   # evaluate, print to stdout

Exit codes: 0=PASS, 1=FAIL, 2=ERROR
"""

import argparse
import json
import sys
from pathlib import Path
from typing import Any

ADVISORY_ONLY = True
SELF_APPROVE_BLOCKED = True
AUTONOMOUS_PROMOTION = False

PASS_THRESHOLD = 0.80
MAX_GATES = 20

REQUIRED_GATE_IDS = [
    "soak_validation",
    "replay_verification",
    "tenant_isolation",
    "rollback_readiness",
]

VALID_DIMENSIONS = {"technical", "operational", "security", "telemetry", "easm", "certification"}
VALID_STATUSES = {"pass", "warn", "fail", "skip"}

EXAMPLE_SPEC = {
    "matrix_run_id": "prm-example-001",
    "tenant_id": "tenant-1",
    "operator_id": "operator-alice",
    "approved_by": "reviewer-bob",
    "pilot_scope": {"domain": "identity-cloud", "endpoints": 10},
    "gates": [
        {"gate_id": "soak_validation", "dimension": "technical", "status": "pass",
         "score": 1.0, "detail": "6h soak PASS 2026-05-14"},
        {"gate_id": "replay_verification", "dimension": "technical", "status": "pass",
         "score": 0.98, "detail": "replay success 98%"},
        {"gate_id": "tenant_isolation", "dimension": "security", "status": "pass",
         "score": 1.0, "detail": "isolation audit PASS"},
        {"gate_id": "rollback_readiness", "dimension": "operational", "status": "pass",
         "score": 0.95, "detail": "rollback drill complete"},
        {"gate_id": "rule_registry", "dimension": "technical", "status": "pass",
         "score": 1.0, "detail": "133 rules, 21/21 checks"},
        {"gate_id": "contract_validation", "dimension": "technical", "status": "pass",
         "detail": "all contracts valid"},
        {"gate_id": "easm_posture_score", "dimension": "easm", "status": "warn",
         "detail": "min posture 65 (advisory)"},
        {"gate_id": "pilot_execution_stable", "dimension": "operational", "status": "pass",
         "score": 0.92},
        {"gate_id": "secret_validation", "dimension": "security", "status": "pass"},
        {"gate_id": "xdr_certification_status", "dimension": "certification", "status": "pass"},
    ],
}


# ---------------------------------------------------------------------------
# Core evaluation functions
# ---------------------------------------------------------------------------

def validate_spec(spec: dict) -> list[str]:
    """Return list of validation errors (empty if OK)."""
    errors = []

    if not isinstance(spec.get("gates"), list):
        errors.append("spec.gates must be a list")
        return errors

    if len(spec["gates"]) > MAX_GATES:
        errors.append(f"Too many gates: {len(spec['gates'])} > MAX={MAX_GATES}")

    for i, gate in enumerate(spec["gates"]):
        if not gate.get("gate_id"):
            errors.append(f"gates[{i}]: missing gate_id")
        if gate.get("dimension") not in VALID_DIMENSIONS:
            errors.append(f"gates[{i}] gate_id={gate.get('gate_id')}: invalid dimension '{gate.get('dimension')}'")
        if gate.get("status") not in VALID_STATUSES:
            errors.append(f"gates[{i}] gate_id={gate.get('gate_id')}: invalid status '{gate.get('status')}'")

    return errors


def check_self_approve(spec: dict) -> str | None:
    """Return error string if self-approval detected, else None."""
    operator_id = spec.get("operator_id", "")
    approved_by = spec.get("approved_by", "")
    if operator_id and approved_by and operator_id == approved_by:
        return f"Self-approval blocked: operator_id == approved_by == '{operator_id}'"
    return None


def compute_matrix_score(gates: list[dict]) -> dict[str, Any]:
    """Compute pass rate and required gate statuses."""
    total = len(gates)
    passed = sum(1 for g in gates if g.get("status") == "pass")
    warned = sum(1 for g in gates if g.get("status") == "warn")
    failed = sum(1 for g in gates if g.get("status") == "fail")
    skipped = sum(1 for g in gates if g.get("status") == "skip")
    countable = total - skipped

    score = round(passed / countable, 4) if countable > 0 else 0.0

    required_gates_pass = True
    required_gate_results = {}
    gate_by_id = {g["gate_id"]: g for g in gates if g.get("gate_id")}
    for req_id in REQUIRED_GATE_IDS:
        gate = gate_by_id.get(req_id)
        gate_status = gate["status"] if gate else "missing"
        required_gate_results[req_id] = gate_status
        if gate_status in ("fail", "missing"):
            required_gates_pass = False

    return {
        "score": score,
        "total": total,
        "passed": passed,
        "warned": warned,
        "failed": failed,
        "skipped": skipped,
        "countable": countable,
        "required_gates_pass": required_gates_pass,
        "required_gate_results": required_gate_results,
    }


def resolve_overall_status(score: float, required_gates_pass: bool) -> str:
    """Determine PASS/FAIL for the matrix run."""
    if not required_gates_pass:
        return "FAIL"
    if score >= PASS_THRESHOLD:
        return "PASS"
    return "FAIL"


def group_gates_by_dimension(gates: list[dict]) -> dict[str, list[dict]]:
    result: dict[str, list[dict]] = {dim: [] for dim in VALID_DIMENSIONS}
    for gate in gates:
        dim = gate.get("dimension", "")
        if dim in result:
            result[dim].append(gate)
    return result


def evaluate_matrix(spec: dict) -> dict[str, Any]:
    """Full matrix evaluation. Returns report dict."""
    errors = validate_spec(spec)
    if errors:
        return {
            "status": "ERROR",
            "errors": errors,
            "is_advisory": ADVISORY_ONLY,
            "autonomous_promotion": AUTONOMOUS_PROMOTION,
        }

    self_approve_error = check_self_approve(spec)
    if self_approve_error:
        return {
            "status": "ERROR",
            "errors": [self_approve_error],
            "is_advisory": ADVISORY_ONLY,
            "autonomous_promotion": AUTONOMOUS_PROMOTION,
        }

    gates = spec["gates"]
    score_data = compute_matrix_score(gates)
    overall_status = resolve_overall_status(score_data["score"], score_data["required_gates_pass"])

    return {
        "matrix_run_id": spec.get("matrix_run_id", ""),
        "tenant_id": spec.get("tenant_id", ""),
        "pilot_scope": spec.get("pilot_scope"),
        "status": overall_status,
        "is_advisory": ADVISORY_ONLY,
        "autonomous_promotion": AUTONOMOUS_PROMOTION,
        "self_approve_blocked": SELF_APPROVE_BLOCKED,
        "score": score_data,
        "by_dimension": group_gates_by_dimension(gates),
        "required_gate_ids": REQUIRED_GATE_IDS,
        "pass_threshold": PASS_THRESHOLD,
        "errors": [],
    }


# ---------------------------------------------------------------------------
# CLI entry point
# ---------------------------------------------------------------------------

def main(_args: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="PILOT-034 Enterprise Pilot Readiness Matrix Validator")
    parser.add_argument("--input", help="Path to matrix spec JSON file")
    parser.add_argument("--output", help="Path to write report JSON")
    parser.add_argument("--template", action="store_true", help="Print example spec and exit")
    args = parser.parse_args(_args)

    if args.template:
        print(json.dumps(EXAMPLE_SPEC, indent=2))
        return 0

    if not args.input:
        parser.print_help()
        return 2

    input_path = Path(args.input)
    if not input_path.exists():
        print(f"ERROR: input file not found: {input_path}", file=sys.stderr)
        return 2

    try:
        spec = json.loads(input_path.read_text(encoding="utf-8"))
    except Exception as exc:
        print(f"ERROR: failed to parse input JSON: {exc}", file=sys.stderr)
        return 2

    report = evaluate_matrix(spec)

    output_str = json.dumps(report, indent=2, default=str)

    if args.output:
        Path(args.output).write_text(output_str, encoding="utf-8")
        print(f"Report written to {args.output}")
    else:
        print(output_str)

    if report.get("status") == "ERROR":
        return 2
    if report.get("status") == "PASS":
        return 0
    return 1


if __name__ == "__main__":
    sys.exit(main())
