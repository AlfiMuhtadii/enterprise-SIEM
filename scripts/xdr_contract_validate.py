#!/usr/bin/env python3
from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Any, Dict, List

from xdr_event_contracts import EVENT_TYPES, envelope, validate_envelope


SAMPLES: Dict[str, Dict[str, Any]] = {
    "xdr.alerts": {
        "alert": {
            "alert_type": "IDENTITY_RISKY_LOGIN",
            "severity": "high",
            "detected_at": "2026-05-13T10:00:00Z",
            "actor_key": "user@example.test",
            "score": 0.91,
            "evidence": {"evidence_ids": ["evt-identity-1"]},
            "raw_event": {"event_source": "identity"},
            "detector_name": "xdr-correlation",
            "detector_version": "go-shadow",
        }
    },
    "alerts.created": {
        "alert_id": "xdr-alert-1",
        "alert_fingerprint": "fp-1",
        "alert": {"alert_id": "xdr-alert-1", "alert_type": "IDENTITY_RISKY_LOGIN", "severity": "high"},
        "created_at": "2026-05-13T10:00:01Z",
        "source": "alert-writer-service",
    },
    "incidents.updated": {
        "incident_id": "xdr-inc-1",
        "incident": {"incident_id": "xdr-inc-1", "status": "open", "severity": "high"},
        "updated_at": "2026-05-13T10:00:02Z",
        "source": "incident-builder-service",
    },
    "ai.analysis.requests": {
        "incident_id": "xdr-inc-1",
        "evidence": [{"event_id": "evt-identity-1"}],
        "question": "Summarize defensive investigation context.",
        "requested_by": "analyst@example.test",
    },
    "ai.analysis.results": {
        "incident_id": "xdr-inc-1",
        "provider": "heuristic",
        "model": "heuristic",
        "confidence": "medium",
        "summary": "Incident contains identity evidence.",
        "recommended_steps": ["Review related identity events."],
        "citations": ["evt-identity-1"],
        "safety": {"mode": "defensive_only"},
    },
    "ai.analysis.completed": {
        "suggestion_id": "ai-1",
        "incident_id": "xdr-inc-1",
        "suggestion_type": "summary",
        "provider": "heuristic",
        "model": "heuristic",
        "status": "completed",
        "confidence_label": "medium",
        "latency_ms": 12,
        "retrieval_citations": ["kb:incident-response"],
    },
}


def validate_docs(root: Path) -> List[str]:
    errors: List[str] = []
    contract_dir = root / "docs" / "contracts" / "events"
    required = ["event-envelope.v1.schema.json"] + [f"{topic}.v1.schema.json" for topic in EVENT_TYPES]
    for name in required:
        path = contract_dir / name
        if not path.exists():
            errors.append(f"missing_contract:{name}")
            continue
        try:
            json.loads(path.read_text(encoding="utf-8"))
        except json.JSONDecodeError as exc:
            errors.append(f"invalid_json:{name}:{exc}")
    return errors


def validate_samples() -> List[str]:
    errors: List[str] = []
    for topic, payload in SAMPLES.items():
        event = envelope(topic=topic, payload=payload, source_service="contract-validator", trace_id="trace-contract-smoke")
        event_errors = validate_envelope(event, topic)
        if event_errors:
            errors.append(f"{topic}:{';'.join(event_errors)}")
        if event["event_type"] != EVENT_TYPES[topic]:
            errors.append(f"{topic}:event_type_mismatch")
    return errors


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate XDR event contracts and sample envelopes")
    parser.add_argument("--root", default=".")
    parser.add_argument("--output", default="")
    args = parser.parse_args()

    root = Path(args.root).resolve()
    errors = validate_docs(root) + validate_samples()
    report = {
        "status": "FAIL" if errors else "PASS",
        "schema_version": 1,
        "topics": sorted(EVENT_TYPES.keys()),
        "errors": errors,
    }
    if args.output:
        out = Path(args.output)
        out.parent.mkdir(parents=True, exist_ok=True)
        out.write_text(json.dumps(report, indent=2), encoding="utf-8")
    print(json.dumps(report, indent=2))
    return 1 if errors else 0


if __name__ == "__main__":
    raise SystemExit(main())
