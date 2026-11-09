import sys
import unittest
from copy import deepcopy
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import xdr_otlp_tls_compose_validate as validator  # noqa: E402


BASE_CONFIG = """distributor:
  receivers:
    otlp:
      protocols:
        http:
"""

TLS_CONFIG = """distributor:
  receivers:
    otlp:
      protocols:
        http:
          endpoint: 0.0.0.0:4318
          tls:
            cert_file: /etc/xdr/internal-mtls/server.crt
            key_file: /etc/xdr/internal-mtls/server.key
            min_version: "1.2"
"""


def valid_configs():
    cert_mount = {"target": validator.CERT_DIR, "read_only": True}
    tempo = {
        "profiles": ["observability"],
        "ports": [{"target": 3200}, {"target": 4318}],
    }
    base = {"services": {"tempo": tempo}}

    prod_tempo = {
        "profiles": [],
        "ports": [],
        "command": ["-config.file=/etc/tempo-tls.yaml"],
        "volumes": [cert_mount, {"target": "/etc/tempo-tls.yaml", "read_only": True}],
        "group_add": ["44444"],
        "depends_on": {"internal-mtls-certs-init": {"condition": "service_completed_successfully"}},
    }
    services = {"tempo": prod_tempo}
    for name in validator.EXPORTER_SERVICES:
        services[name] = {
            "environment": {
                "XDR_OTEL_EXPORTER_ENDPOINT": validator.OTLP_ENDPOINT,
                "XDR_OTEL_CA_CERT": f"{validator.CERT_DIR}/ca.crt",
            },
            "volumes": [deepcopy(cert_mount)],
        }
    return base, {"services": services}


def validate(base, production, tls_config=TLS_CONFIG, cert_generator='SERVICE_SANS = ["tempo"]'):
    return validator.validate_configs(
        base,
        production,
        base_tempo_config=BASE_CONFIG,
        tls_tempo_config=tls_config,
        cert_generator=cert_generator,
        production_env=(
            f"XDR_OTEL_EXPORTER_ENDPOINT={validator.OTLP_ENDPOINT}\n"
            f"XDR_OTEL_CA_CERT={validator.CERT_DIR}/ca.crt\n"
        ),
    )


class OtlpTlsComposeValidatorTest(unittest.TestCase):
    def test_valid_config_passes(self):
        base, production = valid_configs()
        self.assertEqual([], validate(base, production))

    def test_plaintext_production_exporter_fails(self):
        base, production = valid_configs()
        production["services"]["correlation-worker"]["environment"]["XDR_OTEL_EXPORTER_ENDPOINT"] = "http://tempo:4318/v1/traces"
        errors = validate(base, production)
        self.assertTrue(any("correlation-worker" in error and "HTTPS" in error for error in errors))

    def test_missing_exporter_ca_mount_fails(self):
        base, production = valid_configs()
        production["services"]["alert-writer-service"]["volumes"] = []
        errors = validate(base, production)
        self.assertIn("alert-writer-service must mount Tempo CA material read-only", errors)

    def test_production_host_port_fails(self):
        base, production = valid_configs()
        production["services"]["tempo"]["ports"] = [{"target": 4318, "published": "4318"}]
        self.assertIn("production Tempo must not publish host ports", validate(base, production))

    def test_writable_tls_config_mount_fails(self):
        base, production = valid_configs()
        production["services"]["tempo"]["volumes"][1]["read_only"] = False
        self.assertIn("production Tempo TLS configuration mount must be read-only", validate(base, production))

    def test_missing_tls_minimum_fails(self):
        base, production = valid_configs()
        errors = validate(base, production, tls_config=TLS_CONFIG.replace('            min_version: "1.2"\n', ""))
        self.assertTrue(any("min_version" in error for error in errors))

    def test_receiver_mtls_fails_because_exporters_have_no_client_identity(self):
        base, production = valid_configs()
        errors = validate(base, production, tls_config=TLS_CONFIG + "            client_ca_file: /ca.crt\n")
        self.assertTrue(any("client_ca_file" in error for error in errors))

    def test_unused_grpc_receiver_fails(self):
        base, production = valid_configs()
        errors = validate(base, production, tls_config=TLS_CONFIG + "        grpc:\n")
        self.assertIn("Tempo production receiver must not expose unused OTLP gRPC", errors)

    def test_missing_tempo_san_fails(self):
        base, production = valid_configs()
        self.assertIn("internal certificate SAN list must include tempo", validate(base, production, cert_generator="SERVICE_SANS = []"))


if __name__ == "__main__":
    unittest.main()
