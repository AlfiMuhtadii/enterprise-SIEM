"""OTLP/HTTP+JSON span exporter — dependency-free (stdlib + the service's
existing `requests` dependency only, no OTel SDK), matching
services/*/internal/otlpexport (Go, OBS-OTEL-TRACING phase 4) in wire format
and behavior byte-for-byte:

- trace_id/span_id/parent_span_id are lowercase hex strings, per the OTLP
  spec's documented JSON-encoding special case (NOT the default base64
  protobuf-JSON mapping used for other bytes fields) — this is exactly the
  format traceparent.py already produces, so no re-encoding is needed.
- start/end times are nanoseconds-since-epoch, represented as decimal
  strings (per protobuf JSON mapping rules for 64-bit integers).
- Endpoint == "" (default) makes export() a no-op — zero behavior/latency
  change until an operator sets XDR_OTEL_EXPORTER_ENDPOINT.
"""
from __future__ import annotations

import json
import threading
from dataclasses import dataclass, field
from typing import Any, Dict, List, Optional

SPAN_KIND_INTERNAL = 1
SPAN_KIND_SERVER = 2
SPAN_KIND_CLIENT = 3
SPAN_KIND_PRODUCER = 4
SPAN_KIND_CONSUMER = 5


@dataclass
class Span:
    trace_id: str
    span_id: str
    name: str
    start_unix_nano: int
    end_unix_nano: int
    kind: int = SPAN_KIND_INTERNAL
    parent_span_id: str = ""
    attributes: Dict[str, str] = field(default_factory=dict)


def build_request_body(service_name: str, spans: List[Span]) -> bytes:
    """Pure function producing the OTLP/HTTP+JSON ExportTraceServiceRequest
    body — split out from export() so the wire format can be unit tested
    directly without a real HTTP round trip."""
    otlp_spans = []
    for s in spans:
        span: Dict[str, Any] = {
            "traceId": s.trace_id,
            "spanId": s.span_id,
            "name": s.name,
            "kind": s.kind,
            "startTimeUnixNano": str(s.start_unix_nano),
            "endTimeUnixNano": str(s.end_unix_nano),
        }
        if s.parent_span_id:
            span["parentSpanId"] = s.parent_span_id
        if s.attributes:
            span["attributes"] = [
                {"key": k, "value": {"stringValue": v}} for k, v in s.attributes.items()
            ]
        otlp_spans.append(span)

    body = {
        "resourceSpans": [
            {
                "resource": {
                    "attributes": [
                        {"key": "service.name", "value": {"stringValue": service_name}}
                    ]
                },
                "scopeSpans": [
                    {"scope": {"name": "detector-xdr"}, "spans": otlp_spans}
                ],
            }
        ]
    }
    return json.dumps(body, separators=(",", ":")).encode("utf-8")


def export(session: Any, endpoint: str, service_name: str, spans: List[Span], timeout: float = 5.0) -> None:
    """Send spans as a single OTLP ExportTraceServiceRequest via the given
    requests.Session. Raises on failure — callers on a hot path should call
    export_async() instead, which swallows errors after logging."""
    if not endpoint or not spans:
        return
    body = build_request_body(service_name, spans)
    resp = session.post(endpoint, data=body, headers={"Content-Type": "application/json"}, timeout=timeout)
    if resp.status_code < 200 or resp.status_code >= 300:
        raise RuntimeError(f"otlp_export_status={resp.status_code}")


def export_async(session: Any, endpoint: str, service_name: str, spans: List[Span], logger: Optional[Any] = None) -> None:
    """Fire-and-forget export in a background thread — an unreachable/slow
    collector must never add latency to the actual telemetry write path.
    Mirrors the identical `go func() { _ = exporter.Export(spans) }()`
    pattern used in the Go services (phase 4)."""
    if not endpoint or not spans:
        return

    def _run() -> None:
        try:
            export(session, endpoint, service_name, spans, timeout=5.0)
        except Exception as exc:  # best-effort: never propagate to the caller
            if logger is not None:
                logger.warning(f"[otlp-export] span export failed (non-fatal): {exc}")

    threading.Thread(target=_run, daemon=True).start()
