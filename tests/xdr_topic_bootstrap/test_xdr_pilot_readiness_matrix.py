"""Tests for scripts/xdr_pilot_readiness_matrix.py — PILOT-034"""

import json
import sys
import unittest
from pathlib import Path

# Resolve script path
_REPO = Path(__file__).resolve().parent.parent.parent
sys.path.insert(0, str(_REPO / "scripts"))

import xdr_pilot_readiness_matrix as matrix


def _make_passing_spec(**overrides):
    spec = {
        "matrix_run_id": "prm-test-001",
        "tenant_id": "tenant-test",
        "operator_id": "op-alice",
        "approved_by": "reviewer-bob",
        "gates": [
            {"gate_id": "soak_validation", "dimension": "technical", "status": "pass"},
            {"gate_id": "replay_verification", "dimension": "technical", "status": "pass"},
            {"gate_id": "tenant_isolation", "dimension": "security", "status": "pass"},
            {"gate_id": "rollback_readiness", "dimension": "operational", "status": "pass"},
            {"gate_id": "rule_registry", "dimension": "technical", "status": "pass"},
        ],
    }
    spec.update(overrides)
    return spec


class TestConstants(unittest.TestCase):
    def test_advisory_only_is_true(self):
        self.assertTrue(matrix.ADVISORY_ONLY)

    def test_autonomous_promotion_is_false(self):
        self.assertFalse(matrix.AUTONOMOUS_PROMOTION)

    def test_self_approve_blocked_is_true(self):
        self.assertTrue(matrix.SELF_APPROVE_BLOCKED)

    def test_pass_threshold_is_point_eight(self):
        self.assertEqual(0.80, matrix.PASS_THRESHOLD)

    def test_max_gates_is_twenty(self):
        self.assertEqual(20, matrix.MAX_GATES)

    def test_required_gate_ids_contains_four_gates(self):
        self.assertIn("soak_validation", matrix.REQUIRED_GATE_IDS)
        self.assertIn("replay_verification", matrix.REQUIRED_GATE_IDS)
        self.assertIn("tenant_isolation", matrix.REQUIRED_GATE_IDS)
        self.assertIn("rollback_readiness", matrix.REQUIRED_GATE_IDS)


class TestValidateSpec(unittest.TestCase):
    def test_valid_spec_returns_no_errors(self):
        spec = _make_passing_spec()
        errors = matrix.validate_spec(spec)
        self.assertEqual([], errors)

    def test_missing_gates_returns_error(self):
        errors = matrix.validate_spec({"matrix_run_id": "test"})
        self.assertTrue(len(errors) > 0)

    def test_too_many_gates_returns_error(self):
        gates = [
            {"gate_id": f"gate_{i}", "dimension": "technical", "status": "pass"}
            for i in range(matrix.MAX_GATES + 1)
        ]
        errors = matrix.validate_spec({"gates": gates})
        self.assertTrue(any("Too many" in e for e in errors))

    def test_invalid_dimension_returns_error(self):
        spec = _make_passing_spec()
        spec["gates"][0]["dimension"] = "invalid_dimension"
        errors = matrix.validate_spec(spec)
        self.assertTrue(any("invalid dimension" in e for e in errors))

    def test_invalid_status_returns_error(self):
        spec = _make_passing_spec()
        spec["gates"][0]["status"] = "unknown"
        errors = matrix.validate_spec(spec)
        self.assertTrue(any("invalid status" in e for e in errors))

    def test_missing_gate_id_returns_error(self):
        spec = _make_passing_spec()
        spec["gates"].append({"dimension": "technical", "status": "pass"})
        errors = matrix.validate_spec(spec)
        self.assertTrue(any("missing gate_id" in e for e in errors))


