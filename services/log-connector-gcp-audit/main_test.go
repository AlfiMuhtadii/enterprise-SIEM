package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"testing"
	"time"

	"detector-xdr-log-connector-gcp-audit/internal/gcpaudit"
)

func TestMapEntryToEventPromotesCommonFields(t *testing.T) {
	e := gcpaudit.LogEntry{
		InsertID:  "abc123",
		Timestamp: "2026-07-10T10:00:00.000000Z",
		Severity:  "NOTICE",
		Resource:  gcpaudit.Resource{Labels: map[string]string{"project_id": "my-project"}},
		ProtoPayload: map[string]any{
			"status":             map[string]any{},
			"methodName":         "storage.objects.get",
			"serviceName":        "storage.googleapis.com",
			"authenticationInfo": map[string]any{"principalEmail": "alice@example.com"},
			"requestMetadata":    map[string]any{"callerIp": "203.0.113.5"},
		},
		Raw: map[string]any{"insertId": "abc123"},
	}

	event := mapEntryToEvent(e, "tenant-a")

	if event["telemetry_type"] != "gcp_audit" {
		t.Fatalf("expected telemetry_type=gcp_audit, got %v", event["telemetry_type"])
	}
	if event["event_type"] != "storage.objects.get" || event["action"] != "storage.objects.get" {
		t.Fatalf("expected event_type/action from MethodName, got %v/%v", event["event_type"], event["action"])
	}
	if event["cloud_account"] != "my-project" {
		t.Fatalf("expected cloud_account from ProjectID, got %v", event["cloud_account"])
	}
	if event["user"] != "alice@example.com" {
		t.Fatalf("expected user from PrincipalEmail, got %v", event["user"])
	}
	if event["source_ip"] != "203.0.113.5" {
		t.Fatalf("expected source_ip from CallerIP, got %v", event["source_ip"])
	}
	if event["result"] != "success" {
		t.Fatalf("expected result=success for empty status, got %v", event["result"])
	}
	if event["tenant_id"] != "tenant-a" {
		t.Fatalf("expected tenant_id preserved, got %v", event["tenant_id"])
	}
	raw, ok := event["gcp_audit_entry"].(map[string]any)
	if !ok || raw["insertId"] != "abc123" {
		t.Fatalf("expected full raw entry preserved, got %v", event["gcp_audit_entry"])
	}
}

func TestMapEntryToEventReportsErrorResultForPopulatedStatus(t *testing.T) {
	e := gcpaudit.LogEntry{
		ProtoPayload: map[string]any{
			"status": map[string]any{"code": float64(7), "message": "PERMISSION_DENIED"},
		},
	}
	event := mapEntryToEvent(e, "")
	if event["result"] != "error" {
		t.Fatalf("expected result=error for populated status, got %v", event["result"])
	}
}

func TestMapEntryToEventOmitsTenantIdWhenEmpty(t *testing.T) {
	event := mapEntryToEvent(gcpaudit.LogEntry{ProtoPayload: map[string]any{}}, "")
	if _, hasTenant := event["tenant_id"]; hasTenant {
		t.Fatalf("expected no tenant_id key when tenantID is empty")
	}
}

func TestSignMatchesIngestionGatewaySigV2Scheme(t *testing.T) {
	secret := "test-secret"
	body := []byte(`[{"a":1}]`)
	ts := int64(1700000000)

	got := sign(secret, ts, body)

	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(strconv.FormatInt(ts, 10)))
	mac.Write([]byte("."))
	mac.Write(body)
	want := "sha256=" + hex.EncodeToString(mac.Sum(nil))

	if got != want {
		t.Fatalf("signature mismatch: got %q, want %q", got, want)
	}
}

func TestForwardSendsSignedBatch(t *testing.T) {
	var capturedBody []map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-XDR-Signature") == "" {
			t.Errorf("expected signature header set")
		}
		_ = json.NewDecoder(r.Body).Decode(&capturedBody)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := &Connector{ingestURL: server.URL, secret: "s", httpClient: &http.Client{Timeout: 5 * time.Second}}
	if err := c.forward([]map[string]any{{"event_type": "x"}}); err != nil {
		t.Fatalf("unexpected forward error: %v", err)
	}
	if len(capturedBody) != 1 || capturedBody[0]["event_type"] != "x" {
		t.Fatalf("unexpected forwarded body: %v", capturedBody)
	}
	if c.forwarded.Load() != 1 {
		t.Fatalf("expected forwarded=1, got %d", c.forwarded.Load())
	}
}

