from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import xdr_event_flow_resilience_validate as validator


class TestEventFlowMutualTls(unittest.TestCase):
    def test_default_is_plaintext_compatible(self):
        args = validator.parse_args([])

        self.assertFalse(args.mtls_enabled)
        self.assertIsNone(validator.build_mtls_context(args))

    def test_enabled_requires_https_and_complete_identity(self):
        args = validator.parse_args(["--mtls-enabled"])
        with self.assertRaisesRegex(ValueError, "--alert-writer-url"):
            validator.build_mtls_context(args)

        args = validator.parse_args([
            "--mtls-enabled",
            "--alert-writer-url", "https://writer",
            "--incident-builder-url", "https://builder",
            "--mtls-ca", "ca.pem",
        ])
        with self.assertRaisesRegex(ValueError, "--mtls-client-cert"):
            validator.build_mtls_context(args)

    def test_context_loads_client_identity(self):
        args = validator.parse_args([
            "--mtls-enabled",
            "--alert-writer-url", "https://writer",
            "--incident-builder-url", "https://builder",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "client-key.pem",
        ])
        context = MagicMock()

        with patch.object(
            validator.ssl, "create_default_context", return_value=context
        ) as create_context:
            self.assertIs(validator.build_mtls_context(args), context)

        create_context.assert_called_once_with(cafile="ca.pem")
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="client-key.pem"
        )

    def test_service_http_uses_context_but_redpanda_rest_does_not(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.read.return_value = b'{"status":"ok"}'

        with patch.object(
            validator.urllib.request, "urlopen", return_value=response
        ) as urlopen:
            validator.http_json(
                "GET", "https://writer/health", ssl_context=context
            )
            validator.http_json_with_headers(
                "POST",
                "http://redpanda:8082/topics/xdr.alerts",
                {"records": []},
                {"Content-Type": "application/vnd.kafka.json.v2+json"},
            )

        self.assertIs(urlopen.call_args_list[0].kwargs["context"], context)
        self.assertNotIn("context", urlopen.call_args_list[1].kwargs)

    def test_service_wrappers_forward_context(self):
        context = MagicMock()
        with patch.object(validator, "http_json", return_value={"status": "ok"}) as http:
            self.assertTrue(validator.service_available("https://writer", context))
            validator.post_alert_process("https://writer", "trace-1", context)

        self.assertIs(http.call_args_list[0].kwargs["ssl_context"], context)
        self.assertIs(http.call_args_list[1].kwargs["ssl_context"], context)

    def test_invalid_config_stops_before_runtime_and_report(self):
        args = validator.parse_args(["--mtls-enabled"])

        with patch.object(validator, "service_available") as available, \
             patch.object(Path, "write_text") as write:
            self.assertEqual(validator.main(args), 2)

        available.assert_not_called()
        write.assert_not_called()


if __name__ == "__main__":
    unittest.main()
