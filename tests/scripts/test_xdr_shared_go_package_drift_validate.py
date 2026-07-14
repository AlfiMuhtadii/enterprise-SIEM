"""INTERNAL-RUNTIME-SDK: scripts/xdr_shared_go_package_drift_validate.py.

Exercises the drift check against temporary canonical/dependent directories
rather than the real tools/shared-go/mtls + services/*/internal/mtls trees,
so these tests never depend on (or risk mutating) real source."""
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

        self.canonical_dir = self.root / "tools" / "shared-go" / "mtls"
        self.canonical_dir.mkdir(parents=True)
        (self.canonical_dir / "mtls.go").write_text("package mtls\n// canonical\n")
        (self.canonical_dir / "mtls_test.go").write_text("package mtls\n// canonical test\n")

        self.dep_dirs = []
        for name in ["service-a", "service-b"]:
            dep_dir = self.root / "services" / name / "internal" / "mtls"
            dep_dir.mkdir(parents=True)
            (dep_dir / "mtls.go").write_text("package mtls\n// canonical\n")
            (dep_dir / "mtls_test.go").write_text("package mtls\n// canonical test\n")
            self.dep_dirs.append(dep_dir)

        self._orig_root = drift.ROOT
        self._orig_canonical = drift.CANONICAL_DIR
        self._orig_dependents = drift.DEPENDENT_DIRS
        drift.ROOT = self.root
        drift.CANONICAL_DIR = self.canonical_dir
        drift.DEPENDENT_DIRS = self.dep_dirs
        self.addCleanup(self._restore_module_state)

    def _restore_module_state(self):
        drift.ROOT = self._orig_root
        drift.CANONICAL_DIR = self._orig_canonical
        drift.DEPENDENT_DIRS = self._orig_dependents

    def _run(self, argv):
        old_argv = sys.argv
        sys.argv = ["xdr_shared_go_package_drift_validate.py"] + argv
        try:
            return drift.main()
        finally:
            sys.argv = old_argv

    def test_passes_when_all_dependents_match_canonical(self):
        self.assertEqual(self._run([]), 0)

    def test_fails_when_a_dependent_has_drifted(self):
        (self.dep_dirs[0] / "mtls.go").write_text("package mtls\n// DRIFTED\n")

        self.assertEqual(self._run([]), 1)

    def test_fails_when_a_dependent_file_is_missing(self):
        (self.dep_dirs[1] / "mtls_test.go").unlink()

        self.assertEqual(self._run([]), 1)

    def test_sync_overwrites_every_dependent_with_canonical_content(self):
        (self.dep_dirs[0] / "mtls.go").write_text("package mtls\n// DRIFTED\n")
        (self.dep_dirs[1] / "mtls_test.go").unlink()

        self.assertEqual(self._run(["--sync"]), 0)

        for dep_dir in self.dep_dirs:
            self.assertEqual((dep_dir / "mtls.go").read_text(), (self.canonical_dir / "mtls.go").read_text())
            self.assertEqual((dep_dir / "mtls_test.go").read_text(), (self.canonical_dir / "mtls_test.go").read_text())

        self.assertEqual(self._run([]), 0)

    def test_errors_when_canonical_source_is_missing(self):
        (self.canonical_dir / "mtls.go").unlink()

        self.assertEqual(self._run([]), 2)


if __name__ == "__main__":
    unittest.main()
