package main

import (
	"bytes"
	"compress/gzip"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync/atomic"
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

// ---------------------------------------------------------------------------
// CONN-UNTENANTED-INGEST: startup-refusal in strict mode.
// ---------------------------------------------------------------------------

func TestValidateTenantConfigDefaultAllowsEmptyTenant(t *testing.T) {
	if err := validateTenantConfig("", false); err != nil {
		t.Errorf("expected no error in default (non-strict) mode with an empty tenant, got: %v", err)
	}
}

func TestValidateTenantConfigStrictRejectsEmptyTenant(t *testing.T) {
	if err := validateTenantConfig("", true); err == nil {
		t.Error("expected an error in strict mode with an empty tenant")
	}
}

func TestValidateTenantConfigStrictAllowsConfiguredTenant(t *testing.T) {
	if err := validateTenantConfig("tenant-abc", true); err != nil {
		t.Errorf("expected no error in strict mode with a configured tenant, got: %v", err)
	}
}

func TestTwoConnectorInstancesStayTenantIsolated(t *testing.T) {
	f := guardduty.Finding{ID: "finding-1", Type: "UnauthorizedAccess:EC2/SSHBruteForce"}
	eventA := mapFindingToEvent(f, "tenant-a")
	eventB := mapFindingToEvent(f, "tenant-b")

	if eventA["tenant_id"] != "tenant-a" {
		t.Errorf("expected tenant-a's event to carry tenant_id=tenant-a, got %v", eventA["tenant_id"])
	}
	if eventB["tenant_id"] != "tenant-b" {
		t.Errorf("expected tenant-b's event to carry tenant_id=tenant-b, got %v", eventB["tenant_id"])
	}
}

// ---------------------------------------------------------------------------
// CONN-DELIVERY-LOSS: a file's checkpoint (processedFiles+saveState) must
// only be committed after ALL of its derived batches are confirmed
// delivered — never before, and never on partial success.
// ---------------------------------------------------------------------------

func newDeliveryTestConnector(t *testing.T, dir, ingestURL string, batchSize int) *Connector {
	t.Helper()
	return &Connector{
		ingestURL:         ingestURL,
		secret:            "s",
		batchSize:         batchSize,
		watchDir:          dir,
		stateFile:         filepath.Join(dir, ".state.json"),
		quarantineLogPath: filepath.Join(dir, ".quarantine.jsonl"),
		httpClient:        &http.Client{Timeout: 5 * time.Second},
		processedFiles:    map[string]bool{},
		quarantinedFiles:  map[string]bool{},
		forwardMaxRetries: 3,
		forwardRetryBase:  time.Millisecond,
		forwardRetryMax:   5 * time.Millisecond,
	}
}

func writeMultiFindingFile(t *testing.T, path string, n int) {
	t.Helper()
	var lines []string
	for i := 0; i < n; i++ {
		lines = append(lines, fmt.Sprintf(`{"Id":"finding-%d","Type":"UnauthorizedAccess:EC2/SSHBruteForce","AccountId":"123456789012","CreatedAt":"2026-07-10T10:00:00.000Z"}`, i))
	}
	content := strings.Join(lines, "\n")
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
}

func TestProcessFileLeavesFileUnprocessedWhenGatewayUnavailable(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sample.json")
	writeSampleFindingsFile(t, path)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hj, _ := w.(http.Hijacker)
		conn, _, _ := hj.Hijack()
		_ = conn.Close()
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 100)
	c.processFile(path)

	c.mu.Lock()
	processed := c.processedFiles[path]
	c.mu.Unlock()
	if processed {
		t.Error("expected the file to be left unprocessed after exhausting retries against an unavailable gateway")
	}
	if c.deliveryFailedFiles.Load() != 1 {
		t.Errorf("expected deliveryFailedFiles=1, got %d", c.deliveryFailedFiles.Load())
	}
	if _, err := os.Stat(c.stateFile); err == nil {
		t.Error("expected no state file to have been written for an unacknowledged file")
	}
}

func TestProcessFileRetriesThenSucceedsAndMarksProcessed(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sample.json")
	writeSampleFindingsFile(t, path)

	var attempts int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if atomic.AddInt32(&attempts, 1) < 3 {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 100)
	c.processFile(path)

	c.mu.Lock()
	processed := c.processedFiles[path]
	c.mu.Unlock()
	if !processed {
		t.Error("expected the file to be marked processed after eventual delivery success")
	}

	c2 := newDeliveryTestConnector(t, dir, server.URL, 100)
	c2.loadState()
	if !c2.processedFiles[path] {
		t.Error("expected a restarted connector to load the file as processed via the persisted state file")
	}
}

