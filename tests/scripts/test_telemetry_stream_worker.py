"""TELEMETRY-WORKER-COMMIT-AFTER-WRITE: telemetry_stream_worker.py's
manual-commit-after-durable-flush behavior, bounded retry with backoff, and
heartbeat-based liveness. No live Kafka/Postgres/ClickHouse in this
environment -- the core batch/commit loop is deliberately decoupled from
KafkaConsumer (run_consume_loop/iter_consumer_messages take a plain
iterable and injected callbacks), so it's exercised directly against fakes
rather than a mocked KafkaConsumer.
"""
from __future__ import annotations

import sys
import tempfile
import time
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import MagicMock

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import telemetry_stream_worker as m  # noqa: E402


class FakeMsg(SimpleNamespace):
    pass


VALID_EVENT = {
    "schema_version": 1,
    "ts": "2026-07-14T00:00:00Z",
    "event_id": "evt-1",
    "telemetry_type": "endpoint",
    "event_type": "connection_delta",
    "host_id": "host-a",
}


class TestFlushWithRetry(unittest.TestCase):
    def test_succeeds_on_first_try_without_sleeping(self):
        sleeps = []
        flush_fn = MagicMock(return_value=3)

        result = m.flush_with_retry(flush_fn, ["row"], sleep_fn=sleeps.append)

        self.assertEqual(result, 3)
        self.assertEqual(sleeps, [])
        flush_fn.assert_called_once_with(["row"])

    def test_retries_with_exponential_backoff_then_succeeds(self):
        sleeps = []
        attempts = {"n": 0}

        def flush_fn(rows):
            attempts["n"] += 1
            if attempts["n"] < 3:
                raise RuntimeError("transient")
            return len(rows)

        result = m.flush_with_retry(flush_fn, ["a", "b"], max_retries=5, base_delay=1, sleep_fn=sleeps.append)

        self.assertEqual(result, 2)
        self.assertEqual(attempts["n"], 3)
        self.assertEqual(sleeps, [1, 2])

    def test_caps_backoff_at_max_delay(self):
        sleeps = []
        flush_fn = MagicMock(side_effect=RuntimeError("down"))

        with self.assertRaises(RuntimeError):
            m.flush_with_retry(flush_fn, ["a"], max_retries=6, base_delay=10, max_delay=25, sleep_fn=sleeps.append)

        self.assertEqual(sleeps, [10, 20, 25, 25, 25, 25])

    def test_raises_after_exhausting_retries_without_dropping_the_batch_silently(self):
        flush_fn = MagicMock(side_effect=RuntimeError("db down"))

        with self.assertRaises(RuntimeError):
            m.flush_with_retry(flush_fn, ["a"], max_retries=2, base_delay=0, sleep_fn=lambda _d: None)

        self.assertEqual(flush_fn.call_count, 3)


class TestConnectDbWithRetry(unittest.TestCase):
    def test_succeeds_first_try(self):
        sentinel = object()
        with unittest.mock.patch.object(m, "connect_db", return_value=sentinel) as connect:
            result = m.connect_db_with_retry("dsn", sleep_fn=lambda _d: None)
        self.assertIs(result, sentinel)
        connect.assert_called_once_with("dsn")

    def test_retries_then_succeeds(self):
        sentinel = object()
        attempts = {"n": 0}

        def fake_connect(_dsn):
            attempts["n"] += 1
            if attempts["n"] < 3:
                raise OSError("connection refused")
            return sentinel

        with unittest.mock.patch.object(m, "connect_db", side_effect=fake_connect):
            result = m.connect_db_with_retry("dsn", max_retries=5, sleep_fn=lambda _d: None)

        self.assertIs(result, sentinel)
        self.assertEqual(attempts["n"], 3)

    def test_raises_after_exhausting_retries(self):
        with unittest.mock.patch.object(m, "connect_db", side_effect=OSError("down")):
            with self.assertRaises(RuntimeError):
                m.connect_db_with_retry("dsn", max_retries=2, sleep_fn=lambda _d: None)