class TestCheckSelfApprove(unittest.TestCase):
    def test_different_operator_and_approver_is_ok(self):
        spec = {"operator_id": "alice", "approved_by": "bob"}
        self.assertIsNone(matrix.check_self_approve(spec))

    def test_same_operator_and_approver_returns_error(self):
        spec = {"operator_id": "alice", "approved_by": "alice"}
        result = matrix.check_self_approve(spec)
        self.assertIsNotNone(result)
        self.assertIn("Self-approval", result)

    def test_missing_approved_by_is_ok(self):
        spec = {"operator_id": "alice"}
        self.assertIsNone(matrix.check_self_approve(spec))

    def test_both_empty_is_ok(self):
        self.assertIsNone(matrix.check_self_approve({}))


class TestComputeMatrixScore(unittest.TestCase):
    def test_all_pass_score_is_one(self):
        gates = [
            {"gate_id": "g1", "status": "pass"},
            {"gate_id": "g2", "status": "pass"},
            {"gate_id": "g3", "status": "pass"},
        ]
        result = matrix.compute_matrix_score(gates)
        self.assertEqual(1.0, result["score"])

    def test_half_pass_score_is_half(self):
        gates = [
            {"gate_id": "g1", "status": "pass"},
            {"gate_id": "g2", "status": "fail"},
        ]
        result = matrix.compute_matrix_score(gates)
        self.assertEqual(0.5, result["score"])

    def test_skipped_gates_excluded_from_ratio(self):
        gates = [
            {"gate_id": "g1", "status": "pass"},
            {"gate_id": "g2", "status": "skip"},
            {"gate_id": "g3", "status": "pass"},
        ]
        result = matrix.compute_matrix_score(gates)
        self.assertEqual(1.0, result["score"])
        self.assertEqual(1, result["skipped"])

    def test_required_gate_fail_sets_required_gates_pass_false(self):
        gates = [
            {"gate_id": "soak_validation", "status": "fail"},
            {"gate_id": "replay_verification", "status": "pass"},
            {"gate_id": "tenant_isolation", "status": "pass"},
            {"gate_id": "rollback_readiness", "status": "pass"},
        ]
        result = matrix.compute_matrix_score(gates)
        self.assertFalse(result["required_gates_pass"])

    def test_missing_required_gate_sets_required_gates_pass_false(self):
        gates = [{"gate_id": "some_other_gate", "status": "pass"}]
        result = matrix.compute_matrix_score(gates)
        self.assertFalse(result["required_gates_pass"])
        self.assertEqual("missing", result["required_gate_results"]["soak_validation"])

    def test_empty_gates_returns_zero_score(self):
        result = matrix.compute_matrix_score([])
        self.assertEqual(0.0, result["score"])

    def test_warn_gates_counted_in_total(self):
        gates = [
            {"gate_id": "g1", "status": "pass"},
            {"gate_id": "g2", "status": "warn"},
        ]
        result = matrix.compute_matrix_score(gates)
        self.assertEqual(1, result["warned"])
        self.assertEqual(2, result["countable"])


class TestResolveOverallStatus(unittest.TestCase):
    def test_all_required_pass_and_score_above_threshold_is_pass(self):
        self.assertEqual("PASS", matrix.resolve_overall_status(0.85, True))

    def test_required_gate_fail_means_fail_regardless_of_score(self):
        self.assertEqual("FAIL", matrix.resolve_overall_status(1.0, False))

    def test_score_below_threshold_is_fail(self):
        self.assertEqual("FAIL", matrix.resolve_overall_status(0.75, True))

    def test_score_exactly_at_threshold_is_pass(self):
        self.assertEqual("PASS", matrix.resolve_overall_status(0.80, True))

    def test_score_zero_is_fail(self):
        self.assertEqual("FAIL", matrix.resolve_overall_status(0.0, True))


class TestGroupGatesByDimension(unittest.TestCase):
    def test_groups_correctly(self):
        gates = [
            {"gate_id": "g1", "dimension": "technical", "status": "pass"},
            {"gate_id": "g2", "dimension": "security", "status": "pass"},
            {"gate_id": "g3", "dimension": "technical", "status": "warn"},
        ]
        result = matrix.group_gates_by_dimension(gates)
        self.assertEqual(2, len(result["technical"]))
        self.assertEqual(1, len(result["security"]))
        self.assertEqual(0, len(result["easm"]))

    def test_all_dimensions_present_even_if_empty(self):
        result = matrix.group_gates_by_dimension([])
        for dim in matrix.VALID_DIMENSIONS:
            self.assertIn(dim, result)


