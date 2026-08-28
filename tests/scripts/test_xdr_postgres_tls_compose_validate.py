import unittest

from scripts import xdr_postgres_tls_compose_validate as validator


def client_service(discrete=True):
    environment = {
        "DB_SSLMODE": "verify-full",
        "DB_SSLROOTCERT": "/etc/xdr/postgres-certs/ca.crt",
        "DB_SSLCERT": "/etc/xdr/postgres-certs/client.crt",
        "DB_SSLKEY": "/etc/xdr/postgres-certs/client.key",
    }
    if not discrete:
        environment = {
            "SECURITY_INGEST_DSN": (
                "host=postgres sslmode=verify-full "
                "sslrootcert=/etc/xdr/postgres-certs/ca.crt "
                "sslcert=/etc/xdr/postgres-certs/client.crt "
                "sslkey=/etc/xdr/postgres-certs/client.key"
            )
        }
    return {
        "environment": environment,
        "group_add": ["44444"],
        "volumes": [{"target": "/etc/xdr/postgres-certs", "read_only": True}],
    }


def config(required="false", published=False, enabled=None):
    if enabled is None:
        enabled = "true" if required == "true" else "false"
    services = {
        "postgres-tls-init": {
            "environment": {
                "POSTGRES_TLS_ENABLED": enabled,
                "POSTGRES_TLS_REQUIRED": required,
            },
            "entrypoint": [
                "clientcert=verify-ca chown 0:44444 /tls/client.key chmod 640 /tls/client.key"
            ],
        },
        "postgres": {
            "depends_on": {"postgres-tls-init": {"condition": "service_completed_successfully"}},
            "volumes": [{"target": "/etc/postgresql/tls", "read_only": True}],
            "command": ["docker-entrypoint.sh postgres ssl=on ssl_cert_file=x ssl_key_file=x ssl_ca_file=x hba_file=x"],
            "ports": [{"target": 5432}] if published else [],
        },
    }
    for name in validator.DISCRETE_TLS_CLIENTS:
        services[name] = client_service()
    for name in validator.DSN_TLS_CLIENTS:
        services[name] = client_service(False)
    return {"services": services}


class PostgresTlsComposeValidatorTests(unittest.TestCase):
    def test_valid_configs_pass(self):
        self.assertEqual([], validator.validate_configs(config(), config("true")))

    def test_production_must_require_certificates(self):
        errors = validator.validate_configs(config(), config())
        self.assertIn("production postgres TLS must be enabled", errors)
        self.assertIn("production postgres TLS init must fail closed", errors)

    def test_writable_certificate_mount_fails(self):
        base = config()
        base["services"]["postgres"]["volumes"][0]["read_only"] = False
        self.assertIn("postgres certificate volume must be read-only", validator.validate_configs(base, config("true")))

    def test_production_host_port_fails(self):
        self.assertIn("production postgres must not publish host ports", validator.validate_configs(config(), config("true", True)))

    def test_client_certificate_mount_must_be_read_only(self):
        production = config("true")
        production["services"]["app"]["volumes"][0]["read_only"] = False
        self.assertIn(
            "app must mount PostgreSQL client certificates read-only",
            validator.validate_configs(config(), production),
        )

    def test_discrete_client_must_verify_server_identity(self):
        production = config("true")
        production["services"]["alert-writer-service"]["environment"]["DB_SSLMODE"] = "require"
        self.assertIn(
            "alert-writer-service must set DB_SSLMODE=verify-full",
            validator.validate_configs(config(), production),
        )

    def test_dsn_client_must_supply_client_key(self):
        production = config("true")
        production["services"]["telemetry-worker"]["environment"]["SECURITY_INGEST_DSN"] = "sslmode=verify-full"
        self.assertIn(
            "telemetry-worker production DSN missing sslkey=/etc/xdr/postgres-certs/client.key",
            validator.validate_configs(config(), production),
        )


if __name__ == "__main__":
    unittest.main()
