"""INTERNAL-RUNTIME-SDK: scripts/xdr_shared_go_package_drift_validate.py.

Exercises the drift check against temporary canonical/dependent directories
for two synthetic families, rather than the real tools/shared-go/{mtls,
deliver} + services/*/internal/{mtls,deliver} trees, so these tests never
depend on (or risk mutating) real source."""
from __future__ import annotations

import sys
import tempfile
import unittest
from pathlib import Path

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import xdr_shared_go_package_drift_validate as drift  # noqa: E402


class TestSharedGoPackageDriftValidate(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.root = Path(self._tmp.name)

        self.families = {}
        for family_name, dep_names in [("famone", ["service-a", "service-b"]), ("famtwo", ["service-c"])]:
            canonical_dir = self.root / "tools" / "shared-go" / family_name
            canonical_dir.mkdir(parents=True)
            (canonical_dir / "helper.go").write_text("package helper\n// canonical\n")
            (canonical_dir / "helper_test.go").write_text("package helper\n// canonical test\n")

            dep_dirs = []
            for dep_name in dep_names:
                dep_dir = self.root / "services" / dep_name / "internal" / family_name
                dep_dir.mkdir(parents=True)
                (dep_dir / "helper.go").write_text("package helper\n// canonical\n")
                (dep_dir / "helper_test.go").write_text("package helper\n// canonical test\n")
                dep_dirs.append(dep_dir)

            self.families[family_name] = {
                "canonical_dir": canonical_dir,
                "files": ["helper.go", "helper_test.go"],
                "dependents": dep_dirs,
            }

        self._orig_root = drift.ROOT
        self._orig_families = drift.FAMILIES
        drift.ROOT = self.root
        drift.FAMILIES = self.families
        self.addCleanup(self._restore_module_state)

    def _restore_module_state(self):
        drift.ROOT = self._orig_root
        drift.FAMILIES = self._orig_families

    def _run(self, argv):
        old_argv = sys.argv
        sys.argv = ["xdr_shared_go_package_drift_validate.py"] + argv
        try:
            return drift.main()
        finally:
            sys.argv = old_argv

    def test_passes_when_all_families_match_canonical(self):
        self.assertEqual(self._run([]), 0)

    def test_fails_when_one_family_has_drifted(self):
        dep_dirs = self.families["famone"]["dependents"]
        (dep_dirs[0] / "helper.go").write_text("package helper\n// DRIFTED\n")

        self.assertEqual(self._run([]), 1)

    def test_fails_when_a_dependent_file_is_missing(self):
        dep_dirs = self.families["famtwo"]["dependents"]
        (dep_dirs[0] / "helper_test.go").unlink()

        self.assertEqual(self._run([]), 1)

    def test_family_flag_scopes_check_to_a_single_family(self):
        dep_dirs = self.families["famone"]["dependents"]
        (dep_dirs[0] / "helper.go").write_text("package helper\n// DRIFTED\n")

        # famone has drifted, but famtwo hasn't -- scoping to famtwo only
        # must not see famone's drift.
        self.assertEqual(self._run(["--family", "famtwo"]), 0)
        self.assertEqual(self._run(["--family", "famone"]), 1)
        self.assertEqual(self._run([]), 1)

    def test_sync_overwrites_every_dependent_in_every_family_with_canonical_content(self):
        self.families["famone"]["dependents"][0].joinpath("helper.go").write_text("package helper\n// DRIFTED\n")
        self.families["famtwo"]["dependents"][0].joinpath("helper_test.go").unlink()

        self.assertEqual(self._run(["--sync"]), 0)

        for family in self.families.values():
            for dep_dir in family["dependents"]:
                for filename in family["files"]:
                    self.assertEqual((dep_dir / filename).read_text(), (family["canonical_dir"] / filename).read_text())

        self.assertEqual(self._run([]), 0)

    def test_sync_with_family_flag_only_touches_that_family(self):
        self.families["famone"]["dependents"][0].joinpath("helper.go").write_text("package helper\n// DRIFTED\n")
        self.families["famtwo"]["dependents"][0].joinpath("helper.go").write_text("package helper\n// ALSO DRIFTED\n")

        self.assertEqual(self._run(["--sync", "--family", "famone"]), 0)

        self.assertEqual(self._run(["--family", "famone"]), 0)
        self.assertEqual(self._run(["--family", "famtwo"]), 1)

    def test_errors_when_canonical_source_is_missing(self):
        (self.families["famone"]["canonical_dir"] / "helper.go").unlink()

        self.assertEqual(self._run([]), 2)


if __name__ == "__main__":
    unittest.main()
