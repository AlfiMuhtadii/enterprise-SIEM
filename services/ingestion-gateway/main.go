package main

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"os/signal"
	"strconv"
	"sync"
	"sync/atomic"
	"syscall"
	"time"
)

var httpClient = &http.Client{
	Transport: &http.Transport{
		MaxIdleConns:          100,
		MaxIdleConnsPerHost:   10,
		IdleConnTimeout:       90 * time.Second,
		ResponseHeaderTimeout: 10 * time.Second,
	},
}

// tenantBucket is a per-tenant token-bucket rate limiter (IG-2).
// lastSeen tracks the last request time for TTL-based eviction (IG-DOS fix).
type tenantBucket struct {
	tokens   chan struct{}
	rps      int
	lastSeen atomic.Int64 // UnixNano — updated on every tenantAllowed() call
}

func newTenantBucket(rps int) *tenantBucket {
	ch := make(chan struct{}, rps)
	for i := 0; i < rps; i++ {
		ch <- struct{}{}
	}
	b := &tenantBucket{tokens: ch, rps: rps}
	b.lastSeen.Store(time.Now().UnixNano())
	return b
}

// circuitBreaker protects the Kafka publish path from socket exhaustion (IG-3).
// After maxFailures consecutive publish errors the circuit opens for openDuration.
type circuitBreaker struct {
	mu          sync.Mutex
	failures    int
	maxFailures int
	openUntil   time.Time
	openDuration time.Duration
}

func newCircuitBreaker(maxFailures int, openDuration time.Duration) *circuitBreaker {
	return &circuitBreaker{maxFailures: maxFailures, openDuration: openDuration}
}

func (cb *circuitBreaker) allow() bool {
	cb.mu.Lock()
	defer cb.mu.Unlock()
	return time.Now().After(cb.openUntil)
}

func (cb *circuitBreaker) recordSuccess() {
	cb.mu.Lock()
	cb.failures = 0
	cb.mu.Unlock()
}

func (cb *circuitBreaker) recordFailure() {
	cb.mu.Lock()
	defer cb.mu.Unlock()
	cb.failures++
	if cb.failures >= cb.maxFailures {
		cb.openUntil = time.Now().Add(cb.openDuration)
		cb.failures = 0
		log.Printf("[CIRCUIT-BREAKER] publish circuit opened for %s after %d consecutive failures",
			cb.openDuration, cb.maxFailures)
	}
}

type Gateway struct {
	secret               string
	redpandaREST         string
	topic                string
	maxBatchSize         int
	normalizerMetricsURL string
	maxNormalizerQueue   int64
	// IG-1: cached normalizer queue depth, updated by background goroutine
	normalizerQueueDepth atomic.Int64
	// IG-2: per-tenant rate limiter (map[tenantID → *tenantBucket])
	tenantLimiters sync.Map
	perTenantRPS   int
	// RATE-LIMIT-DOS: bound the number of distinct per-tenant buckets so an
	// authenticated client cannot exhaust memory by flooding distinct tenant IDs.
	// When >= maxTenantBuckets distinct tenants are active, additional new tenants
	// share a single overflow bucket instead of each allocating their own.
	// maxTenantBuckets <= 0 disables the cap (unbounded, legacy behavior).
	maxTenantBuckets  int
	tenantBucketCount atomic.Int64
	overflowBucket    atomic.Pointer[tenantBucket]
	// IG-3: bounded retry + circuit breaker on publish path
	cb                *circuitBreaker
	maxPublishRetries int
	publishTimeoutSecs int
	// core counters
	requests      atomic.Int64
	accepted      atomic.Int64
	rejected      atomic.Int64
	publishErrors atomic.Int64
	retryCount    atomic.Int64
}

