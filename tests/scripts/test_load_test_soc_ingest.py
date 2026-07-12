"""ARCH-DB-SPLIT: concurrent write-throughput soak generator added to
load_test_soc.py (split_jsonl / ingest_worker / ingest_telemetry_load).
Mocks subprocess.run (no live Postgres/ClickHouse in this environment) --
these tests verify the sharding/concurrency/aggregation logic itself, not
the real database write, which the ingest_telemetry_events.py-level tests
(test_ingest_telemetry_events.py) already cover with mocked HTTP for the
ClickHouse path.
"""
from __future__ import annotations

import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch, MagicMock

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import load_test_soc as m  # noqa: E402


class TestSplitJsonl(unittest.TestCase):
    def setUp(self):
        fd, name = tempfile.mkstemp(suffix=".jsonl")
        os.close(fd)
        self.tmp = Path(name)
        self.tmp.write_text("\n".join(f'{{"n":{i}}}' for i in range(10)), encoding="utf-8")

    def tearDown(self):
        self.tmp.unlink(missing_ok=True)

    def test_concurrency_one_returns_original_path_unchanged(self):
        shards = m.split_jsonl(self.tmp, 1)
        self.assertEqual(shards, [self.tmp])

    def test_splits_into_requested_number_of_roughly_equal_shards(self):
        shards = m.split_jsonl(self.tmp, 3)
        self.assertEqual(len(shards), 3)
        total_lines = sum(len(p.read_text(encoding="utf-8").splitlines()) for p in shards)
        self.assertEqual(total_lines, 10)

    def test_falls_back_to_single_file_when_parts_exceed_line_count(self):
        shards = m.split_jsonl(self.tmp, 100)
        self.assertEqual(shards, [self.tmp])

    def test_shards_are_disjoint_and_preserve_all_lines(self):
        shards = m.split_jsonl(self.tmp, 4)
        seen = []
        for shard in shards:
            seen.extend(shard.read_text(encoding="utf-8").splitlines())
        self.assertEqual(sorted(seen), sorted(f'{{"n":{i}}}' for i in range(10)))


class TestIngestWorker(unittest.TestCase):
    def test_worker_invokes_ingest_script_with_target_and_offset_file(self):
        shard = Path("storage/logs/fake_shard.jsonl")
        fake_proc = MagicMock(returncode=0, stdout="Processed: 42\n", stderr="")
        with patch.object(m.subprocess, "run", return_value=fake_proc) as run_mock:
            result = m.ingest_worker(shard, "", 500, "postgres", {})

        args = run_mock.call_args[0][0]
        self.assertIn("--target", args)
        self.assertIn("postgres", args)
        self.assertIn("--offset-file", args)
        self.assertEqual(result["events"], 42)
        self.assertEqual(result["exit_code"], 0)

    def test_worker_passes_clickhouse_connection_args_when_target_is_clickhouse(self):
        shard = Path("storage/logs/fake_shard.jsonl")
        fake_proc = MagicMock(returncode=0, stdout="Processed: 7\n", stderr="")
        opts = {"url": "http://ch.test:8123", "db": "detector_analytics", "user": "u", "password": "p"}
        with patch.object(m.subprocess, "run", return_value=fake_proc) as run_mock:
            m.ingest_worker(shard, "", 500, "clickhouse", opts)

        args = run_mock.call_args[0][0]
        self.assertIn("--clickhouse-url", args)
        self.assertIn("http://ch.test:8123", args)

    def test_worker_reports_nonzero_exit_code_on_failure(self):
        shard = Path("storage/logs/fake_shard.jsonl")
        fake_proc = MagicMock(returncode=1, stdout="", stderr="connection refused")
        with patch.object(m.subprocess, "run", return_value=fake_proc):
            result = m.ingest_worker(shard, "", 500, "postgres", {})

        self.assertEqual(result["exit_code"], 1)
        self.assertEqual(result["events"], 0)


class TestIngestTelemetryLoad(unittest.TestCase):
    def setUp(self):
        fd, name = tempfile.mkstemp(suffix=".jsonl")
        os.close(fd)
        self.tmp = Path(name)
        self.tmp.write_text("\n".join(f'{{"n":{i}}}' for i in range(20)), encoding="utf-8")

    def tearDown(self):
        self.tmp.unlink(missing_ok=True)

    def test_aggregates_events_across_concurrent_workers(self):
        def fake_worker(shard, dsn, batch_size, target, opts):
            lines = len(shard.read_text(encoding="utf-8").splitlines())
            return {"shard": str(shard), "exit_code": 0, "events": lines, "elapsed_sec": 0.01, "stderr_tail": ""}

        with patch.object(m, "ingest_worker", side_effect=fake_worker):
            result = m.ingest_telemetry_load(self.tmp, "", 500, target="postgres", concurrency=4)

        self.assertEqual(result["events"], 20)
        self.assertEqual(result["concurrency"], 4)
        self.assertEqual(result["worker_failures"], 0)
        self.assertEqual(result["exit_code"], 0)
        self.assertGreater(result["ingest_events_per_sec"], 0)

    def test_reports_worker_failures_and_nonzero_exit_code(self):
        def fake_worker(shard, dsn, batch_size, target, opts):
            return {"shard": str(shard), "exit_code": 1, "events": 0, "elapsed_sec": 0.01, "stderr_tail": "boom"}

        with patch.object(m, "ingest_worker", side_effect=fake_worker):
            result = m.ingest_telemetry_load(self.tmp, "", 500, target="postgres", concurrency=2)

        self.assertEqual(result["exit_code"], 1)
        self.assertGreater(result["worker_failures"], 0)

    def test_default_concurrency_is_a_single_sequential_worker(self):
        calls = []

        def fake_worker(shard, dsn, batch_size, target, opts):
            calls.append(shard)
            return {"shard": str(shard), "exit_code": 0, "events": 20, "elapsed_sec": 0.01, "stderr_tail": ""}

        with patch.object(m, "ingest_worker", side_effect=fake_worker):
            m.ingest_telemetry_load(self.tmp, "", 500)

        self.assertEqual(calls, [self.tmp])

    def test_target_clickhouse_is_propagated_to_workers(self):
        seen_targets = []

        def fake_worker(shard, dsn, batch_size, target, opts):
            seen_targets.append(target)
            return {"shard": str(shard), "exit_code": 0, "events": 5, "elapsed_sec": 0.01, "stderr_tail": ""}

        with patch.object(m, "ingest_worker", side_effect=fake_worker):
            m.ingest_telemetry_load(self.tmp, "", 500, target="clickhouse", concurrency=2)

        self.assertTrue(all(t == "clickhouse" for t in seen_targets))


if __name__ == "__main__":
    unittest.main(verbosity=2)
