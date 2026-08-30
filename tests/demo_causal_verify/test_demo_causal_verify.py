"""Tests for scripts/demo_causal_verify.py.

All tests use injectable _run_fn / _sleep_fn parameters so no live stack is
required. Nothing writes to security_alerts or security_events directly.
"""
import argparse
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

# Ensure scripts/ is importable.
SCRIPTS_DIR = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

from demo_causal_verify import (
    _parse_args,
    main,
    poll_alerts_report,
    run_demo_feed,
    run_validator,
)
import validate_live_xdr_pipeline as vlp


# ---------------------------------------------------------------------------
# Shared fixtures
# ---------------------------------------------------------------------------

BASE_DEMO_RUN_ID = "test-causal-run-0001"

VALIDATOR_PASS_OUTPUT = (
    "Checking 9 components...\n"
    "  [1]  redpanda         PASS\n"
    "  [9]  XDR_CORRELATION_EVENT_LOOP_ENABLED  PASS\n"
    "LIVE_PIPELINE_READY=true  (all 9 checks PASS)\n"
)

VALIDATOR_FAIL_OUTPUT = (
    "Checking 9 components...\n"
    "  [1]  redpanda         FAIL  (connection refused)\n"
    "LIVE_PIPELINE_READY=false  (1 FAIL)\n"
)

DEMO_FEED_PASS_OUTPUT = (
    f"  demo_run_id  : {BASE_DEMO_RUN_ID}\n"
    f"  batch 1/1: HTTP 202 accepted=5 latency_ms=34\n"
    f"  DONE: demo_run_id={BASE_DEMO_RUN_ID}\n"
    f"  sent=5  failed=0  total=5\n"
)

DEMO_FEED_FAIL_OUTPUT = (
    "  demo_run_id  : \n"
    "  batch 1/1: HTTP 503 error\n"
    "  DONE: sent=0  failed=5  total=5\n"
)

ARTISAN_PASS_OUTPUT = (
    f"  demo_run_id : {BASE_DEMO_RUN_ID}\n"
    "  Total alerts: 2\n"
    "\n"
    "  FIELD_MATCH=PASS  (2 alert(s) matched by demo_run_id in evidence — "
    "lineage is field-level proven)\n"
)

ARTISAN_WARN_OUTPUT = (
    f"  demo_run_id : {BASE_DEMO_RUN_ID}\n"
    "  Total alerts: 1\n"
    "\n"
    "  FIELD_MATCH=WARN  (1 alert(s) matched by manifest time-window only — "
    "demo_run_id not yet in evidence...)\n"
)

ARTISAN_FAIL_OUTPUT = (
    f"  demo_run_id : {BASE_DEMO_RUN_ID}\n"
    "\n"
    "  FIELD_MATCH=FAIL  (no alerts found by field-level or time-window filter — "
    "pipeline may not be processing events)\n"
)


def _make_args(
    *,
    timeout_seconds: int = 60,
    poll_interval_seconds: float = 0.0,
    no_report_write: bool = True,
    verbose: bool = False,
    ingest_url: str = "http://localhost:8091/v1/ingest",
    input_path: str = "fixtures/demo/attack_scenario.jsonl",
    mtls_enabled: bool = False,
) -> object:
    return _parse_args(
        [
            "--input", input_path,
            "--ingest-url", ingest_url,
            "--timeout-seconds", str(timeout_seconds),
            "--poll-interval-seconds", str(poll_interval_seconds),
        ]
        + (["--no-report-write"] if no_report_write else [])
        + (["--verbose"] if verbose else [])
        + ([
            "--mtls-enabled",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "client-key.pem",
        ] if mtls_enabled else [])
    )


# ---------------------------------------------------------------------------
# Test 1: validator failure → LIVE_CAUSAL_PROOF=FAIL, exit code 2
# ---------------------------------------------------------------------------

