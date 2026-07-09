package traceparent

import (
	"strings"
	"testing"
)

func TestGenerateProducesValidTraceparent(t *testing.T) {
	tp := Generate()
	parsed, err := Parse(tp)
	if err != nil {
		t.Fatalf("Generate produced an unparseable traceparent %q: %v", tp, err)
	}
	if len(parsed.TraceID) != 32 || len(parsed.SpanID) != 16 || parsed.Flags != "01" {
		t.Fatalf("unexpected fields: %+v", parsed)
	}
}

func TestGenerateProducesUniqueValues(t *testing.T) {
	a, b := Generate(), Generate()
	if a == b {
		t.Fatalf("expected two calls to Generate to differ, got identical %q", a)
	}
}

func TestParseRejectsWrongVersion(t *testing.T) {
	bad := "01-" + strings.Repeat("a", 32) + "-" + strings.Repeat("b", 16) + "-01"
	if _, err := Parse(bad); err == nil {
		t.Fatalf("expected error for non-00 version")
	}
}

func TestParseRejectsWrongLength(t *testing.T) {
	cases := []string{
		"00-abc-" + strings.Repeat("b", 16) + "-01",
		"00-" + strings.Repeat("a", 32) + "-abc-01",
		"00-" + strings.Repeat("a", 32) + "-" + strings.Repeat("b", 16) + "-1",
		"not-a-traceparent-at-all",
		"",
	}
	for _, c := range cases {
		if _, err := Parse(c); err == nil {
			t.Fatalf("expected error for malformed traceparent %q", c)
		}
	}
}

func TestParseRejectsAllZeroTraceID(t *testing.T) {
	bad := "00-" + strings.Repeat("0", 32) + "-" + strings.Repeat("b", 16) + "-01"
	if _, err := Parse(bad); err == nil {
		t.Fatalf("expected error for all-zero trace-id")
	}
}

func TestParseRejectsAllZeroSpanID(t *testing.T) {
	bad := "00-" + strings.Repeat("a", 32) + "-" + strings.Repeat("0", 16) + "-01"
	if _, err := Parse(bad); err == nil {
		t.Fatalf("expected error for all-zero span-id")
	}
}

func TestParseRejectsUppercaseHex(t *testing.T) {
	bad := "00-" + strings.Repeat("A", 32) + "-" + strings.Repeat("B", 16) + "-01"
	if _, err := Parse(bad); err == nil {
		t.Fatalf("expected error for uppercase hex (W3C requires lowercase)")
	}
}

func TestNewChildSpanPreservesTraceIDChangesSpanID(t *testing.T) {
	root := Generate()
	parsed, _ := Parse(root)
	child := parsed.NewChildSpan()
	childParsed, err := Parse(child)
	if err != nil {
		t.Fatalf("child span traceparent invalid: %v", err)
	}
	if childParsed.TraceID != parsed.TraceID {
		t.Fatalf("expected trace-id preserved, got %s vs %s", childParsed.TraceID, parsed.TraceID)
	}
	if childParsed.SpanID == parsed.SpanID {
		t.Fatalf("expected a new span-id, got the same value %s", parsed.SpanID)
	}
}

func TestPropagateWithValidInboundCreatesChildSpan(t *testing.T) {
	inbound := Generate()
	inboundParsed, _ := Parse(inbound)
	out := Propagate(inbound)
	outParsed, err := Parse(out)
	if err != nil {
		t.Fatalf("propagated traceparent invalid: %v", err)
	}
	if outParsed.TraceID != inboundParsed.TraceID {
		t.Fatalf("expected trace-id preserved across propagation")
	}
	if outParsed.SpanID == inboundParsed.SpanID {
		t.Fatalf("expected a new span-id for this hop")
	}
}

func TestPropagateWithEmptyInboundGeneratesRoot(t *testing.T) {
	out := Propagate("")
	if _, err := Parse(out); err != nil {
		t.Fatalf("expected a valid generated root traceparent, got error: %v", err)
	}
}

func TestPropagateWithInvalidInboundGeneratesRoot(t *testing.T) {
	out := Propagate("garbage-not-a-traceparent")
	if _, err := Parse(out); err != nil {
		t.Fatalf("expected a valid generated root traceparent, got error: %v", err)
	}
}
