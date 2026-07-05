package main

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"strconv"
	"sync/atomic"
	"testing"
	"time"
)

// newTestGateway builds a Gateway with test-friendly defaults.
// secret="" disables HMAC so tests can POST without computing a signature.
func newTestGateway(redpandaURL string) *Gateway {
	return &Gateway{
		secret:               "",
		redpandaREST:         redpandaURL,
		topic:                "telemetry.raw",
		maxBatchSize:         100,
		normalizerMetricsURL: "",
		maxNormalizerQueue:   100_000,
		perTenantRPS:         10,
		cb:                   newCircuitBreaker(5, 30*time.Second),
		maxPublishRetries:    2,
		publishTimeoutSecs:   5,
	}
}

// postIngest calls gw.ingest directly without going through the mux/middleware.
func postIngest(gw *Gateway, body []byte, tenantID string) *httptest.ResponseRecorder {
	req := httptest.NewRequest(http.MethodPost, "/v1/ingest", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	if tenantID != "" {
		req.Header.Set("X-Tenant-ID", tenantID)
	}
	rr := httptest.NewRecorder()
	gw.ingest(rr, req)
	return rr
}

// ---------------------------------------------------------------------------
// Requirement 1: admissionAllowed must read cached state, never network I/O
// ---------------------------------------------------------------------------

func TestAdmissionAllowedNeverCallsNetwork(t *testing.T) {
	var callCount atomic.Int64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount.Add(1)
		json.NewEncoder(w).Encode(map[string]any{"queue_depth": 50000.0})
	}))
	defer srv.Close()

	gw := &Gateway{
		normalizerMetricsURL: srv.URL,
		maxNormalizerQueue:   100_000,
		// poller NOT started — normalizerQueueDepth stays 0
	}
	before := callCount.Load()
	for i := 0; i < 200; i++ {
		gw.admissionAllowed()
	}
	if got := callCount.Load(); got != before {
		t.Errorf("admissionAllowed made %d HTTP calls; expected 0 — must read cached atomic only", got-before)
	}
}

func TestAdmissionAllowedQueueFullDenied(t *testing.T) {
	gw := &Gateway{
		normalizerMetricsURL: "http://127.0.0.1:19998/metrics",
		maxNormalizerQueue:   1000,
	}
	gw.normalizerQueueDepth.Store(1001)
	allowed, reason := gw.admissionAllowed()
	if allowed {
		t.Error("expected admission denied when queue exceeds limit")
	}
	if reason != "normalizer_queue_full" {
		t.Errorf("unexpected reason %q; want normalizer_queue_full", reason)
	}
}

func TestAdmissionAllowedNoMetricsURLAlwaysAllows(t *testing.T) {
	gw := &Gateway{
		normalizerMetricsURL: "", // no admission control configured
		maxNormalizerQueue:   0,
	}
	gw.normalizerQueueDepth.Store(999_999) // even high depth ignored when URL is empty
	allowed, reason := gw.admissionAllowed()
	if !allowed {
		t.Errorf("expected allowed when no metrics URL; got denied: %q", reason)
	}
}

// ---------------------------------------------------------------------------
// Requirement 2: stale/unavailable metrics endpoint must not block handler
// ---------------------------------------------------------------------------

func TestIngestHandlerNotBlockedByStaleMetrics(t *testing.T) {
	// Fake Redpanda: accepts and acks immediately.
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer fakePanda.Close()

	gw := &Gateway{
		secret:               "",
		redpandaREST:         fakePanda.URL,
		topic:                "telemetry.raw",
		maxBatchSize:         100,
		normalizerMetricsURL: "http://127.0.0.1:19997/metrics", // unreachable — poller would fail
		maxNormalizerQueue:   100_000,
		perTenantRPS:         50,
		cb:                   newCircuitBreaker(5, 30*time.Second),
		maxPublishRetries:    1,
		publishTimeoutSecs:   5,
		// normalizerQueueDepth=0 (default) → admissionAllowed returns true immediately
	}

	payload, _ := json.Marshal([]map[string]any{{"event_type": "login", "ts": "2026-06-24T00:00:00Z"}})
	start := time.Now()
	rr := postIngest(gw, payload, "")
	elapsed := time.Since(start)

	if rr.Code != http.StatusAccepted {
		t.Errorf("expected 202, got %d: %s", rr.Code, rr.Body.String())
	}
	// Handler must not block on metrics fetch — admissionAllowed only reads the atomic.
	if elapsed > 500*time.Millisecond {
		t.Errorf("ingest handler took %v; stale metrics URL must not cause blocking", elapsed)
	}
}

