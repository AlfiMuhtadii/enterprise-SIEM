from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import xdr_staged_cutover_validate as staged


class TestStagedCutoverMutualTls(unittest.TestCase):
    def test_default_needs_no_context(self):
        self.assertIsNone(staged.build_mtls_context(staged.parse_args([])))

    def test_enabled_requires_https_and_identity(self):
        with self.assertRaisesRegex(ValueError, "--correlation-url"):
            staged.build_mtls_context(staged.parse_args(["--mtls-enabled"]))

    def test_context_loads_identity(self):
        args = staged.parse_args([
            "--mtls-enabled", "--correlation-url", "https://correlation",
            "--mtls-ca", "ca.pem", "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(staged.ssl, "create_default_context", return_value=context):
            self.assertIs(staged.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(certfile="client.pem", keyfile="key.pem")

    def test_benchmark_propagates_identity_only_when_requested(self):
        args = staged.parse_args([
            "--mtls-enabled", "--correlation-url", "https://correlation",
            "--mtls-ca", "ca.pem", "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        root = Path("repo")
        output = root / "reports" / "result.json"
        with patch.object(staged, "run") as run, patch.object(staged, "load_json", return_value={}):
            staged.run_benchmark(root, args, "go", output)
            staged.run_benchmark(
                root,
                args,
                "go",
                output,
                url="http://127.0.0.1:1",
                use_mtls=False,
            )

        normal_cmd = run.call_args_list[0].args[0]
        fallback_cmd = run.call_args_list[1].args[0]
        self.assertIn("--mtls-enabled", normal_cmd)
        self.assertIn("client.pem", normal_cmd)
        self.assertNotIn("--mtls-enabled", fallback_cmd)
        self.assertNotIn("client.pem", fallback_cmd)

    def test_invalid_config_stops_before_output_and_subprocess(self):
        args = staged.parse_args(["--mtls-enabled"])
        with patch.object(staged, "run") as run, patch.object(staged.Path, "mkdir") as mkdir:
            self.assertEqual(staged.main(args), 2)
        run.assert_not_called()
        mkdir.assert_not_called()


if __name__ == "__main__":
    unittest.main()
