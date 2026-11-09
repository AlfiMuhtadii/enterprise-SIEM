import sys
import unittest
from copy import deepcopy
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import xdr_ollama_tls_compose_validate as validator  # noqa: E402


NGINX_CONFIG = """events {}
http {
  server {
    listen 11434 ssl;
    server_name ollama-tls;
    ssl_certificate /etc/xdr/internal-mtls/server.crt;
    ssl_certificate_key /etc/xdr/internal-mtls/server.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    proxy_pass http://ollama:11434;
    proxy_buffering off;
    proxy_request_buffering off;
    proxy_read_timeout 300s;
  }
}
"""


def valid_configs():
    cert_mount = {"target": validator.CERT_DIR, "read_only": True}
    local_ollama = {
        "profiles": ["ai-embeddings"],
        "ports": [{"target": 11434, "host_ip": "127.0.0.1"}],
    }
    base = {"services": {"ollama": local_ollama}}
    proxy = {
        "image": validator.PROXY_IMAGE,
        "profiles": ["ai-embeddings"],
        "ports": [],
        "volumes": [deepcopy(cert_mount), {"target": "/etc/nginx/nginx.conf", "read_only": True}],
        "group_add": ["44444"],
        "depends_on": {
            "ollama": {"condition": "service_healthy"},
            "internal-mtls-certs-init": {"condition": "service_completed_successfully"},
        },
        "healthcheck": {
            "test": [
                "CMD-SHELL",
                "curl --fail --silent --show-error --cacert /etc/xdr/internal-mtls/ca.crt https://ollama-tls:11434/api/tags",
            ]
        },
    }
    services = {
        "ollama": {"profiles": ["ai-embeddings"], "ports": []},
        "ollama-tls": proxy,
    }
    for name in validator.LARAVEL_SERVICES:
        services[name] = {
            "environment": {
                "SOC_OLLAMA_BASE_URL": validator.OLLAMA_ENDPOINT,
                "SOC_OLLAMA_VERIFY_TLS": "true",
                "SOC_OLLAMA_CA_CERT": f"{validator.CERT_DIR}/ca.crt",
                "SOC_AI_PROVIDER": "local",
                "SOC_OLLAMA_MODEL": "llama3.1",
                "SOC_EMBEDDING_MODEL_URL": "",
                "SOC_EMBEDDING_MODEL_NAME": "all-minilm",
            },
            "volumes": [deepcopy(cert_mount)],
        }
    return base, {"services": services}


def validate(base, production, nginx_config=NGINX_CONFIG, cert_generator='SERVICE_SANS = ["ollama-tls"]'):
    return validator.validate_configs(
        base,
        production,
        nginx_config=nginx_config,
        cert_generator=cert_generator,
        local_env="SOC_OLLAMA_BASE_URL=http://127.0.0.1:11434\n",
        production_env=(
            f"SOC_OLLAMA_BASE_URL={validator.OLLAMA_ENDPOINT}\n"
            "SOC_OLLAMA_VERIFY_TLS=true\n"
            f"SOC_OLLAMA_CA_CERT={validator.CERT_DIR}/ca.crt\n"
        ),
    )


class OllamaTlsComposeValidatorTest(unittest.TestCase):
    def test_valid_config_passes(self):
        base, production = valid_configs()
        self.assertEqual([], validate(base, production))

    def test_plaintext_production_client_fails(self):
        base, production = valid_configs()
        production["services"]["app"]["environment"]["SOC_OLLAMA_BASE_URL"] = "http://ollama:11434"
        errors = validate(base, production)
        self.assertTrue(any("app" in error and "HTTPS" in error for error in errors))

    def test_production_backend_host_port_fails(self):
        base, production = valid_configs()
        production["services"]["ollama"]["ports"] = [{"target": 11434, "published": "11434"}]
        self.assertIn("production Ollama backend must not publish host ports", validate(base, production))

    def test_production_proxy_host_port_fails(self):
        base, production = valid_configs()
        production["services"]["ollama-tls"]["ports"] = [{"target": 11434, "published": "11435"}]
        self.assertIn("production Ollama TLS proxy must not publish host ports", validate(base, production))

    def test_mutable_proxy_image_fails(self):
        base, production = valid_configs()
        production["services"]["ollama-tls"]["image"] = "nginx:latest"
        self.assertIn("production Ollama TLS proxy must use the pinned Nginx image", validate(base, production))

    def test_missing_ca_mount_fails(self):
        base, production = valid_configs()
        production["services"]["queue"]["volumes"] = []
        self.assertIn("queue must mount Ollama CA material read-only", validate(base, production))

    def test_missing_embedding_pass_through_fails(self):
        base, production = valid_configs()
        del production["services"]["scheduler"]["environment"]["SOC_EMBEDDING_MODEL_URL"]
        self.assertIn("scheduler must receive production embedding settings", validate(base, production))

    def test_disabled_healthcheck_verification_fails(self):
        base, production = valid_configs()
        production["services"]["ollama-tls"]["healthcheck"]["test"].append("--insecure")
        self.assertIn("Ollama TLS proxy health check must not disable certificate verification", validate(base, production))

    def test_missing_tls_minimum_fails(self):
        base, production = valid_configs()
        errors = validate(base, production, nginx_config=NGINX_CONFIG.replace("    ssl_protocols TLSv1.2 TLSv1.3;\n", ""))
        self.assertTrue(any("ssl_protocols" in error for error in errors))

    def test_client_certificate_requirement_fails(self):
        base, production = valid_configs()
        errors = validate(base, production, nginx_config=NGINX_CONFIG + "ssl_verify_client on;\n")
        self.assertTrue(any("ssl_verify_client" in error for error in errors))

    def test_missing_ollama_tls_san_fails(self):
        base, production = valid_configs()
        self.assertIn(
            "internal certificate SAN list must include ollama-tls",
            validate(base, production, cert_generator="SERVICE_SANS = []"),
        )


if __name__ == "__main__":
    unittest.main()
