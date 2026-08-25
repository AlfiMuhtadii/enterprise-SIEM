"""Tests for scripts/xdr_production_profile_validate.py — ENTERPRISE-036"""

import argparse
import json
import sys
import tempfile
import unittest
from pathlib import Path

_REPO = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(_REPO / "scripts"))

import xdr_production_profile_validate as pdp


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _args(profile="local", env_file=".env.production.example",
          compose_file="docker-compose.prod.yml", output="", quiet=True,
          dry_run=False) -> argparse.Namespace:
    return argparse.Namespace(
        profile=profile, env_file=env_file, compose_file=compose_file,
        output=output, quiet=quiet, dry_run=dry_run,
    )


def _secure_env(**overrides) -> dict:
    base = {
        "XDR_TENANT_STRICT_MODE": "true",
        "XDR_ENFORCE_INTERNAL_AUTH": "true",
        "APP_DEBUG": "false",
        "APP_FORCE_HTTPS": "true",
        "SESSION_SECURE_COOKIE": "true",
        "XDR_INTERNAL_AUTH_SECRET":            "a" * 64,
        "XDR_NORMALIZER_INTERNAL_TOKEN":       "b" * 64,
        "XDR_ALERT_WRITER_INTERNAL_TOKEN":     "c" * 64,
        "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "d" * 64,
        "XDR_CORRELATION_INTERNAL_TOKEN":      "e" * 64,
        "XDR_AI_RAG_INTERNAL_TOKEN":           "k" * 64,
        "DB_PASSWORD":                         "f" * 32,
        "CLICKHOUSE_PASSWORD":                 "g" * 32,
        "GF_SECURITY_ADMIN_PASSWORD":          "h" * 32,
        "XDR_OPENSEARCH_PASSWORD":             "i" * 32,
        "XDR_OPENSEARCH_WRITER_PASSWORD":      "j" * 32,
    }
    base.update(overrides)
    return base


def _secure_compose() -> dict:
    return {
        "services": {
            "redpanda": {
                "ports": ["127.0.0.1:8082:8082"],
                "restart": "always",
                "healthcheck": {"test": ["CMD", "rpk", "cluster", "health"]},
            },
            "postgres": {
                "ports": [],
                "environment": {"POSTGRES_PASSWORD": "${DB_PASSWORD:?DB_PASSWORD must be set}"},
                "restart": "always",
                "healthcheck": {"test": ["CMD", "pg_isready"]},
            },
            "clickhouse": {
                "ports": [],
                "environment": {"CLICKHOUSE_PASSWORD": "${CLICKHOUSE_PASSWORD:?CLICKHOUSE_PASSWORD must be set}"},
                "restart": "always",
            },
            "opensearch": {
                "ports": [],
                "environment": {
                    "plugins.security.disabled": "false",
                    "OPENSEARCH_INITIAL_ADMIN_PASSWORD": "${OPENSEARCH_INITIAL_ADMIN_PASSWORD:?must be set}",
                },
                "restart": "always",
                "healthcheck": {"test": ["CMD", "curl", "-fsS", "http://127.0.0.1:9200"]},
            },
            "qdrant": {
                "ports": [],
                "restart": "always",
                "healthcheck": {"test": ["CMD", "curl", "-fsS", "http://127.0.0.1:6333/healthz"]},
            },
            "grafana": {
                "ports": ["127.0.0.1:3000:3000"],
                "environment": {
                    "GF_SECURITY_ADMIN_USER": "${GF_SECURITY_ADMIN_USER:?GF_SECURITY_ADMIN_USER must be set}",
                    "GF_SECURITY_ADMIN_PASSWORD": "${GF_SECURITY_ADMIN_PASSWORD:?GF_SECURITY_ADMIN_PASSWORD must be set}",
                },
                "volumes": [
                    "./infra/analytics/grafana/provisioning:/etc/grafana/provisioning:ro",
                    "./infra/analytics/grafana/dashboards:/var/lib/grafana/dashboards:ro",
                ],
                "restart": "always",
            },
            "app": {
                "restart": "always",
                "healthcheck": {"test": ["CMD", "curl", "-fsS", "http://127.0.0.1:8000/health/ready"]},
            },
            "ingestion-gateway":      {"restart": "always", "environment": {"XDR_ENFORCE_INTERNAL_AUTH": "true"}},
            "normalizer-worker":      {"restart": "always", "environment": {"XDR_ENFORCE_INTERNAL_AUTH": "true"}},
            "correlation-worker":     {"restart": "always", "environment": {"XDR_ENFORCE_INTERNAL_AUTH": "true"}},
            "alert-writer-service":   {"restart": "always", "environment": {"XDR_ENFORCE_INTERNAL_AUTH": "true"}},
            "incident-builder-service": {"restart": "always", "environment": {"XDR_ENFORCE_INTERNAL_AUTH": "true"}},
        }
    }


