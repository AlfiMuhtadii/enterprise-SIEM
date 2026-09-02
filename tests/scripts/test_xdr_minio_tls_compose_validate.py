import sys
import unittest
from copy import deepcopy
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import xdr_minio_tls_compose_validate as validator


def valid_configs():
    init = {
        "entrypoint": [
            "cp server.crt /tls/minio/public.crt server.key /tls/minio/private.key "
            "ca.crt /tls/minio/CAs/internal-ca.crt chmod 640 /tls/minio/private.key"
        ]
    }
    local = {"command": ["server", "/data"], "ports": [{"published": "9000"}]}
    production_minio = {
        "command": ["server", "/data", "--certs-dir", f"{validator.CERT_DIR}/minio"],
        "ports": [],
        "volumes": [{"target": validator.CERT_DIR, "read_only": True}],
        "group_add": ["44444"],
        "depends_on": {
            "internal-mtls-certs-init": {"condition": "service_completed_successfully"}
        },
        "healthcheck": {
            "test": [
                f"SSL_CERT_FILE={validator.CERT_DIR}/ca.crt mc alias set health "
                "https://localhost:9000 && mc ready health --quiet"
            ]
        },
    }
    production_services = {
        "internal-mtls-certs-init": init,
        "minio": production_minio,
    }
    for name in validator.CLIENTS:
        production_services[name] = {
            "environment": {
                "AWS_ENDPOINT": "https://minio:9000",
                "AWS_CA_BUNDLE": f"{validator.CERT_DIR}/ca.crt",
            },
            "volumes": [{"target": validator.CERT_DIR, "read_only": True}],
        }
    return {"services": {"minio": local}}, {"services": production_services}


class MinioTlsComposeValidatorTest(unittest.TestCase):
    def setUp(self):
        self.base, self.production = valid_configs()

    def validate(self):
        with patch.object(
            validator.Path,
            "read_text",
            side_effect=['SERVICE_SANS = ["minio"]', "'verify' => env('AWS_CA_BUNDLE') ?: true"],
        ):
            return validator.validate_configs(self.base, self.production)

    def test_valid_config_passes(self):
        self.assertEqual([], self.validate())

    def test_plaintext_production_client_fails(self):
        self.production["services"]["app"]["environment"]["AWS_ENDPOINT"] = "http://minio:9000"
        self.assertTrue(any("must use MinIO HTTPS" in item for item in self.validate()))

    def test_writable_certificate_mount_fails(self):
        self.production["services"]["minio"]["volumes"][0]["read_only"] = False
        self.assertIn("production MinIO certificate mount must be read-only", self.validate())

    def test_missing_certs_directory_fails(self):
        self.production["services"]["minio"]["command"] = ["server", "/data"]
        self.assertIn("production MinIO must use the initialized TLS directory", self.validate())

    def test_production_host_port_fails(self):
        self.production["services"]["minio"]["ports"] = [{"published": "9000"}]
        self.assertIn("production MinIO must not publish API or console ports", self.validate())


if __name__ == "__main__":
    unittest.main()
