package main

import (
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
	"sync/atomic"
	"testing"
	"time"

	"detector-xdr-log-connector-o365/internal/o365"
)

func TestMapRecordToEventPromotesCommonFields(t *testing.T) {
	rec := o365.AuditRecord{
		ID:             "rec-1",
		CreationTime:   "2026-07-11T10:00:00",
		Operation:      "UserLoggedIn",
		OrganizationID: "org-1",
		Workload:       "AzureActiveDirectory",
		UserID:         "alice@contoso.com",
		ClientIP:       "203.0.113.5",
		ResultStatus:   "Success",
		Raw:            map[string]any{"Id": "rec-1"},
	}

	event := mapRecordToEvent(rec, "tenant-a")

	if event["telemetry_type"] != "o365_audit" {
		t.Fatalf("expected telemetry_type=o365_audit, got %v", event["telemetry_type"])
	}
	if event["event_type"] != "UserLoggedIn" || event["action"] != "UserLoggedIn" {
		t.Fatalf("expected event_type/action from Operation, got %v/%v", event["event_type"], event["action"])
	}
	if event["event_source"] != "AzureActiveDirectory" {
		t.Fatalf("expected event_source from Workload, got %v", event["event_source"])
	}
	if event["user"] != "alice@contoso.com" {
		t.Fatalf("expected user from UserID, got %v", event["user"])
	}
	if event["source_ip"] != "203.0.113.5" {
		t.Fatalf("expected source_ip from ClientIP, got %v", event["source_ip"])
	}
	if event["result"] != "success" {
		t.Fatalf("expected result=success (lowercased), got %v", event["result"])
	}
	if event["cloud_account"] != "org-1" {
		t.Fatalf("expected cloud_account from OrganizationID, got %v", event["cloud_account"])
	}
	if event["tenant_id"] != "tenant-a" {
		t.Fatalf("expected tenant_id preserved, got %v", event["tenant_id"])
	}
	raw, ok := event["o365_record"].(map[string]any)
	if !ok || raw["Id"] != "rec-1" {
		t.Fatalf("expected full raw record preserved, got %v", event["o365_record"])
	}
}

func TestMapRecordToEventDefaultsResultToSuccessWhenEmpty(t *testing.T) {
	event := mapRecordToEvent(o365.AuditRecord{ResultStatus: ""}, "")
	if event["result"] != "success" {
		t.Fatalf("expected result=success default, got %v", event["result"])
	}
}

func TestMapRecordToEventOmitsTenantIdWhenEmpty(t *testing.T) {
	event := mapRecordToEvent(o365.AuditRecord{}, "")
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
	rec := o365.AuditRecord{ID: "rec-1"}
	eventA := mapRecordToEvent(rec, "tenant-a")
	eventB := mapRecordToEvent(rec, "tenant-b")

	if eventA["tenant_id"] != "tenant-a" {
		t.Errorf("expected tenant-a's event to carry tenant_id=tenant-a, got %v", eventA["tenant_id"])
	}
	if eventB["tenant_id"] != "tenant-b" {
		t.Errorf("expected tenant-b's event to carry tenant_id=tenant-b, got %v", eventB["tenant_id"])
	}
}

// ---------------------------------------------------------------------------
// End-to-end poll/fetch/deliver tests against a mock O365 API + mock gateway.
// ---------------------------------------------------------------------------

// mockO365Server serves the token endpoint, the content-listing endpoint
// (for exactly one content type, "Audit.General"), and the content-blob
// fetch endpoint, all from one httptest.Server so contentUri can point back
// at itself.
type mockO365Server struct {
	*httptest.Server
	mux            *http.ServeMux
	listCallCount  int32
	fetchCallCount int32
	// contentIDs lists the content pointers the listing endpoint returns —
	// mutable per-test via listContentIDs.
	contentIDs []string
	// blobs maps a content ID to the raw JSON blob its fetch endpoint returns.
	blobs map[string]string
}

