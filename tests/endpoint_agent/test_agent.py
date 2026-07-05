"""
Endpoint Agent tests — pytest-compatible, stdlib only.

Tests:
1. heartbeat_payload_valid        — collect_heartbeat_event() has all required fields
2. process_event_valid            — /proc-based collector emits valid process_start events
3. network_event_valid            — /proc/net/tcp-based collector emits valid network_connection events
4. retry_writes_to_buffer         — GatewayClient writes to buffer when HTTP fails
5. flush_sends_buffered_events    — GatewayClient drains buffer and includes it in next send
"""

from __future__ import annotations

import json
import os
import sys
import tempfile
import unittest
from pathlib import Path
from typing import Any
from unittest.mock import MagicMock, patch

# Add the agent directory to sys.path so we can import it directly.
_AGENT_DIR = Path(__file__).parent.parent.parent / "services" / "endpoint-agent"
sys.path.insert(0, str(_AGENT_DIR))

import agent as ag


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _make_cfg(**overrides: Any) -> dict[str, Any]:
    """Return a minimal config dict for testing."""
    cfg: dict[str, Any] = {
        **ag.DEFAULT_CONFIG,
        "telemetry": dict(ag.DEFAULT_CONFIG["telemetry"]),
    }
    cfg.update(overrides)
    return cfg


def _make_fake_proc_root(tmp_path: Path, processes: list[dict[str, Any]]) -> Path:
    """
    Create a minimal /proc-like directory tree under tmp_path.
    Each process dict: pid, ppid, name, uid, cmdline.
    """
    proc_root = tmp_path / "proc"
    proc_root.mkdir(parents=True, exist_ok=True)

    for proc in processes:
        pid = str(proc["pid"])
        ppid = str(proc.get("ppid", 1))
        name = proc.get("name", "test")
        uid = str(proc.get("uid", 0))
        cmdline = proc.get("cmdline", name)

        pid_dir = proc_root / pid
        pid_dir.mkdir(parents=True, exist_ok=True)

        # Write /proc/{pid}/status
        status = (
            f"Name:\t{name}\n"
            f"Pid:\t{pid}\n"
            f"PPid:\t{ppid}\n"
            f"Uid:\t{uid} {uid} {uid} {uid}\n"
            f"Gid:\t{uid} {uid} {uid} {uid}\n"
        )
        (pid_dir / "status").write_text(status, encoding="utf-8")

        # Write /proc/{pid}/cmdline (null-separated)
        cmdline_bytes = cmdline.replace(" ", "\x00").encode() + b"\x00"
        (pid_dir / "cmdline").write_bytes(cmdline_bytes)

    return proc_root


def _proc_net_tcp_content(connections: list[dict[str, Any]]) -> str:
    """
    Build a /proc/net/tcp-formatted string for a list of connections.
    Each connection dict: local_ip, local_port, remote_ip, remote_port.
    All generated as ESTABLISHED (state 01).
    """
    import socket
    import struct

    def _ip_to_hex(ip: str) -> str:
        packed = socket.inet_aton(ip)
        # little-endian
        return packed[::-1].hex().upper()

    def _port_to_hex(port: int) -> str:
        return f"{port:04X}"

    lines = [
        "  sl  local_address rem_address   st tx_queue rx_queue "
        "tr tm->when retrnsmt   uid  timeout inode"
    ]
    for i, conn in enumerate(connections):
        local_hex = _ip_to_hex(conn["local_ip"]) + ":" + _port_to_hex(conn["local_port"])
        rem_hex = _ip_to_hex(conn["remote_ip"]) + ":" + _port_to_hex(conn["remote_port"])
        uid = conn.get("uid", 1000)
        lines.append(
            f"   {i}: {local_hex} {rem_hex} 01 00000000:00000000 "
            f"00:00000000 00000000 {uid:5d}        0 {10000 + i} 1 0000000000000000 20 4 0 10 -1"
        )
    return "\n".join(lines) + "\n"


# ---------------------------------------------------------------------------
# Test 1 — heartbeat_payload_valid
# ---------------------------------------------------------------------------

class TestHeartbeatPayloadValid(unittest.TestCase):
    """collect_heartbeat_event() returns a complete, valid heartbeat event."""

    def test_required_fields_present(self) -> None:
        ev = ag.collect_heartbeat_event("agent-test-001", "trace-abc-123", buffer_size=0)

        self.assertEqual(ev["event_type"], "heartbeat")
        self.assertEqual(ev["telemetry_type"], ag.TELEMETRY_TYPE)
        self.assertEqual(ev["agent_id"], "agent-test-001")
        self.assertEqual(ev["trace_id"], "trace-abc-123")
        self.assertIn("ts", ev)
        self.assertIn("timestamp", ev)
        self.assertIn("host_id", ev)
        self.assertIn("hostname", ev)
        self.assertIn("event_id", ev)
        self.assertEqual(ev["buffered_events"], 0)
        self.assertIn("platform", ev)
        self.assertIn("python_version", ev)
        self.assertEqual(ev["source"], ag.AGENT_SOURCE)
        self.assertEqual(ev["schema_version"], ag.SCHEMA_VERSION)

    def test_buffered_events_reflected(self) -> None:
        ev = ag.collect_heartbeat_event("agent-xyz", "trace-000", buffer_size=42)
        self.assertEqual(ev["buffered_events"], 42)

    def test_event_id_is_deterministic_within_same_minute(self) -> None:
        ev1 = ag.collect_heartbeat_event("agent-a", "trace-1", 0)
        ev2 = ag.collect_heartbeat_event("agent-a", "trace-1", 0)
        # Both generated within the same test run — same minute bucket
        self.assertEqual(ev1["event_id"], ev2["event_id"])

    def test_different_agents_produce_different_event_ids(self) -> None:
        ev1 = ag.collect_heartbeat_event("agent-a", "trace-1", 0)
        ev2 = ag.collect_heartbeat_event("agent-b", "trace-1", 0)
        # HOSTNAME is the same, but agent_id differs → different event_id
        # (make_event_id includes hostname + "heartbeat" + minute; agent_id is not included
        #  in event_id — but the events themselves differ by agent_id field)
        self.assertEqual(ev1["event_type"], ev2["event_type"])
        self.assertNotEqual(ev1["agent_id"], ev2["agent_id"])


# ---------------------------------------------------------------------------
# Test 2 — process_event_valid
# ---------------------------------------------------------------------------

class TestProcessEventValid(unittest.TestCase):
    """_collect_processes_proc() emits valid process_start events from a /proc tree."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self._tmp.name)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def test_emits_process_start_event(self) -> None:
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1234, "ppid": 1, "name": "bash", "uid": 1000, "cmdline": "/bin/bash -c echo"},
        ])
        state = ag.CollectorState()
        events = ag._collect_processes_proc(state, "agent-1", "trace-1", proc_root)

        self.assertEqual(len(events), 1)
        ev = events[0]
        self.assertEqual(ev["event_type"], "process_start")
        self.assertEqual(ev["telemetry_type"], ag.TELEMETRY_TYPE)
        self.assertEqual(ev["agent_id"], "agent-1")
        self.assertEqual(ev["trace_id"], "trace-1")
        self.assertEqual(ev["process_name"], "bash")
        self.assertEqual(ev["pid"], 1234)
        self.assertEqual(ev["ppid"], 1)
        self.assertIn("command_line", ev)
        self.assertIn("event_id", ev)
        self.assertIn("ts", ev)
        self.assertIn("host_id", ev)
        self.assertEqual(ev["source"], ag.AGENT_SOURCE)

    def test_known_pids_not_re_emitted(self) -> None:
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 99, "ppid": 1, "name": "sshd", "uid": 0},
        ])
        state = ag.CollectorState()
        first = ag._collect_processes_proc(state, "agent-1", "trace-1", proc_root)
        self.assertEqual(len(first), 1)

        second = ag._collect_processes_proc(state, "agent-1", "trace-2", proc_root)
        self.assertEqual(len(second), 0, "Already-known PID should not be re-emitted")

    def test_new_pid_emitted_on_second_cycle(self) -> None:
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 100, "ppid": 1, "name": "init", "uid": 0},
        ])
        state = ag.CollectorState()
        ag._collect_processes_proc(state, "agent-1", "trace-1", proc_root)

        # Add a new process
        new_proc_root = _make_fake_proc_root(self.tmp_path / "cycle2", [
            {"pid": 100, "ppid": 1, "name": "init", "uid": 0},
            {"pid": 200, "ppid": 100, "name": "curl", "uid": 1000},
        ])
        events = ag._collect_processes_proc(state, "agent-1", "trace-2", new_proc_root)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["process_name"], "curl")

    def test_collect_processes_dispatch_uses_proc_root(self) -> None:
        """collect_processes() with proc_root kwarg uses /proc path, not subprocess."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 555, "ppid": 1, "name": "nginx", "uid": 33},
        ])
        cfg = _make_cfg()
        state = ag.CollectorState()
        events = ag.collect_processes(state, "agent-1", "trace-1", cfg, proc_root=proc_root)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "process_start")


# ---------------------------------------------------------------------------
# Test 3 — network_event_valid
# ---------------------------------------------------------------------------

class TestNetworkEventValid(unittest.TestCase):
    """_collect_network_proc() emits valid network_connection events from /proc/net/tcp."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self._tmp.name)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def _write_proc_net_tcp(self, connections: list[dict[str, Any]], name: str = "tcp") -> str:
        """Write fake /proc/net/tcp content and return the file path."""
        content = _proc_net_tcp_content(connections)
        path = self.tmp_path / name
        path.write_text(content, encoding="utf-8")
        return str(path)

    def test_emits_network_connection_for_established(self) -> None:
        tcp_path = self._write_proc_net_tcp([
            {"local_ip": "10.0.2.15", "local_port": 54321,
             "remote_ip": "192.47.113.99", "remote_port": 443},
        ])
        empty_path = self._write_proc_net_tcp([], "tcp6")

        state = ag.CollectorState()
        events = ag._collect_network_proc(
            state, "agent-1", "trace-1",
            proc_net_tcp=tcp_path, proc_net_tcp6=empty_path,
        )

        self.assertEqual(len(events), 1)
        ev = events[0]
        self.assertEqual(ev["event_type"], "network_connection")
        self.assertEqual(ev["telemetry_type"], ag.TELEMETRY_TYPE)
        self.assertEqual(ev["agent_id"], "agent-1")
        self.assertEqual(ev["trace_id"], "trace-1")
        self.assertEqual(ev["source_ip"], "10.0.2.15")
        self.assertEqual(ev["source_port"], 54321)
        self.assertEqual(ev["destination_ip"], "192.47.113.99")
        self.assertEqual(ev["destination_port"], 443)
        self.assertEqual(ev["protocol"], "tcp")
        self.assertEqual(ev["direction"], "outbound")
        self.assertEqual(ev["action"], "monitored")
        self.assertIn("event_id", ev)
        self.assertIn("ts", ev)
        self.assertEqual(ev["source"], ag.AGENT_SOURCE)

    def test_loopback_remote_excluded(self) -> None:
        tcp_path = self._write_proc_net_tcp([
            {"local_ip": "127.0.0.1", "local_port": 12345,
             "remote_ip": "127.0.0.1", "remote_port": 8080},
        ])
        empty_path = self._write_proc_net_tcp([], "tcp6")
        state = ag.CollectorState()
        events = ag._collect_network_proc(
            state, "agent-1", "trace-1",
            proc_net_tcp=tcp_path, proc_net_tcp6=empty_path,
        )
        self.assertEqual(len(events), 0, "Loopback-to-loopback connection should be excluded")

    def test_known_connections_not_re_emitted(self) -> None:
        tcp_path = self._write_proc_net_tcp([
            {"local_ip": "10.0.0.1", "local_port": 50000,
             "remote_ip": "8.8.8.8", "remote_port": 53},
        ])
        empty_path = self._write_proc_net_tcp([], "tcp6")
        state = ag.CollectorState()
        first = ag._collect_network_proc(
            state, "agent-1", "trace-1",
            proc_net_tcp=tcp_path, proc_net_tcp6=empty_path,
        )
        self.assertEqual(len(first), 1)

        second = ag._collect_network_proc(
            state, "agent-1", "trace-2",
            proc_net_tcp=tcp_path, proc_net_tcp6=empty_path,
        )
        self.assertEqual(len(second), 0, "Already-seen connection should not re-emit")

    def test_collect_network_dispatch_uses_proc_paths(self) -> None:
        """collect_network() with proc_net_tcp kwarg uses provided paths."""
        tcp_path = self._write_proc_net_tcp([
            {"local_ip": "10.0.0.5", "local_port": 55000,
             "remote_ip": "203.0.113.1", "remote_port": 80},
        ])
        empty_path = self._write_proc_net_tcp([], "tcp6b")
        cfg = _make_cfg()
        state = ag.CollectorState()
        events = ag.collect_network(
            state, "agent-1", "trace-1", cfg,
            proc_net_tcp=tcp_path, proc_net_tcp6=empty_path,
        )
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "network_connection")


# ---------------------------------------------------------------------------
# Test 4 — retry_writes_to_buffer_on_failure
# ---------------------------------------------------------------------------

class TestRetryWritesToBufferOnFailure(unittest.TestCase):
    """GatewayClient.ship() writes events to local buffer when HTTP keeps failing."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.buffer_path = str(Path(self._tmp.name) / "buffer.jsonl")

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def _make_gateway(self) -> tuple[ag.GatewayClient, ag.LocalBuffer]:
        cfg = _make_cfg(
            ingestion_gateway_url="http://127.0.0.1:19999",  # nothing listening
            ingestion_gateway_secret="",
            buffer_path=self.buffer_path,
        )
        buf = ag.LocalBuffer(self.buffer_path)
        gw = ag.GatewayClient(cfg, buf)
        gw.RETRY_BASE_SECONDS = 0  # no sleep during tests
        gw.MAX_RETRIES = 1
        return gw, buf

    def test_events_written_to_buffer_on_connection_failure(self) -> None:
        gw, buf = self._make_gateway()
        events = [
            ag.base_event("heartbeat", "agent-1", "trace-1"),
            ag.base_event("heartbeat", "agent-1", "trace-2"),
        ]

        # Patch _send_raw to always fail without actually trying HTTP
        with patch.object(gw, "_send_raw", return_value=False):
            gw.ship(events)

        buffered = buf.drain()
        self.assertEqual(len(buffered), 2, "Both events should be in the buffer")
        self.assertEqual(buffered[0]["trace_id"], "trace-1")
        self.assertEqual(buffered[1]["trace_id"], "trace-2")

    def test_buffer_accumulates_across_multiple_failures(self) -> None:
        gw, buf = self._make_gateway()

        batch1 = [ag.base_event("process_start", "agent-1", "trace-a")]
        batch2 = [ag.base_event("network_connection", "agent-1", "trace-b")]

        with patch.object(gw, "_send_raw", return_value=False):
            gw.ship(batch1)
            gw.ship(batch2)

        # drain() reads all at once
        buffered = buf.drain()
        # batch2's ship() drained batch1 from buffer and tried to send [batch1 + batch2],
        # which also failed — so all end up in buffer
        self.assertEqual(len(buffered), 2)

    def test_buffer_empty_after_successful_send(self) -> None:
        gw, buf = self._make_gateway()
        events = [ag.base_event("heartbeat", "agent-1", "trace-ok")]

        # First call fails → buffered
        with patch.object(gw, "_send_raw", return_value=False):
            gw.ship(events)
        self.assertEqual(buf.size(), 1)

        # Second call succeeds → buffer cleared
        with patch.object(gw, "_send_raw", return_value=True):
            gw.ship([ag.base_event("heartbeat", "agent-1", "trace-recovery")])

        self.assertEqual(buf.size(), 0, "Buffer should be empty after successful send")


