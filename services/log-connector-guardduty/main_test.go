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

	"detector-xdr-log-connector-guardduty/internal/guardduty"
)

func TestMapFindingToEventPromotesCommonFields(t *testing.T) {
	f := guardduty.Finding{
		ID:        "finding-1",
		Type:      "UnauthorizedAccess:EC2/SSHBruteForce",
		AccountID: "123456789012",
		Region:    "us-east-1",
		Severity:  5.0,
		CreatedAt: "2026-07-10T10:00:00.000Z",
		Title:     "SSH brute force",
		Service: map[string]any{
			"Action": map[string]any{
				"NetworkConnectionAction": map[string]any{
					"RemoteIpDetails": map[string]any{"IpAddressV4": "203.0.113.5"},
				},
			},
		},
		Raw: map[string]any{"Id": "finding-1"},
	}

	event := mapFindingToEvent(f, "tenant-a")

	if event["telemetry_type"] != "guardduty" {
		t.Fatalf("expected telemetry_type=guardduty, got %v", event["telemetry_type"])
	}
	if event["event_type"] != "UnauthorizedAccess:EC2/SSHBruteForce" || event["action"] != "UnauthorizedAccess:EC2/SSHBruteForce" {
		t.Fatalf("expected event_type/action from Type, got %v/%v", event["event_type"], event["action"])
	}
	if event["cloud_account"] != "123456789012" {
		t.Fatalf("expected cloud_account from AccountID, got %v", event["cloud_account"])
	}
	if event["source_ip"] != "203.0.113.5" {
		t.Fatalf("expected source_ip extracted from NetworkConnectionAction, got %v", event["source_ip"])
	}
	if event["risk_score"] != 5.0 {
		t.Fatalf("expected risk_score from Severity, got %v", event["risk_score"])
	}
	if event["tenant_id"] != "tenant-a" {
		t.Fatalf("expected tenant_id preserved, got %v", event["tenant_id"])
	}
	raw, ok := event["guardduty_finding"].(map[string]any)
	if !ok || raw["Id"] != "finding-1" {
		t.Fatalf("expected full raw finding preserved, got %v", event["guardduty_finding"])
	}
}

func TestMapFindingToEventOmitsTenantIdWhenEmpty(t *testing.T) {
	event := mapFindingToEvent(guardduty.Finding{}, "")
	if _, hasTenant := event["tenant_id"]; hasTenant {
		t.Fatalf("expected no tenant_id key when tenantID is empty")
	}
}

func TestMapFindingToEventSourceIpEmptyWhenNoActionMatches(t *testing.T) {
	f := guardduty.Finding{Service: map[string]any{}}
	event := mapFindingToEvent(f, "")
	if event["source_ip"] != "" {
		t.Fatalf("expected empty source_ip, got %v", event["source_ip"])
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
	writeSampleFindingsFile(t, filepath.Join(dir, "sample.jsonl"))

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
	if c.findingsParsed.Load() != 1 {
		t.Fatalf("expected 1 finding parsed, got %d", c.findingsParsed.Load())
	}

	c.scanOnce()
	if requestCount != 1 {
		t.Fatalf("expected no new forward request on rescan of already-processed file, got %d requests", requestCount)
	}
	if c.filesSkipped.Load() != 1 {
		t.Fatalf("expected filesSkipped=1 after rescan, got %d", c.filesSkipped.Load())
	}
}

func TestScanOnceNeverTreatsOwnStateFileAsFindingsInput(t *testing.T) {
	dir := t.TempDir()
	writeSampleFindingsFile(t, filepath.Join(dir, "sample.jsonl"))

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := &Connector{
		ingestURL:      server.URL,
		secret:         "s",
		batchSize:      100,
		watchDir:       dir,
		stateFile:      filepath.Join(dir, ".guardduty-connector-state.json"),
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
	writeSampleFindingsFile(t, path)

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

func TestHasFindingsExtensionAcceptsExpectedSuffixes(t *testing.T) {
	for _, path := range []string{"a.json", "a.json.gz", "a.jsonl", "a.jsonl.gz"} {
		if !hasFindingsExtension(path) {
			t.Fatalf("expected %q to be accepted", path)
		}
	}
	if hasFindingsExtension("a.txt") {
		t.Fatalf("expected .txt to be rejected")
	}
}

func writeSampleFindingsFile(t *testing.T, path string) {
	t.Helper()
	content := `{"Id":"finding-1","Type":"UnauthorizedAccess:EC2/SSHBruteForce","AccountId":"123456789012","CreatedAt":"2026-07-10T10:00:00.000Z"}`
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("failed to write sample file: %v", err)
	}
}
