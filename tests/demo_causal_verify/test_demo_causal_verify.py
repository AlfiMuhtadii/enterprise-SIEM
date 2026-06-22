"""Tests for scripts/demo_causal_verify.py.

All tests use injectable _run_fn / _sleep_fn parameters so no live stack is
required. Nothing writes to security_alerts or security_events directly.
"""
import json
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

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


if __name__ == "__main__":
    unittest.main(verbosity=2)
