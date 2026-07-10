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

	"detector-xdr-log-connector-cloudtrail/internal/cloudtrail"
)

func TestMapRecordToEventPromotesCommonFields(t *testing.T) {
	rec := cloudtrail.Record{
		EventTime:          "2026-07-10T10:30:00Z",
		EventSource:        "s3.amazonaws.com",
		EventName:          "GetObject",
		AWSRegion:          "us-east-1",
		SourceIPAddress:    "203.0.113.5",
		EventID:            "evt-1",
		RecipientAccountID: "123456789012",
		UserIdentity:       cloudtrail.UserIdentity{UserName: "alice", ARN: "arn:aws:iam::123456789012:user/alice"},
		Raw:                map[string]any{"eventName": "GetObject"},
	}

	event := mapRecordToEvent(rec, "tenant-a")

	if event["telemetry_type"] != "cloudtrail" {
		t.Fatalf("expected telemetry_type=cloudtrail, got %v", event["telemetry_type"])
	}
	if event["event_type"] != "GetObject" || event["action"] != "GetObject" {
		t.Fatalf("expected event_type/action from EventName, got %v/%v", event["event_type"], event["action"])
	}
	if event["user"] != "alice" {
		t.Fatalf("expected user=alice (UserName preferred), got %v", event["user"])
	}
	if event["cloud_account"] != "123456789012" {
		t.Fatalf("expected cloud_account from RecipientAccountID, got %v", event["cloud_account"])
	}
	if event["result"] != "success" {
		t.Fatalf("expected result=success when no errorCode, got %v", event["result"])
	}
	if event["tenant_id"] != "tenant-a" {
		t.Fatalf("expected tenant_id preserved, got %v", event["tenant_id"])
	}
	raw, ok := event["cloudtrail_record"].(map[string]any)
	if !ok || raw["eventName"] != "GetObject" {
		t.Fatalf("expected full raw record preserved, got %v", event["cloudtrail_record"])
	}
}

func TestMapRecordToEventFallsBackToArnThenPrincipalIdForUser(t *testing.T) {
	rec := cloudtrail.Record{UserIdentity: cloudtrail.UserIdentity{ARN: "arn:aws:iam::1:user/bob"}}
	event := mapRecordToEvent(rec, "")
	if event["user"] != "arn:aws:iam::1:user/bob" {
		t.Fatalf("expected ARN fallback, got %v", event["user"])
	}

	rec2 := cloudtrail.Record{UserIdentity: cloudtrail.UserIdentity{PrincipalID: "AIDAEXAMPLE"}}
	event2 := mapRecordToEvent(rec2, "")
	if event2["user"] != "AIDAEXAMPLE" {
		t.Fatalf("expected PrincipalID fallback, got %v", event2["user"])
	}
}

func TestMapRecordToEventReportsErrorCodeAsResult(t *testing.T) {
	rec := cloudtrail.Record{ErrorCode: "AccessDenied", ErrorMessage: "not authorized"}
	event := mapRecordToEvent(rec, "")
	if event["result"] != "AccessDenied" {
		t.Fatalf("expected result=AccessDenied, got %v", event["result"])
	}
	if event["error_message"] != "not authorized" {
		t.Fatalf("expected error_message preserved, got %v", event["error_message"])
	}
}

func TestMapRecordToEventOmitsTenantIdWhenEmpty(t *testing.T) {
	event := mapRecordToEvent(cloudtrail.Record{}, "")
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
	writeSampleCloudTrailFile(t, filepath.Join(dir, "sample.json"))

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
	if c.recordsParsed.Load() != 1 {
		t.Fatalf("expected 1 record parsed, got %d", c.recordsParsed.Load())
	}

	c.scanOnce()
	if requestCount != 1 {
		t.Fatalf("expected no new forward request on rescan of already-processed file, got %d requests", requestCount)
	}
	if c.filesSkipped.Load() != 1 {
		t.Fatalf("expected filesSkipped=1 after rescan, got %d", c.filesSkipped.Load())
	}
}

func TestScanOnceNeverTreatsOwnStateFileAsCloudTrailInput(t *testing.T) {
	// Regression: the state file lives inside watchDir and is named *.json,
	// so without an explicit skip it would be picked up as a candidate
	// CloudTrail export on every scan and fail to parse forever.
	dir := t.TempDir()
	writeSampleCloudTrailFile(t, filepath.Join(dir, "sample.json"))

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := &Connector{
		ingestURL:      server.URL,
		secret:         "s",
		batchSize:      100,
		watchDir:       dir,
		stateFile:      filepath.Join(dir, ".cloudtrail-connector-state.json"),
		httpClient:     &http.Client{Timeout: 5 * time.Second},
		processedFiles: map[string]bool{},
	}

	c.scanOnce() // writes the state file into dir as a side effect
	c.scanOnce() // must not re-scan its own state file as CloudTrail input

	if c.parseErrors.Load() != 0 {
		t.Fatalf("expected 0 parse errors, got %d (state file was likely scanned as input)", c.parseErrors.Load())
	}
	if c.filesScanned.Load() != 1 {
		t.Fatalf("expected exactly 1 real file scanned across both passes, got %d", c.filesScanned.Load())
	}
}

func TestLoadStateRestoresProcessedFilesAcrossRestart(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sample.json")
	writeSampleCloudTrailFile(t, path)

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

func TestLoadStateWithMissingFileStartsEmpty(t *testing.T) {
	dir := t.TempDir()
	c := &Connector{stateFile: filepath.Join(dir, "does-not-exist.json"), processedFiles: map[string]bool{}}
	c.loadState()
	if len(c.processedFiles) != 0 {
		t.Fatalf("expected empty processedFiles when state file absent, got %v", c.processedFiles)
	}
}

func writeSampleCloudTrailFile(t *testing.T, path string) {
	t.Helper()
	content := `{"Records":[{"eventName":"GetObject","eventTime":"2026-07-10T10:00:00Z","userIdentity":{"userName":"alice"}}]}`
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("failed to write sample file: %v", err)
	}
}
