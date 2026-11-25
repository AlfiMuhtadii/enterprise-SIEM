from contextlib import redirect_stderr, redirect_stdout
import hashlib
import hmac
import io
import json
from pathlib import Path
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[2] / "scripts"))
import xdr_capture_coredns as dns


START = "2026-01-01T00:00:00Z"
END = "2026-01-01T00:10:00Z"
STAMP = "2026-01-01T00:01:00.123456789Z"
OPTIONS = dict(source_id="resolver-01", tenant_id="demo", zone="detector.test", start=START, end=END)


def query(stamp=STAMP, fields="38101|57451|IN|A|status.detector.test.|NOERROR"):
    return f"{stamp} {dns.MARKER}{fields}\n".encode()


class CoreDNSCaptureTest(unittest.TestCase):
    def test_preserves_source_time_type_and_outcome_without_client_details(self):
        result, = dns.capture(query(), **OPTIONS)
        self.assertEqual(result["ts"], STAMP)
        self.assertEqual(result["telemetry_type"], "dns")
        self.assertEqual(result["query_type"], "A")
        self.assertEqual(result["response_code"], "NOERROR")
        self.assertEqual(result["queried_domain"], "status.detector.test")
        self.assertNotIn("source_ip", result)
        self.assertNotIn("host_id", result)
        self.assertNotIn("38101", json.dumps(result))
        self.assertNotIn("capture_admission", result)

    def test_excludes_outside_zone_and_window_and_deduplicates_export_lines(self):
        other_zone = query(fields="38101|1|IN|A|notdetector.test.|NOERROR")
        data = b"unrelated startup info\n" + query() * 2 + other_zone + query(stamp=END)
        self.assertEqual(dns.capture(data, **OPTIONS), dns.capture(query(), **OPTIONS))

    def test_same_event_is_stable_but_source_and_query_id_are_part_of_identity(self):
        first, = dns.capture(query(), **OPTIONS)
        other_source, = dns.capture(query(), **dict(OPTIONS, source_id="resolver-02"))
        same_time, = dns.capture(query(fields="38101|1|IN|A|status.detector.test.|NOERROR"), **OPTIONS)
        self.assertNotEqual(first["event_id"], other_source["event_id"])
        self.assertNotEqual(first["event_id"], same_time["event_id"])

    def test_fail_closed_without_timestamp_or_with_malformed_dns_record(self):
        for data in (dns.MARKER.encode() + b"query", query(stamp="bogus"),
                     query(fields="0|1|IN|A|status.detector.test.|NOERROR"),
                     query(fields="1|65536|IN|A|status.detector.test.|NOERROR"),
                     query(fields="1|1|IN|A|bad..detector.test.|NOERROR"),
                     query(fields="1|1|IN|A|status.detector.test.|NOERROR|extra"),
                     query(stamp=END), b"CoreDNS started"):
            with self.subTest(data=data), self.assertRaises(dns.InvalidEvidence):
                dns.capture(data, **OPTIONS)

    def test_cli_does_not_expose_query_and_never_overwrites_capture(self):
        with TemporaryDirectory() as tmp:
            log = Path(tmp) / "source.log"
            log.write_bytes(query())
            output = Path(tmp) / "capture.jsonl"
            arguments = [str(log), "--source-id", OPTIONS["source_id"], "--tenant-id", OPTIONS["tenant_id"],
                         "--zone", OPTIONS["zone"], "--window-start", START, "--window-end", END]
            with redirect_stdout(io.StringIO()) as report:
                self.assertEqual(dns.main(arguments), 0)
            self.assertEqual(json.loads(report.getvalue())["status"], "INSPECTED")
            self.assertFalse(output.exists())
            with redirect_stdout(io.StringIO()) as report:
                self.assertEqual(dns.main(arguments + ["--output", str(output)]), 0)
            result = report.getvalue()
            self.assertNotIn("status.detector.test", result)
            self.assertFalse(json.loads(result)["representative_telemetry_verified"])
            contents = output.read_bytes()
            self.assertEqual(json.loads(contents)["response_code"], "NOERROR")
            with redirect_stderr(io.StringIO()):
                self.assertEqual(dns.main(arguments + ["--output", str(output)]), 2)
            self.assertEqual(output.read_bytes(), contents)

    def test_publish_signs_exact_body_and_accepts_only_full_receipt(self):
        events = dns.capture(query(), **OPTIONS)
        with patch.object(dns.http.client, "HTTPConnection") as connect, patch.object(dns.time, "time", return_value=42):
            client = connect.return_value
            response = client.getresponse.return_value.__enter__.return_value
            response.status = 202
            response.read.return_value = b'{"accepted":1}'
            dns.publish_local(events, 18091, "lab-secret")
            connect.assert_called_with("127.0.0.1", 18091, timeout=10)
            method, path, body, headers = client.request.call_args.args
            self.assertEqual((method, path, json.loads(body)), ("POST", "/v1/ingest", events))
            expected = hmac.new(b"lab-secret", b"42." + body, hashlib.sha256).hexdigest()
            self.assertEqual(headers["X-XDR-Signature"], "sha256=" + expected)
            self.assertEqual(headers["X-XDR-Timestamp"], "42")
            response.read.return_value = b'{"accepted":0}'
            with self.assertRaises(dns.InvalidEvidence):
                dns.publish_local(events, 18091, "lab-secret")
            self.assertEqual(client.close.call_count, 2)