class TestEvaluateMatrix(unittest.TestCase):
    def test_passing_spec_returns_pass(self):
        spec = _make_passing_spec()
        result = matrix.evaluate_matrix(spec)
        self.assertEqual("PASS", result["status"])

    def test_result_is_advisory_true(self):
        spec = _make_passing_spec()
        result = matrix.evaluate_matrix(spec)
        self.assertTrue(result["is_advisory"])

    def test_result_autonomous_promotion_false(self):
        spec = _make_passing_spec()
        result = matrix.evaluate_matrix(spec)
        self.assertFalse(result["autonomous_promotion"])

    def test_self_approve_returns_error_status(self):
        spec = _make_passing_spec(operator_id="alice", approved_by="alice")
        result = matrix.evaluate_matrix(spec)
        self.assertEqual("ERROR", result["status"])
        self.assertTrue(any("Self-approval" in e for e in result["errors"]))

    def test_required_gate_fail_returns_fail(self):
        spec = _make_passing_spec()
        for gate in spec["gates"]:
            if gate["gate_id"] == "soak_validation":
                gate["status"] = "fail"
        result = matrix.evaluate_matrix(spec)
        self.assertEqual("FAIL", result["status"])

    def test_score_below_threshold_returns_fail(self):
        spec = _make_passing_spec()
        for gate in spec["gates"]:
            gate["status"] = "fail"
        result = matrix.evaluate_matrix(spec)
        self.assertEqual("FAIL", result["status"])

    def test_result_contains_required_gate_ids(self):
        spec = _make_passing_spec()
        result = matrix.evaluate_matrix(spec)
        self.assertIn("required_gate_ids", result)
        self.assertIn("soak_validation", result["required_gate_ids"])

    def test_result_contains_by_dimension(self):
        spec = _make_passing_spec()
        result = matrix.evaluate_matrix(spec)
        self.assertIn("by_dimension", result)
        self.assertIn("technical", result["by_dimension"])

    def test_invalid_spec_returns_error_status(self):
        result = matrix.evaluate_matrix({"gates": "not_a_list"})
        self.assertEqual("ERROR", result["status"])


class TestMainCli(unittest.TestCase):
    def test_no_args_returns_2(self):
        rc = matrix.main([])
        self.assertEqual(2, rc)

    def test_template_flag_returns_0(self, tmp_path=None):
        import tempfile
        import os
        rc = matrix.main(["--template"])
        self.assertEqual(0, rc)

    def test_passing_spec_returns_0(self):
        import tempfile
        spec = _make_passing_spec()
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False) as f:
            json.dump(spec, f)
            tmp_in = f.name
        try:
            rc = matrix.main(["--input", tmp_in])
            self.assertEqual(0, rc)
        finally:
            Path(tmp_in).unlink(missing_ok=True)

    def test_failing_spec_returns_1(self):
        import tempfile
        spec = _make_passing_spec()
        for gate in spec["gates"]:
            gate["status"] = "fail"
        with tempfile.NamedTemporaryFile(mode="w", suffix=".json", delete=False) as f:
            json.dump(spec, f)
            tmp_in = f.name
        try:
            rc = matrix.main(["--input", tmp_in])
            self.assertEqual(1, rc)
        finally:
            Path(tmp_in).unlink(missing_ok=True)

    def test_output_file_written(self):
        import tempfile
        spec = _make_passing_spec()
        with tempfile.TemporaryDirectory() as tmp:
            inp = Path(tmp) / "spec.json"
            out = Path(tmp) / "report.json"
            inp.write_text(json.dumps(spec))
            matrix.main(["--input", str(inp), "--output", str(out)])
            self.assertTrue(out.exists())
            data = json.loads(out.read_text())
            self.assertIn("status", data)

    def test_missing_input_file_returns_2(self):
        rc = matrix.main(["--input", "/nonexistent/path/spec.json"])
        self.assertEqual(2, rc)


if __name__ == "__main__":
    unittest.main()
