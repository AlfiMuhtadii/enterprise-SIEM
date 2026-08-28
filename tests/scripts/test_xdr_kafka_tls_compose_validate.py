import unittest

from scripts import xdr_kafka_tls_compose_validate as validator


def valid_config():
    services = {
        "redpanda": {
            "command": [
                "INTERNAL_TLS://0.0.0.0:9093,OUTSIDE_TLS://0.0.0.0:19093",
                "INTERNAL_TLS://redpanda:9093,OUTSIDE_TLS://127.0.0.1:19093",
            ],
            "ports": [{"target": 19093, "host_ip": "127.0.0.1"}],
        }
    }
    for name in validator.GO_SERVICES:
        services[name] = {
            "environment": {
                "XDR_KAFKA_TRANSPORT": "native",
                "XDR_REDPANDA_KAFKA_BROKERS": "redpanda:9093",
                "XDR_REDPANDA_KAFKA_TLS_ENABLED": "true",
            },
            "volumes": [{"target": validator.TLS_CERT_TARGET, "read_only": True}],
        }
    return {"services": services}


VALID_YAML = """
redpanda:
  kafka_api_tls:
    - name: INTERNAL_TLS
      require_client_auth: false
    - name: OUTSIDE_TLS
      require_client_auth: false
"""


class KafkaTLSComposeValidatorTests(unittest.TestCase):
    def test_valid_config_passes(self):
        self.assertEqual([], validator.validate_config(valid_config(), VALID_YAML))

    def test_missing_listener_fails(self):
        config = valid_config()
        config["services"]["redpanda"]["command"] = []
        errors = validator.validate_config(config, VALID_YAML)
        self.assertTrue(any("INTERNAL_TLS" in error for error in errors))

    def test_plaintext_service_override_fails(self):
        config = valid_config()
        config["services"]["normalizer-worker"]["environment"]["XDR_REDPANDA_KAFKA_TLS_ENABLED"] = "false"
        errors = validator.validate_config(config, VALID_YAML)
        self.assertIn("normalizer-worker: Kafka TLS is not enabled", errors)

    def test_writable_certificate_mount_fails(self):
        config = valid_config()
        config["services"]["correlation-worker"]["volumes"][0]["read_only"] = False
        errors = validator.validate_config(config, VALID_YAML)
        self.assertIn("correlation-worker: certificate mount must be read-only", errors)


if __name__ == "__main__":
    unittest.main()
