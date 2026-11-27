from contextlib import redirect_stdout
from copy import deepcopy
import io
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import xdr_reconcile_capture as reconcile


HOST = "dns-client-" + "1" * 32
RULE = "dns_txt_query_pattern"
START = "2026-11-27T14:40:00Z"
END = "2026-11-27T14:42:00Z"
SCOPE = reconcile.Scope("test-dns", "test-tenant", "dns", "network",
                        reconcile.utc_timestamp(START), reconcile.utc_timestamp(END))


def source_record(index):
    return {
        "event_id": f"event-{index}", "trace_id": f"trace-{index}", "host_id": HOST,
        "tenant_id": "test-tenant", "telemetry_type": "dns",
        "ts": f"2026-11-27T14:40:{10 + index:02d}Z", "query_type": "TXT", "response_code": "NOERROR",
    }


def record(event):
    return reconcile.CaptureRecord(reconcile.payload_digest(event), event["trace_id"], event["ts"],
                                   event["host_id"], event["query_type"], event["response_code"])


def advisory():
    return {
        "scope": "network-shadow", "source": "correlation-worker", "shadow_mode": True, "trace_id": "",
        "tenant_id": SCOPE.tenant_id, "shadow_evidence_origin": "unverified",
        "alerts": [{
            "alert_id": "b" * 40, "rule_id": RULE, "tenant_id": SCOPE.tenant_id, "trace_id": "",
            "shadow_mode": True, "shadow_evidence_origin": "unverified", "host": HOST,
            "evidence_ids": ["event-1", "event-2"],
            "evidence": {
                "host": HOST, "aggregation_mode": "dns-event-time-v1",
                "window_start": START, "window_end": "2026-11-27T14:41:00Z",
                "advisory": "network_shadow_only", "no_blocking": True,
                "evidence_lineage_complete": True, "source_event_count": 2, "txt_count": 2,
                "source_trace_ids": ["trace-1", "trace-2"],
            },
        }],
    }