func TestForwardRecordsErrorOnNonSuccessStatus(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
	}))
	defer server.Close()

	c := &Connector{ingestURL: server.URL, secret: "s", httpClient: &http.Client{Timeout: 5 * time.Second}}
	if err := c.forward([]map[string]any{{"a": 1}}); err == nil {
		t.Fatalf("expected error on non-2xx response")
	}
	if c.forwardErrors.Load() != 1 {
		t.Fatalf("expected forwardErrors=1, got %d", c.forwardErrors.Load())
	}
}

func TestScanOnceSkipsAlreadyProcessedFilesOnRescan(t *testing.T) {
	dir := t.TempDir()
	writeSampleAuditLogFile(t, filepath.Join(dir, "sample.jsonl"))

	var requestCount int
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requestCount++
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := &Connector{
		ingestURL:      server.URL,
		secret:         "s",
		batchSize:      100,
		watchDir:       dir,
		stateFile:      filepath.Join(dir, ".state.json"),
		httpClient:     &http.Client{Timeout: 5 * time.Second},
		processedFiles: map[string]bool{},
	}

	c.scanOnce()
	if requestCount != 1 {
		t.Fatalf("expected exactly 1 forward request on first scan, got %d", requestCount)
	}
	if c.entriesParsed.Load() != 1 {
		t.Fatalf("expected 1 entry parsed, got %d", c.entriesParsed.Load())
	}

	c.scanOnce()
	if requestCount != 1 {
		t.Fatalf("expected no new forward request on rescan of already-processed file, got %d requests", requestCount)
	}
	if c.filesSkipped.Load() != 1 {
		t.Fatalf("expected filesSkipped=1 after rescan, got %d", c.filesSkipped.Load())
	}
}

func TestScanOnceNeverTreatsOwnStateFileAsAuditLogInput(t *testing.T) {
	dir := t.TempDir()
	writeSampleAuditLogFile(t, filepath.Join(dir, "sample.jsonl"))

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := &Connector{
		ingestURL:      server.URL,
		secret:         "s",
		batchSize:      100,
		watchDir:       dir,
		stateFile:      filepath.Join(dir, ".gcp-audit-connector-state.json"),
		httpClient:     &http.Client{Timeout: 5 * time.Second},
		processedFiles: map[string]bool{},
	}

	c.scanOnce()
	c.scanOnce()

	if c.parseErrors.Load() != 0 {
		t.Fatalf("expected 0 parse errors, got %d (state file was likely scanned as input)", c.parseErrors.Load())
	}
	if c.filesScanned.Load() != 1 {
		t.Fatalf("expected exactly 1 real file scanned across both passes, got %d", c.filesScanned.Load())
	}
}

func TestLoadStateRestoresProcessedFilesAcrossRestart(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sample.jsonl")
	writeSampleAuditLogFile(t, path)

	c1 := &Connector{
		watchDir:       dir,
		stateFile:      filepath.Join(dir, ".state.json"),
		processedFiles: map[string]bool{},
	}
	c1.mu.Lock()
	c1.processedFiles[path] = true
	c1.mu.Unlock()
	c1.saveState()

	c2 := &Connector{
		watchDir:       dir,
		stateFile:      filepath.Join(dir, ".state.json"),
		processedFiles: map[string]bool{},
	}
	c2.loadState()

	if !c2.processedFiles[path] {
		t.Fatalf("expected processed-files state restored after loadState, got %v", c2.processedFiles)
	}
}

func TestHasAuditLogExtensionAcceptsExpectedSuffixes(t *testing.T) {
	for _, path := range []string{"a.json", "a.json.gz", "a.jsonl", "a.jsonl.gz"} {
		if !hasAuditLogExtension(path) {
			t.Fatalf("expected %q to be accepted", path)
		}
	}
	if hasAuditLogExtension("a.txt") {
		t.Fatalf("expected .txt to be rejected")
	}
}

func writeSampleAuditLogFile(t *testing.T, path string) {
	t.Helper()
	content := `{"insertId":"abc123","timestamp":"2026-07-10T10:00:00.000000Z","protoPayload":{"methodName":"storage.objects.get"}}`
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("failed to write sample file: %v", err)
	}
}