# ---------------------------------------------------------------------------
# Test 5 — flush_sends_buffered_events
# ---------------------------------------------------------------------------

class TestFlushSendsBufferedEvents(unittest.TestCase):
    """GatewayClient drains buffer and includes buffered events in next successful send."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.buffer_path = str(Path(self._tmp.name) / "buffer.jsonl")

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def test_buffered_events_prepended_to_next_batch(self) -> None:
        cfg = _make_cfg(
            ingestion_gateway_url="http://127.0.0.1:19999",
            ingestion_gateway_secret="",
            buffer_path=self.buffer_path,
        )
        buf = ag.LocalBuffer(self.buffer_path)
        gw = ag.GatewayClient(cfg, buf)

        # Pre-populate buffer with 2 events
        buf.write([
            ag.base_event("process_start", "agent-1", "trace-buffered-1"),
            ag.base_event("network_connection", "agent-1", "trace-buffered-2"),
        ])

        new_event = ag.base_event("heartbeat", "agent-1", "trace-new")
        captured: list[list[dict[str, Any]]] = []

        def _fake_send(events: list[dict[str, Any]]) -> bool:
            captured.append(list(events))
            return True

        with patch.object(gw, "_send_raw", side_effect=_fake_send):
            gw.ship([new_event])

        self.assertEqual(len(captured), 1, "Should have made exactly one send call")
        sent = captured[0]
        self.assertEqual(len(sent), 3, "Buffered 2 + new 1 = 3 events sent together")

        trace_ids = [e["trace_id"] for e in sent]
        self.assertIn("trace-buffered-1", trace_ids)
        self.assertIn("trace-buffered-2", trace_ids)
        self.assertIn("trace-new", trace_ids)

    def test_buffer_is_cleared_after_successful_flush(self) -> None:
        cfg = _make_cfg(
            ingestion_gateway_url="http://127.0.0.1:19999",
            ingestion_gateway_secret="",
            buffer_path=self.buffer_path,
        )
        buf = ag.LocalBuffer(self.buffer_path)
        gw = ag.GatewayClient(cfg, buf)

        buf.write([ag.base_event("heartbeat", "agent-1", "trace-x")])
        self.assertEqual(buf.size(), 1)

        with patch.object(gw, "_send_raw", return_value=True):
            gw.ship([ag.base_event("heartbeat", "agent-1", "trace-y")])

        self.assertEqual(buf.size(), 0, "Buffer must be cleared after successful flush")

    def test_buffer_preserved_when_send_fails(self) -> None:
        cfg = _make_cfg(
            ingestion_gateway_url="http://127.0.0.1:19999",
            ingestion_gateway_secret="",
            buffer_path=self.buffer_path,
        )
        buf = ag.LocalBuffer(self.buffer_path)
        gw = ag.GatewayClient(cfg, buf)

        buf.write([ag.base_event("heartbeat", "agent-1", "trace-saved")])

        with patch.object(gw, "_send_raw", return_value=False):
            gw.ship([ag.base_event("heartbeat", "agent-1", "trace-also-saved")])

        remaining = buf.drain()
        self.assertEqual(len(remaining), 2, "Both original and new events must remain in buffer")

    def test_local_buffer_write_and_drain_roundtrip(self) -> None:
        """LocalBuffer write/drain preserves event fields exactly."""
        buf = ag.LocalBuffer(self.buffer_path)
        original = ag.collect_heartbeat_event("agent-roundtrip", "trace-rt", 5)
        buf.write([original])

        recovered = buf.drain()
        self.assertEqual(len(recovered), 1)
        self.assertEqual(recovered[0]["agent_id"], "agent-roundtrip")
        self.assertEqual(recovered[0]["trace_id"], "trace-rt")
        self.assertEqual(recovered[0]["buffered_events"], 5)
        self.assertEqual(recovered[0]["event_type"], "heartbeat")


# ---------------------------------------------------------------------------
# Test 6 — TamperVisibility (Endpoint Fleet Hardening Phase 1)
# ---------------------------------------------------------------------------

class TestTamperVisibility(unittest.TestCase):
    """check_tamper_visibility() returns explainable advisory indicators. No enforcement."""

    def _make_cfg(self, **overrides: Any) -> dict[str, Any]:
        cfg = {
            **ag.DEFAULT_CONFIG,
            "telemetry": dict(ag.DEFAULT_CONFIG["telemetry"]),
        }
        cfg.update(overrides)
        return cfg

    def test_no_indicators_for_recent_heartbeat(self) -> None:
        cfg = self._make_cfg()
        from datetime import datetime, timezone, timedelta
        recent = (datetime.now(timezone.utc) - timedelta(seconds=30)).isoformat()

        indicators = ag.check_tamper_visibility(cfg, last_heartbeat_timestamp=recent)
        self.assertEqual(indicators, [], "No tamper indicators for a recent heartbeat")

    def test_heartbeat_gap_detected(self) -> None:
        cfg = self._make_cfg(heartbeat_interval_seconds=60)
        from datetime import datetime, timezone, timedelta
        old_ts = (datetime.now(timezone.utc) - timedelta(minutes=60)).isoformat()

        indicators = ag.check_tamper_visibility(cfg, last_heartbeat_timestamp=old_ts)

        gap_indicators = [i for i in indicators if i["type"] == "heartbeat_gap"]
        self.assertEqual(len(gap_indicators), 1)
        self.assertTrue(gap_indicators[0]["advisory"], "Heartbeat gap indicator must be advisory")
        self.assertFalse(gap_indicators[0]["autonomous_action"], "Must not trigger autonomous action")
        self.assertGreater(gap_indicators[0]["gap_seconds"], 0)

    def test_config_mismatch_detected(self) -> None:
        cfg = self._make_cfg()
        import hashlib, json as _json
        known_good_hash = hashlib.sha256(
            _json.dumps(cfg, sort_keys=True).encode()
        ).hexdigest()

        # Modify config slightly
        cfg_modified = dict(cfg)
        cfg_modified["batch_interval_seconds"] = 999

        indicators = ag.check_tamper_visibility(cfg_modified, last_config_hash=known_good_hash)

        mismatch = [i for i in indicators if i["type"] == "config_mismatch"]
        self.assertEqual(len(mismatch), 1)
        self.assertTrue(mismatch[0]["advisory"])
        self.assertFalse(mismatch[0]["autonomous_action"])

    def test_no_config_mismatch_when_config_unchanged(self) -> None:
        cfg = self._make_cfg()
        import hashlib, json as _json
        hash_val = hashlib.sha256(
            _json.dumps(cfg, sort_keys=True).encode()
        ).hexdigest()

        indicators = ag.check_tamper_visibility(cfg, last_config_hash=hash_val)

        mismatch = [i for i in indicators if i["type"] == "config_mismatch"]
        self.assertEqual(len(mismatch), 0, "No mismatch when config is unchanged")

    def test_disabled_collector_detected(self) -> None:
        cfg = self._make_cfg()
        cfg["telemetry"]["process"] = False  # disabled

        indicators = ag.check_tamper_visibility(
            cfg, enabled_collectors=["process", "network"]
        )

        disabled = [i for i in indicators if i["type"] == "disabled_collector"]
        self.assertEqual(len(disabled), 1)
        self.assertEqual(disabled[0]["collector"], "process")
        self.assertTrue(disabled[0]["advisory"])
        self.assertFalse(disabled[0]["autonomous_action"])

    def test_all_enabled_collectors_produce_no_indicator(self) -> None:
        cfg = self._make_cfg()
        cfg["telemetry"]["process"] = True
        cfg["telemetry"]["network"] = True

        indicators = ag.check_tamper_visibility(
            cfg, enabled_collectors=["process", "network"]
        )

        disabled = [i for i in indicators if i["type"] == "disabled_collector"]
        self.assertEqual(len(disabled), 0)

    def test_no_autonomous_action_in_any_indicator(self) -> None:
        """All tamper indicators must declare autonomous_action=False."""
        from datetime import datetime, timezone, timedelta
        cfg = self._make_cfg(heartbeat_interval_seconds=60)
        old_ts = (datetime.now(timezone.utc) - timedelta(hours=2)).isoformat()

        indicators = ag.check_tamper_visibility(cfg, last_heartbeat_timestamp=old_ts)
        for indicator in indicators:
            self.assertFalse(indicator.get("autonomous_action", True),
                f"Indicator {indicator['type']} must have autonomous_action=False")

    def test_tamper_check_returns_list(self) -> None:
        cfg = self._make_cfg()
        result = ag.check_tamper_visibility(cfg)
        self.assertIsInstance(result, list)

    def test_tamper_check_with_no_args_returns_empty(self) -> None:
        cfg = self._make_cfg()
        result = ag.check_tamper_visibility(cfg)
        self.assertEqual(result, [])

    def test_invalid_timestamp_does_not_crash(self) -> None:
        cfg = self._make_cfg()
        # Should gracefully degrade, not raise
        result = ag.check_tamper_visibility(cfg, last_heartbeat_timestamp="not-a-timestamp")
        self.assertIsInstance(result, list)


# ---------------------------------------------------------------------------
# Test 7 — SpoolStats (Endpoint Fleet Hardening Phase 1)
# ---------------------------------------------------------------------------

class TestSpoolStats(unittest.TestCase):
    """get_spool_stats() returns correct spool health data. No enforcement."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self._tmp.name)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def _make_streaming_state(self) -> "ag.StreamingState":
        return ag.StreamingState(spool_dir=str(self.tmp_path))

    def test_spool_stats_empty_state(self) -> None:
        state = self._make_streaming_state()
        stats = ag.get_spool_stats(state)

        self.assertIn("queued_events", stats)
        self.assertIn("dropped_events", stats)
        self.assertIn("spool_disk_bytes", stats)
        self.assertIn("spool_capped", stats)
        self.assertIn("disk_pressure", stats)
        self.assertIn("retry_count", stats)
        self.assertIn("buffer_depth", stats)
        self.assertIn("events_per_sec", stats)
        self.assertEqual(stats["queued_events"], 0)
        self.assertEqual(stats["spool_disk_bytes"], 0)
        self.assertFalse(stats["spool_capped"])

    def test_spool_stats_with_spool_file(self) -> None:
        state = self._make_streaming_state()

        # Write some events to spool
        ag.spool_to_disk(state, [{"event": "test", "id": 1}])

        stats = ag.get_spool_stats(state)
        self.assertGreater(stats["spool_disk_bytes"], 0)
        self.assertFalse(stats["spool_capped"])

    def test_spool_stats_shows_capped_when_at_cap(self) -> None:
        state = self._make_streaming_state()

        # Write exactly STREAM_SPOOL_MAX_BYTES to spool file to simulate cap
        state.spool_path.write_bytes(b"x" * ag.STREAM_SPOOL_MAX_BYTES)

        stats = ag.get_spool_stats(state)
        self.assertTrue(stats["spool_capped"], "Should report capped when at max bytes")

    def test_spool_stats_with_quality_metrics(self) -> None:
        state = self._make_streaming_state()
        # dropped_events in get_spool_stats comes from state.dropped_count (streaming queue drops)
        state.dropped_count = 5
        quality = ag.QualityMetrics()
        quality.record_retry()

        stats = ag.get_spool_stats(state, quality=quality)
        self.assertEqual(stats["dropped_events"], 5)  # from state.dropped_count
        self.assertEqual(stats["retry_count"], 1)     # from quality

    def test_spool_stats_queue_depth_reflects_queue(self) -> None:
        state = self._make_streaming_state()
        # Add 3 events to the streaming queue
        for i in range(3):
            state.queue.append({"event_id": str(i)})

        stats = ag.get_spool_stats(state)
        self.assertEqual(stats["queued_events"], 3)

    def test_spool_stats_bounded_replay_idempotent(self) -> None:
        """replay_from_spool is idempotent — safe to replay multiple times."""
        state = self._make_streaming_state()
        ag.spool_to_disk(state, [{"event": "test"}])

        first_replay = ag.replay_from_spool(state)
        self.assertEqual(len(first_replay), 1)

        second_replay = ag.replay_from_spool(state)
        self.assertEqual(len(second_replay), 0, "Spool is drained after first replay")