class DNSAdvisoryEvidenceTest(unittest.TestCase):
    def setUp(self):
        self.events = [source_record(1), source_record(2)]
        self.capture = {event["event_id"]: record(event) for event in self.events}

    def test_complete_scoped_evidence_matches_exactly_one_window(self):
        self.assertEqual(reconcile.reconcile_dns_advisories(self.capture, [advisory()], SCOPE, RULE),
                         {"expected_windows": 1, "matched": 1, "missing": 0})

    def test_wrong_scope_or_misattributed_evidence_never_matches(self):
        cases = (
            ("scope_mismatch", ("tenant_id",), "other"),
            ("scope_mismatch", ("shadow_evidence_origin",), "rehearsal"),
            ("scope_mismatch", ("alerts", 0, "shadow_mode"), False),
            ("scope_mismatch", ("alerts", 0, "host"), "dns-client-" + "2" * 32),
            ("invalid_window", ("alerts", 0, "evidence", "window_start"), "2026-11-27T14:40:01Z"),
            ("invalid_window", ("alerts", 0, "evidence", "aggregation_mode"), "poll"),
            ("evidence_mismatch", ("alerts", 0, "evidence_ids"), ["event-1", "other"]),
            ("evidence_mismatch", ("alerts", 0, "evidence_ids"), ["event-1", "event-1"]),
            ("evidence_mismatch", ("alerts", 0, "evidence_ids"), ["event-1", "event-2", "other"]),
            ("evidence_mismatch", ("alerts", 0, "evidence", "source_trace_ids"), ["trace-1"]),
            ("evidence_mismatch", ("alerts", 0, "evidence", "evidence_lineage_complete"), False),
            ("evidence_mismatch", ("alerts", 0, "evidence", "evidence_lineage_truncated"), True),
            ("evidence_mismatch", ("alerts", 0, "evidence", "txt_count"), 3),
        )
        for error, path, changed in cases:
            with self.subTest(path=path, changed=changed):
                envelope = advisory()
                target = envelope
                for component in path[:-1]:
                    target = target[component]
                target[path[-1]] = changed
                counts = reconcile.reconcile_dns_advisories(self.capture, [envelope], SCOPE, RULE)
                self.assertEqual(counts[error], 1)
                self.assertEqual(counts["missing"], 1)

    def test_report_rejects_duplicate_and_does_not_accept_partial_window(self):
        counts = reconcile.reconcile_dns_advisories(self.capture, [advisory(), advisory()], SCOPE, RULE)
        self.assertEqual(counts["duplicate"], 1)
        partial = reconcile.Scope(SCOPE.source_id, SCOPE.tenant_id, "dns", "network",
                                  reconcile.utc_timestamp("2026-11-27T14:40:05Z"), SCOPE.end)
        counts = reconcile.reconcile_dns_advisories(self.capture, [advisory()], partial, RULE)
        self.assertEqual(counts["expected_windows"], 0)
        self.assertEqual(counts["matched"], 0)
        self.assertEqual(counts["unexpected_window"], 1)

    def test_nxdomain_requires_its_own_response_and_threshold(self):
        nxdomain = {f"event-{i}": record(dict(source_record(i), query_type="A", response_code="NXDOMAIN"))
                    for i in range(1, 6)}
        result = advisory()
        finding = result["alerts"][0]
        finding["rule_id"] = "repeated_nxdomain"
        finding["evidence_ids"] = list(nxdomain)
        finding["evidence"] |= {
            "source_trace_ids": [f"trace-{i}" for i in range(1, 6)], "source_event_count": 5, "nxdomain_count": 5,
        }
        self.assertEqual(reconcile.reconcile_dns_advisories(nxdomain, [result], SCOPE, "repeated_nxdomain")["matched"], 1)
        del nxdomain["event-5"]
        self.assertEqual(reconcile.dns_candidate_windows(nxdomain, SCOPE, "repeated_nxdomain"), {})

    def test_cli_checks_all_three_topics_and_skips_failed_upstream(self):
        with TemporaryDirectory() as tmp:
            capture = Path(tmp) / "source.jsonl"
            capture.write_text("\n".join(map(json.dumps, self.events)), encoding="utf-8")
            registry = Path(tmp) / "registry.json"
            registry.write_text(json.dumps([{
                "source_id": SCOPE.source_id, "tenant_id": SCOPE.tenant_id,
                "client_cert_sha256": "a" * 64, "telemetry_types": ["dns"],
            }]), encoding="utf-8")
            args = [
                "--capture", str(capture), "--registry", str(registry), "--source-id", SCOPE.source_id,
                "--domain", "network", "--telemetry-type", "dns", "--window-start", START, "--window-end", END,
                "--range", "0:10:12", "--normalized-range", "0:20:22",
                "--dns-advisory-rule", RULE, "--advisory-range", "0:30:31",
            ]
            raw = [dict(event, capture_admission={
                "method": "verified_mtls", "source_id": SCOPE.source_id, "received_at": "2026-11-27T14:40:30Z",
            }) for event in self.events]
            normalized = [dict(event, raw_event_id=event["event_id"], normalized_event_id=event["event_id"])
                          for event in self.events]
            with patch.object(reconcile, "broker_records", side_effect=[
                (row for row in raw), (row for row in normalized), (row for row in [advisory()]),
            ]) as broker, redirect_stdout(io.StringIO()) as output:
                self.assertEqual(reconcile.main(args), 0)
            report = json.loads(output.getvalue())
            self.assertEqual(report["advisory_lineage"]["status"], "MATCHED")
            self.assertEqual([call.args[0] for call in broker.call_args_list],
                             ["telemetry.raw", "telemetry.normalized", reconcile.DNS_ADVISORY_TOPIC])
            self.assertNotIn("event-1", output.getvalue())
            self.assertFalse(report["promotion_evidence_verified"])

            changed = deepcopy(normalized)
            changed[0]["query_type"] = "A"
            with patch.object(reconcile, "broker_records", side_effect=[
                (row for row in raw), (row for row in changed),
            ]) as broker, redirect_stdout(io.StringIO()) as output:
                self.assertEqual(reconcile.main(args), 2)
            report = json.loads(output.getvalue())
            self.assertEqual(report["normalized_lineage"]["counts"]["dns_value_mismatch"], 1)
            self.assertEqual(report["advisory_lineage"]["status"], "SKIPPED_UPSTREAM_MISMATCH")
            self.assertEqual(broker.call_count, 2)
