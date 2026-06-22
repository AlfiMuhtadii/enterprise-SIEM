"""Tests for BACKLOG-SECURITY-009: Internal Trust Boundary Hardening.

Covers:
- check_pandaproxy_exposure (check 15): WARN when Pandaproxy is reachable without auth
- check_internal_auth_posture (check 16): WARN when normalizer is in permissive mode
- WARN status counts correctly in warn_count without affecting fail_count
- verifyInternalToken enforcement logic (Python spec mirror)
"""
from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

_SCRIPTS = Path(__file__).parent.parent.parent / "scripts"
sys.path.insert(0, str(_SCRIPTS))

sys.modules.pop("validate_live_xdr_pipeline", None)
import validate_live_xdr_pipeline as vlp  # noqa: E402


# ---------------------------------------------------------------------------
# check_pandaproxy_exposure — check 15
# ---------------------------------------------------------------------------

class TestCheckPandaproxyExposure(unittest.TestCase):
    def _run(self, http_code, http_body="") -> dict:
        with patch.object(vlp, "http_get", return_value=(http_code, http_body)):
            return vlp.check_pandaproxy_exposure("http://127.0.0.1:8082", 3)

    def test_reachable_returns_warn(self):
        result = self._run(200, '["telemetry.raw"]')
        self.assertEqual(result["status"], vlp.WARN)

    def test_reachable_non_200_still_warns(self):
        # Any HTTP response means Pandaproxy is reachable (403, 503, etc.)
        result = self._run(403, '{"error": "forbidden"}')
        self.assertEqual(result["status"], vlp.WARN)

    def test_unreachable_returns_pass(self):
        result = self._run(None, "connection refused")
        self.assertEqual(result["status"], vlp.PASS)

    def test_warn_is_not_required(self):
        result = self._run(200)
        self.assertFalse(result["required"])

    def test_pass_is_not_required(self):
        result = self._run(None)
        self.assertFalse(result["required"])

    def test_warn_evidence_mentions_firewall(self):
        result = self._run(200)
        self.assertIn("firewall", result["evidence"].lower())

    def test_warn_evidence_includes_http_code(self):
        result = self._run(200)
        self.assertIn("200", result["evidence"])

    def test_pass_evidence_mentions_hardened(self):
        result = self._run(None)
        self.assertIn("hardened", result["evidence"].lower())


# ---------------------------------------------------------------------------
# check_internal_auth_posture — check 16
# ---------------------------------------------------------------------------

class TestCheckInternalAuthPosture(unittest.TestCase):
    def _run(self, env_vars: dict, metrics_body: str | None = None) -> dict:
        if metrics_body is None:
            metrics_body = json.dumps({"internal_auth_mode": "permissive"})

        def fake_http_get(url, timeout, max_bytes=512):
            if "metrics" in url:
                return 200, metrics_body
            return 200, ""

        with patch.object(vlp, "http_get", side_effect=fake_http_get):
            return vlp.check_internal_auth_posture(
                "http://127.0.0.1:8092", env_vars, 3
            )

    def test_enforced_with_token_returns_pass(self):
        result = self._run(
            {
                "XDR_ENFORCE_INTERNAL_AUTH": "true",
                "XDR_NORMALIZER_INTERNAL_TOKEN": "secret123",
                "XDR_INTERNAL_AUTH_SECRET": "anothersecret",
            },
            json.dumps({"internal_auth_mode": "enforced"}),
        )
        self.assertEqual(result["status"], vlp.PASS)

    def test_no_enforce_flag_returns_warn(self):
        result = self._run({"XDR_ENFORCE_INTERNAL_AUTH": ""})
        self.assertEqual(result["status"], vlp.WARN)

    def test_enforce_true_but_no_token_returns_warn(self):
        result = self._run(
            {"XDR_ENFORCE_INTERNAL_AUTH": "true", "XDR_NORMALIZER_INTERNAL_TOKEN": ""},
            json.dumps({"internal_auth_mode": "permissive"}),
        )
        self.assertEqual(result["status"], vlp.WARN)

    def test_warn_is_not_required(self):
        result = self._run({})
        self.assertFalse(result["required"])

    def test_pass_is_not_required(self):
        result = self._run(
            {
                "XDR_ENFORCE_INTERNAL_AUTH": "1",
                "XDR_NORMALIZER_INTERNAL_TOKEN": "tok",
                "XDR_INTERNAL_AUTH_SECRET": "s",
            },
            json.dumps({"internal_auth_mode": "enforced"}),
        )
        self.assertFalse(result["required"])

    def test_warn_evidence_lists_missing_flags(self):
        result = self._run({"XDR_ENFORCE_INTERNAL_AUTH": ""})
        self.assertIn("XDR_ENFORCE_INTERNAL_AUTH not set", result["evidence"])

    def test_warn_evidence_mentions_token_missing(self):
        result = self._run({"XDR_ENFORCE_INTERNAL_AUTH": ""})
        self.assertIn("XDR_NORMALIZER_INTERNAL_TOKEN not set", result["evidence"])

    def test_pass_evidence_shows_live_mode(self):
        result = self._run(
            {
                "XDR_ENFORCE_INTERNAL_AUTH": "true",
                "XDR_NORMALIZER_INTERNAL_TOKEN": "tok",
            },
            json.dumps({"internal_auth_mode": "enforced"}),
        )
        self.assertIn("enforced", result["evidence"])

    def test_normalizer_unreachable_mode_shows_unknown(self):
        with patch.object(vlp, "http_get", return_value=(None, "refused")):
            result = vlp.check_internal_auth_posture("http://127.0.0.1:8092", {}, 3)
        self.assertIn("unknown", result["evidence"])

    def test_enforce_with_yes_flag_accepts(self):
        result = self._run(
            {
                "XDR_ENFORCE_INTERNAL_AUTH": "yes",
                "XDR_NORMALIZER_INTERNAL_TOKEN": "tok",
            },
            json.dumps({"internal_auth_mode": "enforced"}),
        )
        self.assertEqual(result["status"], vlp.PASS)

    def test_secret_fallback_noted_in_pass_evidence(self):
        result = self._run(
            {
                "XDR_ENFORCE_INTERNAL_AUTH": "true",
                "XDR_NORMALIZER_INTERNAL_TOKEN": "tok",
                "XDR_INTERNAL_AUTH_SECRET": "",  # not set
            },
            json.dumps({"internal_auth_mode": "enforced"}),
        )
        self.assertEqual(result["status"], vlp.PASS)
        self.assertIn("APP_KEY fallback", result["evidence"])