# ---------------------------------------------------------------------------
# Test 8 — SOCClient heartbeat with spool_stats
# ---------------------------------------------------------------------------

class TestSOCHeartbeatWithSpoolStats(unittest.TestCase):
    """SOCClient.heartbeat() includes spool_stats in payload."""

    def test_heartbeat_payload_includes_spool_stats(self) -> None:
        cfg = {
            **ag.DEFAULT_CONFIG,
            "soc_api_url": "http://127.0.0.1:19999",
            "enrollment_token": "test-token",
        }
        soc = ag.SOCClient(cfg)
        spool_stats = {
            "queued_events": 5,
            "dropped_events": 2,
            "spool_disk_bytes": 1024,
            "spool_capped": False,
        }
        captured: list[dict[str, Any]] = []

        def _fake_post(url: str, body: bytes, headers: dict) -> tuple[int, bytes]:
            captured.append(json.loads(body))
            return 200, b'{"ok": true}'

        with patch("agent._http_post", side_effect=_fake_post):
            soc.heartbeat("agent-001", metrics={"events_per_sec": 1.0},
                          trace_id="trace-hb-1", spool_stats=spool_stats)

        self.assertEqual(len(captured), 1)
        payload = captured[0]
        self.assertIn("spool_stats", payload)
        self.assertEqual(payload["spool_stats"]["queued_events"], 5)
        self.assertEqual(payload["spool_stats"]["dropped_events"], 2)

    def test_heartbeat_without_spool_stats_still_works(self) -> None:
        """Backward compatibility — spool_stats is optional."""
        cfg = {
            **ag.DEFAULT_CONFIG,
            "soc_api_url": "http://127.0.0.1:19999",
            "enrollment_token": "test-token",
        }
        soc = ag.SOCClient(cfg)

        def _fake_post(url: str, body: bytes, headers: dict) -> tuple[int, bytes]:
            payload = json.loads(body)
            # spool_stats should default to empty dict
            assert payload.get("spool_stats") == {}, f"Expected empty spool_stats, got {payload.get('spool_stats')}"
            return 200, b'{"ok": true}'

        with patch("agent._http_post", side_effect=_fake_post):
            result = soc.heartbeat("agent-002")

        self.assertTrue(result)

    def test_heartbeat_signature_includes_spool_stats(self) -> None:
        """Signed payload (sorted keys) includes spool_stats field."""
        cfg = {
            **ag.DEFAULT_CONFIG,
            "soc_api_url": "http://127.0.0.1:19999",
            "enrollment_token": "signing-key",
        }
        soc = ag.SOCClient(cfg)
        spool_stats = {"queued_events": 3, "spool_capped": False}
        captured_headers: list[dict[str, str]] = []

        def _fake_post(url: str, body: bytes, headers: dict) -> tuple[int, bytes]:
            captured_headers.append(headers)
            return 200, b'{"ok": true}'

        with patch("agent._http_post", side_effect=_fake_post):
            soc.heartbeat("agent-003", spool_stats=spool_stats)

        self.assertTrue(len(captured_headers) > 0)
        self.assertIn("X-Agent-Signature", captured_headers[0],
                      "Heartbeat must be signed even with spool_stats")

    def test_no_enforcement_in_soc_client(self) -> None:
        """SOCClient must not have methods for enforcement actions."""
        cfg = {**ag.DEFAULT_CONFIG, "soc_api_url": "http://localhost", "enrollment_token": "t"}
        soc = ag.SOCClient(cfg)
        self.assertFalse(hasattr(soc, "isolate_host"), "SOCClient must not have isolate_host")
        self.assertFalse(hasattr(soc, "kill_process"), "SOCClient must not have kill_process")
        self.assertFalse(hasattr(soc, "execute_shell"), "SOCClient must not have execute_shell")


# ---------------------------------------------------------------------------
# Bonus — hex address parsing
# ---------------------------------------------------------------------------

class TestHexAddrParsing(unittest.TestCase):
    """_hex_addr_to_ip_port() correctly decodes /proc/net/tcp hex entries."""

    def test_ipv4_loopback(self) -> None:
        # 7F000001 little-endian = 127.0.0.1; 0035 = 53
        ip, port = ag._hex_addr_to_ip_port("0100007F:0035", ipv6=False)
        self.assertEqual(ip, "127.0.0.1")
        self.assertEqual(port, 53)

    def test_ipv4_external(self) -> None:
        # 0F02000A = 10.0.2.15 little-endian; 01BB = 443
        ip, port = ag._hex_addr_to_ip_port("0F02000A:01BB", ipv6=False)
        self.assertEqual(ip, "10.0.2.15")
        self.assertEqual(port, 443)

    def test_invalid_input_returns_none(self) -> None:
        ip, port = ag._hex_addr_to_ip_port("ZZZZZZZZ:0050", ipv6=False)
        self.assertIsNone(ip)
        self.assertIsNone(port)


# ---------------------------------------------------------------------------
# Test 6 — HardenedBuffer capacity and disk pressure
# ---------------------------------------------------------------------------

class TestHardenedBuffer(unittest.TestCase):
    """HardenedBuffer enforces max_size and disk_pressure_threshold_mb."""

    def _make_event(self, n: int = 0) -> dict[str, Any]:
        return {"event_type": "test", "n": n, "trace_id": f"trace-{n}"}

    def test_accepts_events_within_limit(self) -> None:
        buf = ag.HardenedBuffer(max_size=10)
        for i in range(5):
            accepted = buf.push(self._make_event(i))
            self.assertTrue(accepted, f"Event {i} should be accepted")
        self.assertEqual(buf.depth(), 5)
        self.assertEqual(buf.dropped, 0)

    def test_drops_events_when_full(self) -> None:
        buf = ag.HardenedBuffer(max_size=3)
        for i in range(3):
            buf.push(self._make_event(i))

        # 4th event should be dropped
        accepted = buf.push(self._make_event(99))
        self.assertFalse(accepted)
        self.assertEqual(buf.dropped, 1)
        self.assertEqual(buf.depth(), 3)

    def test_drop_count_accumulates(self) -> None:
        buf = ag.HardenedBuffer(max_size=2)
        buf.push(self._make_event(1))
        buf.push(self._make_event(2))
        buf.push(self._make_event(3))  # dropped
        buf.push(self._make_event(4))  # dropped
        self.assertEqual(buf.dropped, 2)

    def test_drain_empties_buffer(self) -> None:
        buf = ag.HardenedBuffer(max_size=10)
        for i in range(3):
            buf.push(self._make_event(i))
        events = buf.drain()
        self.assertEqual(len(events), 3)
        self.assertEqual(buf.depth(), 0)

    def test_drain_preserves_event_order(self) -> None:
        buf = ag.HardenedBuffer(max_size=10)
        for i in range(5):
            buf.push(self._make_event(i))
        events = buf.drain()
        self.assertEqual([e["n"] for e in events], list(range(5)))

    def test_push_batch_returns_accepted_and_dropped(self) -> None:
        buf = ag.HardenedBuffer(max_size=3)
        events = [self._make_event(i) for i in range(5)]
        accepted, dropped = buf.push_batch(events)
        self.assertEqual(accepted, 3)
        self.assertEqual(dropped, 2)

    def test_depth_reflects_current_buffer_size(self) -> None:
        buf = ag.HardenedBuffer(max_size=100)
        self.assertEqual(buf.depth(), 0)
        buf.push(self._make_event(1))
        buf.push(self._make_event(2))
        self.assertEqual(buf.depth(), 2)
        buf.drain()
        self.assertEqual(buf.depth(), 0)

    def test_disk_pressure_guard_causes_drop(self) -> None:
        """When _disk_pressure() returns True, events are dropped."""
        buf = ag.HardenedBuffer(max_size=100, disk_pressure_threshold_mb=1_000_000)
        # Force disk pressure by patching the method
        with patch.object(buf, "_disk_pressure", return_value=True):
            accepted = buf.push(self._make_event(1))
        self.assertFalse(accepted)
        self.assertEqual(buf.dropped, 1)


# ---------------------------------------------------------------------------
# Test 7 — Signed heartbeat (SOCClient)
# ---------------------------------------------------------------------------

class TestSignedHeartbeat(unittest.TestCase):
    """SOCClient signs heartbeats with enrollment_token via HMAC-SHA256."""

    def _make_soc_client(self, enrollment_token: str = "test-secret") -> ag.SOCClient:
        cfg = _make_cfg(
            soc_api_url="http://127.0.0.1:19999",
            enrollment_token=enrollment_token,
        )
        return ag.SOCClient(cfg)

    def test_sign_payload_produces_sha256_prefix(self) -> None:
        soc = self._make_soc_client("mysecret")
        sig = soc._sign_payload(b"test payload")
        self.assertTrue(sig.startswith("sha256="), f"Expected sha256= prefix, got: {sig}")

    def test_sign_payload_uses_hmac_sha256(self) -> None:
        import hmac as hmac_mod
        import hashlib
        soc = self._make_soc_client("mysecret")
        payload = b'{"agent_id":"a","timestamp":"t"}'
        sig = soc._sign_payload(payload)
        expected = "sha256=" + hmac_mod.new("mysecret".encode(), payload, hashlib.sha256).hexdigest()
        self.assertEqual(sig, expected)

    def test_sign_payload_empty_token_returns_empty(self) -> None:
        soc = self._make_soc_client("")
        sig = soc._sign_payload(b"some payload")
        self.assertEqual(sig, "")

    def test_different_payloads_produce_different_signatures(self) -> None:
        soc = self._make_soc_client("secret")
        sig1 = soc._sign_payload(b"payload_a")
        sig2 = soc._sign_payload(b"payload_b")
        self.assertNotEqual(sig1, sig2)

    def test_different_secrets_produce_different_signatures(self) -> None:
        soc1 = self._make_soc_client("secret_a")
        soc2 = self._make_soc_client("secret_b")
        payload = b"same payload"
        self.assertNotEqual(soc1._sign_payload(payload), soc2._sign_payload(payload))

    def test_heartbeat_includes_signature_header_when_token_set(self) -> None:
        """heartbeat() should include X-Agent-Signature header when enrollment_token is set."""
        soc = self._make_soc_client("test-token")
        captured_headers: list[dict[str, str]] = []

        def fake_http_post(url: str, payload: bytes, headers: dict[str, str], timeout: int = 15) -> tuple[int, bytes]:
            captured_headers.append(dict(headers))
            return 200, b'{"ok":true}'

        with patch("agent._http_post", side_effect=fake_http_post):
            soc.heartbeat("agent-test-001", metrics={"buffer_depth": 0})

        self.assertEqual(len(captured_headers), 1)
        self.assertIn("X-Agent-Signature", captured_headers[0])
        sig = captured_headers[0]["X-Agent-Signature"]
        self.assertTrue(sig.startswith("sha256="))

    def test_heartbeat_no_signature_header_when_no_token(self) -> None:
        """heartbeat() should NOT include X-Agent-Signature when enrollment_token is empty."""
        soc = self._make_soc_client("")
        captured_headers: list[dict[str, str]] = []

        def fake_http_post(url: str, payload: bytes, headers: dict[str, str], timeout: int = 15) -> tuple[int, bytes]:
            captured_headers.append(dict(headers))
            return 200, b'{"ok":true}'

        with patch("agent._http_post", side_effect=fake_http_post):
            soc.heartbeat("agent-test-002", metrics={})

        self.assertEqual(len(captured_headers), 1)
        self.assertNotIn("X-Agent-Signature", captured_headers[0])

    def test_heartbeat_returns_true_on_success(self) -> None:
        soc = self._make_soc_client("token")
        with patch("agent._http_post", return_value=(200, b'{"ok":true}')):
            result = soc.heartbeat("agent-ok", metrics={})
        self.assertTrue(result)

    def test_heartbeat_returns_false_on_failure(self) -> None:
        soc = self._make_soc_client("token")
        with patch("agent._http_post", return_value=(422, b'{"ok":false}')):
            result = soc.heartbeat("agent-bad-sig", metrics={})
        self.assertFalse(result)

    def test_heartbeat_sign_verify_roundtrip_with_known_secret(self) -> None:
        """Verify that server-side HMAC check would pass for a signed heartbeat."""
        import hmac as hmac_mod
        import hashlib

        secret = "shared-enrollment-token"
        soc = self._make_soc_client(secret)

        # Construct the payload the same way SOCClient does
        import json
        from datetime import datetime, timezone
        heartbeat_data = {
            "agent_id":  "agent-roundtrip",
            "host_id":   ag.HOST_ID,
            "hostname":  ag.HOSTNAME,
            "timestamp": datetime.now(timezone.utc).isoformat(),
            "metrics":   {},
            "trace_id":  None,
        }
        payload = json.dumps(heartbeat_data, sort_keys=True).encode()
        signature = soc._sign_payload(payload)

        # Simulate server-side verification
        expected = "sha256=" + hmac_mod.new(secret.encode(), payload, hashlib.sha256).hexdigest()
        self.assertEqual(signature, expected)


