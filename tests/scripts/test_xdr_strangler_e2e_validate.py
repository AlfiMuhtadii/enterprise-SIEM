from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch


SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import xdr_strangler_e2e_validate as validator


class TestMutualTlsConfiguration(unittest.TestCase):
    def test_default_is_plaintext_compatible(self):
        args = validator.parse_args([])
        self.assertFalse(args.mtls_enabled)
        self.assertIsNone(validator.build_mtls_context(args))

    def test_enabled_requires_https_for_every_first_party_service(self):
        args = validator.parse_args([
            "--mtls-enabled",
            "--gateway-url", "https://gateway:8091",
            "--normalizer-url", "http://normalizer:8092",
            "--ai-url", "https://ai:8094",
        ])
        with self.assertRaisesRegex(ValueError, "--normalizer-url"):
            validator.build_mtls_context(args)

    def test_enabled_requires_complete_identity(self):
        args = validator.parse_args([
            "--mtls-enabled",
            "--gateway-url", "https://gateway:8091",
            "--normalizer-url", "https://normalizer:8092",
            "--ai-url", "https://ai:8094",
            "--mtls-ca", "ca.pem",
        ])
        with self.assertRaisesRegex(ValueError, "--mtls-client-cert"):
            validator.build_mtls_context(args)

    def test_context_loads_ca_and_client_identity(self):
        args = validator.parse_args([
            "--mtls-enabled",
            "--gateway-url", "https://gateway:8091",
            "--normalizer-url", "https://normalizer:8092",
            "--ai-url", "https://ai:8094",
            "--mtls-ca", "ca.pem",
            "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "client-key.pem",
        ])
        context = MagicMock()
        with patch.object(
            validator.ssl, "create_default_context", return_value=context
        ) as create:
            self.assertIs(validator.build_mtls_context(args), context)
        create.assert_called_once_with(cafile="ca.pem")
        context.load_cert_chain.assert_called_once_with(
            certfile="client.pem", keyfile="client-key.pem"
        )


class TestMutualTlsTransport(unittest.TestCase):
    def test_get_and_post_pass_context_to_urlopen(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.status = 200
        response.__enter__.return_value.read.return_value = b'{"ok":true}'
        with patch.object(
            validator.urllib.request, "urlopen", return_value=response
        ) as urlopen:
            validator.get_json("https://gateway/health", ssl_context=context)
            get_call = urlopen.call_args
            validator.post_json(
                "https://gateway/v1/ingest", [], ssl_context=context
            )
            post_call = urlopen.call_args
        self.assertIs(get_call.kwargs["context"], context)
        self.assertIs(post_call.kwargs["context"], context)

    def test_ingestion_batches_reuse_context(self):
        args = validator.parse_args([
            "--gateway-url", "https://gateway:8091",
            "--batch-size", "1",
        ])
        context = MagicMock()
        with patch.object(
            validator,
            "post_json",
            return_value={"ok": True, "latency_ms": 1},
        ) as post:
            result = validator.run_go_ingestion(
                [{"event_id": "one"}, {"event_id": "two"}], args, context
            )
        self.assertEqual(result["accepted"], 2)
        self.assertEqual(post.call_count, 2)
        for call in post.call_args_list:
            self.assertIs(call.kwargs["ssl_context"], context)

    def test_invalid_config_stops_before_dataset_or_network(self):
        args = validator.parse_args(["--mtls-enabled"])
        with patch.object(validator, "load_jsonl") as load_jsonl, \
             patch.object(validator, "get_json") as get_json:
            self.assertEqual(validator.main(args), 2)
        load_jsonl.assert_not_called()
        get_json.assert_not_called()


if __name__ == "__main__":
    unittest.main()
