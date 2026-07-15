"""ARCH-DB-SPLIT: ingest_telemetry_events.py's ClickHouse write-target
support (map_row_dict / insert_batch_clickhouse / run_ingest --target).
No live ClickHouse/Postgres in this environment -- ClickHouse calls are
verified against a real ClickHouseClient instance with its HTTP transport
mocked (matching this repo's existing xdr_infra_clients.py test pattern),
and the Postgres path is verified via a fake DB connection so run_ingest's
branching logic itself is exercised end-to-end without a live database
either way.
"""
from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

SCRIPTS_DIR = Path(__file__).resolve().parents[2] / "scripts"
sys.path.insert(0, str(SCRIPTS_DIR))

import ingest_telemetry_events as m  # noqa: E402
from xdr_infra_clients import HttpResult  # noqa: E402


class TestMapRowDict(unittest.TestCase):
    def test_maps_expected_columns(self):
        row = m.map_row_dict({
            "ts": "2026-07-12T00:00:00Z",
            "event_id": "e1",
            "telemetry_type": "identity",
            "event_type": "login_success",
            "host_id": "host-a",
            "user": "alice",
            "risk_score": "0.75",
        })
        self.assertEqual(row["event_id"], "e1")
        self.assertEqual(row["telemetry_type"], "identity")
        self.assertEqual(row["xdr_user"], "alice")
        self.assertEqual(row["risk_score"], 0.75)
        self.assertEqual(row["tenant_id"], "")

    def test_reads_tenant_id_when_present(self):
        row = m.map_row_dict({
            "ts": "2026-07-12T00:00:00Z", "event_id": "e1", "telemetry_type": "identity",
            "event_type": "login", "host_id": "h1", "tenant_id": "tenant-a",
        })
        self.assertEqual(row["tenant_id"], "tenant-a")

    def test_falls_back_src_dst_ip_for_source_destination(self):
        row = m.map_row_dict({
            "ts": "2026-07-12T00:00:00Z", "event_id": "e1", "telemetry_type": "network",
            "event_type": "conn", "host_id": "h1", "src_ip": "10.0.0.1", "dst_ip": "10.0.0.2",
        })
        self.assertEqual(row["source_ip"], "10.0.0.1")
        self.assertEqual(row["destination_ip"], "10.0.0.2")

    def test_payload_is_the_full_original_event_as_json(self):
        event = {"ts": "2026-07-12T00:00:00Z", "event_id": "e1", "telemetry_type": "identity",
                  "event_type": "login", "host_id": "h1", "extra_field": "kept"}
        row = m.map_row_dict(event)
        self.assertEqual(json.loads(row["payload"]), event)

    def test_matches_map_row_field_order_semantics(self):
        # map_row (Postgres, positional) and map_row_dict (ClickHouse) must
        # derive identical values for every shared field -- same event in,
        # same facts out, regardless of which backend it's headed to.
        event = {
            "ts": "2026-07-12T00:00:00Z", "event_id": "e1", "telemetry_type": "cloud",
            "event_type": "access_key_created", "host_id": "h1", "cloud_account": "acct-1",
            "action": "CreateAccessKey", "result": "success",
        }
        tup = m.map_row(event)
        d = m.map_row_dict(event)
        self.assertEqual(tup[2], d["event_id"])
        self.assertEqual(tup[20], d["cloud_account"])
        self.assertEqual(tup[21], d["xdr_action"])
        self.assertEqual(tup[22], d["xdr_result"])

    def test_map_row_reads_tenant_id_and_leaves_it_none_when_absent(self):
        # Unlike map_row_dict (ClickHouse, absent -> ""), map_row's Postgres
        # tuple leaves tenant_id as None when absent -- NULL is Postgres's
        # own null-tenant convention in this codebase, not an empty string.
        with_tenant = m.map_row({
            "ts": "2026-07-12T00:00:00Z", "event_id": "e1", "telemetry_type": "identity",
            "event_type": "login", "host_id": "h1", "tenant_id": "tenant-a",
        })
        self.assertEqual(with_tenant[1], "tenant-a")

        without_tenant = m.map_row({
            "ts": "2026-07-12T00:00:00Z", "event_id": "e2", "telemetry_type": "identity",
            "event_type": "login", "host_id": "h1",
        })
        self.assertIsNone(without_tenant[1])