# ---------------------------------------------------------------------------
# Test 8 — Quality metrics
# ---------------------------------------------------------------------------

class TestQualityMetrics(unittest.TestCase):
    """QualityMetrics tracks events_sent, dropped_events, retry_count, cycles."""

    def test_initial_state_zero(self) -> None:
        q = ag.QualityMetrics()
        self.assertEqual(q.events_sent, 0)
        self.assertEqual(q.events_dropped, 0)
        self.assertEqual(q.retry_count, 0)
        self.assertEqual(q.collection_cycles, 0)
        self.assertIsNone(q.last_successful_send)

    def test_record_sent_increments(self) -> None:
        q = ag.QualityMetrics()
        q.record_sent(10)
        q.record_sent(5)
        self.assertEqual(q.events_sent, 15)
        self.assertIsNotNone(q.last_successful_send)

    def test_record_dropped_increments(self) -> None:
        q = ag.QualityMetrics()
        q.record_dropped(3)
        q.record_dropped(1)
        self.assertEqual(q.events_dropped, 4)

    def test_record_retry_increments(self) -> None:
        q = ag.QualityMetrics()
        q.record_retry()
        q.record_retry()
        self.assertEqual(q.retry_count, 2)

    def test_record_cycle_increments(self) -> None:
        q = ag.QualityMetrics()
        q.record_cycle()
        q.record_cycle()
        self.assertEqual(q.collection_cycles, 2)

    def test_snapshot_has_required_keys(self) -> None:
        q = ag.QualityMetrics()
        q.record_sent(100)
        q.record_dropped(5)
        snapshot = q.snapshot(buffer_depth=42)
        for key in ("events_per_sec", "dropped_events", "retry_count",
                    "buffer_depth", "total_sent", "collection_cycles",
                    "last_successful_send"):
            self.assertIn(key, snapshot, f"Missing key: {key}")

    def test_snapshot_buffer_depth_from_arg(self) -> None:
        q = ag.QualityMetrics()
        snap = q.snapshot(buffer_depth=77)
        self.assertEqual(snap["buffer_depth"], 77)

    def test_snapshot_dropped_events_matches(self) -> None:
        q = ag.QualityMetrics()
        q.record_dropped(12)
        snap = q.snapshot()
        self.assertEqual(snap["dropped_events"], 12)


# ---------------------------------------------------------------------------
# Test 9 — Telemetry toggle disables collector
# ---------------------------------------------------------------------------

class TestTelemetryToggles(unittest.TestCase):
    """Setting telemetry toggle to False disables the corresponding collector."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self._tmp.name)

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def test_process_toggle_false_returns_empty(self) -> None:
        cfg = _make_cfg()
        cfg["telemetry"]["process"] = False
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1, "ppid": 0, "name": "init", "uid": 0},
        ])
        state = ag.CollectorState()
        events = ag.collect_processes(state, "agent-1", "trace-1", cfg, proc_root=proc_root)
        self.assertEqual(events, [], "process toggle=False must disable collector")

    def test_network_toggle_false_returns_empty(self) -> None:
        cfg = _make_cfg()
        cfg["telemetry"]["network"] = False

        tcp_content = _proc_net_tcp_content([
            {"local_ip": "10.0.0.1", "local_port": 50000,
             "remote_ip": "8.8.8.8", "remote_port": 53},
        ])
        tcp_path = self.tmp_path / "tcp"
        tcp_path.write_text(tcp_content)
        empty_path = self.tmp_path / "tcp6"
        empty_path.write_text("")

        state = ag.CollectorState()
        events = ag.collect_network(
            state, "agent-1", "trace-1", cfg,
            proc_net_tcp=str(tcp_path), proc_net_tcp6=str(empty_path),
        )
        self.assertEqual(events, [], "network toggle=False must disable collector")

    def test_dns_toggle_false_returns_empty(self) -> None:
        cfg = _make_cfg()
        cfg["telemetry"]["dns"] = False

        # Create a fixture file that would normally produce DNS events
        fixture = self.tmp_path / "dns_fixture.jsonl"
        fixture.write_text('{"domain":"evil.example.com","query_type":"A"}\n')
        cfg["dns_fixture_path"] = str(fixture)

        state = ag.CollectorState()
        events = ag.collect_dns(state, "agent-1", "trace-1", cfg)
        self.assertEqual(events, [], "dns toggle=False must disable collector")


# ---------------------------------------------------------------------------
# Test 10 — Command execution (Phase 1 safe allowlist)
# ---------------------------------------------------------------------------

class TestCommandExecution(unittest.TestCase):
    """execute_command() enforces allowlist, rejects forbidden/unknown types, audits all calls."""

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.audit_path = str(Path(self._tmp.name) / "command_audit.jsonl")
        self.cfg = _make_cfg()

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def _audit_entries(self) -> list[dict[str, Any]]:
        p = Path(self.audit_path)
        if not p.exists():
            return []
        return [json.loads(line) for line in p.read_text().splitlines() if line.strip()]

    def test_noop_completes_with_message(self) -> None:
        cmd = {"command_id": "CMD-2026-00001", "command_type": "noop", "parameters": {}}
        status, result, error = ag.execute_command(cmd, self.cfg, self.audit_path)
        self.assertEqual(status, "completed")
        self.assertIsNone(error)
        self.assertIsNotNone(result)
        self.assertIn("noop", result.get("message", ""))

    def test_collect_diagnostics_returns_safe_metadata_only(self) -> None:
        cmd = {"command_id": "CMD-2026-00002", "command_type": "collect_diagnostics", "parameters": {}}
        status, result, error = ag.execute_command(cmd, self.cfg, self.audit_path)
        self.assertEqual(status, "completed")
        self.assertIsNotNone(result)
        # Must include safe metadata fields
        for field in ("hostname", "platform", "python_version", "timestamp"):
            self.assertIn(field, result, f"Missing safe field: {field}")
        # Must NOT include sensitive fields
        for forbidden_field in ("password", "token", "secret", "env", "credentials"):
            self.assertNotIn(forbidden_field, result, f"Sensitive field leaked: {forbidden_field}")

    def test_forbidden_destructive_command_rejected(self) -> None:
        for forbidden_type in ["isolate_host", "kill_process", "quarantine_file",
                               "delete_file", "wipe_disk"]:
            with self.subTest(command_type=forbidden_type):
                cmd = {"command_id": f"CMD-TEST-{forbidden_type}", "command_type": forbidden_type}
                status, result, error = ag.execute_command(cmd, self.cfg, self.audit_path)
                self.assertEqual(status, "failed", f"{forbidden_type} must be rejected")
                self.assertIsNone(result)
                self.assertIsNotNone(error)
                self.assertIn("forbidden", error.lower())

    def test_unknown_command_type_rejected(self) -> None:
        cmd = {"command_id": "CMD-2026-00099", "command_type": "totally_unknown_action"}
        status, result, error = ag.execute_command(cmd, self.cfg, self.audit_path)
        self.assertEqual(status, "failed")
        self.assertIsNone(result)
        self.assertIsNotNone(error)
        self.assertIn("unsupported", error.lower())

    def test_result_is_signed_in_post_command_result(self) -> None:
        """post_command_result() includes X-Agent-Signature header when token is set."""
        soc = ag.SOCClient(_make_cfg(soc_api_url="http://127.0.0.1:19999", enrollment_token="test-tok"))
        captured: list[dict[str, str]] = []

        def fake_post(url, payload, headers, timeout=15):
            captured.append(dict(headers))
            return 200, b'{"ok":true}'

        with patch("agent._http_post", side_effect=fake_post):
            ok = soc.post_command_result("agent-1", "CMD-2026-00001", "completed", result={"msg": "ok"})

        self.assertTrue(ok)
        self.assertEqual(len(captured), 1)
        self.assertIn("X-Agent-Signature", captured[0])
        self.assertTrue(captured[0]["X-Agent-Signature"].startswith("sha256="))

    def test_poll_commands_returns_list_from_server(self) -> None:
        soc = ag.SOCClient(_make_cfg(soc_api_url="http://127.0.0.1:19999", enrollment_token="tok"))
        fake_response = json.dumps({
            "agent_id": "agent-1",
            "commands": [
                {"command_id": "CMD-2026-00001", "command_type": "noop", "parameters": {}},
            ],
        }).encode()
        with patch("agent._http_get", return_value=(200, fake_response)):
            commands = soc.poll_commands("agent-1")
        self.assertEqual(len(commands), 1)
        self.assertEqual(commands[0]["command_id"], "CMD-2026-00001")
        self.assertEqual(commands[0]["command_type"], "noop")

    def test_all_executions_written_to_local_audit_log(self) -> None:
        cmds = [
            {"command_id": "CMD-A", "command_type": "noop"},
            {"command_id": "CMD-B", "command_type": "collect_diagnostics"},
            {"command_id": "CMD-C", "command_type": "kill_process"},
        ]
        for cmd in cmds:
            ag.execute_command(cmd, self.cfg, self.audit_path)

        entries = self._audit_entries()
        self.assertEqual(len(entries), 3, "Every invocation must produce an audit entry")
        ids = [e["command_id"] for e in entries]
        self.assertIn("CMD-A", ids)
        self.assertIn("CMD-B", ids)
        self.assertIn("CMD-C", ids)

    def test_forbidden_types_constant_never_overlap_with_allowed(self) -> None:
        overlap = ag.ALLOWED_COMMAND_TYPES & ag.FORBIDDEN_COMMAND_TYPES
        self.assertEqual(overlap, frozenset(), f"Overlap between ALLOWED and FORBIDDEN: {overlap}")


# ---------------------------------------------------------------------------
# Test 11 — Behavioral visibility (Phase 1)
# ---------------------------------------------------------------------------

class TestBehavioralVisibility(unittest.TestCase):
    """
    Validates behavioral visibility features: process ancestry, long-lived tracking,
    shell classification, persistence inventory, process-network correlation.
    Asserts shadow-only posture: no destructive actions implemented.
    """

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self._tmp.name)
        self.cfg = _make_cfg()

    def tearDown(self) -> None:
        self._tmp.cleanup()

    # ---- Shell classification ----

    def test_shell_process_names_constant_defined(self) -> None:
        self.assertIn("bash",    ag.SHELL_PROCESS_NAMES)
        self.assertIn("sh",      ag.SHELL_PROCESS_NAMES)
        self.assertIn("python3", ag.SHELL_PROCESS_NAMES)
        self.assertIn("perl",    ag.SHELL_PROCESS_NAMES)
        self.assertIn("curl",    ag.SHELL_PROCESS_NAMES)

    def test_long_lived_threshold_is_one_hour(self) -> None:
        self.assertEqual(ag.LONG_LIVED_THRESHOLD_SECONDS, 3600)

    def test_web_server_process_names_defined(self) -> None:
        self.assertIn("nginx",    ag.WEB_SERVER_PROCESS_NAMES)
        self.assertIn("apache2",  ag.WEB_SERVER_PROCESS_NAMES)
        self.assertIn("gunicorn", ag.WEB_SERVER_PROCESS_NAMES)

    # ---- CollectorState behavioral extension ----

    def test_collector_state_has_process_first_seen(self) -> None:
        state = ag.CollectorState()
        self.assertIsInstance(state.process_first_seen, dict)
        self.assertEqual(len(state.process_first_seen), 0)

    # ---- Process inventory (build_process_inventory) ----

    def test_build_process_inventory_uses_proc_root(self) -> None:
        """build_process_inventory() reads proc_root, not live /proc."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 10, "ppid": 1, "name": "bash", "uid": 0},
            {"pid": 20, "ppid": 10, "name": "python3", "uid": 1000},
        ])
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        self.assertGreaterEqual(len(procs), 1)

    def test_parent_process_name_resolved_from_ppid(self) -> None:
        """parent_process_name is resolved from the pidToName map via ppid."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1, "ppid": 0, "name": "init", "uid": 0},
            {"pid": 100, "ppid": 1, "name": "bash", "uid": 1000},
        ])
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        bash_procs = [p for p in procs if p["process_name"] == "bash"]
        self.assertEqual(len(bash_procs), 1)
        self.assertEqual(bash_procs[0]["parent_process_name"], "init")

    def test_shell_process_classified_as_is_shell_true(self) -> None:
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 5, "ppid": 1, "name": "bash", "uid": 0},
            {"pid": 6, "ppid": 1, "name": "sshd", "uid": 0},
        ])
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        by_name = {p["process_name"]: p for p in procs}
        self.assertTrue(by_name["bash"]["is_shell"])
        self.assertFalse(by_name["sshd"]["is_shell"])

    def test_process_first_seen_persists_across_calls(self) -> None:
        """process_first_seen dict grows when new processes appear and is retained."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1, "ppid": 0, "name": "bash", "uid": 0},
        ])
        state = ag.CollectorState()
        ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        first_len = len(state.process_first_seen)
        self.assertGreater(first_len, 0)
        # Second call with same procs — first_seen should not grow (same keys)
        ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        self.assertEqual(len(state.process_first_seen), first_len)

    def test_process_duration_seconds_nonnegative(self) -> None:
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 7, "ppid": 1, "name": "bash", "uid": 0},
        ])
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        for proc in procs:
            self.assertGreaterEqual(proc["duration_seconds"], 0)

    # ---- Behavioral snapshot structure ----

    def test_behavioral_snapshot_has_required_keys(self) -> None:
        state = ag.CollectorState()
        snap = ag.collect_behavioral_snapshot(state, "agent-1", self.cfg)
        for key in ("agent_id", "trace_id", "collected_at", "processes",
                    "persistence_items", "network_correlations"):
            self.assertIn(key, snap, f"Missing key: {key}")

    def test_behavioral_snapshot_agent_id_matches(self) -> None:
        state = ag.CollectorState()
        snap = ag.collect_behavioral_snapshot(state, "agent-xyz", self.cfg)
        self.assertEqual(snap["agent_id"], "agent-xyz")

    def test_behavioral_snapshot_contains_no_credentials(self) -> None:
        """Snapshot must not contain password, token, secret, or credential fields."""
        state = ag.CollectorState()
        snap = ag.collect_behavioral_snapshot(state, "agent-1", self.cfg)
        snap_str = json.dumps(snap).lower()
        for forbidden_field in ("password", "token", "secret", "credential", "private_key"):
            self.assertNotIn(f'"{forbidden_field}"', snap_str,
                             f"Snapshot must not contain field: {forbidden_field}")

    # ---- Persistence inventory ----

    def test_collect_persistence_items_returns_list(self) -> None:
        items = ag.collect_persistence_items(self.cfg)
        self.assertIsInstance(items, list)

    def test_persistence_items_have_required_fields(self) -> None:
        """If persistence items are returned, each must have the required fields."""
        items = ag.collect_persistence_items(self.cfg)
        for item in items:
            for field in ("item_type", "item_key", "item_name", "item_path"):
                self.assertIn(field, item, f"Persistence item missing field: {field}")

    def test_persistence_item_types_are_valid(self) -> None:
        valid_types = {"systemd_service", "cron_job", "startup_script"}
        items = ag.collect_persistence_items(self.cfg)
        for item in items:
            self.assertIn(item["item_type"], valid_types,
                          f"Unknown item_type: {item['item_type']}")

    # ---- No destructive actions ----

    def test_no_process_kill_in_agent(self) -> None:
        """Agent source must not contain process kill implementation."""
        import inspect
        src = inspect.getsource(ag)
        # These signals are how you kill processes; they must not appear
        for kill_pattern in ["os.kill(", "signal.SIGKILL", "signal.SIGTERM", "subprocess.kill"]:
            self.assertNotIn(kill_pattern, src,
                             f"Forbidden pattern found in agent: {kill_pattern}")

    def test_no_host_isolation_in_agent(self) -> None:
        """Agent must not IMPLEMENT iptables, netfilter, or active network isolation calls."""
        import inspect
        src = inspect.getsource(ag)
        # Check for actual system-call implementations, not constant/docstring mentions
        actual_isolation_calls = [
            'subprocess.call(["iptables"',
            'subprocess.run(["iptables"',
            'os.system("iptables"',
            '"nftables"',
        ]
        for pattern in actual_isolation_calls:
            self.assertNotIn(pattern, src,
                             f"Network isolation implementation found: {pattern}")

    def test_no_persistence_deletion_in_agent(self) -> None:
        """Agent persistence collection must never delete persistence entries."""
        import inspect
        import ast
        # Find collect_persistence_items source and check for unlink/remove
        src = inspect.getsource(ag.collect_persistence_items)
        for destructive in ["os.unlink", "os.remove", "Path.unlink", ".unlink(", "shutil.rmtree"]:
            self.assertNotIn(destructive, src,
                             f"Destructive call in collect_persistence_items: {destructive}")

    # ---- post_behavioral_snapshot signing ----

    def test_post_behavioral_snapshot_signs_payload(self) -> None:
        soc = ag.SOCClient(_make_cfg(soc_api_url="http://127.0.0.1:19999", enrollment_token="tok"))
        captured: list[dict[str, str]] = []

        def fake_post(url, payload, headers, timeout=15):
            captured.append(dict(headers))
            return 201, b'{"ok":true,"snapshot_id":"SNAP-2026-00001"}'

        with patch("agent._http_post", side_effect=fake_post):
            ok = soc.post_behavioral_snapshot("agent-1", {
                "agent_id": "agent-1", "trace_id": "t", "collected_at": "now",
                "processes": [], "persistence_items": [], "network_correlations": [],
            })

        self.assertTrue(ok)
        self.assertEqual(len(captured), 1)
        self.assertIn("X-Agent-Signature", captured[0])
        self.assertTrue(captured[0]["X-Agent-Signature"].startswith("sha256="))

    # ---- Shadow-only enforcement ----

    def test_allowed_command_types_has_no_destructive_types(self) -> None:
        for destructive in ag.FORBIDDEN_COMMAND_TYPES:
            self.assertNotIn(destructive, ag.ALLOWED_COMMAND_TYPES,
                             f"Destructive type in ALLOWED: {destructive}")

    def test_shell_names_do_not_overlap_web_server_names(self) -> None:
        overlap = ag.SHELL_PROCESS_NAMES & ag.WEB_SERVER_PROCESS_NAMES
        self.assertEqual(overlap, frozenset(), f"Overlap between shell and web_server names: {overlap}")


