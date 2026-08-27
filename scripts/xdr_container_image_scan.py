#!/usr/bin/env python3
"""Trivy scanning and release policy enforcement for XDR container images.

The scanner itself is pinned by digest because mutable Trivy tags and actions
are inappropriate for a supply-chain control. Local scans remain advisory.
Release scans block critical findings and scanner/runtime failures.
"""

from __future__ import annotations

import argparse
import json
import os
import re
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
RELEASE_POLICY_ID = "release-critical-v1"
RELEASE_BLOCKING_SEVERITIES = ("CRITICAL",)
RELEASE_ADVISORY_SEVERITIES = ("HIGH",)
IMMUTABLE_IMAGE_PATTERN = re.compile(r"^.+@sha256:[0-9a-f]{64}$")


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
                "severity_source": finding.get("SeveritySource", ""),
                "title": finding.get("Title", ""),
                "primary_url": finding.get("PrimaryURL", ""),
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
        except (OSError, json.JSONDecodeError, AttributeError, TypeError) as exc:
            return {"image": image, "status": "ERROR", "detail": str(exc), "vulnerabilities": []}


def remote_trivy_command(image: str, output_dir: Path, docker_config_dir: Path) -> list[str]:
    """Build a remote scan command using registry-scoped Docker credentials."""
    return [
        "docker", "run", "--rm",
        "--env", "DOCKER_CONFIG=/root/.docker",
        "--mount", f"type=bind,source={docker_config_dir.resolve()},target=/root/.docker,readonly",
        "--mount", f"type=bind,source={output_dir.resolve()},target=/output",
        TRIVY_IMAGE,
        "image", "--scanners", "vuln", "--severity", "HIGH,CRITICAL",
        "--format", "json", "--output", "/output/result.json", image,
    ]


def write_scanner_docker_config(source_path: Path, target_path: Path) -> None:
    """Copy inline registry credentials without host-only credential helpers."""
    payload = json.loads(source_path.read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ValueError("Docker registry config must be a JSON object")

    auths = payload.get("auths") or {}
    if not isinstance(auths, dict):
        raise ValueError("Docker registry config auths must be a JSON object")

    sanitized_auths: dict[str, dict[str, str]] = {}
    for registry, credentials in auths.items():
        if not isinstance(registry, str) or not isinstance(credentials, dict):
            raise ValueError("Docker registry auth entries must be JSON objects")
        inline_credentials = {
            key: value
            for key in ("auth", "identitytoken", "registrytoken")
            if isinstance((value := credentials.get(key)), str) and value
        }
        if inline_credentials:
            sanitized_auths[registry] = inline_credentials

    target_path.parent.mkdir(parents=True, exist_ok=True)
    target_path.write_text(
        json.dumps({"auths": sanitized_auths}, separators=(",", ":")),
        encoding="utf-8",
    )
    try:
        target_path.chmod(0o600)
    except OSError:
        pass


def is_immutable_image_reference(image: str) -> bool:
    return IMMUTABLE_IMAGE_PATTERN.fullmatch(image) is not None


def scan_remote_image(image: str) -> dict[str, Any]:
    temp_root = ROOT / ".tmp"
    temp_root.mkdir(exist_ok=True)
    docker_config_dir = Path(os.environ.get("DOCKER_CONFIG", Path.home() / ".docker"))
    if not (docker_config_dir / "config.json").is_file():
        return {
            "image": image,
            "status": "ERROR",
            "detail": f"Docker registry config not found: {docker_config_dir / 'config.json'}",
            "vulnerabilities": [],
        }

    with tempfile.TemporaryDirectory(prefix="xdr-trivy-remote-", dir=temp_root) as temp_dir:
        output_dir = Path(temp_dir) / "output"
        scanner_config_dir = Path(temp_dir) / "docker-config"
        output_dir.mkdir()
        report_path = output_dir / "result.json"
        try:
            write_scanner_docker_config(
                docker_config_dir / "config.json",
                scanner_config_dir / "config.json",
            )
        except (OSError, json.JSONDecodeError, TypeError, ValueError) as exc:
            return {
                "image": image,
                "status": "ERROR",
                "detail": f"Invalid Docker registry config: {exc}",
                "vulnerabilities": [],
            }

        try:
            proc = subprocess.run(
                remote_trivy_command(image, output_dir, scanner_config_dir),
                capture_output=True,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=900,
                env=os.environ.copy(),
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
        except (OSError, json.JSONDecodeError, AttributeError, TypeError) as exc:
            return {"image": image, "status": "ERROR", "detail": str(exc), "vulnerabilities": []}


def evaluate_scan_policy(results: list[dict[str, Any]], policy_name: str) -> dict[str, Any]:
    has_error = any(result["status"] == "ERROR" for result in results)
    findings = [
        finding
        for result in results
        for finding in result.get("vulnerabilities", [])
    ]

    if policy_name == "advisory":
        return {
            "id": "advisory",
            "status": "ERROR" if has_error else ("WARN" if findings else "PASS"),
            "blocking_severities": [],
            "advisory_severities": ["HIGH", "CRITICAL"],
            "ignore_unfixed": False,
            "blocking_findings": [],
        }

    blocking_findings = [
        finding for finding in findings
        if finding.get("severity") in RELEASE_BLOCKING_SEVERITIES
    ]
    status = "ERROR" if has_error else ("BLOCKED" if blocking_findings else "PASS")
    return {
        "id": RELEASE_POLICY_ID,
        "status": status,
        "blocking_severities": list(RELEASE_BLOCKING_SEVERITIES),
        "advisory_severities": list(RELEASE_ADVISORY_SEVERITIES),
        "ignore_unfixed": False,
        "blocking_findings": blocking_findings,
    }


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
    parser.add_argument("--remote", action="store_true", help="scan registry image references instead of local images")
    parser.add_argument("--policy", choices=("advisory", "release"), default="advisory")
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

    if args.remote and args.policy == "release":
        mutable_images = [image for image in images if not is_immutable_image_reference(image)]
        if mutable_images:
            print(
                "status=ERROR detail=release policy requires image@sha256:<64 lowercase hex> references: "
                + ", ".join(mutable_images),
                file=sys.stderr,
            )
            return 1

    results = []
    for image in images:
        print(f"scanning {image} ...")
        results.append(scan_remote_image(image) if args.remote else scan_image(image))

    policy = evaluate_scan_policy(results, args.policy)
    status = policy["status"]
    report = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "scan_status": status,
        "scanner": {"name": "trivy", "version": TRIVY_VERSION, "image": TRIVY_IMAGE},
        "severity_scope": ["HIGH", "CRITICAL"],
        "scan_source": "remote_registry" if args.remote else "local_daemon",
        "advisory_only": args.policy == "advisory",
        "policy": policy,
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

    if status == "ERROR":
        return 1
    if status == "BLOCKED":
        return 2
    return 0


if __name__ == "__main__":
    sys.exit(main())