class TestInsertBatchClickhouse(unittest.TestCase):
    def test_returns_zero_for_empty_batch_without_a_network_call(self):
        client = m.ClickHouseClient("http://ch.test:8123", "db", "u", "p")
        with patch.object(client.http, "request") as req_mock:
            n = m.insert_batch_clickhouse(client, [])
        self.assertEqual(n, 0)
        req_mock.assert_not_called()

    def test_returns_row_count_on_success(self):
        client = m.ClickHouseClient("http://ch.test:8123", "db", "u", "p")
        with patch.object(client.http, "request", return_value=HttpResult(True, 200, 1.0, "")):
            n = m.insert_batch_clickhouse(client, [{"event_id": "e1"}, {"event_id": "e2"}])
        self.assertEqual(n, 2)

    def test_raises_on_failed_insert(self):
        client = m.ClickHouseClient("http://ch.test:8123", "db", "u", "p")
        with patch.object(client.http, "request", return_value=HttpResult(False, 500, 1.0, "", "boom")):
            with self.assertRaises(RuntimeError):
                m.insert_batch_clickhouse(client, [{"event_id": "e1"}])

    def test_sends_json_each_row_body_via_query(self):
        client = m.ClickHouseClient("http://ch.test:8123", "db", "u", "p")
        with patch.object(client.http, "request", return_value=HttpResult(True, 200, 1.0, "")) as req_mock:
            m.insert_batch_clickhouse(client, [{"event_id": "e1"}])
        body = req_mock.call_args[0][2]
        self.assertIn(b"INSERT INTO telemetry_events FORMAT JSONEachRow", body)
        self.assertIn(b'"event_id":"e1"', body)


class TestRunIngestTargetRouting(unittest.TestCase):
    def setUp(self):
        fd, name = tempfile.mkstemp(suffix=".jsonl")
        os.close(fd)
        self.file_path = Path(name)
        events = [
            {"schema_version": 1, "ts": "2026-07-12T00:00:00Z", "event_id": f"e{i}", "telemetry_type": "identity",
             "event_type": "login_success", "host_id": "h1", "event_source": "test-idp"}
            for i in range(3)
        ]
        self.file_path.write_text("\n".join(json.dumps(e) for e in events), encoding="utf-8")
        self.project_root = self.file_path.parent

    def tearDown(self):
        self.file_path.unlink(missing_ok=True)
        offset = self.file_path.parent / (self.file_path.name + ".offset")
        offset.unlink(missing_ok=True)

    def _args(self, target: str) -> argparse.Namespace:
        return argparse.Namespace(
            file=str(self.file_path),
            offset_file=str(self.file_path) + ".offset.test",
            dsn="postgresql://fake",
            batch_size=500,
            from_start=True,
            target=target,
            clickhouse_url="http://ch.test:8123",
            clickhouse_db="detector_analytics",
            clickhouse_user="u",
            clickhouse_password="p",
        )

    def test_target_clickhouse_never_touches_postgres_connect(self):
        args = self._args("clickhouse")
        with patch.object(m, "connect_db") as connect_mock, \
             patch.object(m.ClickHouseClient, "insert_json_each_row", return_value=HttpResult(True, 200, 1.0, "")) as insert_mock:
            rc = m.run_ingest(args, self.project_root)

        self.assertEqual(rc, 0)
        connect_mock.assert_not_called()
        insert_mock.assert_called_once()
        table_arg = insert_mock.call_args[0][0]
        self.assertEqual(table_arg, "telemetry_events")
        rows_arg = insert_mock.call_args[0][1]
        self.assertEqual(len(rows_arg), 3)

    def test_target_postgres_never_touches_clickhouse(self):
        args = self._args("postgres")
        fake_conn = MagicMock()
        fake_conn.cursor.return_value.__enter__.return_value = MagicMock()
        with patch.object(m, "connect_db", return_value=("psycopg3", fake_conn)) as connect_mock, \
             patch.object(m.ClickHouseClient, "insert_json_each_row") as ch_insert_mock:
            rc = m.run_ingest(args, self.project_root)

        self.assertEqual(rc, 0)
        connect_mock.assert_called_once()
        ch_insert_mock.assert_not_called()
        fake_conn.close.assert_called_once()


if __name__ == "__main__":
    unittest.main(verbosity=2)