class TestHeartbeat(unittest.TestCase):
    def test_write_then_fresh(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "heartbeat"
            m.write_heartbeat(path)
            self.assertTrue(m.heartbeat_is_fresh(path))

    def test_stale_heartbeat_is_not_fresh(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "heartbeat"
            path.write_text(str(time.time() - 200), encoding="utf-8")
            self.assertFalse(m.heartbeat_is_fresh(path, max_age_seconds=90))

    def test_missing_heartbeat_is_not_fresh(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "does-not-exist"
            self.assertFalse(m.heartbeat_is_fresh(path))


class TestRunConsumeLoop(unittest.TestCase):
    def _run(self, messages, batch_size=2):
        flush_calls = []
        commit_calls = []
        dead_letters = []
        heartbeats = []

        def flush_fn(rows):
            flush_calls.append(list(rows))
            return len(rows)

        stats = m.run_consume_loop(
            messages,
            row_mapper=lambda v: v["event_id"],
            flush_fn=flush_fn,
            commit_fn=lambda: commit_calls.append(True),
            dead_letter_writer=lambda value, reason: dead_letters.append((value, reason)),
            heartbeat_writer=lambda: heartbeats.append(True),
            batch_size=batch_size,
        )
        return stats, flush_calls, commit_calls, dead_letters, heartbeats

    def test_flushes_and_commits_in_batch_size_chunks(self):
        events = [dict(VALID_EVENT, event_id=f"evt-{i}") for i in range(4)]
        stats, flush_calls, commit_calls, dead_letters, heartbeats = self._run(events, batch_size=2)

        self.assertEqual(stats, {"processed": 4, "inserted_attempted": 4, "invalid": 0})
        self.assertEqual(flush_calls, [["evt-0", "evt-1"], ["evt-2", "evt-3"]])
        self.assertEqual(len(commit_calls), 2)
        self.assertEqual(len(heartbeats), 2)
        self.assertEqual(dead_letters, [])

    def test_flushes_trailing_partial_batch_at_end(self):
        events = [dict(VALID_EVENT, event_id=f"evt-{i}") for i in range(3)]
        stats, flush_calls, commit_calls, _dead, _hb = self._run(events, batch_size=2)

        self.assertEqual(stats["processed"], 3)
        self.assertEqual(flush_calls, [["evt-0", "evt-1"], ["evt-2"]])
        self.assertEqual(len(commit_calls), 2)

    def test_invalid_and_non_object_messages_go_to_dead_letter_not_flush(self):
        messages = ["not-a-dict", {"telemetry_type": "endpoint"}, None]
        stats, flush_calls, commit_calls, dead_letters, _hb = self._run(messages, batch_size=10)

        self.assertEqual(stats["invalid"], 2)
        self.assertEqual(stats["processed"], 2)  # None is skipped entirely, not counted as processed
        self.assertEqual(flush_calls, [])
        self.assertEqual(commit_calls, [])
        self.assertEqual(dead_letters[0][1], "not_object")
        self.assertIn("event_id", dead_letters[1][1])  # validate_event's missing-field error

    def test_flush_failure_propagates_without_committing(self):
        """The core fix this backlog item exists for: a durable-write
        failure must never be silently swallowed with offsets committed
        anyway -- that's exactly how auto-commit could previously lose
        data. Here flush_fn raises, and the caller must see that exception
        with commit_fn never having been called for this batch."""
        events = [dict(VALID_EVENT, event_id="evt-0"), dict(VALID_EVENT, event_id="evt-1")]
        commit_calls = []

        def failing_flush(rows):
            raise RuntimeError("db down")

        with self.assertRaises(RuntimeError):
            m.run_consume_loop(
                events,
                row_mapper=lambda v: v["event_id"],
                flush_fn=failing_flush,
                commit_fn=lambda: commit_calls.append(True),
                dead_letter_writer=lambda *_a: None,
                heartbeat_writer=lambda: None,
                batch_size=2,
            )

        self.assertEqual(commit_calls, [])


class FakeConsumer:
    """Mirrors kafka-python's bounded-timeout iteration behavior: each
    `for msg in consumer:` pass yields exactly the messages in the current
    chunk, then ends (StopIteration), simulating consumer_timeout_ms
    elapsing with nothing new to deliver."""

    def __init__(self, chunks):
        self._chunks = list(chunks)

    def __iter__(self):
        chunk = self._chunks.pop(0) if self._chunks else []
        return iter(FakeMsg(value=v) for v in chunk)


class TestIterConsumerMessages(unittest.TestCase):
    def test_yields_messages_across_multiple_idle_wake_cycles(self):
        consumer = FakeConsumer([["a"], [], ["b"]])
        calls = {"n": 0}
        idle_wakes = []

        def should_stop():
            calls["n"] += 1
            return calls["n"] > 5  # exactly enough calls to drain all 3 chunks (see trace in PR notes)

        result = list(m.iter_consumer_messages(consumer, should_stop, on_idle_wake=lambda: idle_wakes.append(True)))

        self.assertEqual(result, ["a", "b"])
        self.assertEqual(len(idle_wakes), 3)  # fires after chunk0, after the empty chunk1, and after chunk2

    def test_stops_immediately_when_should_stop_is_already_true(self):
        consumer = FakeConsumer([["a"]])
        result = list(m.iter_consumer_messages(consumer, lambda: True))
        self.assertEqual(result, [])

    def test_stops_mid_chunk_when_flag_flips_during_iteration(self):
        consumer = FakeConsumer([["a", "b", "c"]])
        seen = []

        def should_stop():
            return len(seen) >= 2

        for value in m.iter_consumer_messages(consumer, should_stop):
            seen.append(value)

        self.assertEqual(seen, ["a", "b"])


if __name__ == "__main__":
    unittest.main()
