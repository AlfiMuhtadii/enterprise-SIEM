// Package otlpexport implements a minimal, dependency-free OTLP/HTTP+JSON
// trace exporter (OBS-OTEL-TRACING: the "OTLP collector wiring" remaining
// scope after the earlier W3C traceparent propagation phases).
//
// This deliberately does NOT pull in the full OpenTelemetry Go SDK
// (go.opentelemetry.io/otel/...) — this codebase has zero external Go
// dependencies by design (see ENT-SDLC-NO-SUPPLYCHAIN's SBOM note: "Go
// services have zero external deps"), and every span field this connector
// needs (trace-id, span-id, parent-span-id, name, timing) is already
// produced by the existing internal/traceparent package. Building the
// standard OTLP/HTTP JSON request body directly, with the stdlib
// encoding/json, keeps that property intact while still being wire-format
// compatible with any real OTel collector's /v1/traces JSON HTTP receiver.
//
// Per the OTLP spec's JSON encoding special-case, trace_id/span_id/
// parent_span_id are lowercase hex strings (NOT the default base64
// protobuf-JSON mapping used for other bytes fields) — this is exactly the
// format internal/traceparent already produces, so no re-encoding is
// needed.
package otlpexport

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"time"
)

// SpanKind mirrors the OTLP Span.SpanKind enum (trace.proto). Only the
// values this codebase's hop-to-hop propagation actually uses are named.
type SpanKind int

const (
	SpanKindUnspecified SpanKind = 0
	SpanKindInternal    SpanKind = 1
	SpanKindServer      SpanKind = 2
	SpanKindClient      SpanKind = 3
	SpanKindProducer    SpanKind = 4
	SpanKindConsumer    SpanKind = 5
)

// Span is one span this service emits — one per event per hop, matching
// the granularity of the existing traceparent propagation (each event
// carries its own trace-id, so a batch containing events from several
// distinct traces produces one span per trace, not one span per batch).
type Span struct {
	TraceID      string // 32 lowercase hex chars
	SpanID       string // 16 lowercase hex chars
	ParentSpanID string // 16 lowercase hex chars; empty for a root span
	Name         string
	Kind         SpanKind
	Start        time.Time
	End          time.Time
	// Attributes are stamped as string-valued OTLP span attributes. Kept
	// to string values only — this exporter is intentionally minimal, not
	// a general-purpose OTel attribute type system.
	Attributes map[string]string
}

// Exporter POSTs batched spans to an OTLP/HTTP+JSON collector endpoint
// (typically ".../v1/traces"). Endpoint == "" disables export entirely —
// Export becomes a no-op — so this is safe to construct unconditionally
// and gate purely on configuration, matching this codebase's established
// "off by default, zero behavior change until an operator opts in"
// pattern (internal/mtls, tcpadmit, etc).
type Exporter struct {
	Endpoint    string
	ServiceName string
	HTTPClient  *http.Client
}

// Export sends spans as a single OTLP ExportTraceServiceRequest. A nil or
// empty spans slice, or a disabled Exporter (Endpoint == ""), is a no-op
// returning nil. Callers on a hot path should call this in a goroutine —
// Export intentionally does not retry or buffer on failure, since losing
// an observability span must never be treated as more important than the
// telemetry pipeline it's describing.
func (e *Exporter) Export(spans []Span) error {
	if e == nil || e.Endpoint == "" || len(spans) == 0 {
		return nil
	}
	body := BuildRequestBody(e.ServiceName, spans)
	req, err := http.NewRequest(http.MethodPost, e.Endpoint, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")

	client := e.HTTPClient
	if client == nil {
		client = http.DefaultClient
	}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("otlp_export_status=%d", resp.StatusCode)
	}
	return nil
}

// BuildRequestBody is a pure function producing the OTLP/HTTP+JSON
// ExportTraceServiceRequest body — split out from Export so the wire
// format can be unit tested directly without a real HTTP round trip.
func BuildRequestBody(serviceName string, spans []Span) []byte {
	otlpSpans := make([]map[string]any, 0, len(spans))
	for _, s := range spans {
		span := map[string]any{
			"traceId":           s.TraceID,
			"spanId":            s.SpanID,
			"name":              s.Name,
			"kind":              int(s.Kind),
			"startTimeUnixNano": fmt.Sprintf("%d", s.Start.UnixNano()),
			"endTimeUnixNano":   fmt.Sprintf("%d", s.End.UnixNano()),
		}
		if s.ParentSpanID != "" {
			span["parentSpanId"] = s.ParentSpanID
		}
		if len(s.Attributes) > 0 {
			attrs := make([]map[string]any, 0, len(s.Attributes))
			for k, v := range s.Attributes {
				attrs = append(attrs, map[string]any{
					"key":   k,
					"value": map[string]any{"stringValue": v},
				})
			}
			span["attributes"] = attrs
		}
		otlpSpans = append(otlpSpans, span)
	}

	body := map[string]any{
		"resourceSpans": []map[string]any{
			{
				"resource": map[string]any{
					"attributes": []map[string]any{
						{"key": "service.name", "value": map[string]any{"stringValue": serviceName}},
					},
				},
				"scopeSpans": []map[string]any{
					{
						"scope": map[string]any{"name": "detector-xdr"},
						"spans": otlpSpans,
					},
				},
			},
		},
	}

	encoded, _ := json.Marshal(body)
	return encoded
}