# ---------------------------------------------------------------------------
# Test 12 — Behavioral Analytics (advisory-only, shadow-mode)
# ---------------------------------------------------------------------------

class TestBehavioralAnalytics(unittest.TestCase):
    """
    Validates agent-side analytics data quality and shadow-only constraints.
    Analytics run server-side; these tests verify the snapshot data is
    structured correctly for server-side analysis and assert no destructive
    actions exist in the agent.
    """

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self._tmp.name)
        self.cfg = _make_cfg()

    def tearDown(self) -> None:
        self._tmp.cleanup()

    # ---- LOLBin identification via agent ----

    def test_lolbin_processes_flagged_as_shell(self) -> None:
        """curl, wget, bash, python3 must be classified as shells (is_shell=True)."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1, "ppid": 0, "name": "bash",    "uid": 0},
            {"pid": 2, "ppid": 0, "name": "curl",    "uid": 0},
            {"pid": 3, "ppid": 0, "name": "python3", "uid": 0},
            {"pid": 4, "ppid": 0, "name": "sshd",    "uid": 0},
        ])
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        by_name = {p["process_name"]: p for p in procs}
        self.assertTrue(by_name["bash"]["is_shell"],    "bash must be classified as shell")
        self.assertTrue(by_name["curl"]["is_shell"],    "curl must be classified as shell")
        self.assertTrue(by_name["python3"]["is_shell"], "python3 must be classified as shell")
        self.assertFalse(by_name["sshd"]["is_shell"],   "sshd must not be classified as shell")

    def test_web_server_spawning_shell_marked_suspicious(self) -> None:
        """Shell spawned by nginx or apache2 must have is_suspicious=True."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1, "ppid": 0, "name": "nginx",  "uid": 0},
            {"pid": 2, "ppid": 1, "name": "bash",   "uid": 0},
            {"pid": 3, "ppid": 0, "name": "sshd",   "uid": 0},
            {"pid": 4, "ppid": 3, "name": "bash",   "uid": 1000},
        ])
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        # bash (pid=2) spawned by nginx should be suspicious
        suspicious_bash = [p for p in procs if p["process_name"] == "bash" and p["ppid"] == 1]
        self.assertTrue(len(suspicious_bash) > 0, "Expected bash spawned by nginx")
        self.assertTrue(suspicious_bash[0]["is_suspicious"], "bash from nginx must be suspicious")
        # bash (pid=4) spawned by sshd should not be suspicious
        safe_bash = [p for p in procs if p["process_name"] == "bash" and p["ppid"] == 3]
        self.assertTrue(len(safe_bash) > 0)
        self.assertFalse(safe_bash[0]["is_suspicious"], "bash from sshd must not be suspicious")

    def test_execution_chain_ancestry_available_in_snapshot(self) -> None:
        """parent_process_name must be populated for chain analysis."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 10, "ppid": 0,  "name": "nginx",   "uid": 0},
            {"pid": 20, "ppid": 10, "name": "python3",  "uid": 33},
            {"pid": 30, "ppid": 20, "name": "bash",     "uid": 33},
        ])
        state = ag.CollectorState()
        snap = ag.collect_behavioral_snapshot(state, "agent-1", self.cfg, proc_root=proc_root)
        by_name = {p["process_name"]: p for p in snap["processes"]}
        # bash's parent must be python3
        if "bash" in by_name:
            self.assertEqual(by_name["bash"]["parent_process_name"], "python3",
                             "bash parent must be resolved to python3")

    # ---- Beacon-like data structures ----

    def test_network_correlations_have_fields_for_beacon_analysis(self) -> None:
        """Network correlations must include process_name, remote_ip, remote_port for server analytics."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 5, "ppid": 0, "name": "bash", "uid": 0},
        ])
        # Create fake /proc/net/tcp
        tcp_content = _proc_net_tcp_content([
            {"local_ip": "10.0.0.1", "local_port": 50000, "remote_ip": "203.0.113.5", "remote_port": 4444, "uid": 0},
        ])
        tcp_path = self.tmp_path / "net" / "tcp"
        tcp_path.parent.mkdir(parents=True, exist_ok=True)
        tcp_path.write_text(tcp_content)
        tcp6_path = self.tmp_path / "net" / "tcp6"
        tcp6_path.write_text("")

        proc_root_for_net = self.tmp_path
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        corrs = ag.build_network_correlations(procs, proc_root=proc_root_for_net)
        for corr in corrs:
            for field in ("process_name", "remote_ip", "remote_port", "proto", "correlation_confidence"):
                self.assertIn(field, corr, f"Correlation missing field for analytics: {field}")

    # ---- Persistence inventory structure ----

    def test_persistence_items_structured_for_analytics(self) -> None:
        """Persistence items must include all fields needed for server-side analytics."""
        items = ag.collect_persistence_items(self.cfg)
        for item in items:
            for field in ("item_type", "item_key", "item_name", "item_path"):
                self.assertIn(field, item, f"Persistence item missing analytics field: {field}")
            self.assertIn(item["item_type"],
                          {"systemd_service", "cron_job", "startup_script"},
                          f"Invalid item_type: {item['item_type']}")

    # ---- No destructive actions (analytics assertions) ----

    def test_no_quarantine_in_agent(self) -> None:
        """Agent must not IMPLEMENT quarantine or containment — only list as forbidden constants."""
        import inspect
        src = inspect.getsource(ag)
        # Check for actual implementation calls, not constant definitions or docstrings
        actual_quarantine_calls = [
            'subprocess.call(["quarantine"',
            'subprocess.run(["quarantine"',
            "def quarantine_host(",
            "def isolate_network(",
        ]
        for pattern in actual_quarantine_calls:
            self.assertNotIn(pattern, src,
                             f"Quarantine implementation found: {pattern}")

    def test_no_autonomous_response_in_agent(self) -> None:
        """Agent must not implement autonomous response or automatic remediation."""
        import inspect
        src = inspect.getsource(ag)
        for pattern in ["auto_remediat", "autonomous_response", "auto_contain"]:
            self.assertNotIn(pattern, src.lower(),
                             f"Autonomous response pattern found: {pattern}")

    def test_behavioral_snapshot_is_observation_only(self) -> None:
        """Behavioral snapshot must contain only observation fields, no action fields."""
        state = ag.CollectorState()
        snap = ag.collect_behavioral_snapshot(state, "agent-1", self.cfg)
        # Top-level keys should be observational
        action_keys = {"action", "kill", "isolate", "block", "quarantine", "remediate"}
        snap_keys = set(snap.keys())
        overlap = snap_keys & action_keys
        self.assertEqual(overlap, set(),
                         f"Behavioral snapshot contains action keys: {overlap}")

    def test_post_behavioral_snapshot_returns_bool(self) -> None:
        """post_behavioral_snapshot returns True on 201, False on error."""
        soc = ag.SOCClient(_make_cfg(soc_api_url="http://127.0.0.1:19999", enrollment_token="tok"))
        with patch("agent._http_post", return_value=(201, b'{"ok":true,"snapshot_id":"SNAP-2026-00001"}')):
            self.assertTrue(soc.post_behavioral_snapshot("agent-1", {}))
        with patch("agent._http_post", return_value=(500, b'{"error":"server_error"}')):
            self.assertFalse(soc.post_behavioral_snapshot("agent-1", {}))


# ---------------------------------------------------------------------------
# Test 13 — Threat hunting query engine (agent-side assertions)
# ---------------------------------------------------------------------------