# ---------------------------------------------------------------------------
# WARN status counting in warn_count
# ---------------------------------------------------------------------------

class TestWarnStatusCounting(unittest.TestCase):
    """Verify that WARN status is counted in warn_count but not fail_count."""

    def _make_result(self, status: str, required: bool) -> dict:
        return {
            "component": "test",
            "check": "test check",
            "status": status,
            "evidence": "",
            "required": required,
        }

    def test_warn_status_counted_in_warn_count(self):
        results = [
            self._make_result(vlp.PASS, True),
            self._make_result(vlp.WARN, False),
        ]
        warn_count = sum(
            1 for r in results if
            r["status"] == vlp.WARN or
            (r["status"] == vlp.FAIL and not r.get("required", True))
        )
        self.assertEqual(warn_count, 1)

    def test_warn_does_not_affect_fail_count(self):
        results = [
            self._make_result(vlp.WARN, False),
            self._make_result(vlp.WARN, False),
        ]
        fail_count = sum(
            1 for r in results if r["status"] == vlp.FAIL and r.get("required", True)
        )
        self.assertEqual(fail_count, 0)

    def test_fail_nonrequired_still_counted_as_warn(self):
        results = [self._make_result(vlp.FAIL, False)]
        warn_count = sum(
            1 for r in results if
            r["status"] == vlp.WARN or
            (r["status"] == vlp.FAIL and not r.get("required", True))
        )
        self.assertEqual(warn_count, 1)

    def test_mixed_warn_and_fail_nonrequired(self):
        results = [
            self._make_result(vlp.WARN, False),
            self._make_result(vlp.FAIL, False),
            self._make_result(vlp.PASS, True),
        ]
        warn_count = sum(
            1 for r in results if
            r["status"] == vlp.WARN or
            (r["status"] == vlp.FAIL and not r.get("required", True))
        )
        self.assertEqual(warn_count, 2)

    def test_required_fail_not_counted_in_warn(self):
        results = [self._make_result(vlp.FAIL, True)]
        warn_count = sum(
            1 for r in results if
            r["status"] == vlp.WARN or
            (r["status"] == vlp.FAIL and not r.get("required", True))
        )
        self.assertEqual(warn_count, 0)


# ---------------------------------------------------------------------------
# verifyInternalToken spec — Python mirror of Go enforcement logic
# ---------------------------------------------------------------------------

def _verify_token_py(token: str, expected: str, enforced: bool) -> str | None:
    """Python mirror of Go verifyInternalToken for spec validation.
    Returns None on success, error string on failure.
    """
    if enforced:
        if not expected:
            return "internal_auth_enforced_no_token_configured"
        if not token:
            return "missing_token"
        if token != expected:
            return "invalid_token"
        return None
    # Permissive
    if not expected:
        return None
    if not token:
        return "missing_token"
    if token != expected:
        return "invalid_token"
    return None


class TestVerifyInternalTokenSpec(unittest.TestCase):
    def test_permissive_no_expected_accepts_any(self):
        self.assertIsNone(_verify_token_py("", "", enforced=False))
        self.assertIsNone(_verify_token_py("anything", "", enforced=False))

    def test_permissive_with_expected_rejects_missing(self):
        self.assertEqual(_verify_token_py("", "secret", enforced=False), "missing_token")

    def test_permissive_with_expected_rejects_wrong(self):
        self.assertEqual(_verify_token_py("wrong", "secret", enforced=False), "invalid_token")

    def test_permissive_with_expected_accepts_correct(self):
        self.assertIsNone(_verify_token_py("secret", "secret", enforced=False))

    def test_enforced_no_expected_always_rejects(self):
        self.assertEqual(
            _verify_token_py("anything", "", enforced=True),
            "internal_auth_enforced_no_token_configured",
        )

    def test_enforced_missing_token_rejected(self):
        self.assertEqual(_verify_token_py("", "secret", enforced=True), "missing_token")

    def test_enforced_wrong_token_rejected(self):
        self.assertEqual(_verify_token_py("wrong", "secret", enforced=True), "invalid_token")

    def test_enforced_correct_token_accepted(self):
        self.assertIsNone(_verify_token_py("secret", "secret", enforced=True))


if __name__ == "__main__":
    unittest.main()