func main() {
	addr := flag.String("addr", env("XDR_INGEST_ADDR", ":8091"), "listen address")
	flag.Parse()

	cbMaxFailures := envInt("XDR_PUBLISH_CB_FAILURES", 5)
	cbOpenSecs := envInt("XDR_PUBLISH_CB_OPEN_SECONDS", 30)
	publishTimeoutSecs := envInt("XDR_PUBLISH_TIMEOUT_SECONDS", 5)
	maxRetries := envInt("XDR_PUBLISH_MAX_RETRIES", 3)
	perTenantRPS := envInt("XDR_INGEST_PER_TENANT_RPS", envInt("XDR_INGEST_RPS", 50))
	maxTenantBuckets := envInt("XDR_INGEST_MAX_TENANT_BUCKETS", 10000)
	metricsPollSecs := envInt("XDR_NORMALIZER_METRICS_POLL_INTERVAL_SECONDS", 5)

	gw := &Gateway{
		secret:             env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		redpandaREST:       env("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082"),
		topic:              env("XDR_RAW_TOPIC", "telemetry.raw"),
		maxBatchSize:       envInt("XDR_MAX_BATCH_SIZE", 1000),
		normalizerMetricsURL: env("XDR_NORMALIZER_METRICS_URL", ""),
		maxNormalizerQueue: int64(envInt("XDR_MAX_NORMALIZER_QUEUE_DEPTH", 150000)),
		perTenantRPS:       perTenantRPS,
		maxTenantBuckets:   maxTenantBuckets,
		cb:                 newCircuitBreaker(cbMaxFailures, time.Duration(cbOpenSecs)*time.Second),
		maxPublishRetries:  maxRetries,
		publishTimeoutSecs: publishTimeoutSecs,
	}

	// IG-1: start background goroutine to poll normalizer metrics
	if gw.normalizerMetricsURL != "" {
		gw.startMetricsPoller(time.Duration(metricsPollSecs) * time.Second)
	}
	// IG-2: start background goroutine to refill per-tenant token buckets
	gw.startTenantBucketRefiller()

	mux := http.NewServeMux()
	mux.HandleFunc("/health", gw.health)
	mux.HandleFunc("/ready", gw.ready)
	mux.HandleFunc("/metrics", gw.metrics)
	mux.HandleFunc("/v1/ingest", gw.ingest)

	server := &http.Server{
		Addr:              *addr,
		Handler:           rateLimit(mux, envInt("XDR_INGEST_RPS", 50)),
		ReadHeaderTimeout: 10 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      30 * time.Second,
		IdleTimeout:       120 * time.Second,
	}
	go func() {
		stop := make(chan os.Signal, 1)
		signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
		<-stop
		log.Printf("xdr ingestion gateway shutting down gracefully")
		_ = server.Close()
	}()
	validateStartupSecrets(gw.secret)
	log.Printf("xdr ingestion gateway listening on %s topic=%s redpanda=%s", *addr, gw.topic, gw.redpandaREST)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatal(err)
	}
}

// startMetricsPoller runs a background goroutine that polls the normalizer metrics
// endpoint and updates the cached normalizerQueueDepth (IG-1).
// The ingest handler reads the cached value instead of polling synchronously.
func (g *Gateway) startMetricsPoller(interval time.Duration) {
	go func() {
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for range ticker.C {
			ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
			req, err := http.NewRequestWithContext(ctx, http.MethodGet, g.normalizerMetricsURL, nil)
			if err != nil {
				cancel()
				continue
			}
			resp, err := httpClient.Do(req)
			cancel()
			if err != nil || resp == nil {
				continue
			}
			var m map[string]any
			if decErr := json.NewDecoder(resp.Body).Decode(&m); decErr == nil {
				if qd, ok := m["queue_depth"].(float64); ok {
					g.normalizerQueueDepth.Store(int64(qd))
				}
			}
			_ = resp.Body.Close()
		}
	}()
}

// startTenantBucketRefiller refills per-tenant token buckets every second (IG-2)
// and evicts buckets that have been idle longer than XDR_TENANT_LIMITER_IDLE_MINUTES (IG-DOS fix).
func (g *Gateway) startTenantBucketRefiller() {
	idleMinutes := envInt("XDR_TENANT_LIMITER_IDLE_MINUTES", 30)
	idleTTL := time.Duration(idleMinutes) * time.Minute
	go func() {
		ticker := time.NewTicker(time.Second)
		defer ticker.Stop()
		for range ticker.C {
			now := time.Now()
			// RATE-LIMIT-DOS: refill the shared overflow bucket too (it is not in the map).
			if ob := g.overflowBucket.Load(); ob != nil {
				for len(ob.tokens) < ob.rps {
					select {
					case ob.tokens <- struct{}{}:
					default:
					}
				}
			}
			g.tenantLimiters.Range(func(k, v any) bool {
				b := v.(*tenantBucket)
				if now.Sub(time.Unix(0, b.lastSeen.Load())) > idleTTL {
					g.tenantLimiters.Delete(k)
					g.tenantBucketCount.Add(-1) // RATE-LIMIT-DOS: keep the cap counter accurate
					return true
				}
				for len(b.tokens) < b.rps {
					select {
					case b.tokens <- struct{}{}:
					default:
					}
				}
				return true
			})
		}
	}()
}

// tenantAllowed checks per-tenant rate limit (IG-2).
// Returns false if this tenant's bucket is empty (throttle this tenant).
// Updates lastSeen on every call so the eviction goroutine can remove idle buckets (IG-DOS fix).
func (g *Gateway) tenantAllowed(tenantID string) bool {
	if tenantID == "" {
		return true
	}
	b := g.limiterFor(tenantID)
	b.lastSeen.Store(time.Now().UnixNano())
	select {
	case <-b.tokens:
		return true
	default:
		return false
	}
}