// ---------------------------------------------------------------------------
// Requirement 3: per-tenant rate limiting must not starve other tenants
// ---------------------------------------------------------------------------

func TestTenantRateLimitIsolation(t *testing.T) {
	gw := &Gateway{perTenantRPS: 3}

	// Drain tenant-A's bucket completely beyond capacity.
	for i := 0; i < 20; i++ {
		gw.tenantAllowed("tenant-A")
	}
	// tenant-A must now be throttled.
	if gw.tenantAllowed("tenant-A") {
		t.Error("tenant-A should be rate-limited after bucket exhaustion")
	}
	// tenant-B has its own bucket and must be unaffected.
	if !gw.tenantAllowed("tenant-B") {
		t.Error("tenant-B must not be blocked by tenant-A exhaustion")
	}
	// tenant-C (brand new) must also be immediately allowed.
	if !gw.tenantAllowed("tenant-C") {
		t.Error("tenant-C (new tenant) must not be blocked by tenant-A exhaustion")
	}
}

func TestTenantBucketCapBoundsDistinctBuckets(t *testing.T) {
	// RATE-LIMIT-DOS: with a cap of 2, flooding 100 distinct tenant IDs must never
	// allocate more than 2 per-tenant buckets.
	gw := &Gateway{perTenantRPS: 5, maxTenantBuckets: 2}
	for i := 0; i < 100; i++ {
		gw.tenantAllowed(fmt.Sprintf("flood-tenant-%d", i))
	}
	if got := gw.tenantBucketCount.Load(); got > 2 {
		t.Errorf("tenant bucket count = %d, must not exceed cap of 2", got)
	}
}

func TestTenantBucketCapDisabledWhenZero(t *testing.T) {
	// maxTenantBuckets = 0 preserves legacy unbounded behavior.
	gw := &Gateway{perTenantRPS: 5, maxTenantBuckets: 0}
	for i := 0; i < 10; i++ {
		gw.tenantAllowed(fmt.Sprintf("t-%d", i))
	}
	if got := gw.tenantBucketCount.Load(); got != 10 {
		t.Errorf("with cap disabled, expected 10 distinct buckets, got %d", got)
	}
}

func TestExistingTenantKeepsOwnBucketBeyondCap(t *testing.T) {
	gw := &Gateway{perTenantRPS: 3, maxTenantBuckets: 1}
	// tenant-A registers first and owns the only capped slot.
	gw.tenantAllowed("tenant-A")
	// Flood past the cap.
	for i := 0; i < 20; i++ {
		gw.tenantAllowed(fmt.Sprintf("other-%d", i))
	}
	// tenant-A must still resolve to its OWN bucket (present in the map), not overflow.
	if _, ok := gw.tenantLimiters.Load("tenant-A"); !ok {
		t.Error("existing tenant-A lost its dedicated bucket after the cap was reached")
	}
}

func TestOverflowTenantsShareRateLimit(t *testing.T) {
	gw := &Gateway{perTenantRPS: 3, maxTenantBuckets: 1}
	gw.tenantAllowed("owner") // consumes the single capped slot
	// Drain the shared overflow bucket via one overflow tenant.
	for i := 0; i < 3; i++ {
		gw.tenantAllowed("overflow-1")
	}
	// A different overflow tenant shares the (now drained) bucket → must be throttled.
	if gw.tenantAllowed("overflow-2") {
		t.Error("overflow tenants must share a single rate-limited bucket")
	}
}

func TestTenantRateLimitEmptyIDIsUnrestricted(t *testing.T) {
	gw := &Gateway{perTenantRPS: 1}
	// Without a tenant ID, tenantAllowed must always return true (no per-IP fallback in handler).
	for i := 0; i < 100; i++ {
		if !gw.tenantAllowed("") {
			t.Errorf("tenantAllowed with empty ID returned false on iteration %d", i)
		}
	}
}

