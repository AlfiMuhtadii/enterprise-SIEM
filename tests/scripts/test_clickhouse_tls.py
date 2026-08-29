import os
import ssl
import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import sync_postgres_to_clickhouse as sync  # noqa: E402
import xdr_infra_clients as clients  # noqa: E402


class ClickHouseTlsTest(unittest.TestCase):
    def test_invalid_tls_verification_flag_fails_closed(self):
        with patch.dict(os.environ, {"XDR_CLICKHOUSE_VERIFY_TLS": "maybe"}):
            with self.assertRaisesRegex(ValueError, "must be a boolean value"):
                clients.env_bool("XDR_CLICKHOUSE_VERIFY_TLS")

    def test_http_does_not_create_an_ssl_context(self):
        self.assertIsNone(clients.tls_context_for_url("http://clickhouse:8123"))

    @patch.object(ssl, "create_default_context")
    def test_https_uses_the_configured_ca(self, create_context):
        sentinel = object()
        create_context.return_value = sentinel

        context = clients.tls_context_for_url("https://clickhouse:8443", True, "/ca.crt")

        self.assertIs(context, sentinel)
        create_context.assert_called_once_with(cafile="/ca.crt")

    @patch.object(ssl, "_create_unverified_context")
    def test_explicit_insecure_mode_is_not_implicit(self, create_context):
        sentinel = object()
        create_context.return_value = sentinel

        context = clients.tls_context_for_url("https://clickhouse:8443", False, "")

        self.assertIs(context, sentinel)
        create_context.assert_called_once_with()

    def test_clickhouse_client_keeps_ssl_context_on_http_client(self):
        context = ssl.create_default_context()
        with patch.object(clients, "tls_context_for_url", return_value=context) as context_factory:
            client = clients.ClickHouseClient(
                "https://clickhouse:8443", "db", "user", "password", verify_tls=True, ca_cert="/ca.crt"
            )

        self.assertIs(client.http.ssl_context, context)
        context_factory.assert_called_once_with("https://clickhouse:8443", True, "/ca.crt")

    def test_http_client_passes_ssl_context_to_urlopen(self):
        context = ssl.create_default_context()
        response = MagicMock()
        response.status = 200
        response.read.return_value = b"Ok.\n"
        response.__enter__.return_value = response
        response.__exit__.return_value = False

        with patch.object(clients.urllib.request, "urlopen", return_value=response) as urlopen:
            result = clients.HttpClient(retries=0, ssl_context=context).request("GET", "https://clickhouse:8443/ping")

        self.assertTrue(result.ok)
        self.assertIs(urlopen.call_args.kwargs["context"], context)

    def test_sync_client_uses_the_same_tls_context_contract(self):
        context = ssl.create_default_context()
        response = MagicMock()
        response.read.return_value = b"1\n"
        response.__enter__.return_value = response
        response.__exit__.return_value = False

        with patch.dict(os.environ, {"XDR_CLICKHOUSE_VERIFY_TLS": "true", "XDR_CLICKHOUSE_CA_CERT": "/ca.crt"}), \
                patch.object(sync, "tls_context_for_url", return_value=context) as context_factory, \
                patch.object(sync.urllib.request, "urlopen", return_value=response) as urlopen:
            result = sync.ch_request("https://clickhouse:8443", "SELECT 1", "user", "password")

        self.assertEqual(result, "1\n")
        context_factory.assert_called_once_with("https://clickhouse:8443/?query=SELECT+1", True, "/ca.crt")
        self.assertIs(urlopen.call_args.kwargs["context"], context)


if __name__ == "__main__":
    unittest.main()
