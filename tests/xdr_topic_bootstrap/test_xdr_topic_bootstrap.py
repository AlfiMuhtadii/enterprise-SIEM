"""Tests for scripts/xdr_topic_bootstrap.py.

All tests are unit tests — no live Docker or Redpanda required.
Network calls are mocked via unittest.mock.patch.
"""
from __future__ import annotations

import json
import subprocess
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

# Make scripts/ importable (same pattern as tests/demo_feed/)
_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

sys.modules.pop("xdr_topic_bootstrap", None)  # force fresh import each run
import xdr_topic_bootstrap as tb  # noqa: E402


# ---------------------------------------------------------------------------
# fetch_topic_list
# ---------------------------------------------------------------------------

class TestFetchTopicList(unittest.TestCase):
    def _mock_urlopen(self, status: int, body: str):
        resp = MagicMock()
        resp.status = status
        resp.read.return_value = body.encode()
        resp.__enter__ = lambda s: s
        resp.__exit__ = MagicMock(return_value=False)
        return patch("urllib.request.urlopen", return_value=resp)

    def test_string_list_returned_correctly(self):
        body = json.dumps(["telemetry.raw", "telemetry.normalized", "xdr.alerts"])
        with self._mock_urlopen(200, body):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertEqual(result, ["telemetry.raw", "telemetry.normalized", "xdr.alerts"])

    def test_object_list_extracts_name_field(self):
        body = json.dumps([{"name": "telemetry.raw"}, {"name": "xdr.alerts"}])
        with self._mock_urlopen(200, body):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertEqual(result, ["telemetry.raw", "xdr.alerts"])

    def test_object_list_extracts_topic_name_field(self):
        body = json.dumps([{"topic_name": "alerts.created"}])
        with self._mock_urlopen(200, body):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertEqual(result, ["alerts.created"])

    def test_non_200_returns_none(self):
        with self._mock_urlopen(503, ""):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertIsNone(result)

    def test_connection_error_returns_none(self):
        with patch("urllib.request.urlopen", side_effect=OSError("refused")):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertIsNone(result)

    def test_invalid_json_returns_none(self):
        with self._mock_urlopen(200, "not-json{{"):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertIsNone(result)

    def test_non_list_json_returns_none(self):
        with self._mock_urlopen(200, '{"topics": []}'):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertIsNone(result)

    def test_empty_list_returns_empty(self):
        with self._mock_urlopen(200, "[]"):
            result = tb.fetch_topic_list("http://localhost:8082", 5)
        self.assertEqual(result, [])

    def test_trailing_slash_stripped_from_url(self):
        captured = []
        resp = MagicMock()
        resp.status = 200
        resp.read.return_value = b"[]"
        resp.__enter__ = lambda s: s
        resp.__exit__ = MagicMock(return_value=False)

        def fake_urlopen(req, timeout):
            captured.append(req.full_url)
            return resp

        with patch("urllib.request.urlopen", side_effect=fake_urlopen):
            tb.fetch_topic_list("http://localhost:8082/", 5)
        self.assertEqual(captured[0], "http://localhost:8082/topics")

    def test_tls_context_is_scoped_to_pandaproxy_request(self):
        context = MagicMock()
        resp = MagicMock()
        resp.status = 200
        resp.read.return_value = b"[]"
        resp.__enter__ = lambda s: s
        resp.__exit__ = MagicMock(return_value=False)

        with patch("urllib.request.urlopen", return_value=resp) as mock_open:
            result = tb.fetch_topic_list("https://redpanda:8083", 7, context)

        self.assertEqual(result, [])
        self.assertIs(mock_open.call_args.kwargs["context"], context)
        self.assertEqual(mock_open.call_args.kwargs["timeout"], 7)


class TestTlsContext(unittest.TestCase):
    def test_no_ca_preserves_default_transport(self):
        self.assertIsNone(tb.build_tls_context("http://127.0.0.1:8082", None))

    def test_ca_requires_https(self):
        with patch("ssl.create_default_context") as create_context:
            with self.assertRaisesRegex(ValueError, "requires an https://"):
                tb.build_tls_context("http://redpanda:8082", "ca.crt")
        create_context.assert_not_called()

    def test_private_ca_builds_verifying_context(self):
        context = MagicMock()
        with patch("ssl.create_default_context", return_value=context) as create_context:
            result = tb.build_tls_context("https://redpanda:8083", "internal-ca.crt")
        self.assertIs(result, context)
        create_context.assert_called_once_with(cafile="internal-ca.crt")


