import sys
import unittest
from copy import deepcopy
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import xdr_qdrant_tls_compose_validate as validator  # noqa: E402


def valid_configs():
    init = {
        "environment": {"QDRANT_TLS_ENABLED": "false", "QDRANT_TLS_REQUIRED": "false"},
        "entrypoint": ["sh", "server.crt server.key ca.crt chmod 600 /tls/server.key"],
    }
    qdrant = {
        "depends_on": {"qdrant-tls-init": {"condition": "service_completed_successfully"}},
        "environment": {
            "QDRANT__SERVICE__ENABLE_TLS": "false",
            "QDRANT__TLS__CERT": "/qdrant/tls/server.crt",
            "QDRANT__TLS__KEY": "/qdrant/tls/server.key",
            "QDRANT__TLS__CA_CERT": "/qdrant/tls/ca.crt",
        },
        "volumes": [{"target": "/qdrant/tls", "read_only": True}],
        "healthcheck": {"test": ["openssl s_client -CAfile /qdrant/tls/ca.crt -verify_return_error -verify_hostname qdrant /dev/tcp/127.0.0.1/6333"]},
        "ports": [{"published": "6333"}],
    }
    base = {"services": {"qdrant-tls-init": init, "qdrant": qdrant}}
    prod_init = deepcopy(init)
    prod_init["environment"] = {"QDRANT_TLS_ENABLED": "true", "QDRANT_TLS_REQUIRED": "true"}
    prod_qdrant = deepcopy(qdrant)
    prod_qdrant["environment"]["QDRANT__SERVICE__ENABLE_TLS"] = "true"
    prod_qdrant["ports"] = []
    services = {"qdrant-tls-init": prod_init, "qdrant": prod_qdrant}
    for name in validator.TLS_CLIENTS:
        services[name] = {
            "environment": {
                "SOC_QDRANT_BASE_URL": "https://qdrant:6333",
                "XDR_QDRANT_URL": "https://qdrant:6333",
                "XDR_QDRANT_VERIFY_TLS": "true",
                "XDR_QDRANT_CA_CERT": f"{validator.CLIENT_CERT_DIR}/ca.crt",
            },
            "volumes": [{"target": validator.CLIENT_CERT_DIR, "read_only": True}],
        }
    return base, {"services": services}


class QdrantTlsComposeValidatorTest(unittest.TestCase):
    def test_valid_config_passes(self):
        base, production = valid_configs()
        self.assertEqual([], validator.validate_configs(base, production))

    def test_plaintext_production_client_fails(self):
        base, production = valid_configs()
        production["services"]["app"]["environment"]["SOC_QDRANT_BASE_URL"] = "http://qdrant:6333"
        errors = validator.validate_configs(base, production)
        self.assertTrue(any("SOC knowledge client" in error for error in errors))

    def test_missing_ca_mount_fails(self):
        base, production = valid_configs()
        production["services"]["queue"]["volumes"] = []
        errors = validator.validate_configs(base, production)
        self.assertTrue(any("queue must mount" in error for error in errors))

    def test_production_server_tls_bypass_fails(self):
        base, production = valid_configs()
        production["services"]["qdrant"]["environment"]["QDRANT__SERVICE__ENABLE_TLS"] = "false"
        errors = validator.validate_configs(base, production)
        self.assertIn("production Qdrant server must enable TLS", errors)


if __name__ == "__main__":
    unittest.main()
