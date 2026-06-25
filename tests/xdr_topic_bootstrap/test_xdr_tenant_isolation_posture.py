"""Tests for scripts/xdr_tenant_isolation_posture.py — ENTERPRISE-040.

All tests are deterministic and offline.  No DB connection, no filesystem writes,
no real project tree needed.  Each check is driven via _read_fn injection.
"""
from __future__ import annotations

import json
import sys
import unittest
from pathlib import Path

_SCRIPTS = Path(__file__).resolve().parent.parent.parent / "scripts"
if str(_SCRIPTS) not in sys.path:
    sys.path.insert(0, str(_SCRIPTS))

import xdr_tenant_isolation_posture as tip  # noqa: E402


# ---------------------------------------------------------------------------
# Fixture helpers
# ---------------------------------------------------------------------------

_BOUNDARY_PRESENT = """<?php
class TenantBoundaryService {
    public const RLS_ENABLED = false;
    public const USER_MODEL_HAS_TENANT_ID = false;
    public const USER_TENANT_MEMBERSHIPS_SUPPORTED = true;
    public const ISOLATED_TABLES = [
        'advisory_findings', 'advisory_finding_events',
        'dlq_records', 'dlq_normalization_events',
        'shadow_soak_runs', 'shadow_soak_evidence_snapshots',
        'shadow_soak_gate_checks', 'shadow_soak_domain_assessments',
        'shadow_soak_finding_summaries', 'shadow_soak_confidence_bands',
        'shadow_soak_suppression_stats', 'shadow_soak_coverage_stats',
        'shadow_soak_audit_events', 'security_alerts', 'security_incidents',
        'user_tenant_memberships', 'tenant_membership_audit_events',
    ];
    public const UNISOLATED_TABLES = [
        'users', 'security_audit_trails', 'telemetry_events',
        'endpoint_agents', 'endpoint_agent_heartbeats',
    ];
}
"""

_BOUNDARY_RLS_TRUE = _BOUNDARY_PRESENT.replace(
    "RLS_ENABLED = false", "RLS_ENABLED = true"
)

_BOUNDARY_NO_RLS_CONSTANT = """<?php
class TenantBoundaryService {
    public const ISOLATED_TABLES = ['security_alerts'];
    public const UNISOLATED_TABLES = ['users'];
}
"""

_STRICT_MODE_DOC = """# Tenant Strict Mode
XDR_TENANT_STRICT_MODE=false is the default.
Set to true to enforce strict tenant header requirements.
"""

_NULL_MIGRATION_PLAN = """# Tenant Null Migration Plan
## Phase 5 — PostgreSQL Row-Level Security
ALTER TABLE security_alerts ENABLE ROW LEVEL SECURITY;
"""

_POSTURE_DOC = "# Tenant Isolation Posture\nCurrent RLS posture: app-layer only.\n"
_RLS_DECISION = "# RLS Decision Record\nDecision: Option A.\n"
_CONTEXT_AUTHORITY = "<?php\nclass TenantContextAuthority {}\n"
_NULL_AUDIT_CMD = "<?php\nclass TenantNullAuditCommand {}\n"

# Minimal test files
_TENANT_TEST = "<?php\nclass TenantIsolationHardeningTest {}\n"


def _make_read_fn(file_map: dict[Path | str, str | None]):
    """Return a _read_fn that resolves Path keys against the given map."""
    normalised = {str(k): v for k, v in file_map.items()}

    def _read(path: Path) -> str | None:
        return normalised.get(str(path))

    return _read


def _full_pass_read_fn():
    """_read_fn that makes every check pass."""
    return _make_read_fn({
        tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT,
        tip.CONTEXT_AUTHORITY_PATH: _CONTEXT_AUTHORITY,
        tip.NULL_AUDIT_CMD_PATH: _NULL_AUDIT_CMD,
        tip.POSTURE_DOC_PATH: _POSTURE_DOC,
        tip.RLS_DECISION_RECORD_PATH: _RLS_DECISION,
        tip.STRICT_MODE_DOC_PATH: _STRICT_MODE_DOC,
        tip.NULL_MIGRATION_PLAN_PATH: _NULL_MIGRATION_PLAN,
        # migration sentinels
        Path("_migration_alerts_present"): "exists",
        Path("_migration_indexes_present"): "exists",
        # test files
        tip.TENANT_TESTS_DIR / "TenantIsolationHardeningTest.php": _TENANT_TEST,
        tip.TENANT_TESTS_DIR / "TenantContextAuthorityTest.php": _TENANT_TEST,
        tip.TENANT_TESTS_DIR / "TenantStrictModeTest.php": _TENANT_TEST,
    })


