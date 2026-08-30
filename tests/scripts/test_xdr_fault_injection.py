from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import xdr_fault_injection as fault_injection


class TestFaultInjectionMutualTls(unittest.TestCase):
    def test_default_is_plaintext_compatible(self):
        args = fault_injection.parse_args([])

        self.assertFalse(args.mtls_enabled)
        self.assertIsNone(fault_injection.build_mtls_context(args))

    def test_enabled_requires_https_for_service_urls_only(self):
        args = fault_injection.parse_args(["--mtls-enabled"])
        with self.assertRaisesRegex(ValueError, "--ingest-url"):
            fault_injection.build_mtls_context(args)

        args = fault_injection.parse_args([
            "--mtls-enabled",
            "--ingest-url", "https://gateway",
            "--normalizer-url", "https://normalizer",
            "--alert-writer-url", "https://writer",
            "--mtls-ca", "ca.pem",
        ])
        with self.assertRaisesRegex(ValueError, "--mtls-client-cert"):
            fault_injection.build_mtls_context(args)

    def test_context_loads_identity_without_requiring_laravel_https(self):
        args = fault_injection.parse_args([
            "--mtls-enabled",
            "--ingest-url", "https://gateway",
            "--normalizer-url", "https://normalizer",
            "--alert-writer-url", "https://writer",
            "--laravel-url", "http://laravel",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "client-key.pem",
        ])
        context = MagicMock()

        with patch.object(
            fault_injection.ssl, "create_default_context", return_value=context
        ) as create_context:
            self.assertIs(fault_injection.build_mtls_context(args), context)

        create_context.assert_called_once_with(cafile="ca.pem")
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="client-key.pem"
        )

    def test_internal_helpers_use_context_but_laravel_does_not(self):
        args = fault_injection.parse_args([])
        args.internal_ssl_context = MagicMock()

        with patch.object(fault_injection, "http_get", return_value=(200, {})) as get:
            fault_injection.internal_get(args, "https://normalizer/metrics")
        self.assertIs(get.call_args.kwargs["ssl_context"], args.internal_ssl_context)

        with patch.object(
            fault_injection, "http_get", return_value=(401, {})
        ) as get:
            result = fault_injection.inject_invalid_auth_token(args)
        self.assertTrue(result["passed"])
        self.assertNotIn("ssl_context", get.call_args.kwargs)

    def test_malformed_event_uses_internal_context(self):
        args = fault_injection.parse_args([])
        args.internal_ssl_context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.status = 400

        with patch.object(
            fault_injection.urllib.request, "urlopen", return_value=response
        ) as urlopen, patch.object(fault_injection.time, "sleep"):
            result = fault_injection.inject_malformed_events(args)

        self.assertTrue(result["passed"])
        self.assertEqual(urlopen.call_count, 4)
        self.assertTrue(all(
            call.kwargs["context"] is args.internal_ssl_context
            for call in urlopen.call_args_list
        ))

    def test_invalid_config_stops_before_injection_and_report(self):
        args = fault_injection.parse_args(["--mtls-enabled"])

        with patch.object(fault_injection, "run_injection") as run, \
             patch.object(fault_injection, "ensure_report_dir") as ensure:
            self.assertEqual(fault_injection.main(args), 2)

        run.assert_not_called()
        ensure.assert_not_called()


if __name__ == "__main__":
    unittest.main()
