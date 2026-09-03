from __future__ import annotations

import sys
import tempfile
import unittest
import urllib.error
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS))

import xdr_stream_bus as stream_bus  # noqa: E402


class TestStreamBusPandaproxyTls(unittest.TestCase):
    def response(self) -> MagicMock:
        response = MagicMock()
        response.read.return_value = b'{}'
        response.__enter__.return_value = response
        response.__exit__.return_value = False
        return response

    def test_defaults_preserve_jsonl_backend(self):
        args = stream_bus.parse_args(["topics"])
        self.assertEqual(args.backend, "jsonl")
        self.assertEqual(args.redpanda_rest, "http://127.0.0.1:8082")
        self.assertIsNone(args.redpanda_tls_ca)

    def test_no_ca_preserves_default_transport(self):
        self.assertIsNone(
            stream_bus.build_redpanda_tls_context(
                "produce", "redpanda", "http://127.0.0.1:8082", None
            )
        )

    def test_ca_requires_redpanda_produce_mode(self):
        with self.assertRaisesRegex(ValueError, "produce action"):
            stream_bus.build_redpanda_tls_context(
                "topics", "jsonl", "https://redpanda:8083", "ca.crt"
            )

    def test_ca_requires_https(self):
        with patch("ssl.create_default_context") as create_context:
            with self.assertRaisesRegex(ValueError, "requires an https://"):
                stream_bus.build_redpanda_tls_context(
                    "produce", "redpanda", "http://redpanda:8082", "ca.crt"
                )
        create_context.assert_not_called()

    def test_private_ca_builds_verifying_context(self):
        context = MagicMock()
        with patch("ssl.create_default_context", return_value=context) as create_context:
            result = stream_bus.build_redpanda_tls_context(
                "produce", "redpanda", "https://redpanda:8083", "ca.crt"
            )
        self.assertIs(result, context)
        create_context.assert_called_once_with(cafile="ca.crt")

    def test_successful_publish_scopes_context(self):
        context = MagicMock()
        rows = [{"event_id": "one"}]
        with patch("urllib.request.urlopen", return_value=self.response()) as open_request:
            published, failed = stream_bus.produce_redpanda(
                "https://redpanda:8083", "telemetry.raw", rows, context
            )
        self.assertEqual((published, failed), (1, 0))
        self.assertIs(open_request.call_args.kwargs["context"], context)

    def test_failed_publish_is_counted_and_written_to_dlq(self):
        rows = [{"event_id": "one"}, {"event_id": "two"}]
        failure = urllib.error.URLError("certificate verify failed")
        with patch(
            "urllib.request.urlopen",
            side_effect=[self.response(), failure],
        ):
            with patch.object(stream_bus, "produce_jsonl", return_value=1) as dlq:
                published, failed = stream_bus.produce_redpanda(
                    "https://redpanda:8083", "telemetry.raw", rows, MagicMock()
                )
        self.assertEqual((published, failed), (1, 1))
        dlq.assert_called_once()
        self.assertEqual(dlq.call_args.args[1][0]["event"], rows[1])
        self.assertTrue(dlq.call_args.kwargs["dlq"])

    def test_dlq_jsonl_envelope_is_labeled(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory) / "telemetry.raw.dlq.jsonl"
            stream_bus.produce_jsonl(
                output,
                [{"error": "failed", "event": {"event_id": "one"}}],
                dlq=True,
            )
            envelope = stream_bus.json.loads(output.read_text(encoding="utf-8"))
        self.assertTrue(envelope["dlq"])
        self.assertEqual(envelope["event"]["event"]["event_id"], "one")

    def test_invalid_tls_fails_before_source_file_check(self):
        result = stream_bus.main([
            "produce",
            "--backend", "redpanda",
            "--redpanda-rest", "http://redpanda:8082",
            "--redpanda-tls-ca", "ca.crt",
            "--file", "missing.jsonl",
        ])
        self.assertEqual(result, 2)

    def test_any_publish_failure_returns_nonzero(self):
        with tempfile.TemporaryDirectory() as directory:
            source = Path(directory) / "events.jsonl"
            source.write_text('{"event_id":"one"}\n', encoding="utf-8")
            with patch.object(
                stream_bus,
                "produce_redpanda",
                return_value=(0, 1),
            ):
                result = stream_bus.main([
                    "produce",
                    "--backend", "redpanda",
                    "--file", str(source),
                ])
        self.assertEqual(result, 1)


if __name__ == "__main__":
    unittest.main()
