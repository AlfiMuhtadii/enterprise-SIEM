"""W3C Trace Context (level 1) generation, parsing, and hop-to-hop propagation.

Mirrors services/*/internal/traceparent (Go) byte-for-byte in algorithm so
trace continuity survives the ingestion-gateway -> normalizer-worker ->
correlation-worker -> alert-writer-service hop, without any OTel SDK
dependency. Additive to the platform's existing free-form trace_id lineage
field (used across ~90 services/tables for analyst-facing correlation) --
traceparent is a separate, strictly-formatted sibling field.
"""
from __future__ import annotations

import re
import secrets
from typing import NamedTuple, Optional

_PATTERN = re.compile(r"^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$")
_ZERO_TRACE_ID = "0" * 32
_ZERO_SPAN_ID = "0" * 16


class Traceparent(NamedTuple):
    trace_id: str
    span_id: str
    flags: str


def generate() -> str:
    """Return a new root W3C traceparent: version 00, fresh trace-id/span-id, sampled."""
    return f"00-{secrets.token_hex(16)}-{secrets.token_hex(8)}-01"


def parse(value: Optional[str]) -> Optional[Traceparent]:
    """Validate and decompose a traceparent string, or None if invalid/absent."""
    if not value:
        return None
    m = _PATTERN.match(value)
    if not m:
        return None
    trace_id, span_id, flags = m.group(1), m.group(2), m.group(3)
    if trace_id == _ZERO_TRACE_ID or span_id == _ZERO_SPAN_ID:
        return None
    return Traceparent(trace_id, span_id, flags)


def new_child_span(tp: Traceparent) -> str:
    """Return a traceparent carrying the same trace-id but a fresh span-id."""
    return f"00-{tp.trace_id}-{secrets.token_hex(8)}-{tp.flags}"


def propagate(inbound: Optional[str]) -> str:
    """Parse inbound (if any) and return a child-span traceparent for this hop.

    An empty or invalid inbound value never blocks propagation -- a fresh
    root traceparent is generated instead.
    """
    parsed = parse(inbound)
    if parsed:
        return new_child_span(parsed)
    return generate()