// limiterFor returns the token bucket for a tenant (RATE-LIMIT-DOS).
// Existing tenants always keep their own bucket. New tenants get a fresh bucket
// until the distinct-bucket cap is reached, after which they share the overflow
// bucket — bounding memory without rejecting legitimate multi-tenant traffic.
func (g *Gateway) limiterFor(tenantID string) *tenantBucket {
	if v, ok := g.tenantLimiters.Load(tenantID); ok {
		return v.(*tenantBucket)
	}
	if g.maxTenantBuckets > 0 && g.tenantBucketCount.Load() >= int64(g.maxTenantBuckets) {
		return g.getOverflowBucket()
	}
	actual, loaded := g.tenantLimiters.LoadOrStore(tenantID, newTenantBucket(g.perTenantRPS))
	if !loaded {
		g.tenantBucketCount.Add(1)
	}
	return actual.(*tenantBucket)
}

// getOverflowBucket lazily creates the single shared overflow bucket (RATE-LIMIT-DOS).
func (g *Gateway) getOverflowBucket() *tenantBucket {
	if ob := g.overflowBucket.Load(); ob != nil {
		return ob
	}
	nb := newTenantBucket(g.perTenantRPS)
	if g.overflowBucket.CompareAndSwap(nil, nb) {
		return nb
	}
	return g.overflowBucket.Load()
}

func (g *Gateway) health(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "ingestion-gateway", "topic": g.topic})
}

func (g *Gateway) ready(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status": "ready", "service": "ingestion-gateway"})
}

func (g *Gateway) metrics(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{
		"requests":               g.requests.Load(),
		"accepted":               g.accepted.Load(),
		"rejected":               g.rejected.Load(),
		"publish_errors":         g.publishErrors.Load(),
		"retry_count":            g.retryCount.Load(),
		"normalizer_queue_depth": g.normalizerQueueDepth.Load(),
		"tenant_bucket_count":    g.tenantBucketCount.Load(),
		"tenant_bucket_max":      g.maxTenantBuckets,
	})
}

func (g *Gateway) ingest(w http.ResponseWriter, r *http.Request) {
	started := time.Now()
	g.requests.Add(1)
	if r.Method != http.MethodPost {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 8*1024*1024))
	if err != nil {
		g.reject(w, "read_failed", http.StatusBadRequest)
		return
	}
	if err := verifySignature(g.secret, body, r.Header.Get("X-XDR-Signature")); err != nil {
		g.reject(w, err.Error(), http.StatusUnauthorized)
		return
	}
	// Parse events before rate limiting so we can validate X-Tenant-ID against payload.
	var events []map[string]any
	if err := json.Unmarshal(body, &events); err != nil {
		var one map[string]any
		if oneErr := json.Unmarshal(body, &one); oneErr != nil {
			g.reject(w, "invalid_json", http.StatusBadRequest)
			return
		}
		events = []map[string]any{one}
	}
	if len(events) == 0 || len(events) > g.maxBatchSize {
		g.reject(w, "invalid_batch_size", http.StatusBadRequest)
		return
	}
	// IG-2: per-tenant rate limit.
	// RATE-LIMIT-BYPASS fix: derive effective tenant from payload first; X-Tenant-ID header
	// is advisory only. If header and payload disagree, reject — prevents a caller from
	// setting a victim tenant's header to exhaust their rate-limit bucket.
	headerTenantID := r.Header.Get("X-Tenant-ID")
	payloadTenantID := extractPayloadTenantID(events)
	if headerTenantID != "" && payloadTenantID != "" && headerTenantID != payloadTenantID {
		g.reject(w, "tenant_id_header_mismatch", http.StatusBadRequest)
		return
	}
	effectiveTenantID := payloadTenantID
	if effectiveTenantID == "" {
		effectiveTenantID = headerTenantID
	}
	if !g.tenantAllowed(effectiveTenantID) {
		g.reject(w, "tenant_rate_limited", http.StatusTooManyRequests)
		return
	}
	if allowed, reason := g.admissionAllowed(); !allowed {
		g.reject(w, reason, http.StatusTooManyRequests)
		return
	}
	if err := g.publish(events); err != nil {
		g.publishErrors.Add(1)
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	g.accepted.Add(int64(len(events)))
	writeJSON(w, http.StatusAccepted, map[string]any{
		"accepted":   len(events),
		"latency_ms": time.Since(started).Milliseconds(),
	})
}

// extractPayloadTenantID returns the first non-empty tenant_id found in a parsed event batch.
// Returns "" when no event carries a tenant_id field.
func extractPayloadTenantID(events []map[string]any) string {
	for _, e := range events {
		if v, ok := e["tenant_id"]; ok {
			if s, ok := v.(string); ok && s != "" {
				return s
			}
		}
	}
	return ""
}

