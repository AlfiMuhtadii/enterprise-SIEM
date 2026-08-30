from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import xdr_threat_intel_validate as validator


class TestThreatIntelMutualTls(unittest.TestCase):
    def test_local_default_needs_no_context(self):
        self.assertIsNone(validator.build_mtls_context(validator.parse_args([])))

    def test_enabled_requires_service_mode_and_https(self):
        with self.assertRaisesRegex(ValueError, "--use-correlation-service 1"):
            validator.build_mtls_context(validator.parse_args(["--mtls-enabled"]))
        args = validator.parse_args(["--mtls-enabled", "--use-correlation-service", "1"])
        with self.assertRaisesRegex(ValueError, "--correlation-url"):
            validator.build_mtls_context(args)

    def test_context_loads_identity(self):
        args = validator.parse_args([
            "--mtls-enabled", "--use-correlation-service", "1",
            "--correlation-url", "https://correlation", "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem", "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(validator.ssl, "create_default_context", return_value=context):
            self.assertIs(validator.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(certfile="client.pem", keyfile="key.pem")

    def test_service_health_and_fixture_posts_use_context(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.status = 200
        response.__enter__.return_value.read.return_value = b'{"alert_count":0,"shadow_mode":true}'
        fixtures = {"one": [], "two": []}
        with patch.object(validator.urllib.request, "urlopen", return_value=response) as urlopen:
            result = validator.test_correlation_service(
                "https://correlation", "", fixtures, context
            )
        self.assertTrue(result["ok"])
        self.assertEqual(urlopen.call_count, 3)
        self.assertTrue(all(call.kwargs["context"] is context for call in urlopen.call_args_list))

    def test_invalid_config_stops_before_fixture_and_database_mutation(self):
        args = validator.parse_args(["--mtls-enabled"])
        with patch.object(validator, "load_json") as load, \
             patch.object(validator.IoCStore, "__init__", return_value=None) as store:
            self.assertEqual(validator.main(args), 2)
        load.assert_not_called()
        store.assert_not_called()


if __name__ == "__main__":
    unittest.main()
