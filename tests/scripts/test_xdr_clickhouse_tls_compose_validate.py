import copy
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

import xdr_clickhouse_tls_compose_validate as validator  # noqa: E402


class ClickHouseTlsComposeValidatorTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.base = validator.resolved_config(False)
        cls.production = validator.resolved_config(True)

    def test_repository_configs_pass(self):
        self.assertEqual(validator.validate_configs(self.base, self.production), [])

    def test_plaintext_production_client_is_rejected(self):
        production = copy.deepcopy(self.production)
        production["services"]["telemetry-worker"]["environment"]["XDR_CLICKHOUSE_HTTP_URL"] = "http://clickhouse:8123"

        errors = validator.validate_configs(self.base, production)

        self.assertIn("telemetry-worker must use ClickHouse HTTPS", errors)

    def test_missing_ca_mount_is_rejected(self):
        production = copy.deepcopy(self.production)
        production["services"]["app"]["volumes"] = []

        errors = validator.validate_configs(self.base, production)

        self.assertIn("app must mount ClickHouse certificates read-only", errors)

    def test_plaintext_listener_remains_disabled_in_tls_xml(self):
        errors = validator.validate_configs(self.base, self.production)
        self.assertNotIn("TLS config must remove the plaintext ClickHouse HTTP listener", errors)


if __name__ == "__main__":
    unittest.main()
