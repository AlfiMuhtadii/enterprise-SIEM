#!/usr/bin/env python3
"""Advisory Trivy scanning for locally built XDR container images.

The scanner itself is pinned by digest because mutable Trivy tags and actions
are inappropriate for a supply-chain control. Findings produce WARN and exit 0;
scanner/runtime failures produce ERROR and exit 1.
"""

from __future__ import annotations

import argparse
import json
import subprocess
import sys
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_OUTPUT = ROOT / "reports" / "xdr_container_image_scan.json"
TRIVY_IMAGE = (
    "aquasec/trivy@sha256:"
    "be1190afcb28352bfddc4ddeb71470835d16462af68d310f9f4bca710961a41e"
)
TRIVY_VERSION = "0.70.0"


def discover_detector_images() -> list[str]:
    proc = subprocess.run(
        ["docker", "images", "--format", "{{.Repository}}:{{.Tag}}"],
        capture_output=True,
        text=True,
        encoding="utf-8",
        errors="replace",
        timeout=30,
    )
    if proc.returncode != 0:
        raise RuntimeError(proc.stderr.strip() or "docker images failed")

    return sorted({line.strip() for line in proc.stdout.splitlines()
                   if line.strip().startswith("detector-")})


def summarize_trivy(image: str, payload: dict[str, Any]) -> dict[str, Any]:
    vulnerabilities = []
    for result in payload.get("Results") or []:
        for finding in result.get("Vulnerabilities") or []:
            vulnerabilities.append({
                "id": finding.get("VulnerabilityID", ""),
                "package": finding.get("PkgName", ""),
                "installed_version": finding.get("InstalledVersion", ""),
                "fixed_version": finding.get("FixedVersion", ""),
                "severity": finding.get("Severity", "UNKNOWN"),
                "target": result.get("Target", ""),
            })

    counts = {severity: 0 for severity in ("UNKNOWN", "LOW", "MEDIUM", "HIGH", "CRITICAL")}
    for finding in vulnerabilities:
        severity = finding["severity"]
        counts[severity] = counts.get(severity, 0) + 1

    return {
        "image": image,
        "status": "WARN" if vulnerabilities else "PASS",
        "vulnerability_counts": counts,
        "vulnerabilities": vulnerabilities,
    }


