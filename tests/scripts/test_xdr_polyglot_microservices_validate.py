from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import xdr_polyglot_microservices_validate as polyglot


def secure_args(extra: list[str] | None = None):
    args = [
        "--gateway-url", "https://gateway",
        "--normalizer-url", "https://normalizer",
        "--correlation-url", "https://correlation",
        "--ai-url", "https://ai",
        "--alert-writer-url", "https://alert-writer",
        "--incident-builder-url", "https://incident-builder",
        "--internal-mtls-enabled",
        "--internal-mtls-ca", "ca.pem",
        "--internal-mtls-client-cert", "client.pem",
        "--internal-mtls-client-key", "key.pem",
        "--settle-sec", "0",
    ]
    return polyglot.parse_args(args + (extra or []))


class TestPolyglotInternalMutualTls(unittest.TestCase):
    def test_default_needs_no_internal_context(self):
        self.assertIsNone(polyglot.build_internal_mtls_context(polyglot.parse_args([])))

    def test_enabled_requires_https_for_every_internal_service(self):
        with self.assertRaisesRegex(ValueError, "--gateway-url"):
            polyglot.build_internal_mtls_context(
                polyglot.parse_args(["--internal-mtls-enabled"])
            )

    def test_context_loads_identity(self):
        context = MagicMock()
        with patch.object(polyglot.ssl, "create_default_context", return_value=context):
            self.assertIs(polyglot.build_internal_mtls_context(secure_args()), context)
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="key.pem"
        )

    def test_internal_identity_is_scoped_away_from_infrastructure(self):
        internal_context = MagicMock(name="internal_context")
        qdrant_context = MagicMock(name="qdrant_context")
        with tempfile.TemporaryDirectory() as tmp:
            output = Path(tmp) / "report.json"
            args = secure_args(["--output", str(output)])
            with patch.object(
                polyglot, "build_internal_mtls_context", return_value=internal_context
            ), patch.object(
                polyglot, "tls_context_for_url", return_value=qdrant_context
            ), patch.object(
                polyglot, "http_json", return_value=(True, {"status": 200})
            ) as http_json:
                self.assertEqual(polyglot.main(args), 0)

        calls = http_json.call_args_list
        internal_calls = [call for call in calls if any(
            host in call.args[1]
            for host in ("gateway", "normalizer", "correlation", "ai", "alert-writer", "incident-builder")
        )]
        self.assertTrue(internal_calls)
        self.assertTrue(all(call.kwargs["ssl_context"] is internal_context for call in internal_calls))
        contexts_by_url = {call.args[1]: call.kwargs.get("ssl_context") for call in calls}
        self.assertIsNone(contexts_by_url["http://127.0.0.1:8082/topics"])
        self.assertIsNone(contexts_by_url["http://127.0.0.1:8123/ping"])
        self.assertIsNone(contexts_by_url["http://127.0.0.1:9200"])
        self.assertIs(contexts_by_url["http://127.0.0.1:6333/healthz"], qdrant_context)

    def test_invalid_config_stops_before_network_sleep_and_report(self):
        args = polyglot.parse_args(["--internal-mtls-enabled"])
        with patch.object(polyglot, "http_json") as http_json, patch.object(
            polyglot.time, "sleep"
        ) as sleep, patch.object(polyglot.Path, "mkdir") as mkdir:
            self.assertEqual(polyglot.main(args), 2)
        http_json.assert_not_called()
        sleep.assert_not_called()
        mkdir.assert_not_called()


if __name__ == "__main__":
    unittest.main()