# ---------------------------------------------------------------------------
# create_topic
# ---------------------------------------------------------------------------

class TestCreateTopic(unittest.TestCase):
    def test_dry_run_returns_skip_without_running_subprocess(self):
        with patch("subprocess.run") as mock_run:
            status, detail = tb.create_topic("telemetry.raw", dry_run=True)
        mock_run.assert_not_called()
        self.assertEqual(status, tb.SKIP)
        self.assertIn("dry-run", detail)

    def test_success_returns_created(self):
        mock_result = MagicMock()
        mock_result.returncode = 0
        mock_result.stdout = "Created topic telemetry.raw.\n"
        mock_result.stderr = ""
        with patch("subprocess.run", return_value=mock_result):
            status, detail = tb.create_topic("telemetry.raw", dry_run=False)
        self.assertEqual(status, tb.CREATED)

    def test_already_exists_uppercase_returns_pass(self):
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stdout = ""
        mock_result.stderr = "TOPIC_ALREADY_EXISTS"
        with patch("subprocess.run", return_value=mock_result):
            status, _ = tb.create_topic("telemetry.raw", dry_run=False)
        self.assertEqual(status, tb.PASS)

    def test_already_exists_lowercase_returns_pass(self):
        mock_result = MagicMock()
        mock_result.returncode = 1
        mock_result.stdout = "topic already exists"
        mock_result.stderr = ""
        with patch("subprocess.run", return_value=mock_result):
            status, _ = tb.create_topic("telemetry.raw", dry_run=False)
        self.assertEqual(status, tb.PASS)

    def test_docker_not_found_returns_fail(self):
        with patch("subprocess.run", side_effect=FileNotFoundError):
            status, detail = tb.create_topic("telemetry.raw", dry_run=False)
        self.assertEqual(status, tb.FAIL)
        self.assertIn("docker not found", detail)

    def test_timeout_returns_fail(self):
        with patch("subprocess.run", side_effect=subprocess.TimeoutExpired("cmd", 20)):
            status, detail = tb.create_topic("telemetry.raw", dry_run=False)
        self.assertEqual(status, tb.FAIL)
        self.assertIn("timed out", detail)

    def test_oserror_returns_fail(self):
        with patch("subprocess.run", side_effect=OSError("broken pipe")):
            status, detail = tb.create_topic("telemetry.raw", dry_run=False)
        self.assertEqual(status, tb.FAIL)

    def test_rpk_unknown_error_returns_fail(self):
        mock_result = MagicMock()
        mock_result.returncode = 2
        mock_result.stdout = ""
        mock_result.stderr = "unexpected cluster error"
        with patch("subprocess.run", return_value=mock_result):
            status, detail = tb.create_topic("telemetry.raw", dry_run=False)
        self.assertEqual(status, tb.FAIL)
        self.assertIn("cluster error", detail)


# ---------------------------------------------------------------------------
# main()
# ---------------------------------------------------------------------------

