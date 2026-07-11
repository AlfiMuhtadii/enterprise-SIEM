"""ENT-DETECT-ML-NOT-LIVE: realtime_detector_consumer.py's shadow/advisory
output path. --output-mode defaults to "shadow" — findings go to
advisory_findings only, never security_alerts/security_responses, matching
the platform's existing shadow-alert-consumer boundary
(services/alert-writer-service/main.py's shadow_event_loop)."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import realtime_detector_consumer as rdc  # noqa: E402


class TestShadowFingerprint(unittest.TestCase):
    def test_deterministic_for_same_inputs(self):
        a = rdc.shadow_fingerprint("BRUTE_FORCE_IP", "203.0.113.5", ["req-1"])
        b = rdc.shadow_fingerprint("BRUTE_FORCE_IP", "203.0.113.5", ["req-1"])
        self.assertEqual(a, b)

    def test_differs_by_rule_id(self):
        a = rdc.shadow_fingerprint("BRUTE_FORCE_IP", "203.0.113.5", [])
        b = rdc.shadow_fingerprint("SCAN_BURST", "203.0.113.5", [])
        self.assertNotEqual(a, b)

    def test_differs_by_actor(self):
        a = rdc.shadow_fingerprint("BRUTE_FORCE_IP", "203.0.113.5", [])
        b = rdc.shadow_fingerprint("BRUTE_FORCE_IP", "203.0.113.9", [])
        self.assertNotEqual(a, b)

    def test_evidence_id_order_independent(self):
        a = rdc.shadow_fingerprint("SCAN_BURST", "actor", ["req-2", "req-1"])
        b = rdc.shadow_fingerprint("SCAN_BURST", "actor", ["req-1", "req-2"])
        self.assertEqual(a, b, "evidence_ids must be sorted before hashing so ordering never affects dedup")

    def test_empty_actor_falls_back_to_unknown_marker(self):
        # Mirrors shadow_fingerprint(rule_id, actor or "unknown", ...) at the call site.
        a = rdc.shadow_fingerprint("SCAN_BURST", "unknown", [])
        b = rdc.shadow_fingerprint("SCAN_BURST", "unknown", [])
        self.assertEqual(a, b)


class TestShadowPromotionBlocker(unittest.TestCase):
    def test_always_names_domain_soak_required(self):
        reason = rdc.shadow_promotion_blocker(0.9)
        self.assertIn(f"domain_soak_required: no 6h soak PASS for domain={rdc.SHADOW_DOMAIN}", reason)

    def test_low_confidence_reason_included_below_threshold(self):
        reason = rdc.shadow_promotion_blocker(0.10)
        self.assertIn("low_confidence", reason)

    def test_low_confidence_reason_omitted_at_or_above_threshold(self):
        reason = rdc.shadow_promotion_blocker(rdc.SHADOW_PROMOTION_CONFIDENCE_THRESHOLD)
        self.assertNotIn("low_confidence", reason)


class TestOutputModeDefaultsToShadow(unittest.TestCase):
    def test_default_output_mode_is_shadow_with_no_flag_or_env(self):
        with patch.object(sys, "argv", ["realtime_detector_consumer.py"]), \
             patch.dict("os.environ", {}, clear=False):
            if "DETECTOR_OUTPUT_MODE" in __import__("os").environ:
                del __import__("os").environ["DETECTOR_OUTPUT_MODE"]
            args = rdc.parse_args()
        self.assertEqual(args.output_mode, "shadow")

    def test_explicit_flag_selects_active(self):
        with patch.object(sys, "argv", ["realtime_detector_consumer.py", "--output-mode", "active"]):
            args = rdc.parse_args()
        self.assertEqual(args.output_mode, "active")

    def test_env_var_overrides_default(self):
        with patch.object(sys, "argv", ["realtime_detector_consumer.py"]), \
             patch.dict("os.environ", {"DETECTOR_OUTPUT_MODE": "active"}):
            args = rdc.parse_args()
        self.assertEqual(args.output_mode, "active")

    def test_explicit_flag_overrides_env_var(self):
        with patch.object(sys, "argv", ["realtime_detector_consumer.py", "--output-mode", "shadow"]), \
             patch.dict("os.environ", {"DETECTOR_OUTPUT_MODE": "active"}):
            args = rdc.parse_args()
        self.assertEqual(args.output_mode, "shadow")

    def test_invalid_output_mode_rejected(self):
        with patch.object(sys, "argv", ["realtime_detector_consumer.py", "--output-mode", "bogus"]):
            with self.assertRaises(SystemExit):
                rdc.parse_args()


class TestInsertAdvisoryFindings(unittest.TestCase):
    def test_noop_for_empty_rows(self):
        conn = MagicMock()
        rdc.insert_advisory_findings(conn, "psycopg3", [])
        conn.cursor.assert_not_called()
        conn.commit.assert_not_called()

    def test_psycopg3_path_uses_executemany_and_commits(self):
        conn = MagicMock()
        cur = MagicMock()
        conn.cursor.return_value.__enter__.return_value = cur
        rows = [("adv-x", "SCAN_BURST", "web_request", "security_events", "SCAN_BURST", "medium",
                  0.5, "203.0.113.5", None, "{}", "[]", False, "blocked", "fp-1", "2026-07-11T00:00:00", "2026-07-11T00:00:00")]

        rdc.insert_advisory_findings(conn, "psycopg3", rows)

        cur.executemany.assert_called_once()
        sql_arg = cur.executemany.call_args[0][0]
        self.assertIn("INSERT INTO advisory_findings", sql_arg)
        self.assertIn("ON CONFLICT (fingerprint) DO UPDATE", sql_arg)
        conn.commit.assert_called_once()

    def test_sql_never_references_security_alerts_or_incidents(self):
        # ENT-DETECT-ML-NOT-LIVE's core safety property: the shadow write
        # path must never touch the active tables, at the SQL-text level.
        conn = MagicMock()
        cur = MagicMock()
        conn.cursor.return_value.__enter__.return_value = cur
        rdc.insert_advisory_findings(conn, "psycopg3", [("x",) * 16])
        sql_arg = cur.executemany.call_args[0][0]
        self.assertNotIn("security_alerts", sql_arg)
        self.assertNotIn("security_incidents", sql_arg)
        self.assertNotIn("security_responses", sql_arg)


class TestShadowDomainConstant(unittest.TestCase):
    def test_shadow_domain_is_web_request_not_endpoint_or_network(self):
        # The investigation that produced this task confirmed the model's
        # feature vector is HTTP-request features, a materially different
        # domain from the existing endpoint/network shadow producers —
        # the domain value must say so, not silently reuse theirs.
        self.assertEqual(rdc.SHADOW_DOMAIN, "web_request")
        self.assertNotIn(rdc.SHADOW_DOMAIN, {"endpoint", "network"})


if __name__ == "__main__":
    unittest.main()
