import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts"))

from golden_replay_test import compare_expected  # noqa: E402


def dataset(**overrides):
    value = {
        "events": 2,
        "invalid": 0,
        "rules": {"RULE-A": 1},
        "ml_counts": {"normal": 2},
        "ml_signature": "expected-signature",
    }
    value.update(overrides)
    return {"sample": value}


class GoldenReplayComparisonTest(unittest.TestCase):
    def test_rules_only_still_rejects_rule_mismatch(self):
        result = compare_expected(
            dataset(rules={"RULE-A": 2}), dataset(), compare_ml=False
        )

        self.assertFalse(result["ok"])
        self.assertFalse(result["rules_ok"])
        self.assertFalse(result["ml_checked"])
        self.assertIn("rules mismatch", result["mismatches"][0])

    def test_rules_only_ignores_optional_ml_artifact_drift(self):
        result = compare_expected(
            dataset(ml_counts={"scan": 2}, ml_signature="different"),
            dataset(),
            compare_ml=False,
        )

        self.assertTrue(result["ok"])
        self.assertTrue(result["rules_ok"])
        self.assertIsNone(result["ml_ok"])

    def test_model_mode_rejects_ml_count_and_signature_mismatch(self):
        result = compare_expected(
            dataset(ml_counts={"scan": 2}, ml_signature="different"),
            dataset(),
            compare_ml=True,
        )

        self.assertFalse(result["ok"])
        self.assertTrue(result["rules_ok"])
        self.assertTrue(result["ml_checked"])
        self.assertFalse(result["ml_ok"])
        self.assertEqual(2, len(result["mismatches"]))

    def test_event_and_invalid_counts_are_part_of_rule_contract(self):
        result = compare_expected(
            dataset(events=3, invalid=1), dataset(), compare_ml=False
        )

        self.assertFalse(result["ok"])
        self.assertEqual(2, len(result["mismatches"]))


if __name__ == "__main__":
    unittest.main()