# ---------------------------------------------------------------------------
# Tests: Constants
# ---------------------------------------------------------------------------

class TestConstants(unittest.TestCase):
    def test_required_tokens_count(self):
        self.assertEqual(6, len(pdp._REQUIRED_TOKENS))

    def test_required_tokens_includes_internal_auth_secret(self):
        self.assertIn("XDR_INTERNAL_AUTH_SECRET", pdp._REQUIRED_TOKENS)

    def test_required_tokens_includes_all_service_tokens(self):
        for token in [
            "XDR_NORMALIZER_INTERNAL_TOKEN",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN",
            "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN",
            "XDR_CORRELATION_INTERNAL_TOKEN",
            "XDR_AI_RAG_INTERNAL_TOKEN",
        ]:
            self.assertIn(token, pdp._REQUIRED_TOKENS)

    def test_bad_secrets_includes_admin(self):
        self.assertIn("admin", pdp._BAD_SECRETS)

    def test_bad_secrets_includes_detector(self):
        self.assertIn("detector", pdp._BAD_SECRETS)

    def test_bad_secrets_includes_detectoradmin123(self):
        self.assertIn("DetectorAdmin123!", pdp._BAD_SECRETS)

    def test_bad_secrets_includes_changeme(self):
        self.assertIn("changeme", pdp._BAD_SECRETS)

    def test_bad_secrets_includes_replace_placeholders(self):
        self.assertIn("REPLACE_WITH_STRONG_INGEST_SECRET", pdp._BAD_SECRETS)
        self.assertIn("REPLACE_WITH_STRONG_INTERNAL_AUTH_SECRET", pdp._BAD_SECRETS)

    def test_profiles_contain_three_values(self):
        self.assertIn("local", pdp.PROFILES)
        self.assertIn("staging", pdp.PROFILES)
        self.assertIn("production", pdp.PROFILES)

    def test_datastore_credential_keys_cover_postgres_clickhouse_grafana(self):
        self.assertIn("postgres", pdp._DATASTORE_CREDENTIAL_KEYS)
        self.assertIn("clickhouse", pdp._DATASTORE_CREDENTIAL_KEYS)
        self.assertIn("grafana", pdp._DATASTORE_CREDENTIAL_KEYS)

    def test_datastore_credential_env_keys_cover_db_clickhouse_grafana(self):
        self.assertIn("DB_PASSWORD", pdp._DATASTORE_CREDENTIAL_ENV_KEYS)
        self.assertIn("CLICKHOUSE_PASSWORD", pdp._DATASTORE_CREDENTIAL_ENV_KEYS)
        self.assertIn("GF_SECURITY_ADMIN_PASSWORD", pdp._DATASTORE_CREDENTIAL_ENV_KEYS)


# ---------------------------------------------------------------------------
# Tests: _is_public_binding
# ---------------------------------------------------------------------------

class TestIsPublicBinding(unittest.TestCase):
    def test_full_port_string_is_public(self):
        self.assertTrue(pdp._is_public_binding("5432:5432"))

    def test_localhost_binding_is_not_public(self):
        self.assertFalse(pdp._is_public_binding("127.0.0.1:5432:5432"))

    def test_container_port_only_is_not_public(self):
        self.assertFalse(pdp._is_public_binding("5432"))

    def test_dict_without_host_ip_is_not_public(self):
        self.assertFalse(pdp._is_public_binding({"target": 5432}))

    def test_dict_with_localhost_host_ip_is_not_public(self):
        self.assertFalse(pdp._is_public_binding({"host_ip": "127.0.0.1", "target": 5432}))

    def test_dict_with_public_host_ip_is_public(self):
        self.assertTrue(pdp._is_public_binding({"host_ip": "0.0.0.0", "target": 5432}))


# ---------------------------------------------------------------------------
# Tests: PDP-01 / PDP-02 — file existence
# ---------------------------------------------------------------------------

class TestFileExistence(unittest.TestCase):
    def test_pdp01_pass_when_prod_compose_exists(self):
        result = pdp.check_prod_compose_exists(pdp.PROJECT_ROOT)
        self.assertEqual("PDP-01", result["check_id"])
        self.assertEqual(pdp.PASS, result["status"])

    def test_pdp01_fail_when_prod_compose_missing(self):
        result = pdp.check_prod_compose_exists(pdp.PROJECT_ROOT / "__nonexistent__")
        self.assertEqual(pdp.FAIL, result["status"])

    def test_pdp02_pass_when_env_example_exists(self):
        result = pdp.check_env_example_exists(pdp.PROJECT_ROOT)
        self.assertEqual("PDP-02", result["check_id"])
        self.assertEqual(pdp.PASS, result["status"])

    def test_pdp02_fail_when_env_example_missing(self):
        result = pdp.check_env_example_exists(pdp.PROJECT_ROOT / "__nonexistent__")
        self.assertEqual(pdp.FAIL, result["status"])


