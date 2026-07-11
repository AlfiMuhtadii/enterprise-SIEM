"""ENT-SDLC-NO-SUPPLYCHAIN (continuation): scripts/xdr_dependency_vuln_scan.py.

Covers the govulncheck concatenated-JSON-stream parser, the pip-audit JSON
shape mapping, and the tool-missing / tool-error vs real-findings status
distinction -- all without invoking the real external tools (subprocess.run
is mocked throughout)."""
from __future__ import annotations

import json
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import xdr_dependency_vuln_scan as scan  # noqa: E402


def _completed(stdout="", stderr="", returncode=0):
    return subprocess.CompletedProcess(args=[], returncode=returncode, stdout=stdout, stderr=stderr)


class TestParseGovulncheckStream(unittest.TestCase):
    def test_parses_concatenated_json_objects(self):
        text = '{"osv":{"id":"GO-2026-1"}}\n{"finding":{"osv":"GO-2026-1"}}\n'
        objects = scan.parse_govulncheck_stream(text)
        self.assertEqual(len(objects), 2)
        self.assertEqual(objects[0]["osv"]["id"], "GO-2026-1")
        self.assertEqual(objects[1]["finding"]["osv"], "GO-2026-1")

    def test_empty_stream_returns_empty_list(self):
        self.assertEqual(scan.parse_govulncheck_stream(""), [])
        self.assertEqual(scan.parse_govulncheck_stream("   \n  "), [])

    def test_malformed_stream_raises_json_decode_error(self):
        with self.assertRaises(json.JSONDecodeError):
            scan.parse_govulncheck_stream("{not valid json")


class TestScanGoService(unittest.TestCase):
    def test_reports_skipped_when_govulncheck_not_installed(self):
        with patch.object(scan.shutil, "which", return_value=None):
            result = scan.scan_go_service(Path("services/ingestion-gateway"))
        self.assertEqual(result["status"], "SKIPPED")
        self.assertEqual(result["vulnerabilities"], [])

    def test_deduplicates_findings_by_osv_id_and_marks_warn(self):
        stream = (
            json.dumps({"osv": {"id": "GO-2026-1", "summary": "issue one"}}) + "\n"
            + json.dumps({"finding": {"osv": "GO-2026-1"}}) + "\n"
            + json.dumps({"finding": {"osv": "GO-2026-1"}}) + "\n"  # duplicate trace
            + json.dumps({"osv": {"id": "GO-2026-2", "summary": "issue two"}}) + "\n"
            + json.dumps({"finding": {"osv": "GO-2026-2"}}) + "\n"
        )
        with patch.object(scan.shutil, "which", return_value="/usr/bin/govulncheck"), \
             patch.object(scan.subprocess, "run", return_value=_completed(stdout=stream, returncode=3)):
            result = scan.scan_go_service(Path("services/ingestion-gateway"))
        self.assertEqual(result["status"], "WARN")
        self.assertEqual(len(result["vulnerabilities"]), 2)
        ids = {v["id"] for v in result["vulnerabilities"]}
        self.assertEqual(ids, {"GO-2026-1", "GO-2026-2"})

    def test_no_findings_is_pass(self):
        stream = json.dumps({"osv": {"id": "GO-2026-1", "summary": "unreachable"}}) + "\n"
        with patch.object(scan.shutil, "which", return_value="/usr/bin/govulncheck"), \
             patch.object(scan.subprocess, "run", return_value=_completed(stdout=stream, returncode=0)):
            result = scan.scan_go_service(Path("services/ingestion-gateway"))
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["vulnerabilities"], [])

    def test_exit_code_3_is_not_treated_as_tool_error(self):
        stream = json.dumps({"finding": {"osv": "GO-2026-9"}}) + "\n"
        with patch.object(scan.shutil, "which", return_value="/usr/bin/govulncheck"), \
             patch.object(scan.subprocess, "run", return_value=_completed(stdout=stream, returncode=3)):
            result = scan.scan_go_service(Path("services/ingestion-gateway"))
        self.assertNotEqual(result["status"], "ERROR")

    def test_unexpected_nonzero_exit_is_error(self):
        with patch.object(scan.shutil, "which", return_value="/usr/bin/govulncheck"), \
             patch.object(scan.subprocess, "run", return_value=_completed(stdout="", stderr="panic", returncode=2)):
            result = scan.scan_go_service(Path("services/ingestion-gateway"))
        self.assertEqual(result["status"], "ERROR")

    def test_timeout_is_error(self):
        with patch.object(scan.shutil, "which", return_value="/usr/bin/govulncheck"), \
             patch.object(scan.subprocess, "run", side_effect=subprocess.TimeoutExpired(cmd="govulncheck", timeout=180)):
            result = scan.scan_go_service(Path("services/ingestion-gateway"))
        self.assertEqual(result["status"], "ERROR")

    def test_unparseable_output_is_error(self):
        with patch.object(scan.shutil, "which", return_value="/usr/bin/govulncheck"), \
             patch.object(scan.subprocess, "run", return_value=_completed(stdout="{not json", returncode=0)):
            result = scan.scan_go_service(Path("services/ingestion-gateway"))
        self.assertEqual(result["status"], "ERROR")


