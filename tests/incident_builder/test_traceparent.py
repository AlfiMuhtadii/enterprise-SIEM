"""Unit tests for alert-writer-service's traceparent module (W3C Trace Context).

Pure functions, no FastAPI/Pydantic/Kafka stubbing needed -- direct import,
matching the test_alert_identity.py precedent for small extracted modules.
"""
from __future__ import annotations

import sys
import unittest
from pathlib import Path

SERVICES_DIR = Path(__file__).parent.parent.parent / "services" / "incident-builder-service"
sys.path.insert(0, str(SERVICES_DIR))

import traceparent as tp  # noqa: E402


class GenerateTest(unittest.TestCase):
    def test_produces_valid_traceparent(self):
        value = tp.generate()
        parsed = tp.parse(value)
        self.assertIsNotNone(parsed)
        self.assertEqual(len(parsed.trace_id), 32)
        self.assertEqual(len(parsed.span_id), 16)
        self.assertEqual(parsed.flags, "01")

    def test_produces_unique_values(self):
        self.assertNotEqual(tp.generate(), tp.generate())


class ParseTest(unittest.TestCase):
    def test_rejects_none(self):
        self.assertIsNone(tp.parse(None))

    def test_rejects_empty_string(self):
        self.assertIsNone(tp.parse(""))

    def test_rejects_wrong_version(self):
        bad = "01-" + "a" * 32 + "-" + "b" * 16 + "-01"
        self.assertIsNone(tp.parse(bad))

    def test_rejects_wrong_lengths(self):
        cases = [
            "00-abc-" + "b" * 16 + "-01",
            "00-" + "a" * 32 + "-abc-01",
            "00-" + "a" * 32 + "-" + "b" * 16 + "-1",
            "not-a-traceparent-at-all",
        ]
        for case in cases:
            with self.subTest(case=case):
                self.assertIsNone(tp.parse(case))

    def test_rejects_all_zero_trace_id(self):
        bad = "00-" + "0" * 32 + "-" + "b" * 16 + "-01"
        self.assertIsNone(tp.parse(bad))

    def test_rejects_all_zero_span_id(self):
        bad = "00-" + "a" * 32 + "-" + "0" * 16 + "-01"
        self.assertIsNone(tp.parse(bad))

    def test_rejects_uppercase_hex(self):
        bad = "00-" + "A" * 32 + "-" + "B" * 16 + "-01"
        self.assertIsNone(tp.parse(bad))


class NewChildSpanTest(unittest.TestCase):
    def test_preserves_trace_id_changes_span_id(self):
        root = tp.parse(tp.generate())
        child = tp.parse(tp.new_child_span(root))
        self.assertEqual(child.trace_id, root.trace_id)
        self.assertNotEqual(child.span_id, root.span_id)


class PropagateTest(unittest.TestCase):
    def test_valid_inbound_creates_child_span(self):
        inbound = tp.generate()
        inbound_parsed = tp.parse(inbound)
        out_parsed = tp.parse(tp.propagate(inbound))
        self.assertIsNotNone(out_parsed)
        self.assertEqual(out_parsed.trace_id, inbound_parsed.trace_id)
        self.assertNotEqual(out_parsed.span_id, inbound_parsed.span_id)

    def test_empty_inbound_generates_root(self):
        self.assertIsNotNone(tp.parse(tp.propagate("")))

    def test_none_inbound_generates_root(self):
        self.assertIsNotNone(tp.parse(tp.propagate(None)))

    def test_invalid_inbound_generates_root(self):
        self.assertIsNotNone(tp.parse(tp.propagate("garbage-not-a-traceparent")))


if __name__ == "__main__":
    unittest.main()