func newMockO365Server(t *testing.T) *mockO365Server {
	t.Helper()
	m := &mockO365Server{blobs: map[string]string{}}
	mux := http.NewServeMux()
	mux.HandleFunc("/token", func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"access_token":"tok","expires_in":3600}`))
	})
	mux.HandleFunc("/api/v1.0/test-tenant/activity/feed/subscriptions/content", func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&m.listCallCount, 1)
		pointers := make([]map[string]string, 0, len(m.contentIDs))
		for _, id := range m.contentIDs {
			pointers = append(pointers, map[string]string{
				"contentUri":  m.Server.URL + "/blob/" + id,
				"contentId":   id,
				"contentType": "Audit.General",
			})
		}
		_ = json.NewEncoder(w).Encode(pointers)
	})
	mux.HandleFunc("/blob/", func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&m.fetchCallCount, 1)
		id := r.URL.Path[len("/blob/"):]
		blob, ok := m.blobs[id]
		if !ok {
			w.WriteHeader(http.StatusNotFound)
			return
		}
		_, _ = w.Write([]byte(blob))
	})
	m.mux = mux
	m.Server = httptest.NewServer(mux)
	m.contentIDs = nil
	return m
}

func newTestConnector(t *testing.T, mockAPI *mockO365Server, ingestURL string) *Connector {
	t.Helper()
	dir := t.TempDir()
	return &Connector{
		ingestURL:  ingestURL,
		secret:     "s",
		batchSize:  100,
		httpClient: &http.Client{Timeout: 5 * time.Second},
		client: &o365.Client{
			BaseURL:  mockAPI.Server.URL,
			TenantID: "test-tenant",
			Tokens: &o365.TokenSource{
				TokenURL:     mockAPI.Server.URL + "/token",
				ClientID:     "c",
				ClientSecret: "s",
				Resource:     mockAPI.Server.URL,
			},
		},
		contentTypes:      []string{"Audit.General"},
		processedContent:  map[string]bool{},
		stateFile:         filepath.Join(dir, ".state.json"),
		forwardMaxRetries: 3,
		forwardRetryBase:  time.Millisecond,
		forwardRetryMax:   5 * time.Millisecond,
	}
}

func TestPollOnceProcessesNewContentAndMarksProcessed(t *testing.T) {
	mockAPI := newMockO365Server(t)
	defer mockAPI.Close()
	mockAPI.contentIDs = []string{"content-1"}
	mockAPI.blobs["content-1"] = `[{"Id":"rec-1","Operation":"UserLoggedIn"}]`

	var forwardedCount int
	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		forwardedCount++
		w.WriteHeader(http.StatusAccepted)
	}))
	defer gateway.Close()

	c := newTestConnector(t, mockAPI, gateway.URL)
	c.pollOnce()

	if forwardedCount != 1 {
		t.Fatalf("expected exactly 1 forward request, got %d", forwardedCount)
	}
	c.mu.Lock()
	processed := c.processedContent["content-1"]
	c.mu.Unlock()
	if !processed {
		t.Error("expected content-1 to be marked processed")
	}
	if c.recordsParsed.Load() != 1 {
		t.Errorf("expected recordsParsed=1, got %d", c.recordsParsed.Load())
	}
}

func TestPollOnceSkipsAlreadyProcessedContentOnRescan(t *testing.T) {
	mockAPI := newMockO365Server(t)
	defer mockAPI.Close()
	mockAPI.contentIDs = []string{"content-1"}
	mockAPI.blobs["content-1"] = `[{"Id":"rec-1","Operation":"UserLoggedIn"}]`

	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusAccepted)
	}))
	defer gateway.Close()

	c := newTestConnector(t, mockAPI, gateway.URL)
	c.pollOnce()
	if atomic.LoadInt32(&mockAPI.fetchCallCount) != 1 {
		t.Fatalf("expected 1 fetch on first poll, got %d", mockAPI.fetchCallCount)
	}

	c.pollOnce() // second poll: same content ID returned by the list endpoint again
	if atomic.LoadInt32(&mockAPI.fetchCallCount) != 1 {
		t.Errorf("expected no re-fetch of already-processed content, got %d total fetches", mockAPI.fetchCallCount)
	}
	if c.contentSkipped.Load() != 1 {
		t.Errorf("expected contentSkipped=1, got %d", c.contentSkipped.Load())
	}
}

func TestProcessContentLeavesContentUnprocessedWhenGatewayUnavailable(t *testing.T) {
	mockAPI := newMockO365Server(t)
	defer mockAPI.Close()
	mockAPI.contentIDs = []string{"content-1"}
	mockAPI.blobs["content-1"] = `[{"Id":"rec-1","Operation":"UserLoggedIn"}]`

	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		hj, _ := w.(http.Hijacker)
		conn, _, _ := hj.Hijack()
		_ = conn.Close()
	}))
	defer gateway.Close()

	c := newTestConnector(t, mockAPI, gateway.URL)
	c.pollOnce()

	c.mu.Lock()
	processed := c.processedContent["content-1"]
	c.mu.Unlock()
	if processed {
		t.Error("expected content to be left unprocessed after exhausting retries against an unavailable gateway")
	}
	if c.deliveryFailedContent.Load() != 1 {
		t.Errorf("expected deliveryFailedContent=1, got %d", c.deliveryFailedContent.Load())
	}
	if _, err := os.Stat(c.stateFile); err == nil {
		t.Error("expected no state file to have been written for unacknowledged content")
	}
}

func TestProcessContentRestartRetriesAnUnacknowledgedContent(t *testing.T) {
	mockAPI := newMockO365Server(t)
	defer mockAPI.Close()
	mockAPI.contentIDs = []string{"content-1"}
	mockAPI.blobs["content-1"] = `[{"Id":"rec-1","Operation":"UserLoggedIn"}]`

	var attempts int32
	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&attempts, 1)
		w.WriteHeader(http.StatusServiceUnavailable)
	}))
	defer gateway.Close()

	c1 := newTestConnector(t, mockAPI, gateway.URL)
	c1.pollOnce()

	c2 := newTestConnector(t, mockAPI, gateway.URL)
	c2.stateFile = c1.stateFile
	c2.loadState()
	c2.pollOnce()

	if got := atomic.LoadInt32(&attempts); got < 2 {
		t.Errorf("expected the restarted connector to retry the content, got %d total attempts", got)
	}
	if c2.contentSkipped.Load() != 0 {
		t.Errorf("expected the unacknowledged content to be re-fetched, not skipped, got contentSkipped=%d", c2.contentSkipped.Load())
	}
}

func TestProcessContentQuarantinesOversizedContentBlob(t *testing.T) {
	mockAPI := newMockO365Server(t)
	defer mockAPI.Close()
	mockAPI.contentIDs = []string{"content-huge"}
	mockAPI.blobs["content-huge"] = `[{"Id":"rec-1","Operation":"` + fmt.Sprintf("%010000d", 0) + `"}]`

	var forwardCalled bool
	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		forwardCalled = true
		w.WriteHeader(http.StatusAccepted)
	}))
	defer gateway.Close()

	c := newTestConnector(t, mockAPI, gateway.URL)
	c.maxContentBytes = 1000
	c.pollOnce()

	if forwardCalled {
		t.Error("forward must never be called for an oversized content blob")
	}
	if c.contentTooLarge.Load() != 1 {
		t.Fatalf("expected contentTooLarge=1, got %d", c.contentTooLarge.Load())
	}
	c.mu.Lock()
	processed := c.processedContent["content-huge"]
	c.mu.Unlock()
	if !processed {
		t.Error("expected the oversized content to be marked processed (not retried forever)")
	}
}

func TestProcessContentSkipsOversizedRecordButStillForwardsOthers(t *testing.T) {
	mockAPI := newMockO365Server(t)
	defer mockAPI.Close()
	mockAPI.contentIDs = []string{"content-mixed"}
	huge := ""
	for i := 0; i < 5000; i++ {
		huge += "x"
	}
	mockAPI.blobs["content-mixed"] = `[{"Id":"rec-small1","Operation":"A"},{"Id":"rec-huge","Operation":"B","Extra":"` + huge + `"},{"Id":"rec-small2","Operation":"C"}]`

	var captured []map[string]any
	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_ = json.NewDecoder(r.Body).Decode(&captured)
		w.WriteHeader(http.StatusAccepted)
	}))
	defer gateway.Close()

	c := newTestConnector(t, mockAPI, gateway.URL)
	c.maxRecordBytes = 500
	c.pollOnce()

	if c.oversizedRecordsSkipped.Load() != 1 {
		t.Fatalf("expected oversizedRecordsSkipped=1, got %d", c.oversizedRecordsSkipped.Load())
	}
	c.mu.Lock()
	processed := c.processedContent["content-mixed"]
	c.mu.Unlock()
	if !processed {
		t.Error("expected content to still be marked processed — an oversized single record doesn't invalidate the rest")
	}
	if len(captured) != 2 {
		t.Fatalf("expected the 2 small records to be forwarded, got %d: %+v", len(captured), captured)
	}
}

func TestPollOnceQueriesEveryConfiguredContentType(t *testing.T) {
	mockAPI := newMockO365Server(t)
	defer mockAPI.Close()
	mockAPI.contentIDs = nil // no content, just verifying the list call happens per type

	gateway := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusAccepted)
	}))
	defer gateway.Close()

	c := newTestConnector(t, mockAPI, gateway.URL)
	c.contentTypes = []string{"Audit.General", "Audit.General", "Audit.General"} // 3 distinct configured types (same mock handler regardless of name)
	c.pollOnce()

	if atomic.LoadInt32(&mockAPI.listCallCount) != 3 {
		t.Errorf("expected 1 list call per configured content type (3), got %d", mockAPI.listCallCount)
	}
}
