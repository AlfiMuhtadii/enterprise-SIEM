"""Tests for BACKLOG-PROD-024: Production Runtime Profile & Safety Gates.

Covers:
- All 10 security checks (C-01 through C-10) across all three profiles
- Deferred risk visibility (D-01 through D-04) always surfaced as WARN
- Accepted risk visibility (A-01 through A-04) always surfaced as INFO
- Profile-based severity escalation (local=WARN, staging/production=FAIL)
- Placeholder detection
- Exit code mapping (0=PASS, 1=FAIL, 2=ERROR)
- CLI --profile / --env-file / --output flags
"""
from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

import xdr_posture_check as pc  # noqa: E402


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _env(**kwargs: str) -> dict:
    """Build a minimal safe env dict, override with kwargs."""
    base = {
        "APP_DEBUG": "false",
        "APP_FORCE_HTTPS": "true",
        "SESSION_SECURE_COOKIE": "true",
        "XDR_TENANT_STRICT_MODE": "true",
        "XDR_ENFORCE_INTERNAL_AUTH": "true",
        "XDR_INGEST_SECRET": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
        "XDR_INTERNAL_AUTH_SECRET": "bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
        "XDR_NORMALIZER_INTERNAL_TOKEN": "cccc",
        "XDR_ALERT_WRITER_INTERNAL_TOKEN": "dddd",
        "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "eeee",
        "XDR_CORRELATION_INTERNAL_TOKEN": "ffff",
        "XDR_SHADOW_CONSUMER_ENABLED": "false",
        "XDR_DLQ_CONSUMER_ENABLED": "false",
        "XDR_CORRELATION_DLQ_CONSUMER_ENABLED": "false",
        "XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED": "false",
    }
    base.update(kwargs)
    return base


def _find(checks: list[dict], check_id: str) -> dict:
    for c in checks:
        if c["check_id"] == check_id:
            return c
    raise KeyError(check_id)


# ---------------------------------------------------------------------------
# C-01  APP_DEBUG
# ---------------------------------------------------------------------------

