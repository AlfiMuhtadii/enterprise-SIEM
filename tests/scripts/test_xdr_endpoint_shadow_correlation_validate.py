from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import xdr_endpoint_shadow_correlation_validate as validator


class TestEndpointShadowMutualTls(unittest.TestCase):
    def test_plaintext_and_offline_defaults_need_no_context(self):
        self.assertIsNone(validator.build_mtls_context(validator.parse_args([])))
        args = validator.parse_args(["--use-correlation-service", "0"])
        self.assertIsNone(validator.build_mtls_context(args))

    def test_enabled_requires_service_mode_and_https(self):
        args = validator.parse_args(["--mtls-enabled", "--use-correlation-service", "0"])
        with self.assertRaisesRegex(ValueError, "--use-correlation-service 1"):
            validator.build_mtls_context(args)
        with self.assertRaisesRegex(ValueError, "--correlation-url"):
            validator.build_mtls_context(validator.parse_args(["--mtls-enabled"]))

    def test_context_loads_identity(self):
        args = validator.parse_args([
            "--mtls-enabled", "--correlation-url", "https://correlation",
            "--mtls-ca", "ca.pem", "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(validator.ssl, "create_default_context", return_value=context):
            self.assertIs(validator.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(certfile="client.pem", keyfile="key.pem")

    def test_health_and_correlation_use_context(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.status = 200
        response.__enter__.return_value.read.return_value = b'{"shadow_alerts":[]}'
        with patch.object(validator.urllib.request, "urlopen", return_value=response) as urlopen:
            self.assertTrue(validator.correlation_health("https://correlation", context))
            result = validator.post_shadow_correlate("https://correlation", [], context)
        self.assertEqual(result, {"shadow_alerts": []})
        self.assertTrue(all(call.kwargs["context"] is context for call in urlopen.call_args_list))

    def test_invalid_config_stops_before_fixture_or_report(self):
        args = validator.parse_args(["--mtls-enabled"])
        with patch.object(validator, "load_fixtures") as load, patch.object(Path, "write_text") as write:
            self.assertEqual(validator.main(args), 2)
        load.assert_not_called()
        write.assert_not_called()

    def test_distinct_events_from_same_rule_are_not_duplicates(self):
        alerts = [
            {"rule_id": "rule", "event_id": "event-1", "trace_id": "trace"},
            {"rule_id": "rule", "event_id": "event-2", "trace_id": "trace"},
        ]
        self.assertEqual(validator.check_no_duplicates(alerts, "fixture"), (True, None))

        alerts.append(dict(alerts[0]))
        ok, message = validator.check_no_duplicates(alerts, "fixture")
        self.assertFalse(ok)
        self.assertIn("duplicate alert identities", message)


if __name__ == "__main__":
    unittest.main()
