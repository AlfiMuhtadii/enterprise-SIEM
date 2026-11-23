from __future__ import annotations

import io
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))
import xdr_endpoint_shadow_correlation_validate as validator


class TestEndpointShadowMutualTls(unittest.TestCase):
    def test_plaintext_and_offline_defaults_need_no_context(self):
        self.assertIsNone(validator.build_mtls_context(validator.parse_args([])))
        args = validator.parse_args(["--use-correlation-service", "0"])
        self.assertIsNone(validator.build_mtls_context(args))

    def test_enabled_requires_service_mode_and_https(self):
        args = validator.parse_args(["--mtls-enabled", "--use-correlation-service", "0"])
        with self.assertRaisesRegex(ValueError, "--use-correlation-service 1"):
            validator.build_mtls_context(args)
        with self.assertRaisesRegex(ValueError, "--correlation-url"):
            validator.build_mtls_context(validator.parse_args(["--mtls-enabled"]))

    def test_context_loads_identity(self):
        args = validator.parse_args([
            "--mtls-enabled", "--correlation-url", "https://correlation",
            "--mtls-ca", "ca.pem", "--mtls-client-cert", "client.pem",
            "--mtls-client-key", "key.pem",
        ])
        context = MagicMock()
        with patch.object(validator.ssl, "create_default_context", return_value=context):
            self.assertIs(validator.build_mtls_context(args), context)
        context.load_cert_chain.assert_called_once_with(certfile="client.pem", keyfile="key.pem")

    def test_health_and_correlation_use_context(self):
        context = MagicMock()
        response = MagicMock()
        response.__enter__.return_value.status = 200
        response.__enter__.return_value.read.return_value = b'{"shadow_alerts":[]}'
        with patch.object(validator.urllib.request, "urlopen", return_value=response) as urlopen:
            self.assertTrue(validator.correlation_health("https://correlation", context))
            result = validator.post_shadow_correlate("https://correlation", [], context)
        self.assertEqual(result, {"shadow_alerts": []})
        self.assertTrue(all(call.kwargs["context"] is context for call in urlopen.call_args_list))

    def test_invalid_config_stops_before_fixture_or_report(self):
        args = validator.parse_args(["--mtls-enabled"])
        with patch.object(validator, "load_fixtures") as load, patch.object(Path, "write_text") as write:
            self.assertEqual(validator.main(args), 2)
        load.assert_not_called()
        write.assert_not_called()

    def test_distinct_events_from_same_rule_are_not_duplicates(self):
        alerts = [
            {"rule_id": "rule", "event_id": "event-1", "trace_id": "trace"},
            {"rule_id": "rule", "event_id": "event-2", "trace_id": "trace"},
        ]
        self.assertEqual(validator.check_no_duplicates(alerts, "fixture"), (True, None))

        alerts.append(dict(alerts[0]))
        ok, message = validator.check_no_duplicates(alerts, "fixture")
        self.assertFalse(ok)
        self.assertIn("duplicate alert identities", message)


class TestReplayEvidence(unittest.TestCase):
    def run_validator(self, *options):
        args = validator.parse_args(list(options))
        with patch.object(validator, "_write") as write, redirect_stdout(io.StringIO()):
            status = validator.main(args)
        return status, write.call_args.args[0]

    def test_unavailable_live_worker_does_not_run_simulation(self):
        with (
            patch.object(validator, "correlation_health", return_value=False),
            patch.object(validator, "post_shadow_correlate") as post,
            patch.object(validator, "simulate_shadow_rules") as simulate,
        ):
            status, report = self.run_validator()
        self.assertEqual(status, 2)
        self.assertEqual(report["validation_status"], "FAIL")
        self.assertEqual(report["verification_mode"], "live_http_fixture_replay")
        self.assertEqual(report["benign_results"], [])
        self.assertIn("unavailable", report["errors"][0])
        post.assert_not_called()
        simulate.assert_not_called()

    def test_explicit_offline_run_cannot_be_promotion_evidence(self):
        with patch.object(validator, "correlation_health") as health:
            status, report = self.run_validator("--use-correlation-service", "0")
        self.assertEqual(status, 0)
        self.assertEqual(report["verification_mode"], "offline_simulation")
        self.assertTrue(all(r["simulated"] for r in report["benign_results"] + report["suspicious_results"]))
        self.assertFalse(report["promotion_evidence"])
        self.assertEqual(report["broker_isolation"], "not_measured")
        self.assertEqual(set(report["suspicious_skipped"]), set(validator.LIVE_ONLY_EXPECTED_RULES))
        self.assertNotIn("no_active_incident_activation", report["checks"])
        health.assert_not_called()

    def test_http_error_is_not_an_empty_alert_set(self):
        error = validator.urllib.error.HTTPError("http://localhost", 401, "Unauthorized", None, io.BytesIO())
        with error, patch.object(validator.urllib.request, "urlopen", side_effect=error):
            with self.assertRaisesRegex(validator.ReplayEvidenceError, "HTTP 401"):
                validator.post_shadow_correlate("http://localhost", [])

    def test_partial_live_response_fails_fixtures_and_report(self):
        with (
            patch.object(validator, "correlation_health", return_value=True),
            patch.object(validator, "post_shadow_correlate", return_value={"shadow_alerts": []}),
        ):
            status, report = self.run_validator()
        self.assertNotEqual(status, 0)
        self.assertEqual(report["validation_status"], "FAIL")
        self.assertGreater(len(report["all_failures"]), 0)
        self.assertFalse(any(r["ok"] for r in report["benign_results"] + report["suspicious_results"]))
        self.assertIsNone(report["checks"]["trace_id_propagated"])
        self.assertEqual(report["suspicious_skipped"], [])
        self.assertEqual(len(report["suspicious_results"]),
                         len(validator.EXPECTED_RULES) + len(validator.LIVE_ONLY_EXPECTED_RULES))

    def test_live_response_requires_the_correct_topic_and_counts(self):
        complete = {
            "shadow_mode": True, "topic": validator.SHADOW_TOPIC, "events": 1,
            "alert_count": 0, "shadow_alerts": None,
        }
        self.assertEqual(validator.validate_live_response(complete, 1), [])
        for change in ({"topic": "xdr.alerts"}, {"events": True}, {"alert_count": 1},
                       {"shadow_mode": False}, {"shadow_alerts": [{}], "alert_count": 1}):
            with self.subTest(change=change), self.assertRaises(validator.ReplayEvidenceError):
                validator.validate_live_response(complete | change, 1)

    def test_missing_and_malformed_fixture_sets_fail(self):
        with tempfile.TemporaryDirectory() as temp:
            for content in (None, "[]", '[{"event_type": "test"}]', "invalid"):
                with self.subTest(content=content):
                    if content is not None:
                        (Path(temp) / "fixture.json").write_text(content, encoding="utf-8")
                    status, report = self.run_validator("--fixture-dir-benign", temp)
                    self.assertEqual(status, 2)
                    self.assertTrue(report["errors"])
                    self.assertEqual(report["validation_status"], "FAIL")

    def test_every_alert_must_match_a_fixture_trace(self):
        events = [{"trace_id": "fixture-trace"}]
        self.assertFalse(validator.check_trace_id_propagation(
            events, [{"trace_id": "fixture-trace"}, {"trace_id": "unrelated"}], "fixture"
        )[0])
        self.assertFalse(validator.check_trace_id_propagation([], [{"trace_id": "other"}], "fixture")[0])


if __name__ == "__main__":
    unittest.main()