// admissionAllowed reads the cached normalizer queue depth (IG-1).
// No synchronous HTTP call in the request path.
func (g *Gateway) admissionAllowed() (bool, string) {
	if g.normalizerMetricsURL == "" {
		return true, ""
	}
	queueDepth := g.normalizerQueueDepth.Load()
	if queueDepth >= g.maxNormalizerQueue {
		return false, "normalizer_queue_full"
	}
	return true, ""
}

func newTraceID() string {
	b := make([]byte, 16)
	_, _ = rand.Read(b)
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80
	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

// publish sends events to Redpanda with bounded retry + exponential backoff
// and circuit breaker protection (IG-3).
func (g *Gateway) publish(events []map[string]any) error {
	// IG-3: circuit breaker fast-fail when Redpanda is persistently down
	if !g.cb.allow() {
		return fmt.Errorf("circuit_open")
	}

	records := make([]map[string]any, 0, len(events))
	for _, event := range events {
		if tid, _ := event["trace_id"].(string); tid == "" {
			event["trace_id"] = newTraceID()
		}
		records = append(records, map[string]any{"value": event})
	}
	payload, _ := json.Marshal(map[string]any{"records": records})
	url := fmt.Sprintf("%s/topics/%s", g.redpandaREST, g.topic)

	baseDelay := 100 * time.Millisecond
	maxDelay := time.Second
	perAttemptTimeout := time.Duration(g.publishTimeoutSecs) * time.Second

	var lastErr error
	for attempt := 0; attempt < g.maxPublishRetries; attempt++ {
		if attempt > 0 {
			// IG-3: exponential backoff (100ms, 200ms, 400ms, …, capped at 1s)
			backoff := baseDelay * (1 << uint(attempt-1))
			if backoff > maxDelay {
				backoff = maxDelay
			}
			time.Sleep(backoff)
			g.retryCount.Add(1)
		}

		ctx, cancel := context.WithTimeout(context.Background(), perAttemptTimeout)
		req, reqErr := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewReader(payload))
		if reqErr != nil {
			cancel()
			lastErr = reqErr
			continue
		}
		req.Header.Set("Content-Type", "application/vnd.kafka.json.v2+json")
		req.Header.Set("Accept", "application/vnd.kafka.v2+json")

		resp, err := httpClient.Do(req)
		cancel()

		if err == nil && resp != nil && resp.StatusCode >= 200 && resp.StatusCode < 300 {
			_ = resp.Body.Close()
			g.cb.recordSuccess()
			return nil
		}
		if resp != nil {
			_ = resp.Body.Close()
			lastErr = fmt.Errorf("redpanda status=%d", resp.StatusCode)
		} else {
			lastErr = err
		}
	}

	g.cb.recordFailure()
	return lastErr
}

func verifySignature(secret string, body []byte, signature string) error {
	if secret == "" {
		return nil
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write(body)
	expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return errors.New("invalid_signature")
	}
	return nil
}

func rateLimit(next http.Handler, rps int) http.Handler {
	if rps <= 0 {
		return next
	}
	tokens := make(chan struct{}, rps)
	for i := 0; i < rps; i++ {
		tokens <- struct{}{}
	}
	go func() {
		ticker := time.NewTicker(time.Second)
		for range ticker.C {
			for len(tokens) < cap(tokens) {
				tokens <- struct{}{}
			}
		}
	}()
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-tokens:
			next.ServeHTTP(w, r)
		default:
			http.Error(w, "rate_limited", http.StatusTooManyRequests)
		}
	})
}

func (g *Gateway) reject(w http.ResponseWriter, reason string, status int) {
	g.rejected.Add(1)
	writeJSON(w, status, map[string]any{"error": reason})
}

func writeJSON(w http.ResponseWriter, status int, value any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(value)
}

func env(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}
	return fallback
}

func envInt(name string, fallback int) int {
	value, err := strconv.Atoi(env(name, ""))
	if err != nil {
		return fallback
	}
	return value
}

func validateStartupSecrets(ingestSecret string) {
	if ingestSecret == "" {
		log.Printf("[SECURITY-WARN] XDR_INGEST_SECRET is not set — HMAC auth is disabled, all ingest requests accepted")
	} else if ingestSecret == "dev-secret-change-me" {
		log.Printf("[SECURITY-WARN] XDR_INGEST_SECRET is using dev default — replace with a strong secret in production")
	}
	if env("XDR_INTERNAL_AUTH_SECRET", "") == "" {
		log.Printf("[SECURITY-WARN] XDR_INTERNAL_AUTH_SECRET is not set — internal service token auth uses fallback")
	}
}
