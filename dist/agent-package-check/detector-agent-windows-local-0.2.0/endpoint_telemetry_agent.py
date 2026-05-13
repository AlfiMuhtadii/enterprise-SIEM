#!/usr/bin/env python3
"""
Lightweight defensive endpoint telemetry agent.

Collects local process and network snapshots and writes normalized telemetry
JSONL compatible with telemetry_events ingestion. It is intentionally simple:
no kernel hooks, no exploit logic, no remote control.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import hmac
import json
import os
import platform
import socket
import subprocess
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Dict, Iterable, List


AGENT_VERSION = "0.2.0"


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat().replace("+00:00", "Z")


def stable_id(kind: str, payload: Dict[str, Any]) -> str:
    raw = json.dumps(payload, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(f"{kind}|{raw}".encode("utf-8")).hexdigest()[:40]


def host_fingerprint(host_id: str) -> str:
    material = "|".join([
        host_id,
        platform.system(),
        platform.release(),
        platform.machine(),
        str(uuid_node()),
    ])
    return hashlib.sha256(material.encode("utf-8")).hexdigest()


def uuid_node() -> str:
    try:
        import uuid

        return str(uuid.getnode())
    except Exception:
        return "unknown-node"


def run_cmd(cmd: List[str]) -> str:
    try:
        return subprocess.run(cmd, text=True, capture_output=True, timeout=8).stdout
    except Exception:
        return ""


def proc_events(host_id: str) -> Iterable[Dict[str, Any]]:
    ts = now_iso()
    if platform.system().lower().startswith("win"):
        output = run_cmd(["tasklist", "/fo", "csv", "/nh"])
        for parts in list(csv.reader(output.splitlines()))[:300]:
            if len(parts) < 2:
                continue
            payload = {"image": parts[0], "pid": parts[1], "source": "tasklist"}
            ev = {
                "schema_version": 1,
                "ts": ts,
                "telemetry_type": "endpoint",
                "event_type": "process_observed",
                "host_id": host_id,
                "process_name": parts[0],
                "raw": payload,
            }
            ev["event_id"] = stable_id("process", ev)
            yield ev
        return

    output = run_cmd(["ps", "-eo", "pid,ppid,comm,args"])
    for line in output.splitlines()[1:301]:
        parts = line.split(None, 3)
        if len(parts) < 3:
            continue
        payload = {"pid": parts[0], "ppid": parts[1], "comm": parts[2], "args": parts[3] if len(parts) > 3 else ""}
        ev = {
            "schema_version": 1,
            "ts": ts,
            "telemetry_type": "endpoint",
            "event_type": "process_observed",
            "host_id": host_id,
            "process_name": parts[2],
            "raw": payload,
        }
        ev["event_id"] = stable_id("process", ev)
        yield ev


def network_events(host_id: str) -> Iterable[Dict[str, Any]]:
    ts = now_iso()
    output = run_cmd(["netstat", "-ano"]) if platform.system().lower().startswith("win") else run_cmd(["netstat", "-tunp"])
    for line in output.splitlines():
        text = line.strip()
        if not text.lower().startswith(("tcp", "udp")):
            continue
        parts = text.split()
        if len(parts) < 4:
            continue
        proto = parts[0].lower()
        local = parts[1]
        remote = parts[2] if platform.system().lower().startswith("win") else parts[4] if len(parts) > 4 else ""
        if remote in {"*", "*:*", "0.0.0.0:*", "[::]:*"}:
            continue
        dst_ip, dst_port = parse_endpoint(remote)
        src_ip, _src_port = parse_endpoint(local)
        if not dst_ip or not dst_port:
            continue
        ev = {
            "schema_version": 1,
            "ts": ts,
            "telemetry_type": "network",
            "event_type": "connection_observed",
            "host_id": host_id,
            "src_ip": src_ip or "127.0.0.1",
            "dst_ip": dst_ip,
            "dst_port": dst_port,
            "protocol": proto,
            "raw": {"line": text, "source": "netstat"},
        }
        ev["event_id"] = stable_id("network", ev)
        yield ev


def parse_endpoint(value: str) -> tuple[str, int | None]:
    value = value.strip().strip("[]")
    if ":" not in value:
        return value, None
    host, port_text = value.rsplit(":", 1)
    try:
        return host.strip("[]"), int(port_text)
    except ValueError:
        return host.strip("[]"), None


def write_events(events: Iterable[Dict[str, Any]], output: Path) -> int:
    output.parent.mkdir(parents=True, exist_ok=True)
    count = 0
    with output.open("a", encoding="utf-8") as handle:
        for event in events:
            handle.write(json.dumps(event, separators=(",", ":"), ensure_ascii=False) + "\n")
            count += 1
    return count


def read_json(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, dict) else {}
    except json.JSONDecodeError:
        return {}


def write_json(path: Path, data: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(data, indent=2), encoding="utf-8")


def signed_post(base_url: str, path: str, agent_id: str, secret: str, payload: Dict[str, Any], timeout: int = 10) -> Dict[str, Any]:
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    ts = str(int(time.time()))
    sig = hmac.new(secret.encode("utf-8"), ts.encode("utf-8") + b"." + body, hashlib.sha256).hexdigest()
    req = urllib.request.Request(
        base_url.rstrip("/") + path,
        data=body,
        headers={
            "Content-Type": "application/json",
            "X-Agent-Id": agent_id,
            "X-Agent-Timestamp": ts,
            "X-Agent-Signature": sig,
        },
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def enroll(base_url: str, token: str, state_path: Path, host_id: str) -> Dict[str, Any]:
    payload = {
        "host_fingerprint": host_fingerprint(host_id),
        "host_id": host_id,
        "agent_version": AGENT_VERSION,
        "os_family": platform.system().lower(),
        "metadata": {
            "platform": platform.platform(),
            "machine": platform.machine(),
            "collector": "endpoint_telemetry_agent",
        },
    }
    body = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")
    req = urllib.request.Request(
        base_url.rstrip("/") + "/api/agents/register",
        data=body,
        headers={"Content-Type": "application/json", "X-Agent-Enrollment-Token": token},
        method="POST",
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        data = json.loads(resp.read().decode("utf-8"))
    if not data.get("ok"):
        raise RuntimeError(f"enrollment failed: {data}")
    state = {
        "agent_id": data["agent_id"],
        "agent_secret": data["agent_secret"],
        "host_id": host_id,
        "host_fingerprint": payload["host_fingerprint"],
        "agent_version": AGENT_VERSION,
        "enrolled_at": now_iso(),
        "event_count_total": 0,
        "error_count_total": 0,
    }
    write_json(state_path, state)
    return state


def collect_batch(host_id: str, include_process: bool = True, include_network: bool = True) -> List[Dict[str, Any]]:
    batch: List[Dict[str, Any]] = []
    if include_process:
        batch.extend(proc_events(host_id))
    if include_network:
        batch.extend(network_events(host_id))
    return batch


def file_change_events(host_id: str, watch_paths: List[str], seen: Dict[str, float]) -> List[Dict[str, Any]]:
    events: List[Dict[str, Any]] = []
    ts = now_iso()
    for raw in watch_paths:
        root = Path(raw)
        if not root.exists():
            continue
        paths = [root] if root.is_file() else list(root.rglob("*"))[:1000]
        for path in paths:
            if not path.is_file():
                continue
            try:
                mtime = path.stat().st_mtime
            except OSError:
                continue
            key = str(path.resolve())
            if key not in seen:
                seen[key] = mtime
                continue
            if mtime > seen[key]:
                seen[key] = mtime
                ev = {
                    "schema_version": 1,
                    "ts": ts,
                    "telemetry_type": "endpoint",
                    "event_type": "file_changed",
                    "host_id": host_id,
                    "process_name": "file-watcher",
                    "raw": {"path": key, "mtime": mtime},
                }
                ev["event_id"] = stable_id("file", ev)
                events.append(ev)
    return events


def tail_events(host_id: str, tail_files: List[str], offsets: Dict[str, int]) -> List[Dict[str, Any]]:
    events: List[Dict[str, Any]] = []
    for raw in tail_files:
        path = Path(raw)
        if not path.exists() or not path.is_file():
            continue
        offset = int(offsets.get(str(path), 0))
        try:
            size = path.stat().st_size
            if offset > size:
                offset = 0
            with path.open("rb") as handle:
                handle.seek(offset)
                for line in handle.readlines()[:500]:
                    text = line.decode("utf-8", errors="replace").strip()
                    if not text:
                        continue
                    event_type = "log_line"
                    lower = text.lower()
                    if "failed password" in lower or "res=failed" in lower:
                        event_type = "login_failed"
                    elif "accepted password" in lower:
                        event_type = "login_success"
                    ev = {
                        "schema_version": 1,
                        "ts": now_iso(),
                        "telemetry_type": "endpoint",
                        "event_type": event_type,
                        "host_id": host_id,
                        "process_name": "log-tail",
                        "raw": {"path": str(path), "line": text},
                    }
                    ev["event_id"] = stable_id("tail", ev)
                    events.append(ev)
                offsets[str(path)] = handle.tell()
        except OSError:
            continue
    return events


def delta_events(current: List[Dict[str, Any]], seen: set[str]) -> List[Dict[str, Any]]:
    out = []
    for event in current:
        key = str(event.get("event_id"))
        if key in seen:
            continue
        seen.add(key)
        event["event_type"] = "process_created" if event.get("event_type") == "process_observed" else "connection_delta" if event.get("event_type") == "connection_observed" else event.get("event_type")
        event["event_id"] = stable_id("delta", event)
        out.append(event)
    return out


def append_buffer(path: Path, events: List[Dict[str, Any]]) -> None:
    if not events:
        return
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("a", encoding="utf-8") as handle:
        for event in events:
            handle.write(json.dumps(event, separators=(",", ":"), ensure_ascii=False) + "\n")


def read_buffer(path: Path, max_events: int) -> List[Dict[str, Any]]:
    if not path.exists():
        return []
    events: List[Dict[str, Any]] = []
    with path.open("r", encoding="utf-8", errors="replace") as handle:
        for line in handle:
            if len(events) >= max_events:
                break
            if not line.strip():
                continue
            try:
                data = json.loads(line)
                if isinstance(data, dict):
                    events.append(data)
            except json.JSONDecodeError:
                continue
    return events


def rewrite_buffer_without(path: Path, sent_count: int) -> None:
    if not path.exists() or sent_count <= 0:
        return
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    remaining = lines[sent_count:]
    if remaining:
        path.write_text("\n".join(remaining) + "\n", encoding="utf-8")
    else:
        path.unlink()


def ship_with_retry(base_url: str, state: Dict[str, Any], buffer_path: Path, batch_events: List[Dict[str, Any]], max_batch: int) -> tuple[int, str | None]:
    append_buffer(buffer_path, batch_events)
    pending = read_buffer(buffer_path, max_batch)
    if not pending:
        return 0, None
    try:
        result = signed_post(
            base_url,
            "/api/agents/telemetry",
            state["agent_id"],
            state["agent_secret"],
            {"events": pending},
        )
        if not result.get("ok"):
            return 0, f"server rejected telemetry: {result}"
        rewrite_buffer_without(buffer_path, len(pending))
        return len(pending), None
    except Exception as exc:
        return 0, str(exc)


def heartbeat(base_url: str, state: Dict[str, Any], last_batch_count: int, last_error: str | None) -> None:
    payload = {
        "event_count_total": int(state.get("event_count_total", 0)),
        "error_count_total": int(state.get("error_count_total", 0)),
        "last_batch_event_count": last_batch_count,
        "retry_queue_depth": int(state.get("retry_queue_depth", 0)),
        "stream_status": state.get("stream_status"),
        "events_streamed_total": int(state.get("events_streamed_total", 0)),
        "events_dropped_total": int(state.get("events_dropped_total", 0)),
        "stream_retry_total": int(state.get("stream_retry_total", 0)),
        "avg_event_latency_ms": float(state.get("avg_event_latency_ms", 0) or 0),
        "policy_version_seen": int(state.get("policy_version_seen", 0)),
        "config_hash": state.get("config_hash"),
        "last_error": last_error,
        "agent_version": AGENT_VERSION,
        "upgrade_status": state.get("upgrade_status", "current"),
        "metadata": {
            "platform": platform.platform(),
            "buffered": True,
        },
    }
    signed_post(base_url, "/api/agents/heartbeat", state["agent_id"], state["agent_secret"], payload)


def policy_hash(policy: Dict[str, Any]) -> str:
    return hashlib.sha256(json.dumps(policy, sort_keys=True, separators=(",", ":")).encode("utf-8")).hexdigest()


def pull_config(base_url: str, state: Dict[str, Any]) -> Dict[str, Any]:
    result = signed_post(base_url, "/api/agents/config", state["agent_id"], state["agent_secret"], {})
    policy = result.get("policy") if isinstance(result.get("policy"), dict) else {}
    if policy:
        state["policy_id"] = policy.get("policy_id")
        state["policy_version_seen"] = int(policy.get("version") or 0)
        state["config_hash"] = policy_hash(policy)
    latest = str(result.get("latest_version") or AGENT_VERSION)
    state["upgrade_status"] = "upgrade_available" if latest != AGENT_VERSION else "current"
    return result


def execute_command(command: Dict[str, Any], args: argparse.Namespace, state: Dict[str, Any], buffer_path: Path) -> tuple[str, Dict[str, Any]]:
    command_type = str(command.get("command_type") or "")
    payload = command.get("payload") if isinstance(command.get("payload"), dict) else {}
    if command_type == "collect-now":
        batch = collect_batch(args.host_id, not args.no_process, not args.no_network)
        append_buffer(buffer_path, batch)
        return "succeeded", {"collected": len(batch)}
    if command_type == "flush-local-queue":
        pending = len(read_buffer(buffer_path, args.max_batch))
        return "succeeded", {"pending_before_flush": pending, "flush_requested": True}
    if command_type == "refresh-policy":
        return "succeeded", {"policy_version_seen": state.get("policy_version_seen"), "config_hash": state.get("config_hash")}
    if command_type == "restart-agent-loop":
        return "succeeded", {"restart_requested": True}
    if command_type == "rotate-agent-secret":
        new_secret = payload.get("new_agent_secret")
        if not isinstance(new_secret, str) or not new_secret:
            return "failed", {"error": "new_agent_secret missing"}
        return "succeeded", {"secret_rotation_pending": True, "new_agent_secret": new_secret}
    return "unsupported", {"error": f"unsupported command: {command_type}"}


def report_command_result(base_url: str, state: Dict[str, Any], command: Dict[str, Any], status: str, result: Dict[str, Any]) -> None:
    signed_post(
        base_url,
        "/api/agents/commands/result",
        state["agent_id"],
        state["agent_secret"],
        {"command_id": command["command_id"], "status": status, "result": {k: v for k, v in result.items() if k != "new_agent_secret"}},
    )
    if command.get("command_type") == "rotate-agent-secret" and status == "succeeded" and result.get("new_agent_secret"):
        state["agent_secret"] = str(result["new_agent_secret"])


def run_daemon(args: argparse.Namespace) -> int:
    state_path = Path(args.state_file)
    state = read_json(state_path)
    if not state.get("agent_id") or not state.get("agent_secret"):
        if not args.enrollment_token:
            print("ERROR: agent not enrolled. Provide --enrollment-token once.")
            return 1
        state = enroll(args.server_url, args.enrollment_token, state_path, args.host_id)
        print(f"enrolled_agent_id={state['agent_id']}")

    buffer_path = Path(args.buffer_file)
    backoff = 1
    iteration = 0
    seen_process: set[str] = set()
    seen_network: set[str] = set()
    seen_files: Dict[str, float] = {}
    tail_offsets: Dict[str, int] = {}
    stream_total = dropped_total = retry_total = 0
    while True:
        iteration += 1
        config_error = None
        policy = {}
        commands = []
        try:
            config = pull_config(args.server_url, state)
            policy = config.get("policy") if isinstance(config.get("policy"), dict) else {}
            commands = config.get("commands") if isinstance(config.get("commands"), list) else []
            args.interval = int(policy.get("collection_interval_seconds") or args.interval)
            args.max_batch = int(policy.get("max_batch_size") or args.max_batch)
            collectors = policy.get("enabled_collectors") if isinstance(policy.get("enabled_collectors"), dict) else {}
            collect_process = bool(collectors.get("process", not args.no_process))
            collect_network = bool(collectors.get("network", not args.no_network))
        except Exception as exc:
            config_error = str(exc)
            collect_process = not args.no_process
            collect_network = not args.no_network

        for command in commands:
            try:
                status, result = execute_command(command, args, state, buffer_path)
                report_command_result(args.server_url, state, command, status, result)
                if command.get("command_type") == "restart-agent-loop" and status == "succeeded":
                    write_json(state_path, state)
                    return 0
            except Exception as exc:
                state["error_count_total"] = int(state.get("error_count_total", 0)) + 1
                try:
                    report_command_result(args.server_url, state, command, "failed", {"error": str(exc)})
                except Exception:
                    pass

        start_collect = time.time()
        if args.stream:
            batch = []
            if collect_process:
                batch.extend(delta_events(list(proc_events(args.host_id)), seen_process))
            if collect_network:
                batch.extend(delta_events(list(network_events(args.host_id)), seen_network))
            if args.watch_path:
                batch.extend(file_change_events(args.host_id, args.watch_path, seen_files))
            if args.tail_file:
                batch.extend(tail_events(args.host_id, args.tail_file, tail_offsets))
        else:
            batch = collect_batch(args.host_id, collect_process, collect_network)
        avg_latency_ms = (time.time() - start_collect) * 1000
        sent, error = ship_with_retry(args.server_url, state, buffer_path, batch, args.max_batch)
        state["retry_queue_depth"] = len(read_buffer(buffer_path, args.max_batch))
        stream_total += sent
        if config_error and not error:
            error = config_error
        if error:
            retry_total += 1
            state["error_count_total"] = int(state.get("error_count_total", 0)) + 1
            print(f"ship_error={error}; backoff={backoff}")
            time.sleep(min(args.max_backoff, backoff))
            backoff = min(args.max_backoff, backoff * 2)
        else:
            state["event_count_total"] = int(state.get("event_count_total", 0)) + sent
            backoff = 1
        try:
            state["stream_status"] = "degraded" if error else "healthy"
            state["events_streamed_total"] = stream_total
            state["events_dropped_total"] = dropped_total
            state["stream_retry_total"] = retry_total
            state["avg_event_latency_ms"] = avg_latency_ms
            heartbeat(args.server_url, state, sent, error)
        except Exception as exc:
            state["error_count_total"] = int(state.get("error_count_total", 0)) + 1
            print(f"heartbeat_error={exc}")
        write_json(state_path, state)
        print(f"iteration={iteration} collected={len(batch)} sent={sent} buffered={len(read_buffer(buffer_path, args.max_batch))}")
        if not args.daemon or (args.iterations > 0 and iteration >= args.iterations):
            break
        time.sleep(max(1, args.interval))
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Collect lightweight endpoint telemetry")
    parser.add_argument("--output", default="storage/logs/endpoint_agent.jsonl")
    parser.add_argument("--host-id", default=socket.gethostname())
    parser.add_argument("--interval", type=int, default=0, help="Seconds between snapshots; 0 means one-shot")
    parser.add_argument("--iterations", type=int, default=1)
    parser.add_argument("--no-process", action="store_true")
    parser.add_argument("--no-network", action="store_true")
    parser.add_argument("--daemon", action="store_true", help="Run persistent collection and ship loop")
    parser.add_argument("--server-url", default=os.getenv("DETECTOR_SERVER_URL", "http://127.0.0.1:8000"))
    parser.add_argument("--enrollment-token", default=os.getenv("SOC_AGENT_ENROLLMENT_TOKEN", ""))
    parser.add_argument("--state-file", default="storage/app/endpoint_agent_state.json")
    parser.add_argument("--buffer-file", default="storage/app/endpoint_agent_retry_queue.jsonl")
    parser.add_argument("--max-batch", type=int, default=500)
    parser.add_argument("--max-backoff", type=int, default=300)
    parser.add_argument("--stream", action="store_true", help="Collect process/network deltas and tailed file changes")
    parser.add_argument("--watch-path", action="append", default=[], help="Path to watch for file change events")
    parser.add_argument("--tail-file", action="append", default=[], help="Log file to tail incrementally")
    args = parser.parse_args()

    if args.daemon:
        if args.interval <= 0:
            args.interval = 60
        if args.iterations == 1:
            args.iterations = 0
        return run_daemon(args)

    out = Path(args.output)
    iterations = max(1, args.iterations)
    total = 0
    for idx in range(iterations):
        batch: List[Dict[str, Any]] = []
        if not args.no_process:
            batch.extend(proc_events(args.host_id))
        if not args.no_network:
            batch.extend(network_events(args.host_id))
        total += write_events(batch, out)
        if args.interval > 0 and idx < iterations - 1:
            time.sleep(args.interval)
    print(f"host_id={args.host_id}")
    print(f"events_written={total}")
    print(f"output={out}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
