from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import xdr_correlation_shadow_benchmark as benchmark


class TestCorrelationBenchmarkMutualTls(unittest.TestCase):
    def test_default_needs_no_context(self):
        self.assertIsNone(benchmark.build_mtls_context(benchmark.parse_args([])))

    def test_enabled_requires_https_and_identity(self):
        with self.assertRaisesRegex(ValueError, "--correlation-url"):
            benchmark.build_mtls_context(benchmark.parse_args(["--mtls-enabled"]))

    def test_context_loads_identity(self):
        args = benchmark.parse_args([
            "--mtls-enabled", "--correlation-url", "https://correlation",
            "--mtls-ca", "ca.pem", "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(benchmark.ssl, "create_default_context", return_value=context):
            self.assertIs(benchmark.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(certfile="client.pem", keyfile="key.pem")

    def test_get_and_correlate_use_context(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.read.return_value = b'{"alerts":[],"latency_ms":1}'
        with patch.object(benchmark.urllib.request, "urlopen", return_value=response) as urlopen:
            benchmark.get_json("https://correlation/health", context)
            benchmark.go_correlation([], "https://correlation", context)
        self.assertTrue(all(call.kwargs["context"] is context for call in urlopen.call_args_list))

    def test_invalid_config_stops_before_dataset_load(self):
        args = benchmark.parse_args(["--mtls-enabled"])
        with patch.object(benchmark, "load_jsonl") as load:
            self.assertEqual(benchmark.main(args), 2)
        load.assert_not_called()


if __name__ == "__main__":
    unittest.main()