def scan_image(image: str) -> dict[str, Any]:
    temp_root = ROOT / ".tmp"
    temp_root.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="xdr-trivy-", dir=temp_root) as temp_dir:
        input_dir = Path(temp_dir) / "input"
        output_dir = Path(temp_dir) / "output"
        input_dir.mkdir()
        output_dir.mkdir()
        archive_path = input_dir / "image.tar"
        report_path = output_dir / "result.json"
        try:
            saved = subprocess.run(
                ["docker", "save", "--output", str(archive_path), image],
                capture_output=True, text=True, encoding="utf-8", errors="replace", timeout=600,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            return {"image": image, "status": "ERROR", "detail": str(exc), "vulnerabilities": []}

        if saved.returncode != 0 or not archive_path.is_file():
            detail = (saved.stderr or saved.stdout).strip()[-1000:]
            return {
                "image": image, "status": "ERROR",
                "detail": detail or f"docker save exited {saved.returncode}",
                "vulnerabilities": [],
            }

        command = [
            "docker", "run", "--rm",
            "--mount", f"type=bind,source={input_dir.resolve()},target=/input,readonly",
            "--mount", f"type=bind,source={output_dir.resolve()},target=/output",
            TRIVY_IMAGE,
            "image", "--scanners", "vuln", "--severity", "HIGH,CRITICAL",
            "--format", "json", "--output", "/output/result.json", "--input", "/input/image.tar",
        ]
        try:
            proc = subprocess.run(
                command, capture_output=True, text=True, encoding="utf-8",
                errors="replace", timeout=600,
            )
        except (OSError, subprocess.TimeoutExpired) as exc:
            return {"image": image, "status": "ERROR", "detail": str(exc), "vulnerabilities": []}

        if proc.returncode != 0 or not report_path.is_file():
            detail = (proc.stderr or proc.stdout).strip()[-1000:]
            return {
                "image": image,
                "status": "ERROR",
                "detail": detail or f"Trivy exited {proc.returncode} without a report",
                "vulnerabilities": [],
            }

        try:
            return summarize_trivy(image, json.loads(report_path.read_text(encoding="utf-8")))
        except (OSError, json.JSONDecodeError) as exc:
            return {"image": image, "status": "ERROR", "detail": str(exc), "vulnerabilities": []}


class ScannerTests(unittest.TestCase):
    def test_summary_counts_high_and_critical_findings(self) -> None:
        payload = {"Results": [{"Target": "layer", "Vulnerabilities": [
            {"VulnerabilityID": "CVE-1", "PkgName": "a", "InstalledVersion": "1", "Severity": "HIGH"},
            {"VulnerabilityID": "CVE-2", "PkgName": "b", "InstalledVersion": "2", "Severity": "CRITICAL"},
        ]}]}
        result = summarize_trivy("image:test", payload)
        self.assertEqual("WARN", result["status"])
        self.assertEqual(1, result["vulnerability_counts"]["HIGH"])
        self.assertEqual(1, result["vulnerability_counts"]["CRITICAL"])

    def test_empty_results_pass(self) -> None:
        result = summarize_trivy("image:test", {"Results": []})
        self.assertEqual("PASS", result["status"])
        self.assertEqual([], result["vulnerabilities"])

    def test_scanner_is_digest_pinned(self) -> None:
        self.assertRegex(TRIVY_IMAGE, r"^aquasec/trivy@sha256:[0-9a-f]{64}$")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", action="append", dest="images", help="image reference; repeatable")
    parser.add_argument("--output", default=str(DEFAULT_OUTPUT))
    parser.add_argument("--no-report", action="store_true")
    parser.add_argument("--test", action="store_true")
    args = parser.parse_args()

    if args.test:
        suite = unittest.defaultTestLoader.loadTestsFromTestCase(ScannerTests)
        return 0 if unittest.TextTestRunner(verbosity=2).run(suite).wasSuccessful() else 1

    try:
        images = sorted(set(args.images or discover_detector_images()))
    except (OSError, RuntimeError, subprocess.TimeoutExpired) as exc:
        print(f"status=ERROR detail={exc}", file=sys.stderr)
        return 1

    if not images:
        print("status=ERROR detail=no detector images found; use --image", file=sys.stderr)
        return 1

    results = []
    for image in images:
        print(f"scanning {image} ...")
        results.append(scan_image(image))

    has_error = any(result["status"] == "ERROR" for result in results)
    has_warn = any(result["status"] == "WARN" for result in results)
    status = "ERROR" if has_error else ("WARN" if has_warn else "PASS")
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "scan_status": status,
        "scanner": {"name": "trivy", "version": TRIVY_VERSION, "image": TRIVY_IMAGE},
        "severity_scope": ["HIGH", "CRITICAL"],
        "advisory_only": True,
        "images_scanned": len(results),
        "results": results,
    }

    if not args.no_report:
        output = Path(args.output)
        output.parent.mkdir(parents=True, exist_ok=True)
        output.write_text(json.dumps(report, indent=2), encoding="utf-8")
        print(f"report={output}")

    print(f"status={status} images={len(results)}")
    for result in results:
        counts = result.get("vulnerability_counts", {})
        detail = result.get("detail", "")
        print(
            f"  {result['status']:5} {result['image']} "
            f"high={counts.get('HIGH', 0)} critical={counts.get('CRITICAL', 0)} {detail}"
        )

    return 1 if has_error else 0


if __name__ == "__main__":
    sys.exit(main())
