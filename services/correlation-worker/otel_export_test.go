package main

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"

	"detector-xdr-correlation-worker/internal/otlpexport"
)

// ---------------------------------------------------------------------------
// OBS-OTEL-TRACING: exportAlertSpans + the correlateHTTP integration path.
// ---------------------------------------------------------------------------

func waitForCondition(t *testing.T, timeout time.Duration, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	if !cond() {
		t.Fatal("condition not met before timeout")
	}
}

func TestExportAlertSpansSendsOneSpanPerAlertToCollector(t *testing.T) {
	capturedBodies := make(chan []byte, 1)
	collector := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		capturedBodies <- body
		rw.WriteHeader(http.StatusOK)
	}))
	defer collector.Close()

	w := &Worker{otelExporter: &otlpexport.Exporter{Endpoint: collector.URL, ServiceName: "correlation-worker"}}

	events := []Event{
		{EventID: "ev-001", TelemetryType: "identity", EventType: "mfa_failure", User: "alice"},
	}
	alerts := []Alert{
		makeAlert("IDENTITY_MFA_FAILURE_BURST", "alice", events, 0.71),
		makeAlert("IDENTITY_RISKY_IP_LOGIN", "bob", events, 0.76),
	}

	w.exportAlertSpans(alerts, time.Now(), time.Now())

	var capturedBody []byte
	select {
	case capturedBody = <-capturedBodies:
	case <-time.After(time.Second):
		t.Fatal("expected exportAlertSpans to reach the OTLP collector")
	}

	var decoded map[string]any
	if err := json.Unmarshal(capturedBody, &decoded); err != nil {
		t.Fatalf("expected valid OTLP JSON, got error: %v (body: %s)", err, capturedBody)
	}
	spans := decoded["resourceSpans"].([]any)[0].(map[string]any)["scopeSpans"].([]any)[0].(map[string]any)["spans"].([]any)
	if len(spans) != 2 {
		t.Fatalf("expected exactly 1 span per alert (2 alerts), got %d", len(spans))
	}
}

func TestExportAlertSpansNoOpWhenExporterDisabled(t *testing.T) {
	w := &Worker{otelExporter: &otlpexport.Exporter{Endpoint: "", ServiceName: "correlation-worker"}}
	events := []Event{{EventID: "ev-001", TelemetryType: "identity", EventType: "mfa_failure", User: "alice"}}
	alerts := []Alert{makeAlert("IDENTITY_MFA_FAILURE_BURST", "alice", events, 0.71)}

	// Must not panic and must return promptly with a disabled exporter.
	w.exportAlertSpans(alerts, time.Now(), time.Now())
}

func TestExportAlertSpansNoOpForEmptyAlerts(t *testing.T) {
	var called atomic.Bool
	collector := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		called.Store(true)
		rw.WriteHeader(http.StatusOK)
	}))
	defer collector.Close()

	w := &Worker{otelExporter: &otlpexport.Exporter{Endpoint: collector.URL, ServiceName: "correlation-worker"}}
	w.exportAlertSpans(nil, time.Now(), time.Now())
	time.Sleep(50 * time.Millisecond)
	if called.Load() {
		t.Error("expected no HTTP call for an empty alerts slice")
	}
}

func TestCorrelateHTTPExportsSpansForProducedAlerts(t *testing.T) {
	var requestCount int32
	collector := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&requestCount, 1)
		rw.WriteHeader(http.StatusOK)
	}))
	defer collector.Close()

	w := &Worker{otelExporter: &otlpexport.Exporter{Endpoint: collector.URL, ServiceName: "correlation-worker"}}

	// identityAlerts requires >=5 "mfa_failed" events from the same user to
	// produce IDENTITY_MFA_FAILURE_BURST.
	events := make([]Event, 0, 5)
	for i := 0; i < 5; i++ {
		events = append(events, Event{
			EventID:       fmt.Sprintf("ev-%03d", i),
			TelemetryType: "identity",
			EventType:     "mfa_failed",
			User:          "alice",
			Ts:            "2026-07-11T10:00:00Z",
		})
	}
	body, _ := json.Marshal(events)
	req := httptest.NewRequest(http.MethodPost, "/v1/correlate", bytes.NewReader(body))
	rr := httptest.NewRecorder()

	w.correlateHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var respBody map[string]any
	_ = json.Unmarshal(rr.Body.Bytes(), &respBody)
	if respBody["alert_count"] == float64(0) {
		t.Fatalf("expected the fixture to produce at least 1 alert, got response: %s", rr.Body.String())
	}

	waitForCondition(t, time.Second, func() bool { return atomic.LoadInt32(&requestCount) >= 1 })
}

func TestCorrelateHTTPDoesNotExportWhenNoAlertsProduced(t *testing.T) {
	var called atomic.Bool
	collector := httptest.NewServer(http.HandlerFunc(func(rw http.ResponseWriter, r *http.Request) {
		called.Store(true)
		rw.WriteHeader(http.StatusOK)
	}))
	defer collector.Close()

	w := &Worker{otelExporter: &otlpexport.Exporter{Endpoint: collector.URL, ServiceName: "correlation-worker"}}

	// A single benign event with no correlated pattern should produce zero alerts.
	events := []Event{
		{EventID: "ev-001", TelemetryType: "identity", EventType: "login_success", User: "alice", Ts: "2026-07-11T10:00:00Z"},
	}
	body, _ := json.Marshal(events)
	req := httptest.NewRequest(http.MethodPost, "/v1/correlate", bytes.NewReader(body))
	rr := httptest.NewRecorder()

	w.correlateHTTP(rr, req)

	time.Sleep(100 * time.Millisecond)
	if called.Load() {
		t.Error("expected no OTLP export when correlate() produces zero alerts")
	}
}
