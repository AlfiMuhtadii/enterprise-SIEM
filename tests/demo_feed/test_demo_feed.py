"""
demo_feed.py tests — pytest-compatible, stdlib only.

Tests:
1.  tag_only_does_not_post        — tag-only mode makes no HTTP calls
2.  pipeline_refuses_when_unhealthy — pipeline mode exits 1 when readiness fails
3.  dry_run_does_not_post         — dry-run mode makes no POST calls
4.  events_tagged_correctly       — demo_run_id, trace_id, source_event_id injected
5.  manifest_has_required_fields  — all required manifest keys are present
6.  ingest_security_events_not_called — subprocess.run/call not invoked during pipeline
7.  pipeline_happy_path           — pipeline mode POSTs and records sent_count
8.  tag_only_manifest_has_mode    — manifest mode=tag-only, sent_count=0
"""

from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock, patch

# Make scripts/ importable
_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import demo_feed as df  # noqa: E402


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

_SAMPLE_EVENTS = [
    {
        "schema_version": 1,
        "ts": "2026-06-22T10:00:00Z",
        "telemetry_type": "identity",
        "event_type": "mfa_failure",
        "user": "alice@corp.example",
        "event_id": "test-ev-001",
    },
    {
        "schema_version": 1,
        "ts": "2026-06-22T10:00:15Z",
        "telemetry_type": "identity",
        "event_type": "mfa_failure",
        "user": "alice@corp.example",
        "event_id": "test-ev-002",
    },
    {
        "schema_version": 1,
        "ts": "2026-06-22T10:01:00Z",
        "telemetry_type": "cloud",
        "event_type": "new_access_key_created",
        "user": "alice@corp.example",
        "event_id": "test-ev-003",
    },
]


def _write_input(tmp: Path, events: list[dict] = _SAMPLE_EVENTS) -> Path:
    p = tmp / "test_input.jsonl"
    p.write_text("\n".join(json.dumps(e) for e in events), encoding="utf-8")
    return p


def _make_args(**overrides) -> Any:
    """Build a minimal argparse.Namespace for testing."""
    import argparse
    defaults = dict(
        input=None,
        mode="tag-only",
        demo_run_id=None,
        output_dir=None,
        ingest_url="http://localhost:8091/v1/ingest",
        ingest_secret=None,
        tenant_id=None,
        scenario_id=None,
        dry_run=False,
        skip_readiness_check=False,
        timeout_seconds=5,
        batch_size=10,
        mtls_enabled=False,
        mtls_ca=None,
        mtls_client_cert=None,
        mtls_client_key=None,
    )
    defaults.update(overrides)
    ns = argparse.Namespace(**defaults)
    return ns


def _ok_get(url: str, timeout: int) -> tuple[int, str]:
    return 200, '{"status":"ok"}'


def _fail_get(url: str, timeout: int) -> tuple[int | None, str]:
    return None, "Connection refused"


def _ok_post(url: str, headers: dict, body: bytes, timeout: int) -> tuple[int, str]:
    n = len(json.loads(body))
    return 202, json.dumps({"accepted": n, "latency_ms": 5})


def _fail_post(url: str, headers: dict, body: bytes, timeout: int) -> tuple[int, str]:
    return 401, json.dumps({"error": "invalid_signature"})


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------