# ---------------------------------------------------------------------------
# Tests: PDP-03 — strict mode
# ---------------------------------------------------------------------------

class TestStrictMode(unittest.TestCase):
    def test_passes_when_true(self):
        r = pdp.check_strict_mode({"XDR_TENANT_STRICT_MODE": "true"}, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_in_production_when_false(self):
        r = pdp.check_strict_mode({"XDR_TENANT_STRICT_MODE": "false"}, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_when_false(self):
        r = pdp.check_strict_mode({"XDR_TENANT_STRICT_MODE": "false"}, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_fails_in_staging_when_false(self):
        r = pdp.check_strict_mode({"XDR_TENANT_STRICT_MODE": "false"}, "staging")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_check_id_is_pdp03(self):
        r = pdp.check_strict_mode({}, "local")
        self.assertEqual("PDP-03", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-04 — internal auth
# ---------------------------------------------------------------------------

class TestInternalAuth(unittest.TestCase):
    def test_passes_when_true(self):
        r = pdp.check_internal_auth({"XDR_ENFORCE_INTERNAL_AUTH": "true"}, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_in_production_when_false(self):
        r = pdp.check_internal_auth({"XDR_ENFORCE_INTERNAL_AUTH": "false"}, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_when_false(self):
        r = pdp.check_internal_auth({"XDR_ENFORCE_INTERNAL_AUTH": "false"}, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_check_id_is_pdp04(self):
        r = pdp.check_internal_auth({}, "local")
        self.assertEqual("PDP-04", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-05 — required tokens present
# ---------------------------------------------------------------------------

class TestRequiredTokens(unittest.TestCase):
    def test_passes_when_all_tokens_present(self):
        env = _secure_env()
        r = pdp.check_required_tokens(env, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_when_token_missing(self):
        env = _secure_env()
        del env["XDR_NORMALIZER_INTERNAL_TOKEN"]
        r = pdp.check_required_tokens(env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_when_token_empty(self):
        env = _secure_env(XDR_INTERNAL_AUTH_SECRET="")
        r = pdp.check_required_tokens(env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_when_missing(self):
        r = pdp.check_required_tokens({}, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_check_id_is_pdp05(self):
        r = pdp.check_required_tokens(_secure_env(), "local")
        self.assertEqual("PDP-05", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-06 — no placeholder secrets
# ---------------------------------------------------------------------------

class TestPlaceholderSecrets(unittest.TestCase):
    def test_passes_with_strong_secrets(self):
        r = pdp.check_placeholder_secrets(_secure_env(), "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_on_replace_placeholder(self):
        env = _secure_env(XDR_INTERNAL_AUTH_SECRET="REPLACE_WITH_STRONG_INTERNAL_AUTH_SECRET")
        r = pdp.check_placeholder_secrets(env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_on_admin_value(self):
        env = _secure_env(XDR_INTERNAL_AUTH_SECRET="admin")
        r = pdp.check_placeholder_secrets(env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_on_detector_value(self):
        env = _secure_env(XDR_CORRELATION_INTERNAL_TOKEN="detector")
        r = pdp.check_placeholder_secrets(env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_on_detectoradmin123(self):
        env = _secure_env(XDR_ALERT_WRITER_INTERNAL_TOKEN="DetectorAdmin123!")
        r = pdp.check_placeholder_secrets(env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_on_placeholder(self):
        env = _secure_env(XDR_INTERNAL_AUTH_SECRET="changeme")
        r = pdp.check_placeholder_secrets(env, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_check_id_is_pdp06(self):
        r = pdp.check_placeholder_secrets(_secure_env(), "local")
        self.assertEqual("PDP-06", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-07 — datastore ports not public
# ---------------------------------------------------------------------------

class TestDatastorePorts(unittest.TestCase):
    def test_passes_with_empty_ports(self):
        compose = {"services": {"postgres": {"ports": []}}}
        r = pdp.check_datastore_ports(compose, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_passes_with_localhost_binding(self):
        compose = {"services": {"postgres": {"ports": ["127.0.0.1:5432:5432"]}}}
        r = pdp.check_datastore_ports(compose, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_on_public_postgres(self):
        compose = {"services": {"postgres": {"ports": ["5432:5432"]}}}
        r = pdp.check_datastore_ports(compose, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_on_public_opensearch(self):
        compose = {"services": {"opensearch": {"ports": ["9200:9200"]}}}
        r = pdp.check_datastore_ports(compose, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_on_public(self):
        compose = {"services": {"postgres": {"ports": ["5432:5432"]}}}
        r = pdp.check_datastore_ports(compose, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_passes_with_secure_compose(self):
        r = pdp.check_datastore_ports(_secure_compose(), "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_check_id_is_pdp07(self):
        r = pdp.check_datastore_ports({}, "local")
        self.assertEqual("PDP-07", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-08 — Pandaproxy not public
# ---------------------------------------------------------------------------

class TestPandaproxyExposure(unittest.TestCase):
    def test_passes_with_localhost_pandaproxy(self):
        compose = {"services": {"redpanda": {"ports": ["127.0.0.1:8082:8082"]}}}
        r = pdp.check_pandaproxy_exposure(compose, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_on_public_pandaproxy(self):
        compose = {"services": {"redpanda": {"ports": ["8082:8082"]}}}
        r = pdp.check_pandaproxy_exposure(compose, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_on_public_pandaproxy(self):
        compose = {"services": {"redpanda": {"ports": ["8082:8082"]}}}
        r = pdp.check_pandaproxy_exposure(compose, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_passes_with_no_pandaproxy_binding(self):
        compose = {"services": {"redpanda": {"ports": []}}}
        r = pdp.check_pandaproxy_exposure(compose, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_check_id_is_pdp08(self):
        r = pdp.check_pandaproxy_exposure({}, "local")
        self.assertEqual("PDP-08", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-09 — Grafana provisioning read-only
# ---------------------------------------------------------------------------

class TestGrafanaReadonly(unittest.TestCase):
    def _grafana_with_vols(self, volumes: list) -> dict:
        return {"services": {"grafana": {"volumes": volumes}}}

    def test_passes_with_ro_provisioning(self):
        compose = self._grafana_with_vols([
            "./infra/analytics/grafana/provisioning:/etc/grafana/provisioning:ro",
        ])
        r = pdp.check_grafana_readonly(compose, "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_in_production_without_ro(self):
        compose = self._grafana_with_vols([
            "./infra/analytics/grafana/provisioning:/etc/grafana/provisioning",
        ])
        r = pdp.check_grafana_readonly(compose, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_without_ro(self):
        compose = self._grafana_with_vols([
            "./infra/analytics/grafana/provisioning:/etc/grafana/provisioning",
        ])
        r = pdp.check_grafana_readonly(compose, "local")
        self.assertIn(r["status"], [pdp.WARN, pdp.FAIL])

    def test_passes_with_secure_compose(self):
        r = pdp.check_grafana_readonly(_secure_compose(), "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_check_id_is_pdp09(self):
        r = pdp.check_grafana_readonly({}, "local")
        self.assertEqual("PDP-09", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-10 — restart policies
# ---------------------------------------------------------------------------

class TestRestartPolicies(unittest.TestCase):
    def test_passes_when_all_critical_services_have_always(self):
        r = pdp.check_restart_policies(_secure_compose(), "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_in_production_when_restart_missing(self):
        compose = {"services": {"redpanda": {}}}
        r = pdp.check_restart_policies(compose, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_when_restart_missing(self):
        compose = {"services": {"redpanda": {}}}
        r = pdp.check_restart_policies(compose, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_check_id_is_pdp10(self):
        r = pdp.check_restart_policies({}, "local")
        self.assertEqual("PDP-10", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-11 — healthchecks
# ---------------------------------------------------------------------------

class TestHealthchecks(unittest.TestCase):
    def test_passes_when_healthchecks_present(self):
        r = pdp.check_healthchecks(_secure_compose(), "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_non_fail_when_healthchecks_absent_in_overlay(self):
        # In an overlay, healthchecks may be inherited — WARN not FAIL
        r = pdp.check_healthchecks({"services": {}}, "production")
        self.assertIn(r["status"], [pdp.WARN, pdp.INFO])

    def test_check_id_is_pdp11(self):
        r = pdp.check_healthchecks({}, "local")
        self.assertEqual("PDP-11", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-12 / PDP-13 — documentation checks
# ---------------------------------------------------------------------------

class TestDocumentationChecks(unittest.TestCase):
    def test_pdp12_passes_with_existing_ops_docs(self):
        r = pdp.check_backup_docs(pdp.PROJECT_ROOT, "production")
        self.assertEqual("PDP-12", r["check_id"])
        # Project should have backup/log path documentation
        self.assertIn(r["status"], [pdp.PASS, pdp.WARN])

    def test_pdp12_warns_when_no_docs(self):
        r = pdp.check_backup_docs(pdp.PROJECT_ROOT / "__nonexistent__", "production")
        self.assertEqual(pdp.WARN, r["status"])

    def test_pdp13_passes_with_existing_docs(self):
        r = pdp.check_risk_docs(pdp.PROJECT_ROOT, "production")
        self.assertEqual("PDP-13", r["check_id"])
        self.assertIn(r["status"], [pdp.PASS, pdp.WARN])

    def test_pdp13_warns_when_no_docs(self):
        r = pdp.check_risk_docs(pdp.PROJECT_ROOT / "__nonexistent__", "production")
        self.assertEqual(pdp.WARN, r["status"])


# ---------------------------------------------------------------------------
# Tests: PDP-14 — no active scanning/remediation
# ---------------------------------------------------------------------------

class TestNoActiveScanning(unittest.TestCase):
    def test_passes_with_clean_compose_and_env(self):
        r = pdp.check_no_active_scanning(_secure_compose(), _secure_env(), "production")
        self.assertEqual(pdp.PASS, r["status"])

    def test_fails_when_active_scan_enabled_in_compose(self):
        compose = _secure_compose()
        compose["services"]["app"] = {
            "restart": "always",
            "environment": {"EASM_ACTIVE_SCAN_ENABLED": "true"},
        }
        r = pdp.check_no_active_scanning(compose, _secure_env(), "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_when_autonomous_response_enabled_in_env(self):
        env = _secure_env(XDR_AUTONOMOUS_RESPONSE_ENABLED="true")
        r = pdp.check_no_active_scanning(_secure_compose(), env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_on_active_flag(self):
        env = _secure_env(EASM_ACTIVE_SCAN_ENABLED="true")
        r = pdp.check_no_active_scanning({}, env, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_check_id_is_pdp14(self):
        r = pdp.check_no_active_scanning({}, {}, "local")
        self.assertEqual("PDP-14", r["check_id"])


# ---------------------------------------------------------------------------
# Tests: PDP-15 — datastore credentials fail closed (ENT-SEC-WEAK-DEFAULT-SECRETS)
# ---------------------------------------------------------------------------

class TestDatastoreCredentialsFailClosed(unittest.TestCase):
    def test_passes_with_secure_compose_and_env(self):
        r = pdp.check_datastore_credentials_fail_closed(_secure_compose(), _secure_env(), "production")
        self.assertEqual(pdp.PASS, r["status"])
        self.assertEqual("PDP-15", r["check_id"])

    def test_fails_when_postgres_password_not_overridden(self):
        compose = _secure_compose()
        del compose["services"]["postgres"]["environment"]
        r = pdp.check_datastore_credentials_fail_closed(compose, _secure_env(), "production")
        self.assertEqual(pdp.FAIL, r["status"])
        self.assertIn("postgres.POSTGRES_PASSWORD", r["detail"])

    def test_fails_when_override_does_not_fail_closed(self):
        compose = _secure_compose()
        compose["services"]["clickhouse"]["environment"]["CLICKHOUSE_PASSWORD"] = "${CLICKHOUSE_PASSWORD:-detector}"
        r = pdp.check_datastore_credentials_fail_closed(compose, _secure_env(), "production")
        self.assertEqual(pdp.FAIL, r["status"])
        self.assertIn("does not fail closed", r["detail"])

    def test_fails_when_grafana_admin_password_missing(self):
        compose = _secure_compose()
        del compose["services"]["grafana"]["environment"]["GF_SECURITY_ADMIN_PASSWORD"]
        r = pdp.check_datastore_credentials_fail_closed(compose, _secure_env(), "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_when_db_password_env_is_placeholder(self):
        env = _secure_env(DB_PASSWORD="REPLACE_WITH_STRONG_PASSWORD")
        r = pdp.check_datastore_credentials_fail_closed(_secure_compose(), env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_when_clickhouse_password_env_is_weak_default(self):
        env = _secure_env(CLICKHOUSE_PASSWORD="detector")
        r = pdp.check_datastore_credentials_fail_closed(_secure_compose(), env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_when_grafana_password_env_is_weak_default(self):
        env = _secure_env(GF_SECURITY_ADMIN_PASSWORD="admin")
        r = pdp.check_datastore_credentials_fail_closed(_secure_compose(), env, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_profile(self):
        r = pdp.check_datastore_credentials_fail_closed({}, {}, "local")
        self.assertEqual(pdp.WARN, r["status"])

    def test_fails_when_opensearch_admin_password_not_overridden(self):
        compose = _secure_compose()
        del compose["services"]["opensearch"]["environment"]["OPENSEARCH_INITIAL_ADMIN_PASSWORD"]
        r = pdp.check_datastore_credentials_fail_closed(compose, _secure_env(), "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_fails_when_opensearch_password_env_is_placeholder(self):
        env = _secure_env(XDR_OPENSEARCH_PASSWORD="REPLACE_WITH_STRONG_OPENSEARCH_PASSWORD")
        r = pdp.check_datastore_credentials_fail_closed(_secure_compose(), env, "production")
        self.assertEqual(pdp.FAIL, r["status"])


# ---------------------------------------------------------------------------
# Tests: PDP-16 — OpenSearch security plugin enabled (ENT-SEC-OPENSEARCH-OPEN)
# ---------------------------------------------------------------------------

class TestOpensearchSecurityEnabled(unittest.TestCase):
    def test_passes_when_explicitly_disabled_false(self):
        r = pdp.check_opensearch_security_enabled(_secure_compose(), "production")
        self.assertEqual(pdp.PASS, r["status"])
        self.assertEqual("PDP-16", r["check_id"])

    def test_fails_when_no_override_in_prod_compose(self):
        r = pdp.check_opensearch_security_enabled({"services": {"opensearch": {}}}, "production")
        self.assertEqual(pdp.FAIL, r["status"])
        self.assertIn("does not override", r["detail"])

    def test_fails_when_still_true(self):
        compose = {"services": {"opensearch": {"environment": {"plugins.security.disabled": "true"}}}}
        r = pdp.check_opensearch_security_enabled(compose, "production")
        self.assertEqual(pdp.FAIL, r["status"])

    def test_warns_in_local_profile(self):
        r = pdp.check_opensearch_security_enabled({"services": {"opensearch": {}}}, "local")
        self.assertEqual(pdp.WARN, r["status"])


# ---------------------------------------------------------------------------
# Tests: PDP-17 — OpenSearch least-privilege RBAC (ENT-SEC-OPENSEARCH-OPEN)
# ---------------------------------------------------------------------------

def _write_rbac_files(security_dir: Path, mapping_extra: str = "") -> None:
    security_dir.mkdir(parents=True, exist_ok=True)
    (security_dir / "roles.yml").write_text("_meta:\n  type: roles\n", encoding="utf-8")
    (security_dir / "internal_users.yml.example").write_text("_meta:\n  type: internalusers\n", encoding="utf-8")
    mapping = (
        "_meta:\n  type: rolesmapping\n"
        "xdr_alert_writer_role:\n  users:\n    - xdr_alert_writer\n"
        "xdr_reader_role:\n  users:\n    - xdr_reader\n"
        + mapping_extra
    )
    (security_dir / "roles_mapping.yml").write_text(mapping, encoding="utf-8")


class TestOpensearchRbacLeastPrivilege(unittest.TestCase):
    def test_passes_against_real_repo_rbac_files(self):
        r = pdp.check_opensearch_rbac_least_privilege(pdp.PROJECT_ROOT, "production")
        self.assertEqual(pdp.PASS, r["status"])
        self.assertEqual("PDP-17", r["check_id"])

    def test_fails_when_rbac_files_missing(self):
        with tempfile.TemporaryDirectory() as tmp:
            r = pdp.check_opensearch_rbac_least_privilege(Path(tmp), "production")
            self.assertEqual(pdp.FAIL, r["status"])
            self.assertIn("Missing RBAC config file", r["detail"])

    def test_fails_when_mapping_grants_admin_role(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_rbac_files(
                root / "infra" / "opensearch" / "security",
                mapping_extra="admin:\n  users:\n    - xdr_alert_writer\n",
            )
            r = pdp.check_opensearch_rbac_least_privilege(root, "production")
            self.assertEqual(pdp.FAIL, r["status"])
            self.assertIn("admin", r["detail"])

    def test_fails_when_mapping_grants_all_access_role(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_rbac_files(
                root / "infra" / "opensearch" / "security",
                mapping_extra="all_access:\n  users:\n    - xdr_reader\n",
            )
            r = pdp.check_opensearch_rbac_least_privilege(root, "production")
            self.assertEqual(pdp.FAIL, r["status"])

    def test_passes_with_clean_rbac_files(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            _write_rbac_files(root / "infra" / "opensearch" / "security")
            r = pdp.check_opensearch_rbac_least_privilege(root, "production")
            self.assertEqual(pdp.PASS, r["status"])

    def test_does_not_false_positive_on_comment_mentioning_admin(self):
        # The real roles_mapping.yml's header comment legitimately says
        # "never to admin/all_access" while explaining the file's purpose —
        # that must not itself trigger a FAIL.
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            security_dir = root / "infra" / "opensearch" / "security"
            _write_rbac_files(security_dir)
            mapping_path = security_dir / "roles_mapping.yml"
            mapping_path.write_text(
                "# never maps a user to admin or all_access\n" + mapping_path.read_text(encoding="utf-8"),
                encoding="utf-8",
            )
            r = pdp.check_opensearch_rbac_least_privilege(root, "production")
            self.assertEqual(pdp.PASS, r["status"])

    def test_warns_in_local_profile_when_missing(self):
        with tempfile.TemporaryDirectory() as tmp:
            r = pdp.check_opensearch_rbac_least_privilege(Path(tmp), "local")
            self.assertEqual(pdp.WARN, r["status"])


# ---------------------------------------------------------------------------
# Tests: run_all_checks (integration)
# ---------------------------------------------------------------------------

class TestRunAllChecks(unittest.TestCase):
    def test_returns_17_checks(self):
        args = _args(profile="local")
        checks = pdp.run_all_checks(
            args, root=pdp.PROJECT_ROOT,
            env=_secure_env(), compose_data=_secure_compose(),
        )
        self.assertEqual(17, len(checks))

    def test_all_check_ids_present(self):
        args = _args(profile="local")
        checks = pdp.run_all_checks(
            args, root=pdp.PROJECT_ROOT,
            env=_secure_env(), compose_data=_secure_compose(),
        )
        ids = {c["check_id"] for c in checks}
        for n in range(1, 18):
            self.assertIn(f"PDP-{n:02d}", ids)

    def test_overall_pass_with_secure_config(self):
        args = _args(profile="production")
        checks = pdp.run_all_checks(
            args, root=pdp.PROJECT_ROOT,
            env=_secure_env(), compose_data=_secure_compose(),
        )
        fail_count = sum(1 for c in checks if c["status"] == pdp.FAIL)
        self.assertEqual(0, fail_count)

    def test_overall_fail_when_strict_mode_off_in_production(self):
        args = _args(profile="production")
        env = _secure_env(XDR_TENANT_STRICT_MODE="false")
        checks = pdp.run_all_checks(
            args, root=pdp.PROJECT_ROOT, env=env, compose_data=_secure_compose(),
        )
        fail_checks = [c for c in checks if c["status"] == pdp.FAIL]
        self.assertGreater(len(fail_checks), 0)
        ids = [c["check_id"] for c in fail_checks]
        self.assertIn("PDP-03", ids)

    def test_overall_fail_when_public_postgres_in_production(self):
        args = _args(profile="production")
        compose = _secure_compose()
        compose["services"]["postgres"]["ports"] = ["5432:5432"]
        checks = pdp.run_all_checks(
            args, root=pdp.PROJECT_ROOT, env=_secure_env(), compose_data=compose,
        )
        fail_checks = [c["check_id"] for c in checks if c["status"] == pdp.FAIL]
        self.assertIn("PDP-07", fail_checks)


# ---------------------------------------------------------------------------
# Tests: build_report
# ---------------------------------------------------------------------------

class TestBuildReport(unittest.TestCase):
    def _checks_with_statuses(self, statuses: list[str]) -> list[dict]:
        return [
            {"check_id": f"PDP-{i + 1:02d}", "name": f"check {i}", "status": s,
             "detail": "", "remediation": ""}
            for i, s in enumerate(statuses)
        ]

    def test_overall_pass_when_no_fail(self):
        checks = self._checks_with_statuses([pdp.PASS, pdp.WARN, pdp.PASS])
        report = pdp.build_report(checks, _args(), "2026-06-24T00:00:00Z", "2026-06-24T00:00:01Z")
        self.assertEqual(pdp.PASS, report["overall"])

    def test_overall_fail_when_any_fail(self):
        checks = self._checks_with_statuses([pdp.PASS, pdp.FAIL, pdp.PASS])
        report = pdp.build_report(checks, _args(), "2026-06-24T00:00:00Z", "2026-06-24T00:00:01Z")
        self.assertEqual(pdp.FAIL, report["overall"])

    def test_report_task_is_enterprise036(self):
        report = pdp.build_report([], _args(), "T", "T")
        self.assertEqual("ENTERPRISE-036", report["task"])

    def test_report_has_14_checks_field(self):
        checks = self._checks_with_statuses([pdp.PASS] * 14)
        report = pdp.build_report(checks, _args(), "T", "T")
        self.assertEqual(14, report["counts"]["total"])

    def test_report_status_line_format(self):
        checks = self._checks_with_statuses([pdp.PASS, pdp.FAIL, pdp.WARN])
        report = pdp.build_report(checks, _args(), "T", "T")
        self.assertIn("FAIL=1", report["status_line"])
        self.assertIn("PASS=1", report["status_line"])


# ---------------------------------------------------------------------------
# Tests: main() and exit codes
# ---------------------------------------------------------------------------

class TestMainExitCode(unittest.TestCase):
    def test_exits_zero_on_all_pass(self):
        args = _args(profile="production", quiet=True)
        rc = pdp.main(args, root=pdp.PROJECT_ROOT,
                      env=_secure_env(), compose_data=_secure_compose())
        self.assertEqual(0, rc)

    def test_exits_one_on_fail(self):
        args = _args(profile="production", quiet=True)
        env = _secure_env(XDR_TENANT_STRICT_MODE="false")
        rc = pdp.main(args, root=pdp.PROJECT_ROOT,
                      env=env, compose_data=_secure_compose())
        self.assertEqual(1, rc)

    def test_exits_zero_in_local_with_insecure_config(self):
        # In local profile, many issues are WARN not FAIL → overall PASS
        args = _args(profile="local", quiet=True)
        env = _secure_env(XDR_TENANT_STRICT_MODE="false",
                          XDR_ENFORCE_INTERNAL_AUTH="false")
        rc = pdp.main(args, root=pdp.PROJECT_ROOT,
                      env=env, compose_data=_secure_compose())
        self.assertEqual(0, rc)

    def test_dry_run_exits_zero(self):
        args = _args(profile="local", quiet=True, dry_run=True)
        rc = pdp.main(args, root=pdp.PROJECT_ROOT,
                      env=_secure_env(), compose_data=_secure_compose())
        self.assertEqual(0, rc)

    def test_output_flag_writes_json(self):
        import os
        with tempfile.NamedTemporaryFile(suffix=".json", delete=False) as f:
            tmp = f.name
        try:
            args = _args(profile="local", quiet=True, output=tmp)
            pdp.main(args, root=pdp.PROJECT_ROOT,
                     env=_secure_env(), compose_data=_secure_compose())
            with open(tmp) as f:
                data = json.load(f)
            self.assertIn("overall", data)
            self.assertIn("checks", data)
            self.assertEqual(17, len(data["checks"]))
        finally:
            os.unlink(tmp)


# ---------------------------------------------------------------------------
# Tests: parse_env_file (integration with actual .env.production.example)
# ---------------------------------------------------------------------------

class TestParseEnvFileIntegration(unittest.TestCase):
    def test_actual_env_production_example_parseable(self):
        path = pdp.PROJECT_ROOT / ".env.production.example"
        if not path.exists():
            self.skipTest(".env.production.example not present")
        env = pdp._parse_env_file(path)
        self.assertIsInstance(env, dict)
        self.assertGreater(len(env), 0)

    def test_actual_env_has_strict_mode_true(self):
        path = pdp.PROJECT_ROOT / ".env.production.example"
        if not path.exists():
            self.skipTest(".env.production.example not present")
        env = pdp._parse_env_file(path)
        self.assertEqual("true", env.get("XDR_TENANT_STRICT_MODE", "").lower())

    def test_actual_env_has_internal_auth_true(self):
        path = pdp.PROJECT_ROOT / ".env.production.example"
        if not path.exists():
            self.skipTest(".env.production.example not present")
        env = pdp._parse_env_file(path)
        self.assertEqual("true", env.get("XDR_ENFORCE_INTERNAL_AUTH", "").lower())


# ---------------------------------------------------------------------------
# Tests: parse_compose_file (integration with actual docker-compose.prod.yml)
# ---------------------------------------------------------------------------

class TestParseComposeFileIntegration(unittest.TestCase):
    def test_prod_compose_parseable(self):
        path = pdp.PROJECT_ROOT / "docker-compose.prod.yml"
        if not path.exists():
            self.skipTest("docker-compose.prod.yml not present")
        data = pdp._parse_compose_file(path)
        self.assertIsInstance(data, dict)

    def test_prod_compose_has_services(self):
        path = pdp.PROJECT_ROOT / "docker-compose.prod.yml"
        if not path.exists():
            self.skipTest("docker-compose.prod.yml not present")
        data = pdp._parse_compose_file(path)
        self.assertIn("services", data)
        self.assertGreater(len(data.get("services") or {}), 0)

    def test_prod_compose_grafana_has_ro_provisioning(self):
        path = pdp.PROJECT_ROOT / "docker-compose.prod.yml"
        if not path.exists():
            self.skipTest("docker-compose.prod.yml not present")
        data = pdp._parse_compose_file(path)
        r = pdp.check_grafana_readonly(data, "production")
        self.assertEqual(pdp.PASS, r["status"])


if __name__ == "__main__":
    unittest.main()
