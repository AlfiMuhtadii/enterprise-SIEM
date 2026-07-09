// Package traceparent implements W3C Trace Context (level 1) generation,
// parsing, and hop-to-hop propagation, independent of any OTel SDK.
//
// This is additive to the platform's existing free-form trace_id lineage
// field (used pervasively for analyst-facing correlation) — traceparent is a
// separate, strictly-formatted field intended to let a future OTLP collector
// stitch spans across the polyglot pipeline.
package traceparent

import (
	"crypto/rand"
	"fmt"
	"regexp"
)

var pattern = regexp.MustCompile(`^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$`)

const (
	zeroTraceID = "00000000000000000000000000000000"
	zeroSpanID  = "0000000000000000"
)

// Traceparent is the decoded form of a W3C traceparent header value.
type Traceparent struct {
	TraceID string
	SpanID  string
	Flags   string
}

func randomHex(nBytes int) string {
	b := make([]byte, nBytes)
	_, _ = rand.Read(b)
	return fmt.Sprintf("%x", b)
}

// Generate returns a new root W3C traceparent: version 00, a fresh 16-byte
// trace-id, a fresh 8-byte span-id, sampled flag set.
func Generate() string {
	return fmt.Sprintf("00-%s-%s-01", randomHex(16), randomHex(8))
}

// Parse validates and decomposes a W3C traceparent header value per the
// level-1 spec (version "00"; 32-hex trace-id and 16-hex parent-id, neither
// all-zero; 2-hex flags).
func Parse(s string) (*Traceparent, error) {
	m := pattern.FindStringSubmatch(s)
	if m == nil {
		return nil, fmt.Errorf("invalid_traceparent_format")
	}
	traceID, spanID, flags := m[1], m[2], m[3]
	if traceID == zeroTraceID {
		return nil, fmt.Errorf("invalid_traceparent_trace_id")
	}
	if spanID == zeroSpanID {
		return nil, fmt.Errorf("invalid_traceparent_span_id")
	}
	return &Traceparent{TraceID: traceID, SpanID: spanID, Flags: flags}, nil
}

// NewChildSpan returns a traceparent string carrying the same trace-id but a
// freshly generated span-id, representing this hop's span within the trace.
func (t *Traceparent) NewChildSpan() string {
	return fmt.Sprintf("00-%s-%s-%s", t.TraceID, randomHex(8), t.Flags)
}

// Propagate parses an inbound traceparent (if any) and returns a child-span
// traceparent for this hop. An empty or invalid inbound value never blocks
// propagation — a fresh root traceparent is generated instead.
func Propagate(inbound string) string {
	if inbound != "" {
		if tp, err := Parse(inbound); err == nil {
			return tp.NewChildSpan()
		}
	}
	return Generate()
}