class TestValidatorFailureReturnsFail(unittest.TestCase):
    def test_validator_failure_returns_fail(self):
        args = _make_args()
        call_log = []

        def fake_run(cmd, **kw):
            script = " ".join(str(c) for c in cmd)
            call_log.append(script)
            if "validate_live_xdr_pipeline" in script:
                return 1, VALIDATOR_FAIL_OUTPUT
            return 0, ""

        rc = main(args, _run_validator_fn=fake_run)
        self.assertEqual(rc, 2, "Expected FAIL exit code 2")
        self.assertTrue(
            any("validate_live_xdr_pipeline" in c for c in call_log),
            "Validator must be called",
        )
        # demo_feed must NOT be called when validator fails.
        self.assertFalse(
            any("demo_feed" in c for c in call_log),
            "demo_feed must not be called after validator FAIL",
        )


# ---------------------------------------------------------------------------
# Test 2: demo_feed failure → LIVE_CAUSAL_PROOF=FAIL, exit code 2
# ---------------------------------------------------------------------------

class TestDemoFeedFailureReturnsFail(unittest.TestCase):
    def test_demo_feed_failure_returns_fail(self):
        args = _make_args()

        def fake_validator(cmd, **kw):
            return 0, VALIDATOR_PASS_OUTPUT

        def fake_feed(cmd, **kw):
            return 1, DEMO_FEED_FAIL_OUTPUT

        rc = main(
            args,
            _run_validator_fn=fake_validator,
            _run_demo_feed_fn=fake_feed,
        )
        self.assertEqual(rc, 2)

    def test_empty_run_id_does_not_consume_next_output_line(self):
        args = _make_args()

        def fake_feed(cmd, **kw):
            return 1, DEMO_FEED_FAIL_OUTPUT

        success, demo_run_id, accepted, _, _ = run_demo_feed(
            args, _run_fn=fake_feed
        )
        self.assertFalse(success)
        self.assertEqual(demo_run_id, "")
        self.assertEqual(accepted, 0)


# ---------------------------------------------------------------------------
# Test 3: FIELD_MATCH=PASS → LIVE_CAUSAL_PROOF=PASS, exit code 0
# ---------------------------------------------------------------------------

class TestFieldMatchPassReturnsLiveCausalProofPass(unittest.TestCase):
    def test_field_match_pass_returns_pass(self):
        args = _make_args()

        def fake_validator(cmd, **kw):
            return 0, VALIDATOR_PASS_OUTPUT

        def fake_feed(cmd, **kw):
            return 0, DEMO_FEED_PASS_OUTPUT

        artisan_calls = []

        def fake_artisan(cmd, **kw):
            artisan_calls.append(list(cmd))
            return 0, ARTISAN_PASS_OUTPUT

        rc = main(
            args,
            _run_validator_fn=fake_validator,
            _run_demo_feed_fn=fake_feed,
            _run_artisan_fn=fake_artisan,
            _sleep_fn=lambda _: None,
        )
        self.assertEqual(rc, 0)
        self.assertTrue(len(artisan_calls) >= 1, "Artisan must be polled at least once")


# ---------------------------------------------------------------------------
# Test 4: FIELD_MATCH=WARN → LIVE_CAUSAL_PROOF=WARN, exit code 1
# ---------------------------------------------------------------------------

class TestFieldMatchWarnReturnsLiveCausalProofWarn(unittest.TestCase):
    def test_field_match_warn_returns_warn(self):
        # Timeout quickly (1 poll only), always returns WARN.
        args = _make_args(timeout_seconds=1, poll_interval_seconds=0.0)

        def fake_validator(cmd, **kw):
            return 0, VALIDATOR_PASS_OUTPUT

        def fake_feed(cmd, **kw):
            return 0, DEMO_FEED_PASS_OUTPUT

        def fake_artisan(cmd, **kw):
            return 0, ARTISAN_WARN_OUTPUT

        rc = main(
            args,
            _run_validator_fn=fake_validator,
            _run_demo_feed_fn=fake_feed,
            _run_artisan_fn=fake_artisan,
            _sleep_fn=lambda _: None,
        )
        self.assertEqual(rc, 1)