ROOT = Path("/fake/root")


# ---------------------------------------------------------------------------
# TIP-01  RLS_DISABLED_CONFIRMED
# ---------------------------------------------------------------------------

class TestTip01RlsDisabledConfirmed(unittest.TestCase):

    def test_pass_when_rls_enabled_false(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_rls_disabled(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)
        self.assertFalse(r["evidence"]["rls_enabled"])

    def test_fail_when_rls_enabled_true(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_RLS_TRUE})
        r = tip.check_rls_disabled(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)
        self.assertTrue(r["evidence"]["rls_enabled"])

    def test_fail_when_constant_missing(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_NO_RLS_CONSTANT})
        r = tip.check_rls_disabled(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)

    def test_fail_when_file_absent(self):
        fn = _make_read_fn({})
        r = tip.check_rls_disabled(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)
        self.assertIn("not found", r["detail"])

    def test_check_id(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_rls_disabled(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "TIP-01")


# ---------------------------------------------------------------------------
# TIP-02  ISOLATED_REGISTRY_PRESENT
# ---------------------------------------------------------------------------

class TestTip02IsolatedRegistryPresent(unittest.TestCase):

    def test_pass_when_constant_present(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_isolated_registry_present(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_fail_when_constant_absent(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: "<?php class TenantBoundaryService {}"})
        r = tip.check_isolated_registry_present(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)

    def test_fail_when_file_absent(self):
        fn = _make_read_fn({})
        r = tip.check_isolated_registry_present(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-03  ISOLATED_REGISTRY_COUNT
# ---------------------------------------------------------------------------

class TestTip03IsolatedRegistryCount(unittest.TestCase):

    def test_pass_when_count_meets_minimum(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_isolated_registry_count(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)
        self.assertGreaterEqual(r["evidence"]["isolated_table_count"], tip.MIN_ISOLATED_TABLES)

    def test_fail_when_count_below_minimum(self):
        sparse = "<?php\nclass T {\n    public const ISOLATED_TABLES = ['security_alerts'];\n}\n"
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: sparse})
        r = tip.check_isolated_registry_count(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)
        self.assertEqual(r["evidence"]["isolated_table_count"], 1)

    def test_fail_when_array_not_parseable(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: "<?php\nconst ISOLATED_TABLES = 'not_array';"})
        r = tip.check_isolated_registry_count(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)

    def test_evidence_contains_min_key(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_isolated_registry_count(ROOT, "local", fn)
        self.assertIn("min", r["evidence"])
        self.assertEqual(r["evidence"]["min"], tip.MIN_ISOLATED_TABLES)


# ---------------------------------------------------------------------------
# TIP-04  UNISOLATED_REGISTRY_PRESENT
# ---------------------------------------------------------------------------

class TestTip04UnisolatedRegistryPresent(unittest.TestCase):

    def test_pass_when_unisolated_present(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_unisolated_registry_present(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_warn_local_when_constant_absent(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: "<?php class T { public const ISOLATED_TABLES = ['x']; }"})
        r = tip.check_unisolated_registry_present(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.WARN)

    def test_fail_staging_when_constant_absent(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: "<?php class T { public const ISOLATED_TABLES = ['x']; }"})
        r = tip.check_unisolated_registry_present(ROOT, "staging", fn)
        self.assertEqual(r["status"], tip.FAIL)

    def test_evidence_count_correct(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_unisolated_registry_present(ROOT, "local", fn)
        self.assertEqual(r["evidence"]["unisolated_table_count"], 5)


# ---------------------------------------------------------------------------
# TIP-05  TENANT_ID_MIGRATION_PRESENT
# ---------------------------------------------------------------------------

class TestTip05TenantIdMigration(unittest.TestCase):

    def test_pass_when_sentinel_present(self):
        fn = _make_read_fn({Path("_migration_alerts_present"): "exists"})
        r = tip.check_tenant_id_migration(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_fail_when_sentinel_absent(self):
        fn = _make_read_fn({})
        r = tip.check_tenant_id_migration(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)

    def test_check_id(self):
        fn = _make_read_fn({Path("_migration_alerts_present"): "exists"})
        r = tip.check_tenant_id_migration(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "TIP-05")


# ---------------------------------------------------------------------------
# TIP-06  TENANT_ID_INDEX_MIGRATION
# ---------------------------------------------------------------------------

class TestTip06TenantIdIndexMigration(unittest.TestCase):

    def test_pass_when_sentinel_present(self):
        fn = _make_read_fn({Path("_migration_indexes_present"): "exists"})
        r = tip.check_tenant_id_index_migration(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_fail_when_sentinel_absent(self):
        fn = _make_read_fn({})
        r = tip.check_tenant_id_index_migration(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-07  POSTURE_DOC_PRESENT
# ---------------------------------------------------------------------------

class TestTip07PostureDoc(unittest.TestCase):

    def test_pass_when_present(self):
        fn = _make_read_fn({tip.POSTURE_DOC_PATH: _POSTURE_DOC})
        r = tip.check_posture_doc(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_warn_local_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_posture_doc(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.WARN)

    def test_fail_production_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_posture_doc(ROOT, "production", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-08  RLS_DECISION_RECORD_PRESENT
# ---------------------------------------------------------------------------

class TestTip08RlsDecisionRecord(unittest.TestCase):

    def test_pass_when_present(self):
        fn = _make_read_fn({tip.RLS_DECISION_RECORD_PATH: _RLS_DECISION})
        r = tip.check_rls_decision_record(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_warn_local_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_rls_decision_record(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.WARN)

    def test_fail_staging_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_rls_decision_record(ROOT, "staging", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-09  STRICT_MODE_DOC_PRESENT
# ---------------------------------------------------------------------------

class TestTip09StrictModeDoc(unittest.TestCase):

    def test_pass_when_present(self):
        fn = _make_read_fn({tip.STRICT_MODE_DOC_PATH: _STRICT_MODE_DOC})
        r = tip.check_strict_mode_doc(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_warn_local_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_strict_mode_doc(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.WARN)

    def test_fail_production_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_strict_mode_doc(ROOT, "production", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-10  NULL_MIGRATION_PLAN_PRESENT
# ---------------------------------------------------------------------------

class TestTip10NullMigrationPlan(unittest.TestCase):

    def test_pass_when_present(self):
        fn = _make_read_fn({tip.NULL_MIGRATION_PLAN_PATH: _NULL_MIGRATION_PLAN})
        r = tip.check_null_migration_plan(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_warn_local_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_null_migration_plan(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.WARN)

    def test_fail_production_when_absent(self):
        fn = _make_read_fn({})
        r = tip.check_null_migration_plan(ROOT, "production", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-11  APP_LAYER_TESTS_PRESENT
# ---------------------------------------------------------------------------

class TestTip11AppLayerTests(unittest.TestCase):

    def test_pass_when_all_three_present(self):
        fn = _make_read_fn({
            tip.TENANT_TESTS_DIR / "TenantIsolationHardeningTest.php": _TENANT_TEST,
            tip.TENANT_TESTS_DIR / "TenantContextAuthorityTest.php": _TENANT_TEST,
            tip.TENANT_TESTS_DIR / "TenantStrictModeTest.php": _TENANT_TEST,
        })
        r = tip.check_app_layer_tests(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)
        self.assertEqual(len(r["evidence"]["found"]), 3)

    def test_warn_local_when_fewer_than_minimum(self):
        fn = _make_read_fn({
            tip.TENANT_TESTS_DIR / "TenantIsolationHardeningTest.php": _TENANT_TEST,
        })
        r = tip.check_app_layer_tests(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.WARN)

    def test_fail_production_when_fewer_than_minimum(self):
        fn = _make_read_fn({
            tip.TENANT_TESTS_DIR / "TenantIsolationHardeningTest.php": _TENANT_TEST,
        })
        r = tip.check_app_layer_tests(ROOT, "production", fn)
        self.assertEqual(r["status"], tip.FAIL)

    def test_required_evidence_key_present(self):
        fn = _make_read_fn({})
        r = tip.check_app_layer_tests(ROOT, "local", fn)
        self.assertIn("required", r["evidence"])
        self.assertEqual(len(r["evidence"]["required"]), 3)


# ---------------------------------------------------------------------------
# TIP-12  BOUNDARY_SERVICE_PRESENT
# ---------------------------------------------------------------------------

class TestTip12BoundaryService(unittest.TestCase):

    def test_pass_when_file_present(self):
        fn = _make_read_fn({tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT})
        r = tip.check_boundary_service(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_fail_when_file_absent(self):
        fn = _make_read_fn({})
        r = tip.check_boundary_service(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-13  CONTEXT_AUTHORITY_PRESENT
# ---------------------------------------------------------------------------

class TestTip13ContextAuthority(unittest.TestCase):

    def test_pass_when_file_present(self):
        fn = _make_read_fn({tip.CONTEXT_AUTHORITY_PATH: _CONTEXT_AUTHORITY})
        r = tip.check_context_authority(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_fail_when_file_absent(self):
        fn = _make_read_fn({})
        r = tip.check_context_authority(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# TIP-14  NULL_AUDIT_COMMAND_PRESENT
# ---------------------------------------------------------------------------

class TestTip14NullAuditCommand(unittest.TestCase):

    def test_pass_when_file_present(self):
        fn = _make_read_fn({tip.NULL_AUDIT_CMD_PATH: _NULL_AUDIT_CMD})
        r = tip.check_null_audit_command(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.PASS)

    def test_fail_when_file_absent(self):
        fn = _make_read_fn({})
        r = tip.check_null_audit_command(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.FAIL)


# ---------------------------------------------------------------------------
# Advisory A-01  STRICT_MODE_DEFAULT
# ---------------------------------------------------------------------------

class TestAdvisoryStrictModeDefault(unittest.TestCase):

    def test_info_default_documented(self):
        fn = _make_read_fn({tip.STRICT_MODE_DOC_PATH: _STRICT_MODE_DOC})
        r = tip.advisory_strict_mode_default(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.INFO)
        self.assertTrue(r["evidence"]["documented"])

    def test_info_doc_absent(self):
        fn = _make_read_fn({})
        r = tip.advisory_strict_mode_default(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.INFO)

    def test_info_regardless_of_profile(self):
        fn = _make_read_fn({})
        for profile in ("local", "staging", "production"):
            r = tip.advisory_strict_mode_default(ROOT, profile, fn)
            self.assertEqual(r["status"], tip.INFO, f"profile={profile}")

    def test_check_id(self):
        fn = _make_read_fn({})
        r = tip.advisory_strict_mode_default(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "A-01")


# ---------------------------------------------------------------------------
# Advisory A-02  RLS_ROADMAP_PRESENT
# ---------------------------------------------------------------------------

class TestAdvisoryRlsRoadmap(unittest.TestCase):

    def test_info_when_roadmap_found(self):
        fn = _make_read_fn({tip.NULL_MIGRATION_PLAN_PATH: _NULL_MIGRATION_PLAN})
        r = tip.advisory_rls_roadmap(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.INFO)
        self.assertTrue(len(r["evidence"]["found_in"]) > 0)

    def test_info_when_not_found(self):
        fn = _make_read_fn({
            tip.NULL_MIGRATION_PLAN_PATH: "# No roadmap here.",
            tip.POSTURE_DOC_PATH: "# Posture doc.",
            tip.RLS_DECISION_RECORD_PATH: "# Decision.",
        })
        r = tip.advisory_rls_roadmap(ROOT, "local", fn)
        self.assertEqual(r["status"], tip.INFO)
        self.assertEqual(r["evidence"]["found_in"], [])

    def test_info_regardless_of_profile(self):
        fn = _make_read_fn({})
        for profile in ("local", "staging", "production"):
            r = tip.advisory_rls_roadmap(ROOT, profile, fn)
            self.assertEqual(r["status"], tip.INFO, f"profile={profile}")

    def test_check_id(self):
        fn = _make_read_fn({})
        r = tip.advisory_rls_roadmap(ROOT, "local", fn)
        self.assertEqual(r["check_id"], "A-02")


# ---------------------------------------------------------------------------
# run_all — aggregate report
# ---------------------------------------------------------------------------

class TestRunAll(unittest.TestCase):

    def test_overall_pass_when_all_checks_pass(self):
        fn = _full_pass_read_fn()
        report = tip.run_all(ROOT, "local", fn)
        self.assertEqual(report["overall"], tip.PASS)

    def test_overall_fail_when_critical_check_fails(self):
        # Remove boundary service → TIP-01,02,03,04,12 fail
        fn = _make_read_fn({
            tip.CONTEXT_AUTHORITY_PATH: _CONTEXT_AUTHORITY,
            tip.NULL_AUDIT_CMD_PATH: _NULL_AUDIT_CMD,
        })
        report = tip.run_all(ROOT, "local", fn)
        self.assertEqual(report["overall"], tip.FAIL)

    def test_overall_warn_when_only_warnings(self):
        # Missing docs → WARN at local profile, no FAIL
        fn = _make_read_fn({
            tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT,
            tip.CONTEXT_AUTHORITY_PATH: _CONTEXT_AUTHORITY,
            tip.NULL_AUDIT_CMD_PATH: _NULL_AUDIT_CMD,
            Path("_migration_alerts_present"): "exists",
            Path("_migration_indexes_present"): "exists",
            # NO docs present → TIP-07..11 are WARN locally
        })
        report = tip.run_all(ROOT, "local", fn)
        # Should not be FAIL (no FAIL-only checks missing), may be WARN or PASS
        self.assertIn(report["overall"], (tip.PASS, tip.WARN))

    def test_summary_counts_correct(self):
        fn = _full_pass_read_fn()
        report = tip.run_all(ROOT, "local", fn)
        s = report["summary"]
        self.assertEqual(s["total"], 14)
        self.assertEqual(s["advisory"], 2)
        self.assertEqual(s["failed"], 0)

    def test_rls_posture_block_present(self):
        fn = _full_pass_read_fn()
        report = tip.run_all(ROOT, "local", fn)
        rp = report["rls_posture"]
        self.assertFalse(rp["rls_enabled"])
        self.assertEqual(rp["enforcement_layer"], "application")
        self.assertIn("decision_record", rp)

    def test_required_report_fields(self):
        fn = _full_pass_read_fn()
        report = tip.run_all(ROOT, "local", fn)
        for field in ("task", "validator", "profile", "timestamp", "overall", "summary", "checks"):
            self.assertIn(field, report)

    def test_checks_list_length(self):
        fn = _full_pass_read_fn()
        report = tip.run_all(ROOT, "local", fn)
        # 14 primary + 2 advisory
        self.assertEqual(len(report["checks"]), 16)

    def test_production_profile_stricter(self):
        # missing docs are WARN locally but FAIL in production
        fn = _make_read_fn({
            tip.BOUNDARY_SERVICE_PATH: _BOUNDARY_PRESENT,
            tip.CONTEXT_AUTHORITY_PATH: _CONTEXT_AUTHORITY,
            tip.NULL_AUDIT_CMD_PATH: _NULL_AUDIT_CMD,
            Path("_migration_alerts_present"): "exists",
            Path("_migration_indexes_present"): "exists",
        })
        local_report = tip.run_all(ROOT, "local", fn)
        prod_report = tip.run_all(ROOT, "production", fn)
        self.assertGreaterEqual(prod_report["summary"]["failed"],
                                local_report["summary"]["failed"])


# ---------------------------------------------------------------------------
# JSON serialisability
# ---------------------------------------------------------------------------

class TestJsonSerialisability(unittest.TestCase):

    def test_full_pass_report_is_json_serialisable(self):
        fn = _full_pass_read_fn()
        report = tip.run_all(ROOT, "local", fn)
        serialised = json.dumps(report)
        self.assertIsInstance(serialised, str)
        decoded = json.loads(serialised)
        self.assertEqual(decoded["overall"], tip.PASS)

    def test_full_fail_report_is_json_serialisable(self):
        fn = _make_read_fn({})
        report = tip.run_all(ROOT, "production", fn)
        serialised = json.dumps(report)
        decoded = json.loads(serialised)
        self.assertEqual(decoded["overall"], tip.FAIL)


if __name__ == "__main__":
    unittest.main()