class TestScanPythonService(unittest.TestCase):
    def test_clean_dependencies_is_pass(self):
        payload = json.dumps({"dependencies": [{"name": "fastapi", "version": "0.115.0", "vulns": []}]})
        with patch.object(scan.subprocess, "run", return_value=_completed(stdout=payload, returncode=0)):
            result = scan.scan_python_service(Path("services/alert-writer-service"))
        self.assertEqual(result["status"], "PASS")
        self.assertEqual(result["vulnerabilities"], [])

    def test_vulnerable_dependency_is_warn_with_details(self):
        payload = json.dumps({
            "dependencies": [{
                "name": "requests",
                "version": "2.25.0",
                "vulns": [{"id": "PYSEC-2023-1", "fix_versions": ["2.31.0"]}],
            }]
        })
        with patch.object(scan.subprocess, "run", return_value=_completed(stdout=payload, returncode=1)):
            result = scan.scan_python_service(Path("services/alert-writer-service"))
        self.assertEqual(result["status"], "WARN")
        self.assertEqual(result["vulnerabilities"], [
            {"id": "PYSEC-2023-1", "package": "requests", "version": "2.25.0", "fix_versions": ["2.31.0"]},
        ])

    def test_module_not_installed_is_skipped_not_error(self):
        with patch.object(
            scan.subprocess, "run",
            return_value=_completed(stdout="", stderr="No module named pip_audit", returncode=127),
        ):
            result = scan.scan_python_service(Path("services/alert-writer-service"))
        self.assertEqual(result["status"], "SKIPPED")

    def test_unparseable_output_is_error(self):
        with patch.object(scan.subprocess, "run", return_value=_completed(stdout="not json", returncode=0)):
            result = scan.scan_python_service(Path("services/alert-writer-service"))
        self.assertEqual(result["status"], "ERROR")

    def test_timeout_is_error(self):
        with patch.object(scan.subprocess, "run", side_effect=subprocess.TimeoutExpired(cmd="pip_audit", timeout=180)):
            result = scan.scan_python_service(Path("services/alert-writer-service"))
        self.assertEqual(result["status"], "ERROR")


class TestDiscovery(unittest.TestCase):
    def test_discover_go_services_finds_known_service(self):
        services = {d.name for d in scan.discover_go_services()}
        self.assertIn("ingestion-gateway", services)

    def test_discover_python_services_finds_known_service(self):
        services = {d.name for d in scan.discover_python_services()}
        self.assertIn("alert-writer-service", services)


class TestMainStatusAggregation(unittest.TestCase):
    def test_overall_status_error_when_any_service_errors(self):
        with patch.object(scan, "discover_go_services", return_value=[Path("services/x")]), \
             patch.object(scan, "discover_python_services", return_value=[]), \
             patch.object(scan, "scan_go_service", return_value={
                 "service": "x", "type": "go", "tool": "govulncheck",
                 "status": "ERROR", "detail": "boom", "vulnerabilities": [],
             }), \
             patch.object(sys, "argv", ["xdr_dependency_vuln_scan.py", "--no-report", "--quiet"]):
            rc = scan.main()
        self.assertEqual(rc, 1)

    def test_overall_status_pass_exit_zero_when_no_findings(self):
        with patch.object(scan, "discover_go_services", return_value=[]), \
             patch.object(scan, "discover_python_services", return_value=[Path("services/y")]), \
             patch.object(scan, "scan_python_service", return_value={
                 "service": "y", "type": "python", "tool": "pip-audit",
                 "status": "PASS", "detail": "clean", "vulnerabilities": [],
             }), \
             patch.object(sys, "argv", ["xdr_dependency_vuln_scan.py", "--no-report", "--quiet"]):
            rc = scan.main()
        self.assertEqual(rc, 0)

    def test_overall_status_warn_still_exits_zero(self):
        """Findings are advisory evidence, not a hard gate -- WARN must not fail the run."""
        with patch.object(scan, "discover_go_services", return_value=[Path("services/x")]), \
             patch.object(scan, "discover_python_services", return_value=[]), \
             patch.object(scan, "scan_go_service", return_value={
                 "service": "x", "type": "go", "tool": "govulncheck",
                 "status": "WARN", "detail": "1 reachable vulnerability id(s)",
                 "vulnerabilities": [{"id": "GO-2026-1", "summary": "x"}],
             }), \
             patch.object(sys, "argv", ["xdr_dependency_vuln_scan.py", "--no-report", "--quiet"]):
            rc = scan.main()
        self.assertEqual(rc, 0)


if __name__ == "__main__":
    unittest.main(verbosity=2)