func TestIngestHandlerTenantRateLimited(t *testing.T) {
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer fakePanda.Close()

	gw := newTestGateway(fakePanda.URL)
	gw.perTenantRPS = 1 // very low cap to trigger throttle quickly

	payload, _ := json.Marshal([]map[string]any{{"event_type": "test", "ts": "2026-06-24T00:00:00Z"}})

	// First request for tenant-X: should consume the token and succeed.
	rr1 := postIngest(gw, payload, "tenant-X")
	if rr1.Code != http.StatusAccepted {
		t.Fatalf("first request should succeed, got %d", rr1.Code)
	}
	// Second request from tenant-X immediately after: bucket empty → 429.
	rr2 := postIngest(gw, payload, "tenant-X")
	if rr2.Code != http.StatusTooManyRequests {
		t.Errorf("second request should be rate-limited (429), got %d", rr2.Code)
	}
	// Concurrent request from tenant-Y must still succeed.
	rr3 := postIngest(gw, payload, "tenant-Y")
	if rr3.Code != http.StatusAccepted {
		t.Errorf("tenant-Y must not be affected by tenant-X throttle, got %d: %s", rr3.Code, rr3.Body.String())
	}
}

// ---------------------------------------------------------------------------
// Requirement 4: publish timeout must be bounded — no 15s × retries blocking
// ---------------------------------------------------------------------------

func TestPublishBoundedTimeout(t *testing.T) {
	// unblock is closed during cleanup so the handler goroutine can exit before
	// srv.Close() tries to drain active connections (r.Context().Done() is not
	// reliably fired on Windows when the client-side context times out).
	unblock := make(chan struct{})
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-unblock:
		case <-time.After(60 * time.Second): // absolute safety fallback
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer func() {
		close(unblock)             // unblock any handler goroutines still running
		srv.CloseClientConnections() // force-close TCP connections
		srv.Close()
	}()

	gw := &Gateway{
		redpandaREST:       srv.URL,
		topic:              "test.raw",
		cb:                 newCircuitBreaker(10, 30*time.Second),
		maxPublishRetries:  1, // single attempt — no backoff added
		publishTimeoutSecs: 1, // 1-second per-attempt timeout
	}

	events := []map[string]any{{"event_type": "test", "ts": "2026-06-24T00:00:00Z"}}
	start := time.Now()
	err := gw.publish(events)
	elapsed := time.Since(start)

	if err == nil {
		t.Error("expected publish to fail against hanging server")
	}
	// Must not take more than 3× the configured timeout (generous bound for CI variance).
	if elapsed >= 3*time.Second {
		t.Errorf("publish took %v; expected < 3s with publishTimeoutSecs=1 and maxPublishRetries=1", elapsed)
	}
}

// ---------------------------------------------------------------------------
// Requirement 5: existing safe synthetic ingestion path still works
// ---------------------------------------------------------------------------

func TestIngestHandlerSyntheticPath(t *testing.T) {
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]any{
			"offsets": []map[string]any{{"partition": 0, "offset": 1}},
		})
	}))
	defer fakePanda.Close()

	gw := newTestGateway(fakePanda.URL)

	events := []map[string]any{
		{"event_type": "login_success", "telemetry_type": "identity", "ts": "2026-06-24T00:00:00Z", "user": "alice@example.com"},
		{"event_type": "file_access", "telemetry_type": "endpoint", "ts": "2026-06-24T00:00:01Z", "host": "ws-001"},
	}
	body, _ := json.Marshal(events)
	rr := postIngest(gw, body, "")

	if rr.Code != http.StatusAccepted {
		t.Fatalf("expected 202, got %d: %s", rr.Code, rr.Body.String())
	}
	var resp map[string]any
	if err := json.NewDecoder(rr.Body).Decode(&resp); err != nil {
		t.Fatalf("response is not valid JSON: %v", err)
	}
	if accepted, ok := resp["accepted"].(float64); !ok || int(accepted) != len(events) {
		t.Errorf("expected accepted=%d, got %v", len(events), resp["accepted"])
	}
	if _, ok := resp["latency_ms"]; !ok {
		t.Error("response missing latency_ms field")
	}
}

