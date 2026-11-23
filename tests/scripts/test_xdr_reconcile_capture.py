import io
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from contextlib import redirect_stdout
from unittest.mock import MagicMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import xdr_reconcile_capture as evidence


TOPIC = "telemetry.raw"
START = "2026-01-01T00:00:00Z"
OBSERVED = "2026-01-01T00:01:00Z"
END = "2026-01-01T00:10:00Z"
SCOPE = evidence.Scope("sensor-1", "tenant-a", "endpoint", "endpoint",
                       evidence.utc_timestamp(START), evidence.utc_timestamp(END))
RAW = {"event_id": "observed-1", "trace_id": "trace-1", "ts": OBSERVED, "event_type": "process_start",
       "tenant_id": SCOPE.tenant_id, "telemetry_type": SCOPE.telemetry_type, "pid": 123}
STAMP = {"method": "verified_mtls", "source_id": "sensor-1", "received_at": "2026-01-01T00:01:09Z"}


def admitted(**changes):
    return dict({"capture_admission": STAMP, **RAW}, **changes)


class TestEvidenceMatching(unittest.TestCase):
    def setUp(self):
        self.expected = {RAW["event_id"]: evidence.payload_digest(RAW)}

    def test_exact_payload_matches_despite_gateway_fields_and_numeric_encoding(self):
        event = admitted(traceparent="regenerated", pid=123.0)
        self.assertEqual(evidence.reconcile(self.expected, [event], SCOPE), {"matched": 1, "unlisted": 0})

    def test_mismatch_causes_failures_without_relabeling_as_verified(self):
        cases = [
            ("missing", []),
            ("scope_mismatch", [admitted(tenant_id="other")]),
            ("scope_mismatch", [admitted(telemetry_type="dns")]),
            ("missing_admission", [admitted(capture_admission={"method": "verified_mtls", "source_id": "other"})]),
            ("missing_admission", [RAW]),
            ("payload_mismatch", [admitted(pid=456)]),
            ("payload_mismatch", [admitted(trace_id="other")]),
            ("rehearsal", [admitted(synthetic=True)]),
            ("arrival_window_mismatch", [admitted(capture_admission=dict(STAMP, received_at=END))]),
            ("arrival_window_mismatch", [admitted(capture_admission=dict(STAMP, received_at=START))]),
            ("duplicate", [admitted(), admitted()]),
        ]
        for reason, records in cases:
            with self.subTest(reason=reason):
                self.assertEqual(evidence.reconcile(self.expected, records, SCOPE), {reason: 1, "unlisted": 0})

    def test_two_way_check_for_unlisted_records_excludes_rehearsal_and_other_sources(self):
        records = [admitted(), admitted(event_id="observed-2"),
                   admitted(event_id="rehearsal-2", scenario_id="demo"),
                   admitted(event_id="ordinary-2", capture_admission=None)]
        self.assertEqual(evidence.reconcile(self.expected, records, SCOPE), {"matched": 1, "unlisted": 1})

    def test_capture_requires_independent_observation_and_nonempty_window(self):
        with TemporaryDirectory() as tmp:
            path = Path(tmp) / "capture.jsonl"
            for events in ([dict(RAW, ts=END)], [dict(RAW, demo_run_id="demo")],
                           [admitted()], [RAW, RAW], [dict(RAW, trace_id="")]):
                with self.subTest(events=events):
                    path.write_text("\n".join(map(json.dumps, events)), encoding="utf-8")
                    with self.assertRaises(evidence.InvalidEvidence):
                        evidence.read_capture(path, SCOPE)

    def test_duplicate_json_fields_and_nonfinite_numbers_are_rejected(self):
        for raw in (b'{"event_id":"one","event_id":"two"}', b'{"score":NaN}'):
            with self.assertRaises(evidence.InvalidEvidence):
                evidence.strict_json(raw)


class TestBrokerRead(unittest.TestCase):
    @staticmethod
    def response(rows):
        response = MagicMock()
        response.__enter__.return_value.status = 200
        response.__enter__.return_value.read.return_value = json.dumps(rows).encode()
        return response

    @staticmethod
    def row(offset):
        return {"topic": TOPIC, "partition": 0, "offset": offset, "value": RAW}

    def test_paginated_gets_are_bounded_and_do_not_ack(self):
        with patch.object(evidence.http.client, "HTTPConnection") as connect:
            client = connect.return_value
            client.getresponse.side_effect = [self.response([self.row(5)]), self.response([self.row(6), self.row(7)])]
            self.assertEqual(list(evidence.broker_records(TOPIC, [evidence.OffsetRange(0, 5, 7)])), [RAW] * 2)
        self.assertEqual([call.args[0] for call in client.request.call_args_list], ["GET", "GET"])
        self.assertIn("?offset=5&", client.request.call_args_list[0].args[1])
        self.assertIn("?offset=6&", client.request.call_args_list[1].args[1])
        client.close.assert_called_once()

    def test_short_or_gapped_range_is_not_verifiable(self):
        for rows in ([], [self.row(6)], [dict(self.row(5), partition=1)]):
            with self.subTest(rows=rows), patch.object(evidence.http.client, "HTTPConnection") as connect:
                connect.return_value.getresponse.return_value = self.response(rows)
                with self.assertRaises(evidence.InvalidEvidence):
                    list(evidence.broker_records(TOPIC, [evidence.OffsetRange(0, 5, 6)]))


class TestReport(unittest.TestCase):
    def test_success_report_is_scoped_and_does_not_expose_events(self):
        with TemporaryDirectory() as tmp:
            registry = Path(tmp) / "sources.json"
            capture = Path(tmp) / "capture.jsonl"
            registry.write_text(json.dumps([{
                "source_id": SCOPE.source_id, "tenant_id": SCOPE.tenant_id,
                "client_cert_sha256": "a" * 64, "telemetry_types": [SCOPE.telemetry_type],
            }]), encoding="utf-8")
            capture.write_text(json.dumps(dict(RAW, process_name="private-name")), encoding="utf-8")
            args = ["--capture", str(capture), "--registry", str(registry), "--source-id", SCOPE.source_id,
                    "--domain", "endpoint", "--telemetry-type", "endpoint", "--window-start", START,
                    "--window-end", END, "--range", "0:5:6"]
            records = (event for event in [admitted(process_name="private-name")])
            with patch.object(evidence, "broker_records", return_value=records):
                output = io.StringIO()
                with redirect_stdout(output):
                    self.assertEqual(evidence.main(args), 0)
            report = json.loads(output.getvalue())
            self.assertEqual(report["status"], "MATCHED")
            self.assertFalse(report["promotion_evidence_verified"])
            self.assertFalse(report["representative_telemetry_verified"])
            self.assertNotIn("private-name", output.getvalue())
            self.assertNotIn(RAW["event_id"], output.getvalue())
            self.assertEqual(len(report["capture_sha256"]), 64)
            self.assertEqual(len(report["registry_sha256"]), 64)

            with patch.object(evidence, "broker_records") as broker, redirect_stdout(io.StringIO()):
                self.assertEqual(evidence.main(args + ["--range", "0:1:2"]), 2)
                broker.assert_not_called()


if __name__ == "__main__":
    unittest.main()
