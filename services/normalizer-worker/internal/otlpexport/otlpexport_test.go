package otlpexport

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func sampleSpan() Span {
	start := time.Unix(1700000000, 0)
	return Span{
		TraceID:      "0123456789abcdef0123456789abcdef",
		SpanID:       "0123456789abcdef",
		ParentSpanID: "fedcba9876543210",
		Name:         "ingestion-gateway.publish",
		Kind:         SpanKindProducer,
		Start:        start,
		End:          start.Add(50 * time.Millisecond),
		Attributes:   map[string]string{"tenant_id": "tenant-a"},
	}
}

func TestBuildRequestBodyProducesValidJSON(t *testing.T) {
	body := BuildRequestBody("ingestion-gateway", []Span{sampleSpan()})
	var decoded map[string]any
	if err := json.Unmarshal(body, &decoded); err != nil {
		t.Fatalf("expected valid JSON, got error: %v (body: %s)", err, body)
	}
}

func TestBuildRequestBodyShapeMatchesOtlpHttpJson(t *testing.T) {
	body := BuildRequestBody("ingestion-gateway", []Span{sampleSpan()})
	var decoded struct {
		ResourceSpans []struct {
			Resource struct {
				Attributes []struct {
					Key   string `json:"key"`
					Value struct {
						StringValue string `json:"stringValue"`
					} `json:"value"`
				} `json:"attributes"`
			} `json:"resource"`
			ScopeSpans []struct {
				Scope struct {
					Name string `json:"name"`
				} `json:"scope"`
				Spans []struct {
					TraceID           string `json:"traceId"`
					SpanID            string `json:"spanId"`
					ParentSpanID      string `json:"parentSpanId"`
					Name              string `json:"name"`
					Kind              int    `json:"kind"`
					StartTimeUnixNano string `json:"startTimeUnixNano"`
					EndTimeUnixNano   string `json:"endTimeUnixNano"`
				} `json:"spans"`
			} `json:"scopeSpans"`
		} `json:"resourceSpans"`
	}
	if err := json.Unmarshal(body, &decoded); err != nil {
		t.Fatalf("unexpected error decoding into OTLP shape: %v", err)
	}

	if len(decoded.ResourceSpans) != 1 {
		t.Fatalf("expected exactly 1 resourceSpans entry, got %d", len(decoded.ResourceSpans))
	}
	rs := decoded.ResourceSpans[0]
	if len(rs.Resource.Attributes) != 1 || rs.Resource.Attributes[0].Key != "service.name" || rs.Resource.Attributes[0].Value.StringValue != "ingestion-gateway" {
		t.Fatalf("expected service.name=ingestion-gateway resource attribute, got %+v", rs.Resource.Attributes)
	}
	if len(rs.ScopeSpans) != 1 || rs.ScopeSpans[0].Scope.Name != "detector-xdr" {
		t.Fatalf("expected scope.name=detector-xdr, got %+v", rs.ScopeSpans)
	}
	spans := rs.ScopeSpans[0].Spans
	if len(spans) != 1 {
		t.Fatalf("expected exactly 1 span, got %d", len(spans))
	}
	got := spans[0]
	want := sampleSpan()
	if got.TraceID != want.TraceID {
		t.Errorf("traceId: got %q want %q", got.TraceID, want.TraceID)
	}
	if got.SpanID != want.SpanID {
		t.Errorf("spanId: got %q want %q", got.SpanID, want.SpanID)
	}
	if got.ParentSpanID != want.ParentSpanID {
		t.Errorf("parentSpanId: got %q want %q", got.ParentSpanID, want.ParentSpanID)
	}
	if got.Name != want.Name {
		t.Errorf("name: got %q want %q", got.Name, want.Name)
	}
	if got.Kind != int(SpanKindProducer) {
		t.Errorf("kind: got %d want %d (SPAN_KIND_PRODUCER)", got.Kind, SpanKindProducer)
	}
	if got.StartTimeUnixNano != "1700000000000000000" {
		t.Errorf("startTimeUnixNano: got %q want %q", got.StartTimeUnixNano, "1700000000000000000")
	}
	if got.EndTimeUnixNano != "1700000000050000000" {
		t.Errorf("endTimeUnixNano: got %q want %q", got.EndTimeUnixNano, "1700000000050000000")
	}
}