# ---------------------------------------------------------------------------
# Test 5: timeout without PASS or WARN → LIVE_CAUSAL_PROOF=FAIL, exit code 2
# ---------------------------------------------------------------------------

class TestTimeoutReturnsFail(unittest.TestCase):
    def test_timeout_returns_fail(self):
        # Very short timeout so the poll loop exits with no result.
        args = _make_args(timeout_seconds=0, poll_interval_seconds=0.0)

        def fake_validator(cmd, **kw):
            return 0, VALIDATOR_PASS_OUTPUT

        def fake_feed(cmd, **kw):
            return 0, DEMO_FEED_PASS_OUTPUT

        # Artisan always returns FAIL (no alerts found).
        def fake_artisan(cmd, **kw):
            return 0, ARTISAN_FAIL_OUTPUT

        rc = main(
            args,
            _run_validator_fn=fake_validator,
            _run_demo_feed_fn=fake_feed,
            _run_artisan_fn=fake_artisan,
            _sleep_fn=lambda _: None,
        )
        self.assertEqual(rc, 2)


# ---------------------------------------------------------------------------
# Test 6: report files contain demo_run_id and verdict
# ---------------------------------------------------------------------------

class TestReportFilesContainDemoRunIdAndVerdict(unittest.TestCase):
    def test_report_files_have_required_fields(self):
        import importlib
        import demo_causal_verify as dcv

        with tempfile.TemporaryDirectory() as tmpdir:
            tmp_reports = Path(tmpdir)
            # Patch REPORTS_DIR inside the module.
            original = dcv.REPORTS_DIR
            dcv.REPORTS_DIR = tmp_reports
            try:
                args = _make_args(no_report_write=False)

                def fake_validator(cmd, **kw):
                    return 0, VALIDATOR_PASS_OUTPUT

                def fake_feed(cmd, **kw):
                    return 0, DEMO_FEED_PASS_OUTPUT

                def fake_artisan(cmd, **kw):
                    return 0, ARTISAN_PASS_OUTPUT

                rc = main(
                    args,
                    _run_validator_fn=fake_validator,
                    _run_demo_feed_fn=fake_feed,
                    _run_artisan_fn=fake_artisan,
                    _sleep_fn=lambda _: None,
                )
                self.assertEqual(rc, 0)

                # Check JSON report.
                json_files = list(tmp_reports.glob("demo-causal-*.json"))
                self.assertTrue(json_files, "JSON report must be written")
                report = json.loads(json_files[0].read_text())
                self.assertEqual(report["demo_run_id"], BASE_DEMO_RUN_ID)
                self.assertIn("PASS", report["final_verdict"])
                self.assertIn("field_match_status", report)

                # Check Markdown report.
                md_files = list(tmp_reports.glob("demo-causal-*.md"))
                self.assertTrue(md_files, "Markdown report must be written")
                md_text = md_files[0].read_text()
                self.assertIn(BASE_DEMO_RUN_ID, md_text)
                self.assertIn("LIVE_CAUSAL_PROOF=PASS", md_text)
            finally:
                dcv.REPORTS_DIR = original


# ---------------------------------------------------------------------------
# Test 7: psycopg is not imported (no direct DB write)
# ---------------------------------------------------------------------------

class TestNoDirectDbWrite(unittest.TestCase):
    def test_psycopg_not_imported(self):
        """demo_causal_verify.py must not import psycopg (no direct DB writes)."""
        source_path = SCRIPTS_DIR / "demo_causal_verify.py"
        source = source_path.read_text(encoding="utf-8")
        # psycopg import is the actual DB-access mechanism — must not be present.
        self.assertNotIn(
            "import psycopg",
            source,
            "demo_causal_verify.py must not import psycopg — no direct DB writes allowed",
        )
        # No raw SQL INSERT/UPDATE against alert tables.
        import re
        self.assertIsNone(
            re.search(r"INSERT\s+INTO\s+security_alerts", source, re.IGNORECASE),
            "demo_causal_verify.py must not INSERT into security_alerts",
        )
        self.assertIsNone(
            re.search(r"INSERT\s+INTO\s+security_events", source, re.IGNORECASE),
            "demo_causal_verify.py must not INSERT into security_events",
        )


