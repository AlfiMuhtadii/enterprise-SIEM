import unittest

from scripts import validate_environment as validator


class PostgresTlsEnvironmentTests(unittest.TestCase):
    def validate(self, **values):
        errors = []
        validator.validate_postgres_tls(values, errors)
        return errors

    def test_verify_full_requires_root_ca(self):
        self.assertIn(
            "DB_SSLROOTCERT is required when DB_SSLMODE=verify-full",
            self.validate(DB_SSLMODE="verify-full"),
        )

    def test_client_certificate_and_key_must_be_paired(self):
        errors = self.validate(DB_SSLMODE="require", DB_SSLCERT="client.crt")
        self.assertIn("DB_SSLCERT and DB_SSLKEY must be configured together", errors)

    def test_complete_verify_full_configuration_passes(self):
        self.assertEqual([], self.validate(
            DB_SSLMODE="verify-full",
            DB_SSLROOTCERT="ca.crt",
            DB_SSLCERT="client.crt",
            DB_SSLKEY="client.key",
        ))

    def test_unknown_ssl_mode_fails(self):
        errors = self.validate(DB_SSLMODE="trust-me")
        self.assertTrue(any("DB_SSLMODE must be one of" in error for error in errors))


if __name__ == "__main__":
    unittest.main()
