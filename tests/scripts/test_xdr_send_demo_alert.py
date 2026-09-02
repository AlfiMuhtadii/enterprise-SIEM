from __future__ import annotations

import json
import sys
import unittest
import urllib.error
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS))

import xdr_send_demo_alert as sender  # noqa: E402


class TestDemoAlertTls(unittest.TestCase):
    def response(self) -> MagicMock:
        response = MagicMock()
        response.status = 200
        response.read.return_value = b'{"offsets":[{"partition":0,"offset":1}]}'
        response.__enter__.return_value = response
        response.__exit__.return_value = False
        return response

    def test_defaults_preserve_local_plaintext(self):
        args = sender.parse_args([])
        self.assertEqual(args.rest_url, "http://127.0.0.1:8082")
        self.assertIsNone(args.tls_ca)

    def test_no_ca_preserves_default_transport(self):
        self.assertIsNone(sender.build_tls_context("http://127.0.0.1:8082", None))

    def test_ca_requires_https(self):
        with patch("ssl.create_default_context") as create_context:
            with self.assertRaisesRegex(ValueError, "requires an https://"):
                sender.build_tls_context("http://redpanda:8082", "ca.crt")
        create_context.assert_not_called()

    def test_private_ca_builds_verifying_context(self):
        context = MagicMock()
        with patch("ssl.create_default_context", return_value=context) as create_context:
            result = sender.build_tls_context("https://redpanda:8083", "ca.crt")
        self.assertIs(result, context)
        create_context.assert_called_once_with(cafile="ca.crt")

    def test_invalid_tls_fails_before_payload_or_network(self):
        with patch("urllib.request.urlopen") as open_request:
            with patch("time.time") as now:
                result = sender.main([
                    "--rest-url", "http://redpanda:8082",
                    "--tls-ca", "ca.crt",
                ])
        self.assertEqual(result, 2)
        open_request.assert_not_called()
        now.assert_not_called()

    def test_plaintext_send_keeps_context_out_and_payload_intact(self):
        with patch("urllib.request.urlopen", return_value=self.response()) as open_request:
            with patch("time.time", return_value=1234):
                result = sender.main(["--user", "analyst@example.test"])

        self.assertEqual(result, 0)
        request = open_request.call_args.args[0]
        payload = json.loads(request.data)
        self.assertNotIn("context", open_request.call_args.kwargs)
        self.assertEqual(payload["records"][0]["value"]["event_id"], "demo-alert-1234")
        self.assertEqual(
            payload["records"][0]["value"]["actor_key"],
            "analyst@example.test",
        )

    def test_tls_send_scopes_verifying_context_to_request(self):
        context = MagicMock()
        with patch.object(sender, "build_tls_context", return_value=context):
            with patch("urllib.request.urlopen", return_value=self.response()) as open_request:
                result = sender.main([
                    "--rest-url", "https://redpanda:8083",
                    "--tls-ca", "ca.crt",
                ])

        self.assertEqual(result, 0)
        self.assertIs(open_request.call_args.kwargs["context"], context)
        self.assertEqual(
            open_request.call_args.args[0].full_url,
            "https://redpanda:8083/topics/xdr.alerts",
        )

    def test_handshake_failure_returns_operational_error(self):
        failure = urllib.error.URLError("certificate verify failed")
        with patch.object(sender, "build_tls_context", return_value=MagicMock()):
            with patch("urllib.request.urlopen", side_effect=failure):
                result = sender.main([
                    "--rest-url", "https://redpanda:8083",
                    "--tls-ca", "ca.crt",
                ])
        self.assertEqual(result, 2)


if __name__ == "__main__":
    unittest.main()