class TestTagOnlyDoesNotPost(unittest.TestCase):
    """tag-only mode must not make any HTTP calls."""

    def test_no_http_calls(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            args = _make_args(input=str(input_file), mode="tag-only", output_dir=tmp)
            post_mock = MagicMock()
            # patch _http_post; if called the test fails
            with patch.object(df, "_http_post", post_mock):
                rc = df.run_tag_only(args, "demo-test-001")
            self.assertEqual(rc, 0)
            post_mock.assert_not_called()


class TestPipelineRefusesWhenUnhealthy(unittest.TestCase):
    """pipeline mode must exit 1 without sending if readiness fails."""

    def test_exits_nonzero_on_failed_readiness(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            args = _make_args(
                input=str(input_file),
                mode="pipeline",
                output_dir=tmp,
                skip_readiness_check=False,
            )
            post_mock = MagicMock()
            rc = df.run_pipeline(args, "demo-test-002",
                                 _post_fn=post_mock, _get_fn=_fail_get)
            self.assertEqual(rc, 1)
            post_mock.assert_not_called()


class TestDryRunDoesNotPost(unittest.TestCase):
    """dry-run pipeline mode must not POST even when readiness passes."""

    def test_dry_run_no_post(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            args = _make_args(
                input=str(input_file),
                mode="pipeline",
                dry_run=True,
                output_dir=tmp,
                skip_readiness_check=False,
            )
            post_mock = MagicMock()
            rc = df.run_pipeline(args, "demo-test-003",
                                 _post_fn=post_mock, _get_fn=_ok_get)
            self.assertEqual(rc, 0)
            post_mock.assert_not_called()


class TestEventsTaggedCorrectly(unittest.TestCase):
    """Each event must have demo_run_id, trace_id, and source_event_id."""

    def test_tag_fields_injected(self):
        demo_id = "demo-20260622-abc123"
        tagged = df.tag_events(_SAMPLE_EVENTS, demo_id)
        self.assertEqual(len(tagged), len(_SAMPLE_EVENTS))
        for i, ev in enumerate(tagged, 1):
            self.assertEqual(ev["demo_run_id"], demo_id)
            self.assertTrue(ev.get("trace_id", "").startswith(demo_id))
            self.assertIn("source_event_id", ev)

    def test_trace_id_sequential(self):
        demo_id = "demo-20260622-xyz"
        tagged = df.tag_events(_SAMPLE_EVENTS, demo_id)
        self.assertEqual(tagged[0]["trace_id"], f"{demo_id}-trace-0001")
        self.assertEqual(tagged[1]["trace_id"], f"{demo_id}-trace-0002")
        self.assertEqual(tagged[2]["trace_id"], f"{demo_id}-trace-0003")

    def test_source_event_id_uses_existing_event_id(self):
        demo_id = "demo-test"
        tagged = df.tag_events(_SAMPLE_EVENTS, demo_id)
        # _SAMPLE_EVENTS[0] has event_id="test-ev-001"
        self.assertEqual(tagged[0]["source_event_id"], "test-ev-001")

    def test_event_id_replaced_with_trace_id(self):
        """event_id must equal trace_id so each run produces unique alert fingerprints."""
        demo_id = "demo-20260622-abc123"
        tagged = df.tag_events(_SAMPLE_EVENTS, demo_id)
        for ev in tagged:
            self.assertEqual(ev["event_id"], ev["trace_id"])

    def test_event_id_unique_across_runs(self):
        """Two separate runs must produce different event_ids (no fingerprint collision)."""
        tagged_a = df.tag_events(_SAMPLE_EVENTS, "demo-run-aaa")
        tagged_b = df.tag_events(_SAMPLE_EVENTS, "demo-run-bbb")
        ids_a = {ev["event_id"] for ev in tagged_a}
        ids_b = {ev["event_id"] for ev in tagged_b}
        self.assertTrue(ids_a.isdisjoint(ids_b), "event_ids must not overlap across runs")

    def test_scenario_id_injected(self):
        tagged = df.tag_events(_SAMPLE_EVENTS, "demo-x", scenario_id="s-001")
        for ev in tagged:
            self.assertEqual(ev["scenario_id"], "s-001")

    def test_tenant_id_injected(self):
        tagged = df.tag_events(_SAMPLE_EVENTS, "demo-x", tenant_id="tenant-99")
        for ev in tagged:
            self.assertEqual(ev["tenant_id"], "tenant-99")


class TestManifestHasRequiredFields(unittest.TestCase):
    """write_manifest must produce all required keys."""

    _REQUIRED_KEYS = {
        "demo_run_id", "scenario_id", "mode", "dry_run", "input_path",
        "tagged_output_path", "ingest_url", "started_at", "ended_at",
        "event_count", "sent_count", "failed_count", "trace_id_first",
        "trace_id_last", "readiness_check_result", "response_summary",
    }

    def _write_and_read(self, mode: str, dry_run: bool, sent: int) -> dict:
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            tagged_path = tmp_path / "tagged.jsonl"
            tagged_path.write_text("", encoding="utf-8")
            tagged = df.tag_events(_SAMPLE_EVENTS, "demo-manifest-test")
            manifest_path = df.write_manifest(
                demo_run_id="demo-manifest-test",
                scenario_id="sc-1",
                mode=mode,
                dry_run=dry_run,
                input_path=input_file,
                tagged_path=tagged_path,
                ingest_url="http://localhost:8091/v1/ingest",
                started_at=df.now_iso(),
                ended_at=df.now_iso(),
                event_count=len(_SAMPLE_EVENTS),
                sent_count=sent,
                failed_count=0,
                tagged=tagged,
                readiness_check_result="pass",
                response_summary=[],
                output_dir=tmp_path,
            )
            return json.loads(manifest_path.read_text(encoding="utf-8"))

    def test_tag_only_manifest_fields(self):
        data = self._write_and_read("tag-only", False, 0)
        for key in self._REQUIRED_KEYS:
            self.assertIn(key, data, f"Missing key: {key}")

    def test_pipeline_manifest_fields(self):
        data = self._write_and_read("pipeline", False, len(_SAMPLE_EVENTS))
        for key in self._REQUIRED_KEYS:
            self.assertIn(key, data, f"Missing key: {key}")
        self.assertEqual(data["sent_count"], len(_SAMPLE_EVENTS))
        self.assertFalse(data["dry_run"])

    def test_dry_run_manifest_flag(self):
        data = self._write_and_read("pipeline", True, 0)
        self.assertTrue(data["dry_run"])
        self.assertEqual(data["sent_count"], 0)


class TestIngestSecurityEventsNotCalled(unittest.TestCase):
    """Neither subprocess nor ingest_security_events should be invoked."""

    def test_pipeline_does_not_call_subprocess(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            args = _make_args(
                input=str(input_file),
                mode="pipeline",
                output_dir=tmp,
                skip_readiness_check=False,
            )
            with patch("subprocess.run") as mock_run, \
                 patch("subprocess.call") as mock_call:
                df.run_pipeline(args, "demo-test-ise",
                                _post_fn=_ok_post, _get_fn=_ok_get)
                mock_run.assert_not_called()
                mock_call.assert_not_called()


class TestPipelineHappyPath(unittest.TestCase):
    """pipeline mode with mock POST returns rc=0 and records sent_count."""

    def test_sends_all_events(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            args = _make_args(
                input=str(input_file),
                mode="pipeline",
                output_dir=tmp,
                batch_size=2,  # force multiple batches
                skip_readiness_check=False,
            )
            rc = df.run_pipeline(args, "demo-happy-001",
                                 _post_fn=_ok_post, _get_fn=_ok_get)
            self.assertEqual(rc, 0)
            # Verify manifest was written with sent_count = total events
            manifest_file = tmp_path / "demo-happy-001-manifest.json"
            self.assertTrue(manifest_file.exists())
            data = json.loads(manifest_file.read_text(encoding="utf-8"))
            self.assertEqual(data["sent_count"], len(_SAMPLE_EVENTS))
            self.assertEqual(data["failed_count"], 0)
            self.assertEqual(data["mode"], "pipeline")
            self.assertFalse(data["dry_run"])

    def test_failed_post_returns_nonzero(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            args = _make_args(
                input=str(input_file),
                mode="pipeline",
                output_dir=tmp,
                skip_readiness_check=True,  # skip health check for this test
            )
            rc = df.run_pipeline(args, "demo-fail-001",
                                 _post_fn=_fail_post, _get_fn=_ok_get)
            self.assertEqual(rc, 1)
            manifest_file = tmp_path / "demo-fail-001-manifest.json"
            data = json.loads(manifest_file.read_text(encoding="utf-8"))
            self.assertGreater(data["failed_count"], 0)
            self.assertEqual(data["sent_count"], 0)


class TestMutualTlsTransport(unittest.TestCase):
    """mTLS is opt-in, fail-closed, and shared by health and ingestion calls."""

    def test_disabled_does_not_build_context(self):
        args = _make_args(mtls_enabled=False)
        with patch.object(df.ssl, "create_default_context") as create_context:
            self.assertIsNone(df._build_mtls_context(args, args.ingest_url))
        create_context.assert_not_called()

    def test_enabled_rejects_plain_http(self):
        args = _make_args(
            mtls_enabled=True,
            mtls_ca="ca.pem",
            mtls_client_cert="client.pem",
            mtls_client_key="client-key.pem",
        )
        with self.assertRaisesRegex(ValueError, "https://"):
            df._build_mtls_context(args, args.ingest_url)

    def test_enabled_requires_complete_identity(self):
        args = _make_args(
            ingest_url="https://ingestion-gateway:8091/v1/ingest",
            mtls_enabled=True,
            mtls_ca="ca.pem",
        )
        with self.assertRaisesRegex(ValueError, "--mtls-client-cert"):
            df._build_mtls_context(args, args.ingest_url)

    def test_context_loads_ca_and_client_identity(self):
        args = _make_args(
            ingest_url="https://ingestion-gateway:8091/v1/ingest",
            mtls_enabled=True,
            mtls_ca="ca.pem",
            mtls_client_cert="client.pem",
            mtls_client_key="client-key.pem",
        )
        context = MagicMock()
        with patch.object(df.ssl, "create_default_context", return_value=context) as create:
            self.assertIs(df._build_mtls_context(args, args.ingest_url), context)
        create.assert_called_once_with(cafile="ca.pem")
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="client-key.pem"
        )

    def test_http_helpers_pass_context_to_urlopen(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.status = 200
        response.__enter__.return_value.read.return_value = b'{"status":"ok"}'
        with patch.object(df.urllib.request, "urlopen", return_value=response) as urlopen:
            df._http_get("https://gateway/health", 3, context)
            get_call = urlopen.call_args
            df._http_post("https://gateway/v1/ingest", {}, b"[]", 4, context)
            post_call = urlopen.call_args
        self.assertEqual(get_call.kwargs["context"], context)
        self.assertEqual(post_call.kwargs["context"], context)

    def test_pipeline_rejects_invalid_mtls_before_network(self):
        with tempfile.TemporaryDirectory() as tmp:
            input_file = _write_input(Path(tmp))
            args = _make_args(
                input=str(input_file),
                mode="pipeline",
                output_dir=tmp,
                mtls_enabled=True,
            )
            get_mock = MagicMock()
            post_mock = MagicMock()
            rc = df.run_pipeline(
                args, "demo-mtls-invalid", _get_fn=get_mock, _post_fn=post_mock
            )
        self.assertEqual(rc, 2)
        get_mock.assert_not_called()
        post_mock.assert_not_called()

    def test_pipeline_reuses_context_for_health_and_post(self):
        with tempfile.TemporaryDirectory() as tmp:
            input_file = _write_input(Path(tmp))
            args = _make_args(
                input=str(input_file),
                mode="pipeline",
                output_dir=tmp,
                ingest_url="https://ingestion-gateway:8091/v1/ingest",
                mtls_enabled=True,
                mtls_ca="ca.pem",
                mtls_client_cert="client.pem",
                mtls_client_key="client-key.pem",
            )
            context = MagicMock()
            with patch.object(df, "_build_mtls_context", return_value=context), \
                 patch.object(df, "_http_get", return_value=(200, "ok")) as get_mock, \
                 patch.object(
                     df,
                     "_http_post",
                     return_value=(202, json.dumps({"accepted": 3})),
                 ) as post_mock:
                rc = df.run_pipeline(args, "demo-mtls-context")
        self.assertEqual(rc, 0)
        self.assertIs(get_mock.call_args.args[-1], context)
        self.assertIs(post_mock.call_args.args[-1], context)


class TestTagOnlyManifestMode(unittest.TestCase):
    """tag-only mode manifest has mode=tag-only, sent_count=0, ingest_url=null."""

    def test_tag_only_manifest_values(self):
        with tempfile.TemporaryDirectory() as tmp:
            tmp_path = Path(tmp)
            input_file = _write_input(tmp_path)
            args = _make_args(input=str(input_file), mode="tag-only", output_dir=tmp)
            df.run_tag_only(args, "demo-tagonly-001")
            manifest_file = tmp_path / "demo-tagonly-001-manifest.json"
            data = json.loads(manifest_file.read_text(encoding="utf-8"))
            self.assertEqual(data["mode"], "tag-only")
            self.assertEqual(data["sent_count"], 0)
            self.assertIsNone(data["ingest_url"])
            self.assertEqual(data["readiness_check_result"], "skipped")


if __name__ == "__main__":
    unittest.main()