class TestCheckAppDebug(unittest.TestCase):
    def test_false_is_pass(self):
        r = pc.check_app_debug({"APP_DEBUG": "false"}, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_true_is_warn_on_local(self):
        r = pc.check_app_debug({"APP_DEBUG": "true"}, "local")
        self.assertEqual(r["status"], pc.WARN)

    def test_true_is_warn_on_staging(self):
        r = pc.check_app_debug({"APP_DEBUG": "true"}, "staging")
        self.assertEqual(r["status"], pc.WARN)

    def test_true_is_fail_on_production(self):
        r = pc.check_app_debug({"APP_DEBUG": "true"}, "production")
        self.assertEqual(r["status"], pc.FAIL)

    def test_unset_treated_as_true(self):
        r = pc.check_app_debug({}, "production")
        self.assertEqual(r["status"], pc.FAIL)


# ---------------------------------------------------------------------------
# C-02  APP_FORCE_HTTPS
# ---------------------------------------------------------------------------

class TestCheckAppForceHttps(unittest.TestCase):
    def test_true_is_pass(self):
        r = pc.check_app_force_https({"APP_FORCE_HTTPS": "true"}, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_false_is_warn_local(self):
        r = pc.check_app_force_https({"APP_FORCE_HTTPS": "false"}, "local")
        self.assertEqual(r["status"], pc.WARN)

    def test_false_is_fail_production(self):
        r = pc.check_app_force_https({"APP_FORCE_HTTPS": "false"}, "production")
        self.assertEqual(r["status"], pc.FAIL)


# ---------------------------------------------------------------------------
# C-03  SESSION_SECURE_COOKIE
# ---------------------------------------------------------------------------

class TestCheckSessionSecureCookie(unittest.TestCase):
    def test_true_is_pass(self):
        r = pc.check_session_secure_cookie({"SESSION_SECURE_COOKIE": "true"}, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_false_is_warn_local(self):
        r = pc.check_session_secure_cookie({"SESSION_SECURE_COOKIE": "false"}, "local")
        self.assertEqual(r["status"], pc.WARN)

    def test_false_is_fail_production(self):
        r = pc.check_session_secure_cookie({"SESSION_SECURE_COOKIE": "false"}, "production")
        self.assertEqual(r["status"], pc.FAIL)


# ---------------------------------------------------------------------------
# C-04  XDR_TENANT_STRICT_MODE
# ---------------------------------------------------------------------------

class TestCheckTenantStrictMode(unittest.TestCase):
    def test_true_is_pass(self):
        r = pc.check_tenant_strict_mode({"XDR_TENANT_STRICT_MODE": "true"}, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_false_is_warn_local(self):
        r = pc.check_tenant_strict_mode({"XDR_TENANT_STRICT_MODE": "false"}, "local")
        self.assertEqual(r["status"], pc.WARN)

    def test_false_is_fail_production(self):
        r = pc.check_tenant_strict_mode({"XDR_TENANT_STRICT_MODE": "false"}, "production")
        self.assertEqual(r["status"], pc.FAIL)

    def test_false_is_fail_staging(self):
        r = pc.check_tenant_strict_mode({"XDR_TENANT_STRICT_MODE": "false"}, "staging")
        self.assertEqual(r["status"], pc.FAIL)


# ---------------------------------------------------------------------------
# C-05  XDR_ENFORCE_INTERNAL_AUTH
# ---------------------------------------------------------------------------

class TestCheckInternalAuth(unittest.TestCase):
    def test_true_is_pass(self):
        r = pc.check_internal_auth({"XDR_ENFORCE_INTERNAL_AUTH": "true"}, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_false_is_warn_local(self):
        r = pc.check_internal_auth({"XDR_ENFORCE_INTERNAL_AUTH": "false"}, "local")
        self.assertEqual(r["status"], pc.WARN)

    def test_false_is_fail_staging(self):
        r = pc.check_internal_auth({"XDR_ENFORCE_INTERNAL_AUTH": "false"}, "staging")
        self.assertEqual(r["status"], pc.FAIL)

    def test_false_is_fail_production(self):
        r = pc.check_internal_auth({"XDR_ENFORCE_INTERNAL_AUTH": "false"}, "production")
        self.assertEqual(r["status"], pc.FAIL)


# ---------------------------------------------------------------------------
# C-06 / C-07  Secret placeholder detection
# ---------------------------------------------------------------------------

class TestCheckSecrets(unittest.TestCase):
    def test_real_secret_passes(self):
        r = pc.check_ingest_secret({"XDR_INGEST_SECRET": "a" * 64}, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_placeholder_fails_production(self):
        r = pc.check_ingest_secret(
            {"XDR_INGEST_SECRET": "REPLACE_WITH_STRONG_INGEST_SECRET"}, "production"
        )
        self.assertEqual(r["status"], pc.FAIL)

    def test_empty_fails_production(self):
        r = pc.check_ingest_secret({"XDR_INGEST_SECRET": ""}, "production")
        self.assertEqual(r["status"], pc.FAIL)

    def test_placeholder_warns_local(self):
        r = pc.check_ingest_secret(
            {"XDR_INGEST_SECRET": "REPLACE_WITH_STRONG_INGEST_SECRET"}, "local"
        )
        self.assertEqual(r["status"], pc.WARN)

    def test_internal_auth_secret_placeholder_fails_production(self):
        r = pc.check_internal_auth_secret(
            {"XDR_INTERNAL_AUTH_SECRET": "REPLACE_WITH_STRONG_INTERNAL_AUTH_SECRET"}, "production"
        )
        self.assertEqual(r["status"], pc.FAIL)

    def test_change_me_is_placeholder(self):
        self.assertTrue(pc._is_placeholder("change-me"))
        self.assertTrue(pc._is_placeholder("CHANGE_ME"))
        self.assertTrue(pc._is_placeholder("dev-secret-change-me"))

    def test_real_value_not_placeholder(self):
        self.assertFalse(pc._is_placeholder("a" * 32))


# ---------------------------------------------------------------------------
# C-08  Per-service tokens
# ---------------------------------------------------------------------------

class TestCheckServiceTokens(unittest.TestCase):
    def test_all_set_passes(self):
        env = {
            "XDR_NORMALIZER_INTERNAL_TOKEN": "tok1",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN": "tok2",
            "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "tok3",
            "XDR_CORRELATION_INTERNAL_TOKEN": "tok4",
        }
        r = pc.check_service_tokens(env, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_missing_token_fails_production(self):
        env = {
            "XDR_NORMALIZER_INTERNAL_TOKEN": "tok1",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN": "",
            "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "tok3",
            "XDR_CORRELATION_INTERNAL_TOKEN": "tok4",
        }
        r = pc.check_service_tokens(env, "production")
        self.assertEqual(r["status"], pc.FAIL)

    def test_placeholder_token_warns_local(self):
        env = {
            "XDR_NORMALIZER_INTERNAL_TOKEN": "REPLACE_WITH_STRONG_NORMALIZER_TOKEN",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN": "x",
            "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN": "x",
            "XDR_CORRELATION_INTERNAL_TOKEN": "x",
        }
        r = pc.check_service_tokens(env, "local")
        self.assertEqual(r["status"], pc.WARN)


# ---------------------------------------------------------------------------
# C-09  Shadow consumer
# ---------------------------------------------------------------------------

class TestCheckShadowConsumer(unittest.TestCase):
    def test_false_is_pass(self):
        r = pc.check_shadow_consumer({"XDR_SHADOW_CONSUMER_ENABLED": "false"}, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_true_is_info_on_local(self):
        r = pc.check_shadow_consumer({"XDR_SHADOW_CONSUMER_ENABLED": "true"}, "local")
        self.assertEqual(r["status"], pc.INFO)

    def test_true_is_warn_on_staging(self):
        r = pc.check_shadow_consumer({"XDR_SHADOW_CONSUMER_ENABLED": "true"}, "staging")
        self.assertEqual(r["status"], pc.WARN)

    def test_true_is_fail_on_production(self):
        r = pc.check_shadow_consumer({"XDR_SHADOW_CONSUMER_ENABLED": "true"}, "production")
        self.assertEqual(r["status"], pc.FAIL)


# ---------------------------------------------------------------------------
# C-10  DLQ consumers
# ---------------------------------------------------------------------------

class TestCheckDlqConsumers(unittest.TestCase):
    def test_all_off_passes(self):
        env = {
            "XDR_DLQ_CONSUMER_ENABLED": "false",
            "XDR_CORRELATION_DLQ_CONSUMER_ENABLED": "false",
            "XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED": "false",
        }
        r = pc.check_dlq_consumers(env, "production")
        self.assertEqual(r["status"], pc.PASS)

    def test_enabled_dlq_warns_on_production(self):
        env = {
            "XDR_DLQ_CONSUMER_ENABLED": "true",
            "XDR_CORRELATION_DLQ_CONSUMER_ENABLED": "false",
            "XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED": "false",
        }
        r = pc.check_dlq_consumers(env, "production")
        self.assertEqual(r["status"], pc.WARN)

    def test_enabled_dlq_is_info_on_local(self):
        env = {
            "XDR_DLQ_CONSUMER_ENABLED": "true",
            "XDR_CORRELATION_DLQ_CONSUMER_ENABLED": "false",
            "XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED": "false",
        }
        r = pc.check_dlq_consumers(env, "local")
        self.assertEqual(r["status"], pc.INFO)


# ---------------------------------------------------------------------------
# Deferred risks
# ---------------------------------------------------------------------------

class TestDeferredRisks(unittest.TestCase):
    def test_ig1_is_warn(self):
        self.assertEqual(pc.deferred_ig1()["status"], pc.WARN)

    def test_ig2_is_warn(self):
        self.assertEqual(pc.deferred_ig2()["status"], pc.WARN)

    def test_ig3_is_warn(self):
        self.assertEqual(pc.deferred_ig3()["status"], pc.WARN)

    def test_infra3_is_warn(self):
        self.assertEqual(pc.deferred_infra3()["status"], pc.WARN)

    def test_deferred_checks_always_present(self):
        checks = pc.run_checks(_env(), "local")
        ids = [c["check_id"] for c in checks]
        for d in ("D-01", "D-02", "D-03", "D-04"):
            self.assertIn(d, ids)


# ---------------------------------------------------------------------------
# Accepted risks
# ---------------------------------------------------------------------------

class TestAcceptedRisks(unittest.TestCase):
    def test_db3_is_info(self):
        self.assertEqual(pc.accepted_db3()["status"], pc.INFO)

    def test_db4_is_info(self):
        self.assertEqual(pc.accepted_db4()["status"], pc.INFO)

    def test_infra4_is_info(self):
        self.assertEqual(pc.accepted_infra4()["status"], pc.INFO)

    def test_rag1_is_info(self):
        self.assertEqual(pc.accepted_rag1()["status"], pc.INFO)

    def test_accepted_checks_always_present(self):
        checks = pc.run_checks(_env(), "production")
        ids = [c["check_id"] for c in checks]
        for a in ("A-01", "A-02", "A-03", "A-04"):
            self.assertIn(a, ids)


# ---------------------------------------------------------------------------
# Full run scenarios
# ---------------------------------------------------------------------------

class TestRunChecks(unittest.TestCase):
    def test_all_secure_env_passes_all_profiles(self):
        for profile in pc.PROFILES:
            with self.subTest(profile=profile):
                checks = pc.run_checks(_env(), profile)
                report = pc.summarize(checks, profile)
                self.assertEqual(report["fail_count"], 0)

    def test_unsafe_env_warns_on_local(self):
        env = _env(
            APP_DEBUG="true",
            XDR_TENANT_STRICT_MODE="false",
            XDR_ENFORCE_INTERNAL_AUTH="false",
        )
        checks = pc.run_checks(env, "local")
        report = pc.summarize(checks, "local")
        self.assertEqual(report["fail_count"], 0)
        self.assertGreater(report["warn_count"], 0)

    def test_unsafe_env_fails_on_production(self):
        env = _env(
            APP_DEBUG="true",
            XDR_TENANT_STRICT_MODE="false",
            XDR_ENFORCE_INTERNAL_AUTH="false",
        )
        checks = pc.run_checks(env, "production")
        report = pc.summarize(checks, "production")
        self.assertGreater(report["fail_count"], 0)

    def test_placeholder_secrets_fail_staging(self):
        env = _env(
            XDR_INGEST_SECRET="REPLACE_WITH_STRONG_INGEST_SECRET",
            XDR_ENFORCE_INTERNAL_AUTH="false",
        )
        checks = pc.run_checks(env, "staging")
        report = pc.summarize(checks, "staging")
        self.assertGreater(report["fail_count"], 0)

    def test_shadow_consumer_true_fails_production(self):
        env = _env(XDR_SHADOW_CONSUMER_ENABLED="true")
        checks = pc.run_checks(env, "production")
        c = _find(checks, "C-09")
        self.assertEqual(c["status"], pc.FAIL)

    def test_18_checks_total(self):
        checks = pc.run_checks(_env(), "production")
        self.assertEqual(len(checks), 18)


# ---------------------------------------------------------------------------
# CLI exit codes
# ---------------------------------------------------------------------------

class TestMainExitCodes(unittest.TestCase):
    def _run(self, env_content: str, profile: str = "production") -> int:
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".env", delete=False, encoding="utf-8"
        ) as f:
            f.write(env_content)
            tmp = f.name
        return pc.main(["--profile", profile, "--env-file", tmp, "--quiet"])

    def _safe_env_content(self) -> str:
        lines = [
            "APP_DEBUG=false",
            "APP_FORCE_HTTPS=true",
            "SESSION_SECURE_COOKIE=true",
            "XDR_TENANT_STRICT_MODE=true",
            "XDR_ENFORCE_INTERNAL_AUTH=true",
            "XDR_INGEST_SECRET=" + "a" * 64,
            "XDR_INTERNAL_AUTH_SECRET=" + "b" * 64,
            "XDR_NORMALIZER_INTERNAL_TOKEN=tok1",
            "XDR_ALERT_WRITER_INTERNAL_TOKEN=tok2",
            "XDR_INCIDENT_BUILDER_INTERNAL_TOKEN=tok3",
            "XDR_CORRELATION_INTERNAL_TOKEN=tok4",
            "XDR_SHADOW_CONSUMER_ENABLED=false",
            "XDR_DLQ_CONSUMER_ENABLED=false",
            "XDR_CORRELATION_DLQ_CONSUMER_ENABLED=false",
            "XDR_ALERT_WRITE_DLQ_CONSUMER_ENABLED=false",
        ]
        return "\n".join(lines)

    def test_safe_env_exits_0_production(self):
        self.assertEqual(self._run(self._safe_env_content(), "production"), 0)

    def test_safe_env_exits_0_local(self):
        self.assertEqual(self._run(self._safe_env_content(), "local"), 0)

    def test_unsafe_env_exits_1_production(self):
        content = "APP_DEBUG=true\nXDR_TENANT_STRICT_MODE=false\n"
        self.assertEqual(self._run(content, "production"), 1)

    def test_unsafe_env_exits_0_local(self):
        content = "APP_DEBUG=true\nXDR_TENANT_STRICT_MODE=false\n"
        self.assertEqual(self._run(content, "local"), 0)

    def test_missing_env_file_exits_2(self):
        result = pc.main(["--env-file", "/nonexistent/path/.env", "--quiet"])
        self.assertEqual(result, 2)

    def test_output_writes_json(self):
        import os
        with tempfile.TemporaryDirectory() as tmpdir:
            env_file = Path(tmpdir) / ".env"
            env_file.write_text(self._safe_env_content())
            out_file = Path(tmpdir) / "posture.json"
            pc.main(["--profile", "production", "--env-file", str(env_file),
                     "--output", str(out_file), "--quiet"])
            self.assertTrue(out_file.exists())
            report = json.loads(out_file.read_text())
            self.assertIn("overall", report)
            self.assertIn("checks", report)
            self.assertEqual(report["profile"], "production")


if __name__ == "__main__":
    unittest.main()