func TestProcessFileRestartRetriesAnUnacknowledgedFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sample.json")
	writeSampleFindingsFile(t, path)

	var attempts int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&attempts, 1)
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer server.Close()

	c1 := newDeliveryTestConnector(t, dir, server.URL, 100)
	c1.processFile(path)

	c2 := newDeliveryTestConnector(t, dir, server.URL, 100)
	c2.loadState()
	c2.scanOnce()

	if got := atomic.LoadInt32(&attempts); got < 2 {
		t.Errorf("expected the restarted connector to retry the file, got %d total attempts", got)
	}
	if c2.filesSkipped.Load() != 0 {
		t.Errorf("expected the unacknowledged file to be re-scanned, not skipped, got filesSkipped=%d", c2.filesSkipped.Load())
	}
}

func TestProcessFileMultiBatchMiddleBatchFailureLeavesFileUnprocessed(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "multi.json")
	writeMultiFindingFile(t, path, 5) // batchSize=2 -> 3 batches

	var batchCount int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		n := atomic.AddInt32(&batchCount, 1)
		if n == 2 {
			w.WriteHeader(http.StatusServiceUnavailable)
			return
		}
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 2)
	c.forwardMaxRetries = 1
	c.processFile(path)

	c.mu.Lock()
	processed := c.processedFiles[path]
	c.mu.Unlock()
	if processed {
		t.Error("expected the file to be left unprocessed when its middle batch fails, even though the first batch succeeded")
	}
	if c.forwarded.Load() != 2 {
		t.Errorf("expected the first (successful) batch's 2 findings to be counted as forwarded, got %d", c.forwarded.Load())
	}
}

// ---------------------------------------------------------------------------
// CONN-UNBOUNDED-FILE: size ceilings, quarantine, and rejection metrics.
// ---------------------------------------------------------------------------

func TestProcessFileQuarantinesOversizedPlainFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "huge.json")
	if err := os.WriteFile(path, []byte(`{"Id":"x","Type":"`+strings.Repeat("a", 10000)+`"}`), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Error("forward must never be called for a quarantined oversized file")
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 100)
	c.maxFileBytes = 1000
	c.processFile(path)

	if c.filesQuarantined.Load() != 1 {
		t.Fatalf("expected filesQuarantined=1, got %d", c.filesQuarantined.Load())
	}
	c.mu.Lock()
	quarantined := c.quarantinedFiles[path]
	c.mu.Unlock()
	if !quarantined {
		t.Error("expected the oversized file to be recorded as quarantined")
	}
	if _, err := os.Stat(path); err != nil {
		t.Errorf("expected the original file to be left in place, got stat error: %v", err)
	}

	data, err := os.ReadFile(c.quarantineLogPath)
	if err != nil {
		t.Fatalf("expected a durable quarantine log to be written: %v", err)
	}
	if !bytes.Contains(data, []byte("file_exceeds_max_file_bytes")) {
		t.Errorf("expected the quarantine record to name the reason, got: %s", data)
	}
}

func TestScanOnceNeverRetriesAQuarantinedFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "huge.json")
	if err := os.WriteFile(path, []byte(`{"Id":"x","Type":"`+strings.Repeat("a", 10000)+`"}`), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 100)
	c.maxFileBytes = 1000
	c.scanOnce()
	if c.filesQuarantined.Load() != 1 {
		t.Fatalf("expected filesQuarantined=1 after first scan, got %d", c.filesQuarantined.Load())
	}

	c.scanOnce()
	if c.filesQuarantined.Load() != 1 {
		t.Errorf("expected filesQuarantined to stay at 1 (no re-quarantine attempt), got %d", c.filesQuarantined.Load())
	}
	if c.filesScanned.Load() != 1 {
		t.Errorf("expected filesScanned=1 (only the first scan actually read the file), got %d", c.filesScanned.Load())
	}
}

func TestQuarantineIsRestartSafeAcrossConnectorInstances(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "huge.json")
	if err := os.WriteFile(path, []byte(`{"Id":"x","Type":"`+strings.Repeat("a", 10000)+`"}`), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	c1 := newDeliveryTestConnector(t, dir, "http://unused.invalid", 100)
	c1.maxFileBytes = 1000
	c1.processFile(path)

	c2 := newDeliveryTestConnector(t, dir, "http://unused.invalid", 100)
	c2.maxFileBytes = 1000
	c2.loadQuarantineLog()
	c2.scanOnce()

	if c2.filesScanned.Load() != 0 {
		t.Errorf("expected the restarted connector to load the quarantine record and skip the file, got filesScanned=%d", c2.filesScanned.Load())
	}
}