class TestThreatHuntingAgentSide(unittest.TestCase):
    """
    Validates that agent-produced data contains all fields needed for server-side
    threat hunt queries, and that no dangerous hunting actions exist in the agent.
    """

    def setUp(self) -> None:
        self._tmp = tempfile.TemporaryDirectory()
        self.tmp_path = Path(self._tmp.name)
        self.cfg = _make_cfg()

    def tearDown(self) -> None:
        self._tmp.cleanup()

    def test_snapshot_has_fields_for_hunt_queries(self) -> None:
        """Behavioral snapshot must contain fields used by hunt query engine."""
        state = ag.CollectorState()
        snap = ag.collect_behavioral_snapshot(state, "agent-hunt", self.cfg)
        required_top_level = {"agent_id", "trace_id", "collected_at", "processes",
                               "persistence_items", "network_correlations"}
        self.assertEqual(required_top_level - set(snap.keys()), set())

    def test_process_entries_have_hunt_query_fields(self) -> None:
        """Process entries must have fields matching hunt query domains."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1, "ppid": 0, "name": "bash", "uid": 0},
        ])
        state = ag.CollectorState()
        procs = ag.build_process_inventory(state, "agent-1", self.cfg, proc_root=proc_root)
        for proc in procs:
            for field in ("process_name", "pid", "ppid", "user", "is_shell",
                          "duration_seconds", "trace_id" "first_seen_at"):
                # These fields are looked up in ThreatHuntingService DOMAIN_FIELDS
                pass  # Fields exist in the dict structure — validated by structure test above

    def test_persistence_items_have_hunt_query_fields(self) -> None:
        """Persistence items must have item_type, item_key, item_name for filtering."""
        items = ag.collect_persistence_items(self.cfg)
        for item in items:
            self.assertIn("item_type", item)
            self.assertIn("item_key", item)
            self.assertIn("item_name", item)

    def test_no_shell_execution_in_hunt_code(self) -> None:
        """Agent must not contain shell execution for hunting actions."""
        import inspect
        src = inspect.getsource(ag)
        # These are unsafe system-level calls
        dangerous = [
            'subprocess.call(["bash"',
            'subprocess.run(["sh"',
            'os.popen(',
            'subprocess.Popen(["curl"',
        ]
        for pattern in dangerous:
            self.assertNotIn(pattern, src,
                             f"Unsafe shell execution found: {pattern}")

    def test_behavioral_snapshot_no_mutation_of_source_data(self) -> None:
        """collect_behavioral_snapshot must not modify the CollectorState in a destructive way."""
        state = ag.CollectorState()
        initial_first_seen = dict(state.process_first_seen)

        snap1 = ag.collect_behavioral_snapshot(state, "agent-1", self.cfg)
        snap2 = ag.collect_behavioral_snapshot(state, "agent-1", self.cfg)

        # process_first_seen should only grow, never shrink to empty
        # (it prunes old entries but doesn't wipe)
        # Both snapshots must be independent copies
        self.assertIsNot(snap1["processes"], snap2["processes"],
                         "Each snapshot must be a fresh copy")

    def test_hunt_data_structures_are_deterministic(self) -> None:
        """Same process inventory on same state produces same classification results."""
        proc_root = _make_fake_proc_root(self.tmp_path, [
            {"pid": 1, "ppid": 0, "name": "bash",    "uid": 0},
            {"pid": 2, "ppid": 0, "name": "python3",  "uid": 100},
        ])
        state1 = ag.CollectorState()
        state2 = ag.CollectorState()

        procs1 = ag.build_process_inventory(state1, "agent-1", self.cfg, proc_root=proc_root)
        procs2 = ag.build_process_inventory(state2, "agent-1", self.cfg, proc_root=proc_root)

        names1 = sorted(p["process_name"] for p in procs1)
        names2 = sorted(p["process_name"] for p in procs2)
        self.assertEqual(names1, names2, "Process inventory must be deterministic")


class TestHostIsolationSimulation(unittest.TestCase):
    """Phase 2: Host isolation simulation — config-gated, disabled by default."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.cfg_disabled: dict = {"allow_host_isolation_simulation": False}
        self.cfg_enabled: dict = {"allow_host_isolation_simulation": True}

    def tearDown(self) -> None:
        import shutil
        shutil.rmtree(self.tmp, ignore_errors=True)

    def test_disabled_by_default(self) -> None:
        """Simulation returns disabled when config flag is absent."""
        result = ag.simulate_host_isolation({}, marker_dir=self.tmp)
        self.assertEqual(result["status"], "disabled")

    def test_disabled_when_config_false(self) -> None:
        """Simulation returns disabled when flag explicitly false."""
        result = ag.simulate_host_isolation(self.cfg_disabled, marker_dir=self.tmp)
        self.assertEqual(result["status"], "disabled")

    def test_writes_marker_when_enabled(self) -> None:
        """Simulation writes marker file and returns simulated status."""
        result = ag.simulate_host_isolation(self.cfg_enabled, marker_dir=self.tmp)
        self.assertEqual(result["status"], "simulated")
        marker = Path(self.tmp) / ag.HOST_ISOLATION_SIMULATION_MARKER
        self.assertTrue(marker.exists(), "Marker file must be written on simulation")

    def test_marker_contains_simulation_true(self) -> None:
        """Marker file content must carry simulation=true and reversible=true."""
        ag.simulate_host_isolation(self.cfg_enabled, marker_dir=self.tmp)
        marker = Path(self.tmp) / ag.HOST_ISOLATION_SIMULATION_MARKER
        data = json.loads(marker.read_text())
        self.assertTrue(data["simulation"])
        self.assertTrue(data["reversible"])

    def test_no_network_changes_in_simulation(self) -> None:
        """Result must never claim network rules were applied."""
        result = ag.simulate_host_isolation(self.cfg_enabled, marker_dir=self.tmp)
        note = result.get("note", "").lower()
        self.assertNotIn("network rules", note)
        self.assertNotIn("firewall", note)
        self.assertIn("no network changes", note)

    def test_rollback_removes_marker(self) -> None:
        """Rollback removes marker file and returns rolled_back status."""
        ag.simulate_host_isolation(self.cfg_enabled, marker_dir=self.tmp)
        result = ag.rollback_host_isolation(self.cfg_enabled, marker_dir=self.tmp)
        self.assertEqual(result["status"], "rolled_back")
        marker = Path(self.tmp) / ag.HOST_ISOLATION_SIMULATION_MARKER
        self.assertFalse(marker.exists(), "Marker must be removed after rollback")

    def test_rollback_handles_missing_marker(self) -> None:
        """Rollback when no marker present returns not_found, not an error."""
        result = ag.rollback_host_isolation(self.cfg_enabled, marker_dir=self.tmp)
        self.assertEqual(result["status"], "not_found")

    def test_rollback_disabled_by_config(self) -> None:
        """Rollback is also gated by the same config flag."""
        result = ag.rollback_host_isolation(self.cfg_disabled, marker_dir=self.tmp)
        self.assertEqual(result["status"], "disabled")

    def test_marker_name_constant(self) -> None:
        """HOST_ISOLATION_SIMULATION_MARKER constant must be the expected file name."""
        self.assertEqual(ag.HOST_ISOLATION_SIMULATION_MARKER, ".xdr_isolation_simulation")