class TestMutualTlsOrchestration(unittest.TestCase):
    def test_cli_defaults_to_plaintext_compatible_mode(self):
        args = _parse_args([])
        self.assertFalse(args.mtls_enabled)
        self.assertIsNone(args.mtls_ca)

    def test_validator_and_feed_receive_complete_mtls_arguments(self):
        args = _make_args(
            ingest_url="https://localhost:8091/v1/ingest",
            mtls_enabled=True,
        )
        commands = []

        def fake_validator(cmd, **kwargs):
            commands.append(cmd)
            return 0, VALIDATOR_PASS_OUTPUT

        def fake_feed(cmd, **kwargs):
            commands.append(cmd)
            return 0, DEMO_FEED_PASS_OUTPUT

        self.assertTrue(run_validator(args, _run_fn=fake_validator)[0])
        self.assertTrue(run_demo_feed(args, _run_fn=fake_feed)[0])
        for command in commands:
            self.assertIn("--mtls-enabled", command)
            self.assertEqual(command[command.index("--mtls-ca") + 1], "ca.pem")
            self.assertEqual(
                command[command.index("--mtls-client-cert") + 1], "client.pem"
            )
            self.assertEqual(
                command[command.index("--mtls-client-key") + 1], "client-key.pem"
            )

    def test_validator_accepts_ingest_endpoint_or_base_url(self):
        self.assertEqual(
            vlp.ingestion_base_url("https://gateway:8091/v1/ingest"),
            "https://gateway:8091",
        )
        self.assertEqual(
            vlp.ingestion_base_url("https://gateway:8091"),
            "https://gateway:8091",
        )

    def test_validator_mtls_rejects_http_and_incomplete_identity(self):
        args = argparse.Namespace(
            mtls_enabled=True,
            mtls_ca="ca.pem",
            mtls_client_cert="client.pem",
            mtls_client_key="client-key.pem",
        )
        with self.assertRaisesRegex(ValueError, "https://"):
            vlp.build_ingestion_mtls_context(args, "http://localhost:8091")
        args.mtls_client_key = None
        with self.assertRaisesRegex(ValueError, "--mtls-client-key"):
            vlp.build_ingestion_mtls_context(args, "https://localhost:8091")

    def test_validator_loads_identity_and_scopes_context_to_injected_get(self):
        args = argparse.Namespace(
            mtls_enabled=True,
            mtls_ca="ca.pem",
            mtls_client_cert="client.pem",
            mtls_client_key="client-key.pem",
        )
        context = MagicMock()
        with patch.object(vlp.ssl, "create_default_context", return_value=context) as create:
            actual = vlp.build_ingestion_mtls_context(
                args, "https://localhost:8091"
            )
        self.assertIs(actual, context)
        create.assert_called_once_with(cafile="ca.pem")
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="client-key.pem"
        )

        get = MagicMock(return_value=(200, "ok"))
        result = vlp.check_service_health(
            "ingestion-gateway",
            "https://localhost:8091",
            3,
            True,
            _http_get_fn=get,
        )
        self.assertEqual(result["status"], vlp.PASS)
        get.assert_called_once_with("https://localhost:8091/health", 3)

    def test_validator_parser_exposes_mtls_options(self):
        args = vlp._parse_args([
            "--ingest-url", "https://localhost:8091/v1/ingest",
            "--mtls-enabled",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "client-key.pem",
        ])
        self.assertTrue(args.mtls_enabled)
        self.assertEqual(args.ingest_url, "https://localhost:8091/v1/ingest")


if __name__ == "__main__":
    unittest.main(verbosity=2)
