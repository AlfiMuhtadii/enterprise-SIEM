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
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"detector-xdr-ingestion-gateway/internal/kafkanative"
	"detector-xdr-ingestion-gateway/internal/mtls"
	"detector-xdr-ingestion-gateway/internal/otlpexport"
	"detector-xdr-ingestion-gateway/internal/traceparent"
)

var httpClient = &http.Client{
	Transport: &http.Transport{
		MaxIdleConns:          100,
		MaxIdleConnsPerHost:   10,
		IdleConnTimeout:       90 * time.Second,
		ResponseHeaderTimeout: 10 * time.Second,
	},
}

// tenantBucket is a per-tenant token-bucket rate limiter (IG-2), refilled
// lazily via an atomic time-delta CAS loop rather than a background ticker
// (PERF-GO-LIMITER) — refill happens inline on the request path that needs
// it, so idle tenants cost nothing and no goroutine touches every live
// bucket once a second regardless of demand.
// lastSeen tracks the last request time for TTL-based eviction (IG-DOS fix).
type tenantBucket struct {
	rps        int64
	tokens     atomic.Int64
	lastRefill atomic.Int64 // UnixNano of the last successful refill
	lastSeen   atomic.Int64 // UnixNano — updated on every tenantAllowed() call
}

func newTenantBucket(rps int) *tenantBucket {
	b := &tenantBucket{rps: int64(rps)}
	now := time.Now().UnixNano()
	b.tokens.Store(int64(rps))
	b.lastRefill.Store(now)
	b.lastSeen.Store(now)
	return b
}

// refill lazily tops up tokens based on elapsed time since the last refill.
// The CAS on lastRefill ensures concurrent callers never double-count the
// same elapsed window; a losing CAS just retries against the fresher value.
func (b *tenantBucket) refill(now int64) {
	for {
		last := b.lastRefill.Load()
		elapsed := now - last
		add := elapsed * b.rps / int64(time.Second)
		if add <= 0 {
			return
		}
		if !b.lastRefill.CompareAndSwap(last, last+add*int64(time.Second)/b.rps) {
			continue
		}
		for {
			cur := b.tokens.Load()
			next := cur + add
			if next > b.rps {
				next = b.rps
			}
			if b.tokens.CompareAndSwap(cur, next) {
				return
			}
		}
	}
}