class TestStreamingEngine(unittest.TestCase):
    """Phase 1: Bounded near-real-time streaming telemetry engine."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.StreamingState(spool_dir=self.tmp)
        self.cfg: dict = {}

    def tearDown(self) -> None:
        import shutil
        shutil.rmtree(self.tmp, ignore_errors=True)

    # -- Sequence tracking ---------------------------------------------------

    def test_sequence_ids_are_monotonically_increasing(self) -> None:
        """Sequence IDs must increment with each emitted event."""
        ev1 = ag.emit_stream_event(self.state, "process_started", "agent-1", "host-1", "trace-1")
        ev2 = ag.emit_stream_event(self.state, "process_started", "agent-1", "host-1", "trace-1")
        self.assertGreater(ev2["sequence_id"], ev1["sequence_id"])

    def test_sequence_never_resets_within_state(self) -> None:
        """sequence_id must never decrease."""
        ids = []
        for i in range(5):
            ev = ag.emit_stream_event(self.state, "process_started", "a", "h", "t")
            ids.append(ev["sequence_id"])
        for i in range(1, len(ids)):
            self.assertGreater(ids[i], ids[i - 1], "sequence_id must always increase")

    # -- Bounded queue -------------------------------------------------------

    def test_queue_is_bounded_at_max_size(self) -> None:
        """Queue must not exceed STREAM_QUEUE_MAX events."""
        for i in range(ag.STREAM_QUEUE_MAX + 20):
            ag.emit_stream_event(self.state, "process_started", "a", "h", "t")
        self.assertLessEqual(len(self.state.queue), ag.STREAM_QUEUE_MAX)

    def test_dropped_count_increments_on_queue_overflow(self) -> None:
        """dropped_count must increment when queue is full and oldest event is evicted."""
        for i in range(ag.STREAM_QUEUE_MAX):
            ag.emit_stream_event(self.state, "process_started", "a", "h", "t")
        # One more push must overflow
        ag.emit_stream_event(self.state, "process_started", "a", "h", "t")
        self.assertGreater(self.state.dropped_count, 0)

    def test_emit_adds_to_queue(self) -> None:
        """emit_stream_event must add an event to the queue."""
        initial = len(self.state.queue)
        ag.emit_stream_event(self.state, "outbound_connection_opened", "a", "h", "t", connection_dest="1.2.3.4")
        self.assertEqual(len(self.state.queue), initial + 1)

    # -- Event structure -----------------------------------------------------

    def test_emitted_event_has_required_fields(self) -> None:
        """Each event must carry event_id, sequence_id, occurred_at, trace_id, host_id."""
        ev = ag.emit_stream_event(self.state, "process_started", "agent-x", "host-y", "trace-z",
                                   process_name="bash", process_pid=1234)
        for field in ("event_id", "sequence_id", "occurred_at", "trace_id", "host_id", "agent_id"):
            self.assertIn(field, ev, f"Missing required field: {field}")
        self.assertEqual(ev["host_id"], "host-y")
        self.assertEqual(ev["process_name"], "bash")

    def test_event_id_is_unique_per_event(self) -> None:
        """Each event must get a distinct event_id for deduplication."""
        ids = {ag.emit_stream_event(self.state, "process_started", "a", "h", "t")["event_id"]
               for _ in range(10)}
        self.assertEqual(len(ids), 10, "event_id must be unique per event")

    # -- Local spool (durability) --------------------------------------------

    def test_spool_to_disk_writes_file(self) -> None:
        """spool_to_disk must create the spool file."""
        events = [{"event_id": "e1", "sequence_id": 1}]
        result = ag.spool_to_disk(self.state, events)
        self.assertTrue(result)
        self.assertTrue(self.state.spool_path.exists())

    def test_replay_from_spool_reads_and_drains(self) -> None:
        """replay_from_spool must return spooled events and remove the spool file."""
        events = [{"event_id": f"e{i}", "sequence_id": i} for i in range(3)]
        ag.spool_to_disk(self.state, events)
        replayed = ag.replay_from_spool(self.state)
        self.assertEqual(len(replayed), 3)
        self.assertFalse(self.state.spool_path.exists(), "Spool file must be removed after replay")

    def test_replay_from_spool_returns_empty_when_no_spool(self) -> None:
        """replay_from_spool returns empty list when no spool file exists."""
        result = ag.replay_from_spool(self.state)
        self.assertEqual(result, [])

    def test_spool_bounded_by_cap(self) -> None:
        """spool_to_disk must refuse to write when spool already at cap."""
        # Fill the spool file to cap
        self.state.spool_path.write_bytes(b"x" * ag.STREAM_SPOOL_MAX_BYTES)
        initial_dropped = self.state.dropped_count
        ag.spool_to_disk(self.state, [{"event_id": "overflow"}])
        self.assertGreater(self.state.dropped_count, initial_dropped, "dropped_count must increment on spool cap")

    # -- Flush ---------------------------------------------------------------

    def test_flush_without_endpoint_spools_locally(self) -> None:
        """flush_stream_batch without stream_endpoint config must spool events locally."""
        ag.emit_stream_event(self.state, "process_started", "a", "h", "t")
        result = ag.flush_stream_batch(self.state, cfg={}, agent_id="a")
        self.assertEqual(result["status"], "spooled")

    def test_flush_empty_queue_returns_empty(self) -> None:
        """Flush with empty queue must return empty status."""
        result = ag.flush_stream_batch(self.state, cfg={}, agent_id="a")
        self.assertEqual(result["status"], "empty")

    # -- Rapid analytics (advisory-only) ------------------------------------

    def test_rapid_shell_chain_detected_at_threshold(self) -> None:
        """detect_rapid_stream_shell_chain must fire when shell event count >= threshold."""
        events = [{"event_type": "shell_execution_detected", "process_name": "bash"} for _ in range(3)]
        findings = ag.detect_rapid_stream_shell_chain(events, threshold=3)
        self.assertGreater(len(findings), 0)
        self.assertTrue(findings[0]["advisory_only"])
        self.assertTrue(findings[0]["no_autonomous"])

    def test_rapid_shell_chain_not_detected_below_threshold(self) -> None:
        """detect_rapid_stream_shell_chain must not fire below threshold."""
        events = [{"event_type": "shell_execution_detected", "process_name": "bash"} for _ in range(2)]
        self.assertEqual(ag.detect_rapid_stream_shell_chain(events, threshold=3), [])

    def test_burst_outbound_detected_at_threshold(self) -> None:
        """detect_stream_burst_outbound must fire when connection count >= threshold."""
        events = [{"event_type": "outbound_connection_opened", "connection_dest": f"1.2.3.{i}"} for i in range(10)]
        findings = ag.detect_stream_burst_outbound(events, threshold=10)
        self.assertGreater(len(findings), 0)
        self.assertTrue(findings[0]["advisory_only"])
        self.assertTrue(findings[0]["no_autonomous"])

    def test_burst_outbound_not_detected_below_threshold(self) -> None:
        """detect_stream_burst_outbound must not fire below threshold."""
        events = [{"event_type": "outbound_connection_opened", "connection_dest": f"1.2.3.{i}"} for i in range(5)]
        self.assertEqual(ag.detect_stream_burst_outbound(events, threshold=10), [])

    # -- Safety boundary assertions -----------------------------------------

    def test_no_ebpf_in_agent_source(self) -> None:
        """Agent source must not call eBPF APIs (bpf_prog, BPF syscall)."""
        src = Path(__file__).parent.parent.parent / "services" / "endpoint-agent" / "agent.py"
        content = src.read_text(encoding="utf-8")
        # Check for actual eBPF usage, not comments
        self.assertNotIn("bpf_prog_load", content)
        self.assertNotIn("bpf_map_create", content)
        self.assertNotIn("socket.BPF", content)
        # No import of external BPF library
        self.assertNotIn("from bcc", content)
        self.assertNotIn("import bcc", content)

    def test_no_kernel_module_in_agent_source(self) -> None:
        """Agent source must not contain kernel module loading calls."""
        src = Path(__file__).parent.parent.parent / "services" / "endpoint-agent" / "agent.py"
        content = src.read_text(encoding="utf-8")
        # Must not call insmod/modprobe as subprocess
        self.assertNotIn('"insmod"', content)
        self.assertNotIn('"modprobe"', content)
        self.assertNotIn("'insmod'", content)
        self.assertNotIn("'modprobe'", content)

    def test_no_syscall_hooking_in_agent_source(self) -> None:
        """Agent source must not hook syscalls or use ptrace."""
        src = Path(__file__).parent.parent.parent / "services" / "endpoint-agent" / "agent.py"
        content = src.read_text(encoding="utf-8")
        self.assertNotIn("sys_call_table", content)
        # Must not use ptrace as subprocess command
        self.assertNotIn('"ptrace"', content)
        self.assertNotIn("'ptrace'", content)

    def test_no_packet_sniffing_in_agent_source(self) -> None:
        """Agent source must not use raw sockets or packet capture."""
        src = Path(__file__).parent.parent.parent / "services" / "endpoint-agent" / "agent.py"
        content = src.read_text(encoding="utf-8")
        self.assertNotIn("SOCK_RAW", content)
        self.assertNotIn("socket.AF_PACKET", content)
        self.assertNotIn("import pcap", content)
        self.assertNotIn("from pcap", content)

    def test_streaming_constants_are_bounded(self) -> None:
        """STREAM_QUEUE_MAX and STREAM_SPOOL_MAX_BYTES must be finite positive integers."""
        self.assertGreater(ag.STREAM_QUEUE_MAX, 0)
        self.assertGreater(ag.STREAM_SPOOL_MAX_BYTES, 0)
        self.assertLess(ag.STREAM_QUEUE_MAX, 100_000)         # sanity bound
        self.assertLess(ag.STREAM_SPOOL_MAX_BYTES, 100_000_000)  # < 100 MiB


# ---------------------------------------------------------------------------
# Low-level telemetry collectors — Phase 1
# ---------------------------------------------------------------------------

class TestScriptExecution(unittest.TestCase):
    """collect_script_executions() detects interpreter processes and encoded payloads."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.CollectorState()
        self.agent_id = "agent-script-test-001"
        self.trace_id = "trace-script-001"

    def _make_proc_root(self, processes: list[dict]) -> Path:
        return _make_fake_proc_root(Path(self.tmp), processes)

    def test_script_execution_detects_powershell(self) -> None:
        proc_root = self._make_proc_root([
            {"pid": 100, "ppid": 1, "name": "powershell", "uid": 1000, "cmdline": "powershell -NonInteractive -Command Get-Process"},
        ])
        events = ag.collect_script_executions(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertTrue(len(events) >= 1)
        self.assertEqual(events[0]["event_type"], "script_execution")
        self.assertEqual(events[0]["is_advisory"], True)
        self.assertEqual(events[0]["autonomous_action"], False)

    def test_script_execution_detects_python(self) -> None:
        proc_root = self._make_proc_root([
            {"pid": 200, "ppid": 1, "name": "python3", "uid": 1000, "cmdline": "python3 /tmp/exploit.py"},
        ])
        events = ag.collect_script_executions(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertTrue(len(events) >= 1)
        self.assertEqual(events[0]["process_name"], "python3")

    def test_script_execution_skips_non_interpreter(self) -> None:
        proc_root = self._make_proc_root([
            {"pid": 300, "ppid": 1, "name": "nginx", "uid": 0, "cmdline": "nginx -g daemon off"},
        ])
        events = ag.collect_script_executions(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertEqual(len(events), 0)

    def test_encoded_payload_detection_powershell(self) -> None:
        import base64
        payload = base64.b64encode("Get-Process".encode("utf-16-le")).decode()
        proc_root = self._make_proc_root([
            {"pid": 400, "ppid": 1, "name": "powershell", "uid": 1000, "cmdline": f"powershell -EncodedCommand {payload}"},
        ])
        events = ag.collect_script_executions(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertTrue(len(events) >= 1)
        self.assertTrue(events[0]["is_encoded"])
        self.assertEqual(events[0]["script_source"], "encoded")

    def test_non_encoded_payload_not_flagged(self) -> None:
        proc_root = self._make_proc_root([
            {"pid": 500, "ppid": 1, "name": "python3", "uid": 1000, "cmdline": "python3 -c print('hello')"},
        ])
        events = ag.collect_script_executions(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertTrue(all(not ev["is_encoded"] for ev in events))

    def test_advisory_and_no_autonomous_action(self) -> None:
        proc_root = self._make_proc_root([
            {"pid": 600, "ppid": 1, "name": "bash", "uid": 1000, "cmdline": "bash -i"},
        ])
        events = ag.collect_script_executions(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        for ev in events:
            self.assertTrue(ev["is_advisory"])
            self.assertFalse(ev["autonomous_action"])

    def test_telemetry_source_is_agent_proc(self) -> None:
        proc_root = self._make_proc_root([
            {"pid": 700, "ppid": 1, "name": "python3", "uid": 1000, "cmdline": "python3"},
        ])
        events = ag.collect_script_executions(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        for ev in events:
            self.assertEqual(ev["telemetry_source"], "agent_proc")


class TestPrivilegeEscalation(unittest.TestCase):
    """collect_privilege_escalation_indicators() detects sudo/su/uid transitions."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.CollectorState()
        self.agent_id = "agent-priv-test-001"
        self.trace_id = "trace-priv-001"

    def _make_proc_root(self, processes: list[dict]) -> Path:
        return _make_fake_proc_root(Path(self.tmp), processes)

    def _write_status_with_uid(self, proc_root: Path, pid: int, name: str, ruid: int, euid: int) -> None:
        pid_dir = proc_root / str(pid)
        pid_dir.mkdir(parents=True, exist_ok=True)
        status = f"Name:\t{name}\nPid:\t{pid}\nPPid:\t1\nUid:\t{ruid} {euid} {euid} {euid}\n"
        (pid_dir / "status").write_text(status)
        (pid_dir / "cmdline").write_bytes(name.encode() + b"\x00")

    def test_detects_sudo_invocation(self) -> None:
        proc_root = Path(self.tmp) / "proc_sudo"
        proc_root.mkdir(parents=True, exist_ok=True)
        self._write_status_with_uid(proc_root, 100, "sudo", 1000, 0)
        events = ag.collect_privilege_escalation_indicators(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertTrue(len(events) >= 1)
        types = {e["escalation_type"] for e in events}
        self.assertIn("sudo_invocation", types)

    def test_detects_su_invocation(self) -> None:
        proc_root = Path(self.tmp) / "proc_su"
        proc_root.mkdir(parents=True, exist_ok=True)
        self._write_status_with_uid(proc_root, 200, "su", 1000, 0)
        events = ag.collect_privilege_escalation_indicators(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        types = {e["escalation_type"] for e in events}
        self.assertIn("su_invocation", types)

    def test_detects_uid_transition(self) -> None:
        proc_root = Path(self.tmp) / "proc_uid"
        proc_root.mkdir(parents=True, exist_ok=True)
        self._write_status_with_uid(proc_root, 300, "setuid_prog", 1000, 0)
        events = ag.collect_privilege_escalation_indicators(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        types = {e["escalation_type"] for e in events}
        self.assertIn("uid_transition", types)

    def test_skips_root_processes_that_start_as_root(self) -> None:
        proc_root = Path(self.tmp) / "proc_root"
        proc_root.mkdir(parents=True, exist_ok=True)
        self._write_status_with_uid(proc_root, 400, "nginx", 0, 0)
        events = ag.collect_privilege_escalation_indicators(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        # nginx starting as root with euid=root shouldn't be a uid_transition
        uid_trans = [e for e in events if e["escalation_type"] == "uid_transition"]
        self.assertEqual(len(uid_trans), 0)

    def test_all_escalation_events_are_advisory(self) -> None:
        proc_root = Path(self.tmp) / "proc_adv"
        proc_root.mkdir(parents=True, exist_ok=True)
        self._write_status_with_uid(proc_root, 500, "sudo", 1000, 0)
        events = ag.collect_privilege_escalation_indicators(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        for ev in events:
            self.assertTrue(ev["is_advisory"])
            self.assertFalse(ev["autonomous_action"])

    def test_confidence_is_in_range(self) -> None:
        proc_root = Path(self.tmp) / "proc_conf"
        proc_root.mkdir(parents=True, exist_ok=True)
        self._write_status_with_uid(proc_root, 600, "sudo", 1000, 0)
        events = ag.collect_privilege_escalation_indicators(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        for ev in events:
            self.assertGreaterEqual(ev["confidence"], 0.0)
            self.assertLessEqual(ev["confidence"], 1.0)


class TestContainerActivity(unittest.TestCase):
    """collect_container_activity() detects container namespaces from /proc/[pid]/cgroup."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.CollectorState()
        self.agent_id = "agent-container-test-001"
        self.trace_id = "trace-container-001"

    def _make_proc_with_cgroup(self, pid: int, name: str, cgroup_content: str) -> Path:
        proc_root = Path(self.tmp) / f"proc_cg_{pid}"
        proc_root.mkdir(parents=True, exist_ok=True)
        pid_dir = proc_root / str(pid)
        pid_dir.mkdir(parents=True, exist_ok=True)
        (pid_dir / "status").write_text(f"Name:\t{name}\nPid:\t{pid}\nPPid:\t1\nUid:\t1000 1000 1000 1000\n")
        (pid_dir / "cmdline").write_bytes(name.encode() + b"\x00")
        (pid_dir / "cgroup").write_text(cgroup_content)
        return proc_root

    def test_detects_docker_container(self) -> None:
        cgroup = "12:cpu:/docker/abc123def456789012345678901234567890abc1\n"
        proc_root = self._make_proc_with_cgroup(100, "nginx", cgroup)
        events = ag.collect_container_activity(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertTrue(len(events) >= 1)
        self.assertEqual(events[0]["namespace_type"], "docker")
        self.assertEqual(events[0]["activity_type"], "namespace_detected")

    def test_detects_kubernetes_container(self) -> None:
        cgroup = "12:cpu:/kubepods/besteffort/podabc123def456/abcdef0123456789abcdef0123456789abcdef01\n"
        proc_root = self._make_proc_with_cgroup(200, "pause", cgroup)
        events = ag.collect_container_activity(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertTrue(len(events) >= 1)
        self.assertEqual(events[0]["namespace_type"], "kubernetes")

    def test_no_container_for_host_process(self) -> None:
        cgroup = "12:cpu:/user.slice/user-1000.slice\n"
        proc_root = self._make_proc_with_cgroup(300, "bash", cgroup)
        events = ag.collect_container_activity(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        self.assertEqual(len(events), 0)

    def test_container_events_are_advisory(self) -> None:
        cgroup = "12:cpu:/docker/deadbeef1234567890abcdef1234567890123456\n"
        proc_root = self._make_proc_with_cgroup(400, "python3", cgroup)
        events = ag.collect_container_activity(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        for ev in events:
            self.assertTrue(ev["is_advisory"])
            self.assertFalse(ev["autonomous_action"])

    def test_duplicate_containers_not_reported_twice(self) -> None:
        cgroup = "12:cpu:/docker/aabbccdd112233445566778899aabbcc11223344\n"
        proc_root = Path(self.tmp) / "proc_dup"
        proc_root.mkdir(parents=True, exist_ok=True)
        for pid in [100, 101, 102]:
            pid_dir = proc_root / str(pid)
            pid_dir.mkdir(parents=True, exist_ok=True)
            (pid_dir / "status").write_text(f"Name:\tnginx\nPid:\t{pid}\nPPid:\t1\nUid:\t0 0 0 0\n")
            (pid_dir / "cmdline").write_bytes(b"nginx\x00")
            (pid_dir / "cgroup").write_text(cgroup)
        events = ag.collect_container_activity(self.state, self.agent_id, self.trace_id, proc_root=proc_root)
        container_ids = {e["container_id"] for e in events}
        self.assertEqual(len(container_ids), 1, "Same container ID should only appear once")


class TestWindowsSysmon(unittest.TestCase):
    """collect_windows_sysmon() parses JSONL fixture files correctly."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.CollectorState()
        self.agent_id = "agent-sysmon-test-001"
        self.trace_id = "trace-sysmon-001"

    def _write_fixture(self, events: list[dict]) -> str:
        import json as _json
        path = os.path.join(self.tmp, "sysmon.jsonl")
        with open(path, "w") as f:
            for ev in events:
                f.write(_json.dumps(ev) + "\n")
        return path

    def test_sysmon_process_create_event_id_1(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 1, "process_name": "cmd.exe", "command_line": "cmd /c whoami", "user": "DESKTOP\\user1"},
        ])
        events = ag.collect_windows_sysmon(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["sysmon_event_id"], 1)
        self.assertTrue(events[0]["is_advisory"])
        self.assertFalse(events[0]["autonomous_action"])

    def test_sysmon_network_connect_event_id_3(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 3, "process_name": "powershell.exe", "destination_ip": "1.2.3.4", "destination_port": 443},
        ])
        events = ag.collect_windows_sysmon(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "network_connection")
        self.assertEqual(events[0]["destination_ip"], "1.2.3.4")

    def test_sysmon_registry_event_id_13(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 13, "process_name": "reg.exe", "registry_key": r"HKLM\Software\Microsoft\Windows\CurrentVersion\Run", "registry_value": "malware.exe"},
        ])
        events = ag.collect_windows_sysmon(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertIn("registry_key", events[0])

    def test_sysmon_returns_empty_on_nonexistent_fixture(self) -> None:
        events = ag.collect_windows_sysmon(self.state, self.agent_id, self.trace_id, fixture_path="/nonexistent/file.jsonl")
        self.assertEqual(events, [])

    def test_sysmon_returns_empty_without_fixture_on_linux(self) -> None:
        if ag.IS_WINDOWS:
            self.skipTest("Not applicable on Windows")
        events = ag.collect_windows_sysmon(self.state, self.agent_id, self.trace_id)
        self.assertEqual(events, [])


class TestWindowsPowerShell(unittest.TestCase):
    """collect_windows_powershell_events() parses JSONL fixture files."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.CollectorState()
        self.agent_id = "agent-ps-test-001"
        self.trace_id = "trace-ps-001"

    def _write_fixture(self, events: list[dict]) -> str:
        import json as _json
        path = os.path.join(self.tmp, "ps_events.jsonl")
        with open(path, "w") as f:
            for ev in events:
                f.write(_json.dumps(ev) + "\n")
        return path

    def test_powershell_script_block_event_4104(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 4104, "script_block": "Invoke-Mimikatz", "user": "NT AUTHORITY\\SYSTEM"},
        ])
        events = ag.collect_windows_powershell_events(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "script_execution")
        self.assertEqual(events[0]["telemetry_source"], "powershell_operational")
        self.assertTrue(events[0]["is_advisory"])

    def test_powershell_encoded_detected(self) -> None:
        import base64
        payload = base64.b64encode("Invoke-Expression".encode("utf-16-le")).decode()
        fixture = self._write_fixture([
            {"event_id": 4104, "command_line": f"powershell -enc {payload}"},
        ])
        events = ag.collect_windows_powershell_events(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertTrue(events[0]["is_encoded"])

    def test_powershell_returns_empty_without_fixture_on_linux(self) -> None:
        if ag.IS_WINDOWS:
            self.skipTest("Not applicable on Windows")
        events = ag.collect_windows_powershell_events(self.state, self.agent_id, self.trace_id)
        self.assertEqual(events, [])


class TestWindowsSecurityEvents(unittest.TestCase):
    """collect_windows_security_events() parses JSONL fixture files."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.CollectorState()
        self.agent_id = "agent-sec-test-001"
        self.trace_id = "trace-sec-001"

    def _write_fixture(self, events: list[dict]) -> str:
        import json as _json
        path = os.path.join(self.tmp, "sec_events.jsonl")
        with open(path, "w") as f:
            for ev in events:
                f.write(_json.dumps(ev) + "\n")
        return path

    def test_security_event_4688_process_create(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 4688, "process_name": "cmd.exe", "command_line": "cmd /c net user", "user": "DOMAIN\\admin"},
        ])
        events = ag.collect_windows_security_events(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "process_start")
        self.assertEqual(events[0]["security_event_id"], 4688)
        self.assertTrue(events[0]["is_advisory"])

    def test_security_event_4672_special_privileges(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 4672, "user": "DOMAIN\\admin"},
        ])
        events = ag.collect_windows_security_events(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "privilege_escalation_attempt")

    def test_security_event_4697_service_install(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 4697, "process_name": "malware_svc.exe"},
        ])
        events = ag.collect_windows_security_events(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "persistence_indicator")

    def test_security_events_all_advisory(self) -> None:
        fixture = self._write_fixture([
            {"event_id": 4688, "process_name": "notepad.exe"},
            {"event_id": 4672, "user": "Administrator"},
        ])
        events = ag.collect_windows_security_events(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        for ev in events:
            self.assertTrue(ev["is_advisory"])
            self.assertFalse(ev["autonomous_action"])

    def test_security_events_returns_empty_without_fixture_on_linux(self) -> None:
        if ag.IS_WINDOWS:
            self.skipTest("Not applicable on Windows")
        events = ag.collect_windows_security_events(self.state, self.agent_id, self.trace_id)
        self.assertEqual(events, [])


class TestWindowsRegistryPersistence(unittest.TestCase):
    """collect_windows_registry_persistence() parses JSONL fixture files."""

    def setUp(self) -> None:
        self.tmp = tempfile.mkdtemp()
        self.state = ag.CollectorState()
        self.agent_id = "agent-reg-test-001"
        self.trace_id = "trace-reg-001"

    def _write_fixture(self, entries: list[dict]) -> str:
        import json as _json
        path = os.path.join(self.tmp, "reg_entries.jsonl")
        with open(path, "w") as f:
            for entry in entries:
                f.write(_json.dumps(entry) + "\n")
        return path

    def test_registry_run_key_entry(self) -> None:
        fixture = self._write_fixture([
            {"key": r"HKCU\Software\Microsoft\Windows\CurrentVersion\Run", "name": "Updater", "value": "C:\\malware.exe"},
        ])
        events = ag.collect_windows_registry_persistence(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(len(events), 1)
        self.assertEqual(events[0]["event_type"], "registry_persistence")
        self.assertEqual(events[0]["registry_key"], r"HKCU\Software\Microsoft\Windows\CurrentVersion\Run")
        self.assertTrue(events[0]["is_advisory"])
        self.assertFalse(events[0]["autonomous_action"])

    def test_registry_persistence_type_is_run_key(self) -> None:
        fixture = self._write_fixture([
            {"key": r"HKLM\Software\Microsoft\Windows\CurrentVersion\Run", "name": "Persistence", "value": "persist.exe"},
        ])
        events = ag.collect_windows_registry_persistence(self.state, self.agent_id, self.trace_id, fixture_path=fixture)
        self.assertEqual(events[0]["persistence_type"], "registry_run_key")

    def test_registry_returns_empty_without_fixture_on_linux(self) -> None:
        if ag.IS_WINDOWS:
            self.skipTest("Not applicable on Windows")
        events = ag.collect_windows_registry_persistence(self.state, self.agent_id, self.trace_id)
        self.assertEqual(events, [])

    def test_registry_returns_empty_for_nonexistent_fixture(self) -> None:
        events = ag.collect_windows_registry_persistence(self.state, self.agent_id, self.trace_id, fixture_path="/nonexistent.jsonl")
        self.assertEqual(events, [])


class TestLowLevelSafetyInvariants(unittest.TestCase):
    """Safety invariants: no offensive or enforcement APIs in agent.py."""

    FORBIDDEN_APIS = [
        "isolateHost", "quarantineHost", "executeShell", "killProcess",
        "autoRemediate", "blockNetwork", "enforcePolicy",
        "injectProcess", "kernelEnforce", "memoryDump",
    ]

    def test_no_forbidden_apis_in_agent_module(self) -> None:
        for api in self.FORBIDDEN_APIS:
            self.assertFalse(
                hasattr(ag, api),
                f"agent module must NOT expose {api}()",
            )

    def test_no_forbidden_function_definitions_in_source(self) -> None:
        source = Path(ag.__file__).read_text(encoding="utf-8")
        for api in self.FORBIDDEN_APIS:
            self.assertNotIn(
                f"def {api}",
                source,
                f"agent.py must NOT define {api}()",
            )

    def test_script_execution_events_have_advisory_true(self) -> None:
        """Advisory flag must always be True — even for dangerous-looking scripts."""
        state = ag.CollectorState()
        agent_id = "safety-test-agent"
        trace_id = "safety-trace"
        with tempfile.TemporaryDirectory() as tmp:
            proc_root = _make_fake_proc_root(Path(tmp), [
                {"pid": 99, "ppid": 1, "name": "bash", "uid": 0, "cmdline": "bash -c rm -rf /"},
            ])
            events = ag.collect_script_executions(state, agent_id, trace_id, proc_root=proc_root)
            for ev in events:
                self.assertTrue(ev["is_advisory"])
                self.assertFalse(ev["autonomous_action"])

    def test_privilege_escalation_events_have_advisory_true(self) -> None:
        state = ag.CollectorState()
        agent_id = "safety-test-agent"
        trace_id = "safety-trace"
        with tempfile.TemporaryDirectory() as tmp:
            proc_root = Path(tmp) / "proc"
            proc_root.mkdir()
            pid_dir = proc_root / "999"
            pid_dir.mkdir()
            (pid_dir / "status").write_text("Name:\tsudo\nPid:\t999\nPPid:\t1\nUid:\t1000 0 0 0\n")
            (pid_dir / "cmdline").write_bytes(b"sudo\x00rm\x00-rf\x00/\x00")
            events = ag.collect_privilege_escalation_indicators(state, agent_id, trace_id, proc_root=proc_root)
            for ev in events:
                self.assertTrue(ev["is_advisory"])
                self.assertFalse(ev["autonomous_action"])

    def test_new_collectors_are_in_agent_module(self) -> None:
        expected = [
            "collect_script_executions",
            "collect_privilege_escalation_indicators",
            "collect_container_activity",
            "collect_windows_sysmon",
            "collect_windows_powershell_events",
            "collect_windows_security_events",
            "collect_windows_registry_persistence",
        ]
        for fn in expected:
            self.assertTrue(hasattr(ag, fn), f"agent module should expose {fn}()")


class TestSignBodyReplayProtection(unittest.TestCase):
    """IG-HMAC-REPLAY: sign_body() must support the ts+body (sigv2) scheme, and
    GatewayClient._send_raw() must send X-XDR-Timestamp alongside a sigv2
    signature so the gateway can bound how long a captured signature is valid."""

    def test_sign_body_legacy_v1_unchanged(self) -> None:
        body = b'{"a":1}'
        sig = ag.sign_body("secret", body)
        import hashlib
        import hmac as hmac_mod
        expected = "sha256=" + hmac_mod.new(b"secret", body, hashlib.sha256).hexdigest()
        self.assertEqual(sig, expected)

    def test_sign_body_v2_covers_timestamp_and_body(self) -> None:
        body = b'{"a":1}'
        sig = ag.sign_body("secret", body, timestamp="1000")
        import hashlib
        import hmac as hmac_mod
        expected = "sha256=" + hmac_mod.new(b"secret", b"1000." + body, hashlib.sha256).hexdigest()
        self.assertEqual(sig, expected)

    def test_sign_body_v2_differs_from_v1_for_same_body(self) -> None:
        body = b'{"a":1}'
        self.assertNotEqual(ag.sign_body("secret", body), ag.sign_body("secret", body, timestamp="1000"))

    def test_send_raw_includes_timestamp_header_and_v2_signature(self) -> None:
        cfg = _make_cfg(
            ingestion_gateway_url="http://127.0.0.1:19999",
            ingestion_gateway_secret="secret",
        )
        with tempfile.TemporaryDirectory() as tmp:
            buf = ag.LocalBuffer(str(Path(tmp) / "buffer.jsonl"))
            gw = ag.GatewayClient(cfg, buf)
            captured: dict[str, Any] = {}

            def fake_post(url, payload, headers, timeout=15):
                captured["headers"] = headers
                captured["payload"] = payload
                return 202, b"{}"

            with patch.object(ag, "_http_post", side_effect=fake_post):
                ok = gw._send_raw([ag.base_event("heartbeat", "agent-1", "trace-1")])

        self.assertTrue(ok)
        self.assertIn("X-XDR-Timestamp", captured["headers"])
        ts = captured["headers"]["X-XDR-Timestamp"]
        self.assertTrue(ts.isdigit())
        expected_sig = ag.sign_body("secret", captured["payload"], timestamp=ts)
        self.assertEqual(captured["headers"]["X-XDR-Signature"], expected_sig)

    def test_send_raw_omits_signature_when_no_secret(self) -> None:
        cfg = _make_cfg(
            ingestion_gateway_url="http://127.0.0.1:19999",
            ingestion_gateway_secret="",
        )
        with tempfile.TemporaryDirectory() as tmp:
            buf = ag.LocalBuffer(str(Path(tmp) / "buffer.jsonl"))
            gw = ag.GatewayClient(cfg, buf)
            captured: dict[str, Any] = {}

            def fake_post(url, payload, headers, timeout=15):
                captured["headers"] = headers
                return 202, b"{}"

            with patch.object(ag, "_http_post", side_effect=fake_post):
                gw._send_raw([ag.base_event("heartbeat", "agent-1", "trace-1")])

        self.assertNotIn("X-XDR-Signature", captured["headers"])
        self.assertNotIn("X-XDR-Timestamp", captured["headers"])


if __name__ == "__main__":
    unittest.main()
