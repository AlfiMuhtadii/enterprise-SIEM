from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import xdr_resilience_validate as validator


class TestResilienceMutualTls(unittest.TestCase):
    def test_default_is_plaintext_compatible(self):
        args = validator.parse_args([])
        self.assertFalse(args.mtls_enabled)
        self.assertIsNone(validator.build_mtls_context(args))

    def test_enabled_requires_https_and_complete_identity(self):
        args = validator.parse_args(["--mtls-enabled"])
        with self.assertRaisesRegex(ValueError, "--ingest-url"):
            validator.build_mtls_context(args)

        args = validator.parse_args([
            "--mtls-enabled",
            "--ingest-url", "https://gateway",
            "--normalizer-url", "https://normalizer",
            "--alert-writer-url", "https://writer",
            "--incident-builder-url", "https://builder",
            "--mtls-ca", "ca.pem",
        ])
        with self.assertRaisesRegex(ValueError, "--mtls-client-cert"):
            validator.build_mtls_context(args)

    def test_context_loads_identity(self):
        args = validator.parse_args([
            "--mtls-enabled",
            "--ingest-url", "https://gateway",
            "--normalizer-url", "https://normalizer",
            "--alert-writer-url", "https://writer",
            "--incident-builder-url", "https://builder",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "client-key.pem",
        ])
        context = MagicMock()
        with patch.object(
            validator.ssl, "create_default_context", return_value=context
        ):
            self.assertIs(validator.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="client-key.pem"
        )

    def test_internal_helpers_use_context_but_laravel_does_not(self):
        args = validator.parse_args(["--run-active"])
        args.internal_ssl_context = MagicMock()
        with patch.object(validator, "http_get", return_value={}) as get:
            validator.internal_get(args, "https://gateway/health")
        self.assertIs(get.call_args.kwargs["ssl_context"], args.internal_ssl_context)

        with patch.object(
            validator, "http_post", return_value={"status": 401}
        ) as post:
            result = validator.validate_invalid_auth_token(args)
        self.assertTrue(result["passed"])
        self.assertNotIn("ssl_context", post.call_args.kwargs)

    def test_invalid_config_stops_before_report(self):
        args = validator.parse_args(["--mtls-enabled"])
        with patch.object(validator, "run_scenario") as run, \
             patch.object(validator, "write_report") as write:
            self.assertEqual(validator.main(args), 2)
        run.assert_not_called()
        write.assert_not_called()


if __name__ == "__main__":
    unittest.main()
