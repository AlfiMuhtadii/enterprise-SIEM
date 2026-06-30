"""PERF-SUBPROCESS-POLL: the ClickHouse sync daemon runs the sync in-process
(importing the module and calling main([])) instead of spawning a subprocess."""
from __future__ import annotations

import sys
import unittest
from pathlib import Path
from unittest.mock import patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import clickhouse_sync_daemon as daemon  # noqa: E402
import sync_postgres_to_clickhouse as ch_sync  # noqa: E402


class TestInProcessSync(unittest.TestCase):
    def test_daemon_does_not_import_subprocess(self):
        # The whole point of the refactor: no per-cycle process spawn.
        self.assertFalse(hasattr(daemon, "subprocess"))

    def test_run_once_calls_sync_main_in_process_with_empty_argv(self):
        with patch.object(daemon.ch_sync, "main", return_value=0) as m:
            rc = daemon.run_once()
        m.assert_called_once_with([])
        self.assertEqual(rc, 0)

    def test_run_once_swallows_exceptions_and_returns_1(self):
        with patch.object(daemon.ch_sync, "main", side_effect=RuntimeError("db down")):
            rc = daemon.run_once()  # must not raise
        self.assertEqual(rc, 1)

    def test_run_once_propagates_nonzero_return(self):
        with patch.object(daemon.ch_sync, "main", return_value=1):
            self.assertEqual(daemon.run_once(), 1)

    def test_sync_parse_args_accepts_explicit_argv(self):
        ns = ch_sync.parse_args([])
        self.assertEqual(ns.batch_size, 1000)
        ns2 = ch_sync.parse_args(["--batch-size", "50"])
        self.assertEqual(ns2.batch_size, 50)


if __name__ == "__main__":
    unittest.main(verbosity=2)