func TestIngestHandlerInvalidJSON(t *testing.T) {
	gw := newTestGateway("http://127.0.0.1:19996")
	req := httptest.NewRequest(http.MethodPost, "/v1/ingest", bytes.NewReader([]byte("not-json{")))
	rr := httptest.NewRecorder()
	gw.ingest(rr, req)
	if rr.Code != http.StatusBadRequest {
		t.Errorf("expected 400 for invalid JSON, got %d", rr.Code)
	}
}

func TestIngestHandlerBatchTooLarge(t *testing.T) {
	gw := newTestGateway("http://127.0.0.1:19996")
	gw.maxBatchSize = 2

	events := make([]map[string]any, 3)
	for i := range events {
		events[i] = map[string]any{"event_type": "test"}
	}
	body, _ := json.Marshal(events)
	rr := postIngest(gw, body, "")
	if rr.Code != http.StatusBadRequest {
		t.Errorf("expected 400 for oversized batch, got %d", rr.Code)
	}
}

// ---------------------------------------------------------------------------
// Circuit breaker unit tests
// ---------------------------------------------------------------------------

func TestCircuitBreakerAllowsWhenClosed(t *testing.T) {
	cb := newCircuitBreaker(3, 30*time.Second)
	if !cb.allow() {
		t.Error("new circuit breaker should be closed (allow=true)")
	}
}

func TestCircuitBreakerOpensAfterMaxFailures(t *testing.T) {
	failSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer failSrv.Close()

	gw := &Gateway{
		redpandaREST:       failSrv.URL,
		topic:              "test.raw",
		cb:                 newCircuitBreaker(2, 30*time.Second),
		maxPublishRetries:  1,
		publishTimeoutSecs: 5,
	}
	events := []map[string]any{{"event_type": "test"}}

	// Trigger maxFailures consecutive failures.
	for i := 0; i < 2; i++ {
		_ = gw.publish(events)
	}
	// Circuit must be open now.
	if gw.cb.allow() {
		t.Error("circuit breaker should be open after maxFailures consecutive publish errors")
	}
}

func TestCircuitBreakerFastFailWhenOpen(t *testing.T) {
	var serverCalls atomic.Int64
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		serverCalls.Add(1)
		w.WriteHeader(http.StatusOK)
	}))
	defer srv.Close()

	gw := &Gateway{
		redpandaREST:       srv.URL,
		topic:              "test.raw",
		cb:                 newCircuitBreaker(1, 30*time.Second),
		maxPublishRetries:  1,
		publishTimeoutSecs: 5,
	}
	// Manually force the circuit open.
	gw.cb.openUntil = time.Now().Add(30 * time.Second)

	events := []map[string]any{{"event_type": "test"}}
	beforeCalls := serverCalls.Load()
	start := time.Now()
	err := gw.publish(events)
	elapsed := time.Since(start)

	if err == nil {
		t.Error("expected circuit_open error")
	}
	if err.Error() != "circuit_open" {
		t.Errorf("unexpected error: %v; want circuit_open", err)
	}
	// Must not have contacted the server.
	if got := serverCalls.Load(); got != beforeCalls {
		t.Errorf("circuit open made %d server calls; expected 0", got-beforeCalls)
	}
	// Must return nearly instantly (no network I/O).
	if elapsed > 10*time.Millisecond {
		t.Errorf("circuit-open fast-fail took %v; expected < 10ms", elapsed)
	}
}

// ---------------------------------------------------------------------------
// RATE-LIMIT-BYPASS: X-Tenant-ID header must match payload tenant_id
// ---------------------------------------------------------------------------

func TestExtractPayloadTenantIDReturnsFirstFound(t *testing.T) {
	events := []map[string]any{
		{"event_type": "login", "tenant_id": "tenant-abc"},
		{"event_type": "access", "tenant_id": "tenant-xyz"},
	}
	got := extractPayloadTenantID(events)
	if got != "tenant-abc" {
		t.Errorf("expected tenant-abc, got %q", got)
	}
}

func TestExtractPayloadTenantIDReturnsEmptyWhenNone(t *testing.T) {
	events := []map[string]any{
		{"event_type": "login"},
	}
	got := extractPayloadTenantID(events)
	if got != "" {
		t.Errorf("expected empty string, got %q", got)
	}
}

