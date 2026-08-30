from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import xdr_endpoint_normalization_validate as validator


class TestEndpointNormalizationMutualTls(unittest.TestCase):
    def test_default_and_offline_modes_need_no_tls_context(self):
        self.assertIsNone(validator.build_mtls_context(validator.parse_args([])))
        args = validator.parse_args(["--use-normalizer-service", "0"])
        self.assertIsNone(validator.build_mtls_context(args))

    def test_enabled_requires_service_mode_https_and_identity(self):
        args = validator.parse_args([
            "--mtls-enabled", "--use-normalizer-service", "0"
        ])
        with self.assertRaisesRegex(ValueError, "--use-normalizer-service 1"):
            validator.build_mtls_context(args)

        args = validator.parse_args(["--mtls-enabled"])
        with self.assertRaisesRegex(ValueError, "--normalizer-url"):
            validator.build_mtls_context(args)

    def test_context_loads_identity(self):
        args = validator.parse_args([
            "--mtls-enabled",
            "--normalizer-url", "https://normalizer",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(
            validator.ssl, "create_default_context", return_value=context
        ):
            self.assertIs(validator.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="key.pem"
        )

    def test_health_and_normalize_requests_use_context(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.status = 200
        response.__enter__.return_value.read.return_value = b'{"malformed":0,"enqueued":1}'
        with patch.object(
            validator.urllib.request, "urlopen", return_value=response
        ) as urlopen:
            self.assertTrue(validator.normalizer_health("https://normalizer", context))
            result = validator.try_normalizer_service(
                "https://normalizer", {"event_id": "one"}, "fixture", context
            )
        self.assertTrue(result["service_ok"])
        self.assertTrue(all(
            call.kwargs["context"] is context for call in urlopen.call_args_list
        ))

    def test_invalid_config_stops_before_fixture_or_report(self):
        args = validator.parse_args(["--mtls-enabled"])
        with patch.object(Path, "glob") as glob, patch.object(Path, "write_text") as write:
            self.assertEqual(validator.main(args), 2)
        glob.assert_not_called()
        write.assert_not_called()


if __name__ == "__main__":
    unittest.main()
