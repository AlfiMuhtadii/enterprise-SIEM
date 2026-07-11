#!/usr/bin/env python3
"""
ENT-SDLC-NO-SUPPLYCHAIN (continuation) — dependency-level vulnerability
scanning.

The existing SBOM generator (scripts/xdr_generate_sbom.py) explicitly scoped
out "image vuln scan gate (trivy)" and "signed builds (cosign)" as needing
tooling this environment cannot install (both require a running Docker
daemon / registry access). This script covers a distinct, narrower, but
genuinely achievable slice: SOURCE dependency vulnerability scanning —
"does any exact-pinned version this repo depends on have a known CVE" — via
two real, network-installable, no-Docker-needed tools:

  - govulncheck (Go): call-graph reachability analysis against the Go
    vulnerability database (vuln.go.dev) — distinguishes "a vulnerable
    symbol exists in an imported module" from "this code actually calls
    it", which a naive version-list scan cannot.
  - pip-audit (Python): queries the PyPI Advisory Database (via `python -m
    pip_audit`, not the `pip-audit` console script, so this works even when
    pip's Scripts/ directory isn't on PATH) for each requirements.txt.

This is advisory evidence-gathering, not a hard CI gate — matching this
platform's "no autonomous action" posture: findings are reported for human
review, never auto-remediated (bumping a pinned dependency version is a
deliberate decision with its own regression risk, not something this
script does).

Usage:
    python scripts/xdr_dependency_vuln_scan.py
    python scripts/xdr_dependency_vuln_scan.py --output reports/xdr_dependency_vuln_scan.json
    python scripts/xdr_dependency_vuln_scan.py --skip-go        # Python only
    python scripts/xdr_dependency_vuln_scan.py --skip-python    # Go only

Exit codes:
    0 - scan completed (status PASS or WARN — findings, if any, are in the report)
    1 - a scan tool itself failed to run (missing binary, crash, unparseable output)
"""

from __future__ import annotations

import argparse
import json
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent.parent
SERVICES_DIR = ROOT / "services"
DEFAULT_OUTPUT = ROOT / "reports" / "xdr_dependency_vuln_scan.json"


def discover_go_services() -> list[Path]:
    return sorted(d for d in SERVICES_DIR.iterdir() if d.is_dir() and (d / "go.mod").is_file())


def discover_python_services() -> list[Path]:
    return sorted(d for d in SERVICES_DIR.iterdir() if d.is_dir() and (d / "requirements.txt").is_file())


def parse_govulncheck_stream(text: str) -> list[dict[str, Any]]:
    """govulncheck -json emits a stream of concatenated JSON objects (not an
    array, not NDJSON) — decode them one at a time with raw_decode()."""
    decoder = json.JSONDecoder()
    idx = 0
    objects = []
    length = len(text)
    while idx < length:
        stripped = text[idx:].lstrip()
        if not stripped:
            break
        idx = length - len(stripped)
        obj, end = decoder.raw_decode(text, idx)
        objects.append(obj)
        idx = end
    return objects


def scan_go_service(service_dir: Path) -> dict[str, Any]:
    name = service_dir.name
    if shutil.which("govulncheck") is None:
        return {
            "service": name,
            "type": "go",
            "tool": "govulncheck",
            "status": "SKIPPED",
            "detail": "govulncheck not installed (go install golang.org/x/vuln/cmd/govulncheck@latest)",
            "vulnerabilities": [],
        }

    try:
        proc = subprocess.run(
            ["govulncheck", "-json", "./..."],
            cwd=service_dir,
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=180,
        )
    except (subprocess.TimeoutExpired, OSError) as exc:
        return {
            "service": name, "type": "go", "tool": "govulncheck",
            "status": "ERROR", "detail": str(exc), "vulnerabilities": [],
        }

    try:
        objects = parse_govulncheck_stream(proc.stdout)
    except json.JSONDecodeError as exc:
        return {
            "service": name, "type": "go", "tool": "govulncheck",
            "status": "ERROR", "detail": f"unparseable govulncheck output: {exc}",
            "vulnerabilities": [],
        }

    osv_summaries: dict[str, str] = {}
    reachable_osv_ids: set[str] = set()
    for obj in objects:
        if "osv" in obj:
            osv_summaries[obj["osv"]["id"]] = obj["osv"].get("summary", "")
        if "finding" in obj:
            osv_id = obj["finding"].get("osv")
            if osv_id:
                reachable_osv_ids.add(osv_id)

    vulnerabilities = [
        {"id": osv_id, "summary": osv_summaries.get(osv_id, "")}
        for osv_id in sorted(reachable_osv_ids)
    ]

    # govulncheck exits 3 when vulnerabilities are found (by design) — not a
    # tool failure. Only treat other non-zero exits as a real ERROR.
    if proc.returncode not in (0, 3):
        return {
            "service": name, "type": "go", "tool": "govulncheck",
            "status": "ERROR",
            "detail": f"govulncheck exited {proc.returncode}: {proc.stderr[:500]}",
            "vulnerabilities": vulnerabilities,
        }

    return {
        "service": name,
        "type": "go",
        "tool": "govulncheck",
        "status": "WARN" if vulnerabilities else "PASS",
        "detail": f"{len(vulnerabilities)} reachable vulnerability id(s)" if vulnerabilities else "clean",
        "vulnerabilities": vulnerabilities,
    }