func TestProcessFileQuarantinesGzipExpansionOverLimit(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "bomb.json.gz")
	huge := bytes.Repeat([]byte("A"), 5_000_000)
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	if _, err := gz.Write([]byte(`{"Id":"x","Type":"` + string(huge) + `"}`)); err != nil {
		t.Fatalf("gzip write: %v", err)
	}
	if err := gz.Close(); err != nil {
		t.Fatalf("gzip close: %v", err)
	}
	if err := os.WriteFile(path, buf.Bytes(), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		t.Error("forward must never be called for a quarantined compression-bomb file")
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 100)
	c.maxExpandedBytes = 1_000_000
	c.processFile(path)

	if c.filesQuarantined.Load() != 1 {
		t.Fatalf("expected filesQuarantined=1, got %d", c.filesQuarantined.Load())
	}
	data, err := os.ReadFile(c.quarantineLogPath)
	if err != nil {
		t.Fatalf("expected a durable quarantine log to be written: %v", err)
	}
	if !bytes.Contains(data, []byte("decompressed_content_exceeds_max_expanded_bytes")) {
		t.Errorf("expected the quarantine record to name the expansion-limit reason, got: %s", data)
	}
}

func TestProcessFileSkipsOversizedRecordButStillForwardsOthers(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "mixed.json")
	hugeLine := `{"Id":"finding-huge","Type":"` + strings.Repeat("x", 5000) + `"}`
	content := `{"Id":"finding-small1"}` + "\n" + hugeLine + "\n" + `{"Id":"finding-small2"}`
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}

	var captured []map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&captured)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 100)
	c.maxRecordBytes = 500
	c.processFile(path)

	if c.oversizedRecordsSkipped.Load() != 1 {
		t.Fatalf("expected oversizedRecordsSkipped=1, got %d", c.oversizedRecordsSkipped.Load())
	}
	c.mu.Lock()
	processed := c.processedFiles[path]
	c.mu.Unlock()
	if !processed {
		t.Error("expected the file to still be marked processed — an oversized single record doesn't invalidate the rest of the file")
	}
	if len(captured) != 2 {
		t.Fatalf("expected the 2 small findings to be forwarded, got %d: %+v", len(captured), captured)
	}
}

func TestScanOnceMalformedFileDoesNotBlockSubsequentValidFile(t *testing.T) {
	dir := t.TempDir()
	malformedPath := filepath.Join(dir, "a-malformed.json")
	validPath := filepath.Join(dir, "b-valid.json")
	if err := os.WriteFile(malformedPath, []byte("not json at all { { {"), 0o644); err != nil {
		t.Fatalf("write malformed fixture: %v", err)
	}
	writeSampleFindingsFile(t, validPath)

	var forwardedCount int
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		forwardedCount++
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 100)
	c.scanOnce()

	if forwardedCount != 1 {
		t.Errorf("expected the valid file to still be forwarded despite the malformed file in the same scan, got %d forward calls", forwardedCount)
	}
	c.mu.Lock()
	validProcessed := c.processedFiles[validPath]
	c.mu.Unlock()
	if !validProcessed {
		t.Error("expected the valid file to be marked processed")
	}
}

func TestProcessFileStableAcrossLargeMultiRecordFixture(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "large.json")
	const n = 3000
	writeMultiFindingFile(t, path, n)

	var totalForwarded int
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var batch []map[string]any
		_ = json.NewDecoder(r.Body).Decode(&batch)
		totalForwarded += len(batch)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer server.Close()

	c := newDeliveryTestConnector(t, dir, server.URL, 200)
	c.maxFileBytes = 50 * 1024 * 1024
	c.maxExpandedBytes = 50 * 1024 * 1024
	c.maxRecordBytes = 1024 * 1024
	c.processFile(path)

	if c.filesQuarantined.Load() != 0 {
		t.Errorf("expected no quarantine for a well-formed large fixture within limits, got %d", c.filesQuarantined.Load())
	}
	if totalForwarded != n {
		t.Fatalf("expected all %d findings forwarded across batches, got %d", n, totalForwarded)
	}
	c.mu.Lock()
	processed := c.processedFiles[path]
	c.mu.Unlock()
	if !processed {
		t.Error("expected the large file to be marked processed after full delivery")
	}
}
