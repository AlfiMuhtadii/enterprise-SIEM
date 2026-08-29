import os
import ssl
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import xdr_infra_clients as clients  # noqa: E402
import xdr_polyglot_microservices_validate as polyglot  # noqa: E402


class QdrantTlsTest(unittest.TestCase):
    def test_invalid_tls_verification_flag_fails_closed(self):
        with patch.dict(os.environ, {"XDR_QDRANT_VERIFY_TLS": "maybe"}):
            with self.assertRaisesRegex(ValueError, "must be a boolean value"):
                clients.env_bool("XDR_QDRANT_VERIFY_TLS")

    @patch.object(clients, "tls_context_for_url")
    def test_qdrant_client_uses_destination_scoped_ssl_context(self, context_factory):
        context = ssl.create_default_context()
        context_factory.return_value = context

        client = clients.QdrantClient(
            "https://qdrant:6333", "soc_knowledge", verify_tls=True, ca_cert="/ca.crt"
        )

        self.assertIs(client.http.ssl_context, context)
        context_factory.assert_called_once_with("https://qdrant:6333", True, "/ca.crt")

    @patch.object(clients, "tls_context_for_url")
    def test_plaintext_qdrant_does_not_receive_a_global_tls_override(self, context_factory):
        context_factory.return_value = None

        client = clients.QdrantClient("http://qdrant:6333", "soc_knowledge")

        self.assertIsNone(client.http.ssl_context)
        context_factory.assert_called_once_with("http://qdrant:6333", True, "")

    def test_polyglot_smoke_client_passes_qdrant_ssl_context_to_urlopen(self):
        context = ssl.create_default_context()
        response = MagicMock()
        response.status = 200
        response.read.return_value = b'{"title":"qdrant"}'
        response.__enter__.return_value = response
        response.__exit__.return_value = False

        with patch.object(polyglot.urllib.request, "urlopen", return_value=response) as urlopen:
            ok, result = polyglot.http_json(
                "GET", "https://qdrant:6333/healthz", ssl_context=context
            )

        self.assertTrue(ok)
        self.assertEqual(200, result["status"])
        self.assertIs(urlopen.call_args.kwargs["context"], context)


if __name__ == "__main__":
    unittest.main()
