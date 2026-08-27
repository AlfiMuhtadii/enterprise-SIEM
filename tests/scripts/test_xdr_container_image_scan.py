import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest import mock

from scripts import xdr_container_image_scan as scanner


def result(*findings: dict, status: str | None = None) -> dict:
    vulnerabilities = list(findings)
    return {
        "image": "ghcr.io/example/detector@sha256:" + ("a" * 64),
        "status": status or ("WARN" if vulnerabilities else "PASS"),
        "vulnerabilities": vulnerabilities,
    }


class ReleasePolicyTests(unittest.TestCase):
    def test_release_workflow_scans_digest_before_signing_and_retains_evidence(self) -> None:
        workflow = (scanner.ROOT / ".github" / "workflows" / "release.yml").read_text(encoding="utf-8")
        build_position = workflow.index("- name: Build and push canonical digest")
        scan_position = workflow.index("- name: Enforce release vulnerability policy")
        upload_position = workflow.index("- name: Upload release vulnerability evidence")
        sign_position = workflow.index("- name: Sign canonical digest")
        scan_block = workflow[scan_position:upload_position]

        self.assertLess(build_position, scan_position)
        self.assertLess(scan_position, upload_position)
        self.assertLess(upload_position, sign_position)
        self.assertIn("--policy release", scan_block)
        self.assertIn('--image "${IMAGE}@${DIGEST}"', scan_block)
        self.assertNotIn("continue-on-error", scan_block)
        self.assertIn("if: always()", workflow[upload_position:sign_position])
        self.assertIn("retention-days: 90", workflow[upload_position:sign_position])

    def test_release_policy_blocks_critical_with_a_fix(self) -> None:
        policy = scanner.evaluate_scan_policy([
            result({
                "id": "CVE-2026-0001",
                "severity": "CRITICAL",
                "fixed_version": "2.0",
            })
        ], "release")

        self.assertEqual("BLOCKED", policy["status"])
        self.assertEqual("release-critical-v1", policy["id"])
        self.assertEqual(1, len(policy["blocking_findings"]))

    def test_release_policy_blocks_unfixed_critical(self) -> None:
        policy = scanner.evaluate_scan_policy([
            result({
                "id": "CVE-2026-0002",
                "severity": "CRITICAL",
                "fixed_version": "",
            })
        ], "release")

        self.assertEqual("BLOCKED", policy["status"])
        self.assertFalse(policy["ignore_unfixed"])

    def test_release_policy_keeps_high_findings_advisory(self) -> None:
        policy = scanner.evaluate_scan_policy([
            result({"id": "CVE-2026-0003", "severity": "HIGH"})
        ], "release")

        self.assertEqual("PASS", policy["status"])
        self.assertEqual([], policy["blocking_findings"])
        self.assertEqual(["HIGH"], policy["advisory_severities"])

    def test_scanner_error_fails_closed_before_findings(self) -> None:
        policy = scanner.evaluate_scan_policy([
            result(status="ERROR"),
            result({"id": "CVE-2026-0004", "severity": "CRITICAL"}),
        ], "release")

        self.assertEqual("ERROR", policy["status"])

    def test_advisory_policy_preserves_existing_warn_contract(self) -> None:
        policy = scanner.evaluate_scan_policy([
            result({"id": "CVE-2026-0005", "severity": "CRITICAL"})
        ], "advisory")

        self.assertEqual("WARN", policy["status"])
        self.assertEqual([], policy["blocking_severities"])

    def test_remote_command_mounts_registry_scoped_credentials_read_only(self) -> None:
        command = scanner.remote_trivy_command(
            "ghcr.io/example/detector@sha256:" + ("b" * 64),
            Path("output"),
            Path("docker-config"),
        )

        command_text = " ".join(command)
        self.assertIn("DOCKER_CONFIG=/root/.docker", command_text)
        self.assertIn("target=/root/.docker,readonly", command_text)
        self.assertNotIn("TRIVY_USERNAME", command_text)
        self.assertNotIn("TRIVY_PASSWORD", command_text)
        self.assertRegex(command[-1], r"@sha256:[0-9a-f]{64}$")

    def test_release_reference_must_be_an_immutable_lowercase_digest(self) -> None:
        self.assertTrue(scanner.is_immutable_image_reference(
            "ghcr.io/example/detector@sha256:" + ("c" * 64)
        ))
        self.assertFalse(scanner.is_immutable_image_reference("ghcr.io/example/detector:v1.2.3"))
        self.assertFalse(scanner.is_immutable_image_reference(
            "ghcr.io/example/detector@sha256:" + ("C" * 64)
        ))

    def test_remote_scan_fails_closed_without_registry_config(self) -> None:
        with tempfile.TemporaryDirectory() as temp_dir:
            with mock.patch.dict(os.environ, {"DOCKER_CONFIG": temp_dir}):
                scan_result = scanner.scan_remote_image(
                    "ghcr.io/example/detector@sha256:" + ("d" * 64)
                )

        self.assertEqual("ERROR", scan_result["status"])
        self.assertIn("config.json", scan_result["detail"])

    @mock.patch.object(scanner, "scan_remote_image")
    def test_release_cli_returns_two_and_records_blocking_evidence(self, scan: mock.Mock) -> None:
        image = "ghcr.io/example/detector@sha256:" + ("e" * 64)
        scan.return_value = result({
            "id": "CVE-2026-0006",
            "severity": "CRITICAL",
            "fixed_version": "",
        })
        scan.return_value["image"] = image

        with tempfile.TemporaryDirectory() as temp_dir:
            output = Path(temp_dir) / "report.json"
            with mock.patch.object(sys, "argv", [
                "xdr_container_image_scan.py",
                "--remote",
                "--policy", "release",
                "--image", image,
                "--output", str(output),
            ]):
                exit_code = scanner.main()
            report = json.loads(output.read_text(encoding="utf-8"))

        self.assertEqual(2, exit_code)
        self.assertEqual("BLOCKED", report["scan_status"])
        self.assertFalse(report["advisory_only"])
        self.assertEqual(image, report["results"][0]["image"])
        self.assertEqual("CVE-2026-0006", report["policy"]["blocking_findings"][0]["id"])


if __name__ == "__main__":
    unittest.main()
