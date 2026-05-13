#!/usr/bin/env python3
"""Standalone AI/RAG service boundary for SOC analysis workflows."""

from __future__ import annotations

import hashlib
import time
from typing import Any, Dict, List

from xdr_event_contracts import envelope

try:
    from fastapi import FastAPI
    from pydantic import BaseModel
except Exception:  # Allows syntax validation without installed FastAPI.
    FastAPI = None  # type: ignore
    BaseModel = object  # type: ignore


class AnalysisRequest(BaseModel):
    incident_id: str
    evidence: List[Dict[str, Any]] = []
    question: str = "Summarize defensive investigation context."


class RetrievalRequest(BaseModel):
    query: str
    limit: int = 5
    context: Dict[str, Any] = {}


class EmbeddingRequest(BaseModel):
    documents: List[Dict[str, Any]]


def pseudo_embedding(text: str, size: int = 64) -> List[float]:
    digest = hashlib.sha256(text.encode()).digest()
    return [round((digest[idx % len(digest)] / 127.5) - 1.0, 4) for idx in range(size)]


def heuristic_summary(request: AnalysisRequest) -> Dict[str, Any]:
    domains = sorted({str(item.get("telemetry_type", "unknown")) for item in request.evidence})
    high_risk = [item for item in request.evidence if float(item.get("risk_score") or 0) >= 0.7]
    return {
        "incident_id": request.incident_id,
        "provider": "heuristic",
        "confidence": "medium" if high_risk else "low",
        "summary": f"Incident contains {len(request.evidence)} evidence items across {', '.join(domains) or 'unknown'} domains.",
        "recommended_steps": [
            "Review linked identity, endpoint, cloud, and proxy evidence.",
            "Confirm whether high-risk events are expected enterprise activity.",
            "Escalate if the same user or host appears in multiple domains.",
        ],
        "citations": [item.get("event_id") for item in request.evidence if item.get("event_id")][:10],
        "safety": {"mode": "defensive_only", "offensive_guidance": False},
    }


if FastAPI is not None:
    app = FastAPI(title="Detector XDR AI/RAG Service", version="0.1.0")
    METRICS = {"analysis_requests": 0, "retrieval_requests": 0, "embedding_requests": 0, "latency_ms_total": 0.0}

    @app.get("/health")
    def health() -> Dict[str, Any]:
        return {"status": "ok", "service": "ai-rag", "provider": "heuristic"}

    @app.get("/metrics")
    def metrics() -> Dict[str, Any]:
        return METRICS

    @app.post("/v1/analyze")
    def analyze(request: AnalysisRequest) -> Dict[str, Any]:
        started = time.perf_counter()
        METRICS["analysis_requests"] += 1
        result = heuristic_summary(request)
        METRICS["latency_ms_total"] += (time.perf_counter() - started) * 1000
        result["event"] = envelope(
            topic="ai.analysis.results",
            payload=dict(result),
            source_service="ai-rag-service",
            trace_id=f"ai-{hashlib.sha256((request.incident_id + request.question).encode()).hexdigest()[:16]}",
            aggregate_type="incident",
            aggregate_id=request.incident_id,
        )
        return result

    @app.post("/v1/retrieve")
    def retrieve(request: RetrievalRequest) -> Dict[str, Any]:
        started = time.perf_counter()
        METRICS["retrieval_requests"] += 1
        METRICS["latency_ms_total"] += (time.perf_counter() - started) * 1000
        return {
            "query": request.query,
            "results": [
                {"citation": "kb:incident-response", "score": 0.82, "title": "Incident response checklist"},
                {"citation": "kb:xdr-correlation", "score": 0.74, "title": "XDR correlation notes"},
            ][: max(0, request.limit)],
        }

    @app.post("/v1/embed")
    def embed(request: EmbeddingRequest) -> Dict[str, Any]:
        METRICS["embedding_requests"] += 1
        return {
            "vectors": [
                {"id": doc.get("id"), "embedding": pseudo_embedding(str(doc.get("text") or doc))}
                for doc in request.documents
            ]
        }


def main() -> int:
    if FastAPI is None:
        print("FastAPI is not installed. Install requirements.txt and run: uvicorn main:app --host 0.0.0.0 --port 8094")
        return 0
    print("Run with: uvicorn main:app --host 0.0.0.0 --port 8094")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