class TestMain(unittest.TestCase):
    def _patch_fetch(self, topics):
        return patch.object(tb, "fetch_topic_list", return_value=topics)

    def _patch_create(self, status, detail="ok"):
        return patch.object(tb, "create_topic", return_value=(status, detail))

    def test_all_topics_present_exits_0(self):
        with self._patch_fetch(list(tb.REQUIRED_TOPICS)):
            rc = tb.main(["--pandaproxy", "http://x:8082"])
        self.assertEqual(rc, 0)

    def test_pandaproxy_unreachable_exits_2(self):
        with self._patch_fetch(None):
            rc = tb.main(["--pandaproxy", "http://x:8082"])
        self.assertEqual(rc, 2)

    def test_missing_topic_created_exits_0(self):
        existing = list(tb.REQUIRED_TOPICS[:-1])  # one topic missing
        with self._patch_fetch(existing):
            with self._patch_create(tb.CREATED):
                rc = tb.main(["--pandaproxy", "http://x:8082"])
        self.assertEqual(rc, 0)

    def test_creation_failure_exits_1(self):
        with self._patch_fetch([]):
            with self._patch_create(tb.FAIL, "docker not found"):
                rc = tb.main(["--pandaproxy", "http://x:8082"])
        self.assertEqual(rc, 1)

    def test_dry_run_skips_creation_exits_0(self):
        with self._patch_fetch([]):
            rc = tb.main(["--pandaproxy", "http://x:8082", "--dry-run"])
        self.assertEqual(rc, 0)

    def test_partial_failure_exits_1(self):
        # Two topics missing; only first one fails to create.
        call_count = {"n": 0}
        def side_effect(topic, dry_run, replication_factor=1):
            call_count["n"] += 1
            return (tb.FAIL, "err") if call_count["n"] == 1 else (tb.CREATED, "ok")
        with self._patch_fetch(list(tb.REQUIRED_TOPICS[:2])):
            with patch.object(tb, "create_topic", side_effect=side_effect):
                rc = tb.main(["--pandaproxy", "http://x:8082"])
        self.assertEqual(rc, 1)

    def test_invalid_tls_configuration_fails_before_fetch(self):
        with patch.object(tb, "fetch_topic_list") as fetch:
            rc = tb.main([
                "--pandaproxy", "http://redpanda:8082",
                "--tls-ca", "ca.crt",
            ])
        self.assertEqual(rc, 2)
        fetch.assert_not_called()

    def test_main_passes_verifying_context_to_fetch(self):
        context = MagicMock()
        with patch.object(tb, "build_tls_context", return_value=context):
            with patch.object(tb, "fetch_topic_list", return_value=list(tb.REQUIRED_TOPICS)) as fetch:
                rc = tb.main([
                    "--pandaproxy", "https://redpanda:8083",
                    "--tls-ca", "ca.crt",
                ])
        self.assertEqual(rc, 0)
        fetch.assert_called_once_with("https://redpanda:8083", 5, context)


# ---------------------------------------------------------------------------
# _parse_prometheus_gauge (new validator helper — tested here since it's
# imported via the validator module, but we test via the bootstrap module's
# counterpart in the validator to keep test scope focused)
# ---------------------------------------------------------------------------

_SAMPLE_METRICS = """\
# HELP redpanda_kafka_max_offset Latest readable offset
# TYPE redpanda_kafka_max_offset gauge
redpanda_kafka_max_offset{redpanda_namespace="redpanda",redpanda_partition="0",redpanda_topic="controller"} 21.0
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_partition="0",redpanda_topic="telemetry.raw"} 65.0
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_partition="0",redpanda_topic="xdr.alerts"} 15.0
redpanda_kafka_max_offset{redpanda_namespace="kafka",redpanda_partition="0",redpanda_topic="alerts.created"} 0.0
"""

sys.modules.pop("validate_live_xdr_pipeline", None)
import validate_live_xdr_pipeline as vlp  # noqa: E402


class TestParsePrometheusGauge(unittest.TestCase):
    def test_finds_matching_series(self):
        val = vlp._parse_prometheus_gauge(
            _SAMPLE_METRICS, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": "telemetry.raw"},
        )
        self.assertEqual(val, 65.0)

    def test_returns_none_for_missing_topic(self):
        val = vlp._parse_prometheus_gauge(
            _SAMPLE_METRICS, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": "telemetry.normalized"},
        )
        self.assertIsNone(val)

    def test_namespace_label_discriminates_correctly(self):
        val_redpanda = vlp._parse_prometheus_gauge(
            _SAMPLE_METRICS, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "redpanda", "redpanda_topic": "controller"},
        )
        val_kafka = vlp._parse_prometheus_gauge(
            _SAMPLE_METRICS, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": "controller"},
        )
        self.assertEqual(val_redpanda, 21.0)
        self.assertIsNone(val_kafka)

    def test_zero_offset_returned_as_zero(self):
        val = vlp._parse_prometheus_gauge(
            _SAMPLE_METRICS, "redpanda_kafka_max_offset",
            {"redpanda_namespace": "kafka", "redpanda_topic": "alerts.created"},
        )
        self.assertEqual(val, 0.0)

    def test_comment_lines_skipped(self):
        text = "# HELP foo bar\n# TYPE foo gauge\nfoo{a=\"1\"} 42.0\n"
        val = vlp._parse_prometheus_gauge(text, "foo", {"a": "1"})
        self.assertEqual(val, 42.0)

    def test_empty_text_returns_none(self):
        val = vlp._parse_prometheus_gauge("", "redpanda_kafka_max_offset", {"a": "b"})
        self.assertIsNone(val)


if __name__ == "__main__":
    unittest.main()