def scan_python_service(service_dir: Path) -> dict[str, Any]:
    name = service_dir.name
    req_path = service_dir / "requirements.txt"

    try:
        proc = subprocess.run(
            [sys.executable, "-m", "pip_audit", "-r", str(req_path), "--format", "json"],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=180,
        )
    except (subprocess.TimeoutExpired, OSError) as exc:
        return {
            "service": name, "type": "python", "tool": "pip-audit",
            "status": "ERROR", "detail": str(exc), "vulnerabilities": [],
        }

    if proc.returncode not in (0, 1):
        # pip-audit itself couldn't run (e.g. module not installed) rather
        # than "ran fine and found vulnerabilities" (which is exit 1).
        detail = proc.stderr.strip()[:500] or proc.stdout.strip()[:500]
        if "No module named" in detail:
            return {
                "service": name, "type": "python", "tool": "pip-audit",
                "status": "SKIPPED",
                "detail": "pip-audit not installed (pip install pip-audit)",
                "vulnerabilities": [],
            }
        return {
            "service": name, "type": "python", "tool": "pip-audit",
            "status": "ERROR", "detail": f"pip-audit exited {proc.returncode}: {detail}",
            "vulnerabilities": [],
        }

    try:
        data = json.loads(proc.stdout)
    except json.JSONDecodeError as exc:
        return {
            "service": name, "type": "python", "tool": "pip-audit",
            "status": "ERROR", "detail": f"unparseable pip-audit output: {exc}",
            "vulnerabilities": [],
        }

    vulnerabilities = []
    for dep in data.get("dependencies", []):
        for vuln in dep.get("vulns", []) or []:
            vulnerabilities.append({
                "id": vuln.get("id", ""),
                "package": dep.get("name", ""),
                "version": dep.get("version", ""),
                "fix_versions": vuln.get("fix_versions", []),
            })

    return {
        "service": name,
        "type": "python",
        "tool": "pip-audit",
        "status": "WARN" if vulnerabilities else "PASS",
        "detail": f"{len(vulnerabilities)} vulnerable dependency version(s)" if vulnerabilities else "clean",
        "vulnerabilities": vulnerabilities,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT), help="report JSON path")
    parser.add_argument("--no-report", action="store_true", help="skip writing the report file")
    parser.add_argument("--skip-go", action="store_true")
    parser.add_argument("--skip-python", action="store_true")
    parser.add_argument("--quiet", action="store_true")
    args = parser.parse_args()

    results: list[dict[str, Any]] = []

    if not args.skip_go:
        for svc in discover_go_services():
            if not args.quiet:
                print(f"scanning (go)     {svc.name} ...")
            results.append(scan_go_service(svc))

    if not args.skip_python:
        for svc in discover_python_services():
            if not args.quiet:
                print(f"scanning (python) {svc.name} ...")
            results.append(scan_python_service(svc))

    has_error = any(r["status"] == "ERROR" for r in results)
    has_warn = any(r["status"] == "WARN" for r in results)
    overall = "ERROR" if has_error else ("WARN" if has_warn else "PASS")

    total_vulns = sum(len(r["vulnerabilities"]) for r in results)

    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "scan_status": overall,
        "services_scanned": len(results),
        "total_vulnerabilities_found": total_vulns,
        "results": results,
        "note": (
            "Advisory evidence only, per this platform's no-autonomous-action posture. "
            "Reachable-only (govulncheck call-graph analysis, not a raw version-list match) "
            "for Go; PyPI Advisory Database lookups for Python. Distinct from — and does NOT "
            "replace — the still-blocked container image scan gate (trivy) and signed builds "
            "(cosign), both of which need a running Docker daemon this environment doesn't have."
        ),
    }

    if not args.no_report:
        out_path = Path(args.output)
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(report, indent=2), encoding="utf-8")
        print(f"report={out_path}")

    print(f"status={overall}  services={len(results)}  vulnerabilities={total_vulns}")
    if not args.quiet:
        for r in results:
            marker = {"PASS": "PASS", "WARN": "WARN", "ERROR": "FAIL", "SKIPPED": "SKIP"}[r["status"]]
            print(f"  {marker:4}  {r['type']:6}  {r['service']:30}  {r['detail']}")

    return 1 if has_error else 0


if __name__ == "__main__":
    sys.exit(main())