func TestBuildRequestBodyOmitsParentSpanIdForRootSpan(t *testing.T) {
	root := sampleSpan()
	root.ParentSpanID = ""
	body := BuildRequestBody("ingestion-gateway", []Span{root})

	var decoded map[string]any
	_ = json.Unmarshal(body, &decoded)
	span := decoded["resourceSpans"].([]any)[0].(map[string]any)["scopeSpans"].([]any)[0].(map[string]any)["spans"].([]any)[0].(map[string]any)
	if _, present := span["parentSpanId"]; present {
		t.Errorf("expected no parentSpanId key for a root span, got %v", span["parentSpanId"])
	}
}

func TestBuildRequestBodyBatchesMultipleSpansInOneRequest(t *testing.T) {
	spans := []Span{sampleSpan(), sampleSpan(), sampleSpan()}
	body := BuildRequestBody("ingestion-gateway", spans)

	var decoded map[string]any
	_ = json.Unmarshal(body, &decoded)
	gotSpans := decoded["resourceSpans"].([]any)[0].(map[string]any)["scopeSpans"].([]any)[0].(map[string]any)["spans"].([]any)
	if len(gotSpans) != 3 {
		t.Fatalf("expected 3 spans batched into 1 request, got %d", len(gotSpans))
	}
}

func TestExportDisabledWhenEndpointEmpty(t *testing.T) {
	called := false
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called = true
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	e := &Exporter{Endpoint: "", ServiceName: "test"}
	if err := e.Export([]Span{sampleSpan()}); err != nil {
		t.Fatalf("expected no error from a disabled exporter, got: %v", err)
	}
	if called {
		t.Error("expected no HTTP call when Endpoint is empty")
	}
}

func TestExportNoOpForEmptySpanSlice(t *testing.T) {
	called := false
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called = true
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	e := &Exporter{Endpoint: server.URL, ServiceName: "test"}
	if err := e.Export(nil); err != nil {
		t.Fatalf("expected no error for an empty span slice, got: %v", err)
	}
	if called {
		t.Error("expected no HTTP call for an empty span slice")
	}
}

func TestExportSendsRealHTTPPostToCollectorEndpoint(t *testing.T) {
	var capturedBody []byte
	var capturedContentType string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			t.Errorf("expected POST, got %s", r.Method)
		}
		if r.URL.Path != "/v1/traces" {
			t.Errorf("expected /v1/traces, got %s", r.URL.Path)
		}
		capturedContentType = r.Header.Get("Content-Type")
		buf := make([]byte, r.ContentLength)
		_, _ = r.Body.Read(buf)
		capturedBody = buf
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	e := &Exporter{Endpoint: server.URL + "/v1/traces", ServiceName: "ingestion-gateway"}
	if err := e.Export([]Span{sampleSpan()}); err != nil {
		t.Fatalf("unexpected export error: %v", err)
	}
	if capturedContentType != "application/json" {
		t.Errorf("expected Content-Type: application/json, got %q", capturedContentType)
	}
	if len(capturedBody) == 0 {
		t.Error("expected a non-empty request body to reach the collector")
	}
}

func TestExportReturnsErrorOnNon2xxResponse(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer server.Close()

	e := &Exporter{Endpoint: server.URL, ServiceName: "test"}
	if err := e.Export([]Span{sampleSpan()}); err == nil {
		t.Fatal("expected an error on a 503 response from the collector")
	}
}

func TestExportReturnsErrorWhenCollectorUnreachable(t *testing.T) {
	e := &Exporter{Endpoint: "http://127.0.0.1:1", ServiceName: "test", HTTPClient: &http.Client{Timeout: time.Second}}
	if err := e.Export([]Span{sampleSpan()}); err == nil {
		t.Fatal("expected an error when the collector endpoint is unreachable")
	}
}
