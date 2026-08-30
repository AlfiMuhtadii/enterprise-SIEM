from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import xdr_correlation_soak as soak


class TestCorrelationSoakMutualTls(unittest.TestCase):
    def test_default_needs_no_context(self):
        self.assertIsNone(soak.build_mtls_context(soak.parse_args([])))

    def test_enabled_requires_https_and_identity(self):
        with self.assertRaisesRegex(ValueError, "--correlation-url"):
            soak.build_mtls_context(soak.parse_args(["--mtls-enabled"]))

    def test_context_loads_identity(self):
        args = soak.parse_args([
            "--mtls-enabled", "--correlation-url", "https://correlation",
            "--mtls-ca", "ca.pem", "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(soak.ssl, "create_default_context", return_value=context):
            self.assertIs(soak.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(certfile="client.pem", keyfile="key.pem")

    def test_metrics_and_every_post_attempt_use_context(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.read.return_value = b'{"alert_count":0}'
        with patch.object(soak.urllib.request, "urlopen", return_value=response) as urlopen:
            soak.get_json("https://correlation/metrics", ssl_context=context)
            soak.post_correlate("https://correlation", [], 1, 2, 0, context)
        self.assertTrue(all(call.kwargs["context"] is context for call in urlopen.call_args_list))

    def test_invalid_config_stops_before_dataset_and_subprocess(self):
        args = soak.parse_args(["--mtls-enabled"])
        with patch.object(soak, "load_jsonl") as load, patch.object(soak.subprocess, "run") as run:
            self.assertEqual(soak.main(args), 2)
        load.assert_not_called()
        run.assert_not_called()


if __name__ == "__main__":
    unittest.main()