// allow attempts to consume a single token, refilling first. Lock-free.
func (b *tenantBucket) allow() bool {
	now := time.Now().UnixNano()
	b.lastSeen.Store(now)
	b.refill(now)
	for {
		cur := b.tokens.Load()
		if cur <= 0 {
			return false
		}
		if b.tokens.CompareAndSwap(cur, cur-1) {
			return true
		}
	}
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
	// IG-HMAC-REPLAY: sigv2 (ts+body) tolerance window and whether legacy
	// body-only signatures (no X-XDR-Timestamp header) are still accepted.
	sigTimestampToleranceSecs int64
	sigV2Required             bool
	// CONN-UNTENANTED-INGEST: strict-mode rejection of unattributed batches.
	requireTenant bool
	// OBS-OTEL-TRACING: OTLP/HTTP span export, disabled unless an endpoint
	// is configured. nil-safe (Export is a no-op on a nil *Exporter).
	otelExporter *otlpexport.Exporter
	// core counters
	requests      atomic.Int64
	accepted      atomic.Int64
	rejected      atomic.Int64
	publishErrors atomic.Int64
	retryCount    atomic.Int64
	// ARCH-KAFKA-NATIVE: nil unless XDR_KAFKA_TRANSPORT=native.
	nativeProducer *kafkanative.Producer
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
		sigTimestampToleranceSecs: int64(envInt("XDR_INGEST_SIG_TOLERANCE_SECONDS", 300)),
		sigV2Required:             envBool("XDR_INGEST_SIGV2_REQUIRED", false),
		requireTenant:             envBool("XDR_INGEST_REQUIRE_TENANT", false),
		otelExporter: &otlpexport.Exporter{
			Endpoint:    env("XDR_OTEL_EXPORTER_ENDPOINT", ""),
			ServiceName: "ingestion-gateway",
		},
	}

	// ARCH-KAFKA-NATIVE: off by default (XDR_KAFKA_TRANSPORT=pandaproxy) --
	// zero behavior change unless an operator opts in.
	if env("XDR_KAFKA_TRANSPORT", "pandaproxy") == "native" {
		brokers := splitBrokers(env("XDR_REDPANDA_KAFKA_BROKERS", "redpanda:9092"))
		producer, err := kafkanative.NewProducer(brokers)
		if err != nil {
			log.Fatalf("xdr ingestion gateway: native kafka producer init failed: %v", err)
		}
		gw.nativeProducer = producer
		log.Printf("xdr ingestion gateway: using native kafka transport brokers=%v", brokers)
	}

	// IG-1: start background goroutine to poll normalizer metrics
	if gw.normalizerMetricsURL != "" {
		gw.startMetricsPoller(time.Duration(metricsPollSecs) * time.Second)
	}
	// IG-2: start background goroutine to refill per-tenant token buckets
	gw.startTenantBucketRefiller()

	// ENT-SEC-NO-TLS-INTERNAL (phase 1): internal mTLS, disabled by default.
	// When enabled, this server requires and verifies a client certificate on
	// every inbound connection, and the shared httpClient (used for the
	// normalizer-worker metrics poll) presents its own client certificate.
	// Generate dev/test certs via scripts/xdr_generate_internal_mtls_certs.py.
	mtlsEnabled := envBool("XDR_INTERNAL_MTLS_ENABLED", false)
	caFile := env("XDR_INTERNAL_MTLS_CA", "")
	serverTLSCfg, err := mtls.ServerConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_SERVER_CERT", ""),
		env("XDR_INTERNAL_MTLS_SERVER_KEY", ""),
		caFile,
	)
	if err != nil {
		log.Fatalf("xdr ingestion gateway: internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		caFile,
	)
	if err != nil {
		log.Fatalf("xdr ingestion gateway: internal mTLS client config error: %v", err)
	}
	if clientTLSCfg != nil {
		if t, ok := httpClient.Transport.(*http.Transport); ok {
			t.TLSClientConfig = clientTLSCfg
		}
	}

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
		TLSConfig:         serverTLSCfg,
	}
	go func() {
		stop := make(chan os.Signal, 1)
		signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
		<-stop
		log.Printf("xdr ingestion gateway shutting down gracefully")
		// GO-GRACEFUL-SHUTDOWN: drain in-flight ingest requests instead of dropping them.
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("xdr ingestion gateway shutdown error: %v", err)
		}
	}()
	validateStartupSecrets(gw.secret)
	log.Printf("xdr ingestion gateway listening on %s topic=%s redpanda=%s internal_mtls=%v", *addr, gw.topic, gw.redpandaREST, mtlsEnabled)
	var serveErr error
	if serverTLSCfg != nil {
		// Cert/key are already loaded into server.TLSConfig.Certificates by
		// mtls.ServerConfig, so both filename args are intentionally empty.
		serveErr = server.ListenAndServeTLS("", "")
	} else {
		serveErr = server.ListenAndServe()
	}
	if serveErr != nil && serveErr != http.ErrServerClosed {
		log.Fatal(serveErr)
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

// startTenantBucketRefiller evicts per-tenant buckets that have been idle
// longer than XDR_TENANT_LIMITER_IDLE_MINUTES (IG-DOS fix). Token refill
// itself no longer happens here (PERF-GO-LIMITER): tenantBucket.allow()
// refills lazily via an atomic time-delta CAS loop on the request path, so
// this goroutine only needs to run the (much cheaper, much less frequent)
// idle sweep, not touch every live bucket's tokens once a second.
func (g *Gateway) startTenantBucketRefiller() {
	idleMinutes := envInt("XDR_TENANT_LIMITER_IDLE_MINUTES", 30)
	idleTTL := time.Duration(idleMinutes) * time.Minute
	go func() {
		ticker := time.NewTicker(time.Minute)
		defer ticker.Stop()
		for range ticker.C {
			now := time.Now()
			g.tenantLimiters.Range(func(k, v any) bool {
				b := v.(*tenantBucket)
				if now.Sub(time.Unix(0, b.lastSeen.Load())) > idleTTL {
					g.tenantLimiters.Delete(k)
					g.tenantBucketCount.Add(-1) // RATE-LIMIT-DOS: keep the cap counter accurate
				}
				return true
			})
		}
	}()
}

// tenantAllowed checks per-tenant rate limit (IG-2).
// Returns false if this tenant's bucket is empty (throttle this tenant).
func (g *Gateway) tenantAllowed(tenantID string) bool {
	if tenantID == "" {
		return true
	}
	return g.limiterFor(tenantID).allow()
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
	if err := verifySignature(g.secret, body, r.Header.Get("X-XDR-Signature"), r.Header.Get("X-XDR-Timestamp"), g.sigTimestampToleranceSecs, g.sigV2Required); err != nil {
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
	// CONN-UNTENANTED-INGEST: in strict mode, an unattributed batch (no
	// tenant_id in the payload, no X-Tenant-ID header) is rejected outright
	// rather than silently accepted — tenantAllowed("") intentionally treats
	// an empty tenant as "unrestricted" for rate-limiting purposes, which is
	// a separate concern from whether the batch should be admitted at all.
	if g.requireTenant && effectiveTenantID == "" {
		g.reject(w, "tenant_required", http.StatusBadRequest)
		return
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
//
// OBS-OTEL-TRACING: one OTLP span is emitted per event (not per batch) —
// each event carries its own trace-id via traceparent, so a batch mixing
// events from several distinct traces must produce one span per trace, not
// a single span that would misrepresent all of them as one trace. All
// spans in a batch share the same start/end (the true wall-clock window of
// this publish() call, retries included), batched into a single OTLP
// export request. Export happens in a goroutine so an unreachable/slow
// collector never adds latency to the actual telemetry publish path.
func (g *Gateway) publish(events []map[string]any) (err error) {
	// IG-3: circuit breaker fast-fail when Redpanda is persistently down
	if !g.cb.allow() {
		return fmt.Errorf("circuit_open")
	}

	start := time.Now()
	records := make([]map[string]any, 0, len(events))
	values := make([][]byte, 0, len(events))
	spans := make([]otlpexport.Span, 0, len(events))
	for _, event := range events {
		if tid, _ := event["trace_id"].(string); tid == "" {
			event["trace_id"] = newTraceID()
		}
		inboundTP, _ := event["traceparent"].(string)
		outboundTP := traceparent.Propagate(inboundTP)
		event["traceparent"] = outboundTP
		if g.nativeProducer != nil {
			b, marshalErr := json.Marshal(event)
			if marshalErr != nil {
				return fmt.Errorf("kafkanative: marshal event: %w", marshalErr)
			}
			values = append(values, b)
		} else {
			records = append(records, map[string]any{"value": event})
		}

		if parsed, parseErr := traceparent.Parse(outboundTP); parseErr == nil {
			span := otlpexport.Span{
				TraceID: parsed.TraceID,
				SpanID:  parsed.SpanID,
				Name:    "ingestion-gateway.publish",
				Kind:    otlpexport.SpanKindProducer,
			}
			if inboundParsed, inErr := traceparent.Parse(inboundTP); inErr == nil {
				span.ParentSpanID = inboundParsed.SpanID
			}
			spans = append(spans, span)
		}
	}
	defer func() {
		end := time.Now()
		for i := range spans {
			spans[i].Start = start
			spans[i].End = end
		}
		exporter := g.otelExporter
		go func() { _ = exporter.Export(spans) }()
	}()

	if g.nativeProducer != nil {
		return g.publishNative(values)
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

// publishNative mirrors publish()'s REST retry loop (same backoff schedule,
// same circuit-breaker recordSuccess/recordFailure calls, same retryCount
// metric) over a native Kafka producer instead of an HTTP POST.
func (g *Gateway) publishNative(values [][]byte) error {
	baseDelay := 100 * time.Millisecond
	maxDelay := time.Second
	perAttemptTimeout := time.Duration(g.publishTimeoutSecs) * time.Second

	var lastErr error
	for attempt := 0; attempt < g.maxPublishRetries; attempt++ {
		if attempt > 0 {
			backoff := baseDelay * (1 << uint(attempt-1))
			if backoff > maxDelay {
				backoff = maxDelay
			}
			time.Sleep(backoff)
			g.retryCount.Add(1)
		}

		ctx, cancel := context.WithTimeout(context.Background(), perAttemptTimeout)
		err := g.nativeProducer.Publish(ctx, g.topic, values)
		cancel()

		if err == nil {
			g.cb.recordSuccess()
			return nil
		}
		lastErr = err
	}

	g.cb.recordFailure()
	return lastErr
}

// splitBrokers parses a comma-separated broker list, trimming whitespace
// and dropping empty entries (e.g. a trailing comma).
func splitBrokers(raw string) []string {
	parts := strings.Split(raw, ",")
	out := make([]string, 0, len(parts))
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			out = append(out, p)
		}
	}
	return out
}

// verifySignature checks the X-XDR-Signature header against the request body.
//
// IG-HMAC-REPLAY: when the sender includes an X-XDR-Timestamp header, the
// signature covers "ts.body" (sigv2) and the timestamp must fall within
// toleranceSecs of the gateway's clock — bounding how long a captured
// (signature, timestamp, body) tuple remains replayable, instead of forever.
// Senders that don't yet send a timestamp fall back to the legacy body-only
// signature (sigv1), unless sigV2Required forces the tolerance-bounded path.
func verifySignature(secret string, body []byte, signature string, timestamp string, toleranceSecs int64, sigV2Required bool) error {
	if secret == "" {
		return nil
	}
	if timestamp != "" {
		ts, err := strconv.ParseInt(timestamp, 10, 64)
		if err != nil {
			return errors.New("invalid_timestamp")
		}
		delta := time.Now().Unix() - ts
		if delta < 0 {
			delta = -delta
		}
		if toleranceSecs > 0 && delta > toleranceSecs {
			return errors.New("stale_timestamp")
		}
		mac := hmac.New(sha256.New, []byte(secret))
		mac.Write([]byte(timestamp))
		mac.Write([]byte("."))
		mac.Write(body)
		expected := "sha256=" + hex.EncodeToString(mac.Sum(nil))
		if !hmac.Equal([]byte(expected), []byte(signature)) {
			return errors.New("invalid_signature")
		}
		return nil
	}
	if sigV2Required {
		return errors.New("timestamp_required")
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

func envBool(name string, fallback bool) bool {
	value := os.Getenv(name)
	if value == "" {
		return fallback
	}
	return value == "1" || value == "true" || value == "yes"
}

// shouldRefuseIngestSecret reports whether the gateway must refuse to start
// (IG-HMAC-FAIL-OPEN): in enforced/production posture, an empty or dev-default
// XDR_INGEST_SECRET would silently disable HMAC verification and accept every
// unsigned ingest request, so it must be a hard failure rather than a warning.
func shouldRefuseIngestSecret(ingestSecret string, enforced bool) bool {
	return enforced && (ingestSecret == "" || ingestSecret == "dev-secret-change-me")
}

func validateStartupSecrets(ingestSecret string) {
	enforced := envBool("XDR_ENFORCE_INTERNAL_AUTH", false)
	if shouldRefuseIngestSecret(ingestSecret, enforced) {
		log.Fatalf("[SECURITY-FATAL] XDR_ENFORCE_INTERNAL_AUTH=true but XDR_INGEST_SECRET is empty or using the dev default — refusing to start")
	}
	if ingestSecret == "" {
		log.Printf("[SECURITY-WARN] XDR_INGEST_SECRET is not set — HMAC auth is disabled, all ingest requests accepted")
	} else if ingestSecret == "dev-secret-change-me" {
		log.Printf("[SECURITY-WARN] XDR_INGEST_SECRET is using dev default — replace with a strong secret in production")
	}
	if env("XDR_INTERNAL_AUTH_SECRET", "") == "" {
		log.Printf("[SECURITY-WARN] XDR_INTERNAL_AUTH_SECRET is not set — internal service token auth uses fallback")
	}
}