func TestExtractPayloadTenantIDSkipsEmptyString(t *testing.T) {
	events := []map[string]any{
		{"event_type": "login", "tenant_id": ""},
		{"event_type": "access", "tenant_id": "tenant-xyz"},
	}
	got := extractPayloadTenantID(events)
	if got != "tenant-xyz" {
		t.Errorf("expected tenant-xyz, got %q", got)
	}
}

func TestIngestHandlerRejectsTenantIDMismatch(t *testing.T) {
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer fakePanda.Close()

	gw := newTestGateway(fakePanda.URL)

	events := []map[string]any{
		{"event_type": "login", "tenant_id": "tenant-actual"},
	}
	body, _ := json.Marshal(events)

	// Header claims a different tenant than the payload — must be rejected.
	req := httptest.NewRequest(http.MethodPost, "/v1/ingest", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Tenant-ID", "tenant-spoofed")
	rr := httptest.NewRecorder()
	gw.ingest(rr, req)

	if rr.Code != http.StatusBadRequest {
		t.Errorf("expected 400 on tenant_id mismatch, got %d: %s", rr.Code, rr.Body.String())
	}
}

func TestIngestHandlerAllowsWhenHeaderMatchesPayload(t *testing.T) {
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]any{"offsets": []any{}})
	}))
	defer fakePanda.Close()

	gw := newTestGateway(fakePanda.URL)

	events := []map[string]any{
		{"event_type": "login", "tenant_id": "tenant-abc"},
	}
	body, _ := json.Marshal(events)

	rr := postIngest(gw, body, "tenant-abc")
	if rr.Code != http.StatusAccepted {
		t.Errorf("expected 202 when header matches payload, got %d: %s", rr.Code, rr.Body.String())
	}
}

func TestIngestHandlerAllowsWhenNoHeaderSet(t *testing.T) {
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]any{"offsets": []any{}})
	}))
	defer fakePanda.Close()

	gw := newTestGateway(fakePanda.URL)

	events := []map[string]any{
		{"event_type": "login", "tenant_id": "tenant-abc"},
	}
	body, _ := json.Marshal(events)

	// No X-Tenant-ID header — should be allowed (payload tenant is used directly).
	rr := postIngest(gw, body, "")
	if rr.Code != http.StatusAccepted {
		t.Errorf("expected 202 when no header set, got %d: %s", rr.Code, rr.Body.String())
	}
}

func TestIngestHandlerAllowsWhenNoPayloadTenantID(t *testing.T) {
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]any{"offsets": []any{}})
	}))
	defer fakePanda.Close()

	gw := newTestGateway(fakePanda.URL)

	// Events without tenant_id — header is used for rate limiting, no mismatch possible.
	events := []map[string]any{{"event_type": "login"}}
	body, _ := json.Marshal(events)
	rr := postIngest(gw, body, "tenant-header-only")
	if rr.Code != http.StatusAccepted {
		t.Errorf("expected 202 when payload has no tenant_id, got %d: %s", rr.Code, rr.Body.String())
	}
}

func TestIngestHandlerUsesPayloadTenantForRateLimit(t *testing.T) {
	fakePanda := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusOK)
	}))
	defer fakePanda.Close()

	gw := newTestGateway(fakePanda.URL)
	gw.perTenantRPS = 1

	events := []map[string]any{{"event_type": "login", "tenant_id": "payload-tenant"}}
	body, _ := json.Marshal(events)

	// First request: 202
	rr1 := postIngest(gw, body, "payload-tenant")
	if rr1.Code != http.StatusAccepted {
		t.Fatalf("first request should succeed, got %d", rr1.Code)
	}
	// Second request same tenant: 429 (bucket exhausted)
	rr2 := postIngest(gw, body, "payload-tenant")
	if rr2.Code != http.StatusTooManyRequests {
		t.Errorf("second request should be rate-limited, got %d", rr2.Code)
	}
}

// IG-HMAC-FAIL-OPEN: in enforced posture, an empty or dev-default ingest secret
// must be a hard failure, not a silent fail-open warning.

func TestShouldRefuseIngestSecretEnforcedEmpty(t *testing.T) {
	if !shouldRefuseIngestSecret("", true) {
		t.Error("expected refusal when enforced=true and secret is empty")
	}
}

func TestShouldRefuseIngestSecretEnforcedDevDefault(t *testing.T) {
	if !shouldRefuseIngestSecret("dev-secret-change-me", true) {
		t.Error("expected refusal when enforced=true and secret is the dev default")
	}
}

func TestShouldRefuseIngestSecretEnforcedStrongSecret(t *testing.T) {
	if shouldRefuseIngestSecret("a-strong-random-secret", true) {
		t.Error("must not refuse when enforced=true and a real secret is configured")
	}
}

func TestShouldRefuseIngestSecretPermissiveEmpty(t *testing.T) {
	if shouldRefuseIngestSecret("", false) {
		t.Error("must not refuse when enforced=false, even with an empty secret (permissive/demo posture)")
	}
}

func TestShouldRefuseIngestSecretPermissiveDevDefault(t *testing.T) {
	if shouldRefuseIngestSecret("dev-secret-change-me", false) {
		t.Error("must not refuse when enforced=false, even with the dev-default secret")
	}
}

// IG-HMAC-REPLAY: sigv2 (ts+body) signature verification with a tolerance window.

func signV2(secret, timestamp string, body []byte) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(timestamp))
	mac.Write([]byte("."))
	mac.Write(body)
	return "sha256=" + hex.EncodeToString(mac.Sum(nil))
}

func TestVerifySignatureNoSecretAlwaysPasses(t *testing.T) {
	if err := verifySignature("", []byte("body"), "garbage", "", 300, false); err != nil {
		t.Errorf("empty secret must disable verification, got error: %v", err)
	}
}

func TestVerifySignatureLegacyV1StillAccepted(t *testing.T) {
	body := []byte(`{"a":1}`)
	mac := hmac.New(sha256.New, []byte("secret"))
	mac.Write(body)
	sigV1 := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	if err := verifySignature("secret", body, sigV1, "", 300, false); err != nil {
		t.Errorf("legacy v1 body-only signature should be accepted when sigV2Required=false, got: %v", err)
	}
}

func TestVerifySignatureV1RejectedWhenV2Required(t *testing.T) {
	body := []byte(`{"a":1}`)
	mac := hmac.New(sha256.New, []byte("secret"))
	mac.Write(body)
	sigV1 := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	if err := verifySignature("secret", body, sigV1, "", 300, true); err == nil {
		t.Error("legacy v1 signature (no timestamp) must be rejected when sigV2Required=true")
	}
}

func TestVerifySignatureV2ValidWithinTolerance(t *testing.T) {
	body := []byte(`{"a":1}`)
	ts := strconv.FormatInt(time.Now().Unix(), 10)
	sig := signV2("secret", ts, body)
	if err := verifySignature("secret", body, sig, ts, 300, false); err != nil {
		t.Errorf("valid sigv2 within tolerance should be accepted, got: %v", err)
	}
}

func TestVerifySignatureV2RejectsStaleTimestamp(t *testing.T) {
	body := []byte(`{"a":1}`)
	staleTs := strconv.FormatInt(time.Now().Add(-10*time.Minute).Unix(), 10)
	sig := signV2("secret", staleTs, body)
	if err := verifySignature("secret", body, sig, staleTs, 300, false); err == nil {
		t.Error("a timestamp older than the tolerance window must be rejected (replay protection)")
	}
}

func TestVerifySignatureV2RejectsFutureTimestamp(t *testing.T) {
	body := []byte(`{"a":1}`)
	futureTs := strconv.FormatInt(time.Now().Add(10*time.Minute).Unix(), 10)
	sig := signV2("secret", futureTs, body)
	if err := verifySignature("secret", body, sig, futureTs, 300, false); err == nil {
		t.Error("a timestamp far in the future must be rejected too")
	}
}

func TestVerifySignatureV2RejectsWrongSignature(t *testing.T) {
	body := []byte(`{"a":1}`)
	ts := strconv.FormatInt(time.Now().Unix(), 10)
	if err := verifySignature("secret", body, "sha256=deadbeef", ts, 300, false); err == nil {
		t.Error("an incorrect sigv2 signature must be rejected")
	}
}

func TestVerifySignatureV2RejectsMalformedTimestamp(t *testing.T) {
	body := []byte(`{"a":1}`)
	sig := signV2("secret", "not-a-number", body)
	if err := verifySignature("secret", body, sig, "not-a-number", 300, false); err == nil {
		t.Error("a non-numeric X-XDR-Timestamp must be rejected")
	}
}
