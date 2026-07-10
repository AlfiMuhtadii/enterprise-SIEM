package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	_ "net/http/pprof"
	"net/url"
	"os"
	"os/signal"
	"runtime"
	"runtime/debug"
	"sort"
	"strings"
	"sync/atomic"
	"syscall"
	"time"

	"detector-xdr-correlation-worker/internal/ioc"
	"detector-xdr-correlation-worker/internal/mtls"
	"detector-xdr-correlation-worker/internal/shadowrules"
	"detector-xdr-correlation-worker/internal/traceparent"
)

var httpClient = &http.Client{
	Timeout: 15 * time.Second,
	Transport: &http.Transport{
		MaxIdleConns:          100,
		MaxIdleConnsPerHost:   10,
		IdleConnTimeout:       90 * time.Second,
		ResponseHeaderTimeout: 10 * time.Second,
	},
}

type Event struct {
	Ts             string  `json:"ts"`
	EventID        string  `json:"event_id"`
	TelemetryType  string  `json:"telemetry_type"`
	EventType      string  `json:"event_type"`
	User           string  `json:"user"`
	Host           string  `json:"host"`
	SourceIP       string  `json:"source_ip"`
	DestinationIP  string  `json:"destination_ip"`
	Domain         string  `json:"domain"`
	FileHash       string  `json:"file_hash"`
	EmailSender    string  `json:"email_sender"`
	EmailRecipient string  `json:"email_recipient"`
	CloudAccount   string  `json:"cloud_account"`
	Action         string  `json:"action"`
	Result         string  `json:"result"`
	RiskScore      float64 `json:"risk_score"`
	EventSource    string  `json:"event_source"`
	TraceID        string  `json:"trace_id"`
	Traceparent    string  `json:"traceparent,omitempty"`
	// Demo lineage fields — injected by demo_feed.py, omitted in non-demo events.
	DemoRunID     string `json:"demo_run_id,omitempty"`
	SourceEventID string `json:"source_event_id,omitempty"`
	ScenarioID    string `json:"scenario_id,omitempty"`
	TenantID      string `json:"tenant_id,omitempty"`
}

type Alert struct {
	AlertID     string         `json:"alert_id"`
	AlertType   string         `json:"alert_type"`
	Actor       string         `json:"actor"`
	ActorKey    string         `json:"actor_key,omitempty"`
	Severity    string         `json:"severity"`
	Score       float64        `json:"score"`
	Domains     []string       `json:"domains"`
	EvidenceIDs []string       `json:"evidence_ids"`
	Evidence    map[string]any `json:"evidence,omitempty"`
	ShadowMode  bool           `json:"shadow_mode"`
	TraceID     string         `json:"trace_id,omitempty"`
	Traceparent string         `json:"traceparent,omitempty"`
	TenantID    string         `json:"tenant_id,omitempty"`
}

type Worker struct {
	redpandaREST             string
	inputTopic               string
	outputTopic              string
	dlqTopic                 string
	correlationFailedTopic   string
	shadowAlertsTopic        string
	networkShadowAlertsTopic string
	iocLookupURL             string
	group                    string
	scope                    string
	processed                atomic.Int64
	alerts                   atomic.Int64
	latencyMS                atomic.Int64
	published                atomic.Int64
	publishErrors            atomic.Int64
	consumerPolls            atomic.Int64
	consumerErrors           atomic.Int64
	reconnectCount           atomic.Int64
	pollErrorCount           atomic.Int64
	consumerRecreateCount    atomic.Int64
	retryCount               atomic.Int64
	shadowAlertsPublished    atomic.Int64
	dlqWritten               atomic.Int64
	dlqWriteErrors           atomic.Int64
}

func validateCorrelationSecrets() {
	enforced := envBool("XDR_ENFORCE_INTERNAL_AUTH", false)
	token := env("XDR_CORRELATION_INTERNAL_TOKEN", "")
	if enforced {
		if token == "" {
			log.Fatalf("[SECURITY-FATAL] XDR_ENFORCE_INTERNAL_AUTH=true but XDR_CORRELATION_INTERNAL_TOKEN is not set — refusing to start")
		}
		log.Printf("[SECURITY] correlation-worker: internal auth enforced — /v1/correlate requires X-Internal-Service-Token")
	} else {
		if token == "" {
			log.Printf("[SECURITY-WARN] XDR_ENFORCE_INTERNAL_AUTH not set — /v1/correlate has no token enforcement (unsafe for non-local deployments)")
		} else {
			log.Printf("[SECURITY-INFO] XDR_CORRELATION_INTERNAL_TOKEN set (permissive mode — set XDR_ENFORCE_INTERNAL_AUTH=true to enforce)")
		}
	}
}

func verifyCorrelationToken(token string) error {
	enforced := envBool("XDR_ENFORCE_INTERNAL_AUTH", false)
	expected := env("XDR_CORRELATION_INTERNAL_TOKEN", "")
	if enforced {
		if expected == "" {
			return fmt.Errorf("internal_auth_enforced_no_token_configured")
		}
		if token == "" {
			return fmt.Errorf("missing_token")
		}
		if token != expected {
			return fmt.Errorf("invalid_token")
		}
		return nil
	}
	if expected == "" {
		return nil
	}
	if token == "" {
		return fmt.Errorf("missing_token")
	}
	if token != expected {
		return fmt.Errorf("invalid_token")
	}
	return nil
}

func main() {
	addr := flag.String("addr", env("XDR_CORRELATION_ADDR", ":8093"), "listen address")
	flag.Parse()
	validateCorrelationSecrets()
	debug.SetGCPercent(envInt("XDR_CORRELATION_GOGC", 300))
	// PERF-GO-HOT-HTTP: size the IOC lookup cache from env before any lookups happen.
	ioc.Configure(
		time.Duration(envInt("XDR_IOC_CACHE_TTL_SECONDS", 60))*time.Second,
		envInt("XDR_IOC_CACHE_MAX", 10000),
	)
	w := &Worker{
		redpandaREST:             env("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082"),
		inputTopic:               env("XDR_NORMALIZED_TOPIC", "telemetry.normalized"),
		outputTopic:              env("XDR_ALERTS_TOPIC", "xdr.alerts"),
		dlqTopic:                 env("XDR_CORRELATION_DLQ_TOPIC", "xdr.alerts.dlq"),
		correlationFailedTopic:   env("XDR_CORRELATION_FAILED_TOPIC", "xdr.correlation_failed"),
		shadowAlertsTopic:        env("XDR_ENDPOINT_SHADOW_TOPIC", "xdr.alerts.shadow.endpoint"),
		networkShadowAlertsTopic: env("XDR_NETWORK_SHADOW_TOPIC", "xdr.alerts.shadow.network"),
		iocLookupURL:             env("XDR_IOC_LOOKUP_URL", ""),
		group:                    env("XDR_CORRELATION_GROUP", "correlation-worker-v1"),
		scope:                    env("XDR_CORRELATION_SCOPE", "identity-cloud"),
	}
	// ENT-SEC-NO-TLS-INTERNAL (phase 3): internal mTLS, disabled by default.
	// Same mechanism proven on ingestion-gateway (phase 1) and normalizer-worker
	// (phase 2) — see scripts/xdr_generate_internal_mtls_certs.py for dev/test certs.
	mtlsEnabled := envBool("XDR_INTERNAL_MTLS_ENABLED", false)
	caFile := env("XDR_INTERNAL_MTLS_CA", "")
	serverTLSCfg, err := mtls.ServerConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_SERVER_CERT", ""),
		env("XDR_INTERNAL_MTLS_SERVER_KEY", ""),
		caFile,
	)
	if err != nil {
		log.Fatalf("xdr correlation worker: internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		caFile,
	)
	if err != nil {
		log.Fatalf("xdr correlation worker: internal mTLS client config error: %v", err)
	}
	if clientTLSCfg != nil {
		if t, ok := httpClient.Transport.(*http.Transport); ok {
			t.TLSClientConfig = clientTLSCfg
		}
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", w.health)
	mux.HandleFunc("/ready", w.ready)
	mux.HandleFunc("/metrics", w.metrics)
	mux.HandleFunc("/v1/correlate", w.correlateHTTP)
	mux.HandleFunc("/v1/correlate-endpoint-shadow", w.correlateEndpointShadowHTTP)
	if envBool("XDR_CORRELATION_EVENT_LOOP_ENABLED", false) {
		go w.consumeLoop()
	}
	server := &http.Server{
		Addr:              *addr,
		Handler:           mux,
		ReadHeaderTimeout: time.Duration(envInt("XDR_CORRELATION_READ_HEADER_TIMEOUT_SEC", 10)) * time.Second,
		ReadTimeout:       time.Duration(envInt("XDR_CORRELATION_READ_TIMEOUT_SEC", 180)) * time.Second,
		WriteTimeout:      time.Duration(envInt("XDR_CORRELATION_WRITE_TIMEOUT_SEC", 180)) * time.Second,
		IdleTimeout:       time.Duration(envInt("XDR_CORRELATION_IDLE_TIMEOUT_SEC", 120)) * time.Second,
		MaxHeaderBytes:    envInt("XDR_CORRELATION_MAX_HEADER_BYTES", 1<<20),
		TLSConfig:         serverTLSCfg,
	}
	go func() {
		stop := make(chan os.Signal, 1)
		signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
		<-stop
		log.Printf("xdr correlation worker shutting down gracefully")
		// GO-GRACEFUL-SHUTDOWN: drain in-flight requests instead of dropping them.
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		if err := server.Shutdown(ctx); err != nil {
			log.Printf("xdr correlation worker shutdown error: %v", err)
		}
	}()
	log.Printf("xdr correlation worker listening on %s shadow_mode=true internal_mtls=%v", *addr, mtlsEnabled)
	var serveErr error
	if serverTLSCfg != nil {
		serveErr = server.ListenAndServeTLS("", "")
	} else {
		serveErr = server.ListenAndServe()
	}
	if serveErr != nil && serveErr != http.ErrServerClosed {
		log.Fatal(serveErr)
	}
}

func (w *Worker) health(rw http.ResponseWriter, r *http.Request) {
	writeJSON(rw, http.StatusOK, map[string]any{"status": "ok", "service": "xdr-correlation", "mode": "shadow", "keep_alive": true})
}

func (w *Worker) ready(rw http.ResponseWriter, r *http.Request) {
	writeJSON(rw, http.StatusOK, map[string]any{"status": "ready", "service": "xdr-correlation"})
}

func (w *Worker) metrics(rw http.ResponseWriter, r *http.Request) {
	var mem runtime.MemStats
	runtime.ReadMemStats(&mem)
	writeJSON(rw, http.StatusOK, map[string]any{
		"processed":                w.processed.Load(),
		"alerts":                   w.alerts.Load(),
		"last_latency_ms":          w.latencyMS.Load(),
		"published":                w.published.Load(),
		"publish_errors":           w.publishErrors.Load(),
		"consumer_polls":           w.consumerPolls.Load(),
		"consumer_errors":          w.consumerErrors.Load(),
		"reconnect_count":          w.reconnectCount.Load(),
		"poll_error_count":         w.pollErrorCount.Load(),
		"consumer_recreate_count":  w.consumerRecreateCount.Load(),
		"retry_count":              w.retryCount.Load(),
		"shadow_alerts_published":  w.shadowAlertsPublished.Load(),
		"ioc_lookup_total":         ioc.LookupTotal.Load(),
		"ioc_match_total":          ioc.MatchTotal.Load(),
		"ioc_cache_hits":           ioc.CacheHits.Load(),
		"dlq_written":              w.dlqWritten.Load(),
		"dlq_write_errors":         w.dlqWriteErrors.Load(),
		"input_topic":              w.inputTopic,
		"output_topic":             w.outputTopic,
		"correlation_failed_topic": w.correlationFailedTopic,
		"goroutines":               runtime.NumGoroutine(),
		"heap_alloc_mb":            float64(mem.HeapAlloc) / 1024.0 / 1024.0,
		"internal_auth_mode": func() string {
			if envBool("XDR_ENFORCE_INTERNAL_AUTH", false) {
				return "enforced"
			}
			return "permissive"
		}(),
	})
}

func (w *Worker) writeDLQRecord(record map[string]any) error {
	if err := w.publish(w.correlationFailedTopic, []map[string]any{record}); err != nil {
		w.dlqWriteErrors.Add(1)
		log.Printf("correlation dlq write failed topic=%s err=%v", w.correlationFailedTopic, err)
		return err
	}
	w.dlqWritten.Add(1)
	return nil
}

func (w *Worker) consumeLoop() {
	for {
		w.consumeOnce()
		w.reconnectCount.Add(1)
		log.Printf("correlation consumer reconnecting in 5s")
		time.Sleep(5 * time.Second)
	}
}

func (w *Worker) consumeOnce() {
	w.consumerRecreateCount.Add(1)
	instance := fmt.Sprintf("correlation-%d", time.Now().UnixNano())
	baseURI, err := w.consumerCreate(w.group, instance)
	if err != nil {
		w.consumerErrors.Add(1)
		log.Printf("correlation consumer create failed: %v", err)
		return
	}
	if err := w.consumerSubscribe(baseURI, w.inputTopic); err != nil {
		w.consumerErrors.Add(1)
		log.Printf("correlation consumer subscribe failed: %v", err)
		return
	}
	log.Printf("correlation consuming topic=%s group=%s output=%s", w.inputTopic, w.group, w.outputTopic)
	for {
		records, err := w.consumerPoll(baseURI)
		w.consumerPolls.Add(1)
		if err != nil {
			w.pollErrorCount.Add(1)
			w.consumerErrors.Add(1)
			log.Printf("correlation consumer poll failed: %v — reconnecting", err)
			return
		}
		if len(records) == 0 {
			continue
		}
		events := make([]Event, 0, len(records))
		rawMaps := make([]map[string]any, 0, len(records))
		for _, record := range records {
			if v, ok := record["value"].(map[string]any); ok {
				rawMaps = append(rawMaps, v)
			}
			body, _ := json.Marshal(record["value"])
			var event Event
			if err := json.Unmarshal(body, &event); err != nil {
				w.publishErrors.Add(1)
				partition := int64(0)
				offset := int64(0)
				if p, ok := record["partition"].(float64); ok {
					partition = int64(p)
				}
				if o, ok := record["offset"].(float64); ok {
					offset = int64(o)
				}
				dlqRec := map[string]any{
					"dlq_event_type":   "correlation_parse_error",
					"source_topic":     w.inputTopic,
					"source_partition": partition,
					"source_offset":    offset,
					"error_message":    err.Error(),
					"reason":           "invalid_json",
					"ts":               time.Now().UTC().Format(time.RFC3339Nano),
				}
				if writeErr := w.writeDLQRecord(dlqRec); writeErr != nil {
					return
				}
				continue
			}
			events = append(events, event)
		}
		started := time.Now()
		alerts := correlate(events)
		if w.scope == "identity-cloud" || w.scope == "identity-cloud-saas" {
			alerts = correlateIdentityCloud(events)
		}
		elapsed := time.Since(started).Milliseconds()
		w.processed.Add(int64(len(events)))
		w.alerts.Add(int64(len(alerts)))
		w.latencyMS.Store(elapsed)
		shadowTraceID := ""
		for _, rm := range rawMaps {
			if tid, _ := rm["trace_id"].(string); tid != "" {
				shadowTraceID = tid
				break
			}
		}
		if shadowAlerts := correlateEndpointShadowAll(rawMaps, w.iocLookupURL); len(shadowAlerts) > 0 {
			w.shadowAlertsPublished.Add(int64(len(shadowAlerts)))
			shadowPayload := map[string]any{
				"trace_id":    shadowTraceID,
				"source":      "correlation-worker",
				"scope":       "endpoint-shadow",
				"alerts":      shadowAlerts,
				"shadow_mode": true,
			}
			if err := w.publish(w.shadowAlertsTopic, []map[string]any{shadowPayload}); err != nil {
				w.publishErrors.Add(1)
				log.Printf("endpoint shadow publish failed: %v", err)
			}
		}
		if networkAlerts := shadowrules.CorrelateNetworkShadowAll(rawMaps); len(networkAlerts) > 0 {
			networkPayload := map[string]any{
				"trace_id":    shadowTraceID,
				"source":      "correlation-worker",
				"scope":       "network-shadow",
				"alerts":      networkAlerts,
				"shadow_mode": true,
			}
			if err := w.publish(w.networkShadowAlertsTopic, []map[string]any{networkPayload}); err != nil {
				w.publishErrors.Add(1)
				log.Printf("network shadow publish failed: %v", err)
			}
		}
		if len(alerts) == 0 {
			continue
		}
		batchTraceID := ""
		for _, ev := range events {
			if ev.TraceID != "" {
				batchTraceID = ev.TraceID
				break
			}
		}
		payload := map[string]any{
			"trace_id": batchTraceID,
			"source":   "correlation-worker",
			"scope":    w.scope,
			"alerts":   alerts,
		}
		if err := w.publish(w.outputTopic, []map[string]any{payload}); err != nil {
			w.publishErrors.Add(1)
			w.retryCount.Add(1)
			dlqRec := map[string]any{
				"dlq_event_type": "correlation_publish_error",
				"source_topic":   w.inputTopic,
				"error_message":  err.Error(),
				"reason":         "alert_publish_failed",
				"alert_count":    len(alerts),
				"ts":             time.Now().UTC().Format(time.RFC3339Nano),
			}
			if writeErr := w.writeDLQRecord(dlqRec); writeErr != nil {
				return
			}
			continue
		}
		w.published.Add(int64(len(alerts)))
	}
}

func (w *Worker) consumerCreate(group string, name string) (string, error) {
	payload, _ := json.Marshal(map[string]any{
		"name":              name,
		"format":            "json",
		"auto.offset.reset": "earliest",
	})
	req, _ := http.NewRequest(http.MethodPost, fmt.Sprintf("%s/consumers/%s", w.redpandaREST, group), bytes.NewReader(payload))
	req.Header.Set("Content-Type", "application/vnd.kafka.v2+json")
	req.Header.Set("Accept", "application/vnd.kafka.v2+json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", fmt.Errorf("consumer_create_failed status=%d body=%s", resp.StatusCode, string(body))
	}
	var out map[string]any
	if err := json.Unmarshal(body, &out); err != nil {
		return "", err
	}
	return normalizeConsumerBaseURI(fmt.Sprint(out["base_uri"]), w.redpandaREST), nil
}

func normalizeConsumerBaseURI(baseURI string, restURL string) string {
	advertised, errA := url.Parse(baseURI)
	internal, errI := url.Parse(restURL)
	if errA == nil && errI == nil && advertised.Host != "" && internal.Host != "" {
		advertised.Scheme = internal.Scheme
		advertised.Host = internal.Host
		return advertised.String()
	}
	return baseURI
}

func (w *Worker) consumerSubscribe(baseURI string, topic string) error {
	payload, _ := json.Marshal(map[string]any{"topics": []string{topic}})
	req, _ := http.NewRequest(http.MethodPost, baseURI+"/subscription", bytes.NewReader(payload))
	req.Header.Set("Content-Type", "application/vnd.kafka.v2+json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("consumer_subscribe_failed status=%d body=%s", resp.StatusCode, string(body))
	}
	return nil
}

func (w *Worker) consumerPoll(baseURI string) ([]map[string]any, error) {
	req, _ := http.NewRequest(http.MethodGet, baseURI+"/records?timeout=1000&max_bytes=4194304", nil)
	req.Header.Set("Accept", "application/vnd.kafka.json.v2+json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("consumer_poll_failed status=%d body=%s", resp.StatusCode, string(body))
	}
	if strings.TrimSpace(string(body)) == "" {
		return []map[string]any{}, nil
	}
	var records []map[string]any
	if err := json.Unmarshal(body, &records); err != nil {
		return nil, err
	}
	return records, nil
}

func (w *Worker) publish(topic string, events []map[string]any) error {
	records := make([]map[string]any, 0, len(events))
	for _, event := range events {
		records = append(records, map[string]any{"value": event})
	}
	payload, _ := json.Marshal(map[string]any{"records": records})
	req, _ := http.NewRequest(http.MethodPost, fmt.Sprintf("%s/topics/%s", w.redpandaREST, topic), bytes.NewReader(payload))
	req.Header.Set("Content-Type", "application/vnd.kafka.json.v2+json")
	req.Header.Set("Accept", "application/vnd.kafka.v2+json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("publish_failed status=%d body=%s", resp.StatusCode, string(body))
	}
	return nil
}

func (w *Worker) correlateHTTP(rw http.ResponseWriter, r *http.Request) {
	started := time.Now()
	if r.Method != http.MethodPost {
		http.Error(rw, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	if err := verifyCorrelationToken(r.Header.Get("X-Internal-Service-Token")); err != nil {
		http.Error(rw, "unauthorized", http.StatusUnauthorized)
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 32*1024*1024))
	if err != nil {
		http.Error(rw, "read_failed", http.StatusBadRequest)
		return
	}
	var events []Event
	if err := json.Unmarshal(body, &events); err != nil {
		http.Error(rw, "invalid_json", http.StatusBadRequest)
		return
	}
	alerts := correlate(events)
	scope := r.URL.Query().Get("scope")
	if scope == "identity-cloud" {
		alerts = correlateIdentityCloud(events)
	}
	elapsed := time.Since(started).Milliseconds()
	w.processed.Add(int64(len(events)))
	w.alerts.Add(int64(len(alerts)))
	w.latencyMS.Store(elapsed)
	writeJSON(rw, http.StatusOK, map[string]any{
		"events":      len(events),
		"alerts":      alerts,
		"alert_count": len(alerts),
		"latency_ms":  elapsed,
		"shadow_mode": true,
	})
}

func correlate(events []Event) []Alert {
	byUser := map[string][]Event{}
	byDomain := map[string][]Event{}
	byHost := map[string][]Event{}
	byIP := map[string][]Event{}
	for _, ev := range events {
		ev.TelemetryType = strings.ToLower(ev.TelemetryType)
		ev.EventType = strings.ToLower(ev.EventType)
		if ev.TelemetryType == "identity_provider" {
			ev.TelemetryType = "identity"
		} else if ev.TelemetryType == "saas_audit" {
			ev.TelemetryType = "saas"
		}
		if ev.User != "" {
			byUser[ev.User] = append(byUser[ev.User], ev)
		}
		if ev.Domain != "" {
			byDomain[strings.ToLower(ev.Domain)] = append(byDomain[strings.ToLower(ev.Domain)], ev)
		}
		if ev.Host != "" {
			byHost[ev.Host] = append(byHost[ev.Host], ev)
		}
		if ev.TelemetryType == "identity" && ev.SourceIP != "" {
			byIP[ev.SourceIP] = append(byIP[ev.SourceIP], ev)
		}
	}
	alerts := make([]Alert, 0)
	for user, group := range byUser {
		alerts = append(alerts, identityAlerts(user, group)...)
		alerts = append(alerts, cloudSaasAlerts(user, group)...)
		alerts = append(alerts, crossUserAlerts(user, group)...)
	}
	for domain, group := range byDomain {
		var ioc, dns, endpoint []Event
		for _, ev := range group {
			if ev.RiskScore >= 0.7 || strings.Contains(ev.EventType, "ioc") {
				ioc = append(ioc, ev)
			}
			if ev.TelemetryType == "dns" {
				dns = append(dns, ev)
			}
			if ev.TelemetryType == "endpoint" {
				endpoint = append(endpoint, ev)
			}
		}
		if len(ioc) > 0 && len(dns) > 0 && len(endpoint) > 0 {
			alerts = append(alerts, makeAlert("XDR_IOC_DNS_ENDPOINT_CHAIN", domain, []Event{ioc[0], dns[len(dns)-1], endpoint[len(endpoint)-1]}, 0.86))
		}
	}
	for host, group := range byHost {
		var proxy, process []Event
		for _, ev := range group {
			if (ev.TelemetryType == "proxy" || ev.TelemetryType == "firewall") && ev.RiskScore >= 0.6 {
				proxy = append(proxy, ev)
			}
			if ev.TelemetryType == "endpoint" && ev.EventType == "process_created" {
				process = append(process, ev)
			}
		}
		if len(proxy) > 0 && len(process) > 0 {
			alerts = append(alerts, makeAlert("XDR_PROXY_ENDPOINT_ESCALATION", host, []Event{proxy[len(proxy)-1], process[len(process)-1]}, 0.76))
		}
	}
	for ip, group := range byIP {
		users := map[string]bool{}
		for _, ev := range group {
			if ev.User != "" {
				users[ev.User] = true
			}
		}
		if len(users) >= 3 {
			alerts = append(alerts, makeAlert("IDENTITY_UNUSUAL_LOGIN_SOURCE", ip, tail(group, 12), 0.71))
		}
	}
	sort.Slice(alerts, func(i, j int) bool {
		if alerts[i].AlertType == alerts[j].AlertType {
			return alerts[i].Actor < alerts[j].Actor
		}
		return alerts[i].AlertType < alerts[j].AlertType
	})
	return dedupe(alerts)
}

func correlateIdentityCloud(events []Event) []Alert {
	byUser := make(map[string][]Event, 1024)
	byIP := make(map[string][]Event, 1024)
	for _, ev := range events {
		ev.TelemetryType = strings.ToLower(ev.TelemetryType)
		ev.EventType = strings.ToLower(ev.EventType)
		if ev.TelemetryType == "identity_provider" {
			ev.TelemetryType = "identity"
		} else if ev.TelemetryType == "saas_audit" {
			ev.TelemetryType = "saas"
		}
		if ev.TelemetryType != "identity" && ev.TelemetryType != "cloud" && ev.TelemetryType != "saas" {
			continue
		}
		if ev.User != "" {
			byUser[ev.User] = append(byUser[ev.User], ev)
		}
		if ev.TelemetryType == "identity" && ev.SourceIP != "" {
			byIP[ev.SourceIP] = append(byIP[ev.SourceIP], ev)
		}
	}

	userAlerts := parallelUserCorrelation(byUser)
	ipAlerts := make([]Alert, 0, len(byIP))
	for ip, group := range byIP {
		users := map[string]bool{}
		for _, ev := range group {
			if ev.User != "" {
				users[ev.User] = true
			}
		}
		if len(users) >= 3 {
			ipAlerts = append(ipAlerts, makeAlert("IDENTITY_UNUSUAL_LOGIN_SOURCE", ip, tail(group, 12), 0.71))
		}
	}
	alerts := append(userAlerts, ipAlerts...)
	sort.Slice(alerts, func(i, j int) bool {
		if alerts[i].AlertType == alerts[j].AlertType {
			return alerts[i].Actor < alerts[j].Actor
		}
		return alerts[i].AlertType < alerts[j].AlertType
	})
	return dedupe(alerts)
}

func parallelUserCorrelation(byUser map[string][]Event) []Alert {
	type item struct {
		user  string
		group []Event
	}
	workers := runtime.NumCPU()
	if workers < 2 {
		workers = 2
	}
	jobs := make(chan item, len(byUser))
	results := make(chan []Alert, len(byUser))
	for i := 0; i < workers; i++ {
		go func() {
			for job := range jobs {
				alerts := make([]Alert, 0, 4)
				alerts = append(alerts, identityAlerts(job.user, job.group)...)
				alerts = append(alerts, cloudSaasAlerts(job.user, job.group)...)
				results <- alerts
			}
		}()
	}
	for user, group := range byUser {
		jobs <- item{user: user, group: group}
	}
	close(jobs)
	merged := make([]Alert, 0, len(byUser))
	for i := 0; i < len(byUser); i++ {
		merged = append(merged, <-results...)
	}
	return merged
}

func identityAlerts(actor string, group []Event) []Alert {
	var failed, risky, success, priv []Event
	services := map[string]bool{}
	sources := map[string]bool{}
	for _, ev := range group {
		if ev.TelemetryType != "identity" {
			continue
		}
		if ev.EventType == "login_failed" || ev.EventType == "mfa_failed" || ev.Result == "failure" {
			failed = append(failed, ev)
			services[ev.EventSource] = true
		}
		if ev.EventType == "login_success" {
			success = append(success, ev)
			if ev.SourceIP != "" {
				sources[ev.SourceIP] = true
			}
			if ev.RiskScore >= 0.7 {
				risky = append(risky, ev)
			}
		}
		if strings.Contains(ev.EventType, "privilege") || strings.Contains(ev.Action, "role") || strings.Contains(ev.Action, "admin") {
			priv = append(priv, ev)
		}
	}
	alerts := []Alert{}
	if len(failed) >= 5 {
		alerts = append(alerts, makeAlert("IDENTITY_MFA_FAILURE_BURST", actor, tail(failed, 10), 0.71))
	}
	if len(failed) >= 4 && len(services) >= 2 {
		alerts = append(alerts, makeAlert("IDENTITY_FAILED_LOGIN_ACROSS_SERVICES", actor, tail(failed, 10), 0.76))
	}
	if len(risky) > 0 {
		alerts = append(alerts, makeAlert("IDENTITY_RISKY_IP_LOGIN", actor, tail(risky, 5), 0.76))
	}
	if len(sources) >= 2 {
		alerts = append(alerts, makeAlert("IDENTITY_IMPOSSIBLE_TRAVEL", actor, tail(success, 6), 0.66))
	}
	if len(priv) > 0 {
		alerts = append(alerts, makeAlert("IDENTITY_PRIVILEGE_ESCALATION", actor, tail(priv, 5), 0.78))
	}
	return alerts
}

func cloudSaasAlerts(actor string, group []Event) []Alert {
	var high, downloads, objectAccess, keys, settings, admin []Event
	for _, ev := range group {
		if ev.TelemetryType != "cloud" && ev.TelemetryType != "saas" {
			continue
		}
		text := strings.ToLower(ev.EventType + " " + ev.Action)
		if ev.RiskScore >= 0.7 {
			high = append(high, ev)
		}
		if strings.Contains(text, "download") {
			downloads = append(downloads, ev)
		}
		if strings.Contains(text, "object") || strings.Contains(strings.ToLower(ev.Action), "getobject") {
			objectAccess = append(objectAccess, ev)
		}
		if strings.Contains(text, "access_key") || ev.Action == "CreateAccessKey" {
			keys = append(keys, ev)
		}
		if strings.Contains(text, "security_setting") || strings.Contains(strings.ToLower(ev.Action), "policy") {
			settings = append(settings, ev)
		}
		if strings.Contains(text, "admin") {
			admin = append(admin, ev)
		}
	}
	alerts := []Alert{}
	if len(high) >= 3 {
		alerts = append(alerts, makeAlert("CLOUD_UNUSUAL_API_ACTIVITY", actor, tail(high, 10), 0.71))
	}
	if len(objectAccess) >= 5 {
		alerts = append(alerts, makeAlert("CLOUD_SUSPICIOUS_OBJECT_ACCESS", actor, tail(objectAccess, 10), 0.71))
	}
	if len(downloads) >= 10 {
		alerts = append(alerts, makeAlert("CLOUD_MASS_DOWNLOAD", actor, tail(downloads, 20), 0.76))
	}
	if len(keys) > 0 {
		alerts = append(alerts, makeAlert("CLOUD_NEW_ACCESS_KEY", actor, tail(keys, 5), 0.73))
	}
	if len(settings) > 0 {
		alerts = append(alerts, makeAlert("CLOUD_SECURITY_SETTING_MODIFIED", actor, tail(settings, 5), 0.73))
	}
	if len(admin) > 0 && anyTelemetry(admin, "saas") {
		alerts = append(alerts, makeAlert("SAAS_UNUSUAL_ADMIN_ACTIVITY", actor, tail(admin, 8), 0.76))
	}
	return alerts
}

func crossUserAlerts(actor string, group []Event) []Alert {
	var email, login, endpoint, priv, cloud []Event
	for _, ev := range group {
		if ev.TelemetryType == "email" && (strings.Contains(ev.EventType, "phish") || ev.RiskScore >= 0.7) {
			email = append(email, ev)
		}
		if ev.TelemetryType == "identity" && (ev.EventType == "login_success" || ev.EventType == "suspicious_login") {
			login = append(login, ev)
		}
		if ev.TelemetryType == "endpoint" && (ev.EventType == "process_created" || ev.EventType == "scheduled_task_created") {
			endpoint = append(endpoint, ev)
		}
		if strings.Contains(ev.EventType, "privilege") || strings.Contains(ev.Action, "role") {
			priv = append(priv, ev)
		}
		if ev.TelemetryType == "cloud" {
			cloud = append(cloud, ev)
		}
	}
	alerts := []Alert{}
	if len(email) > 0 && len(login) > 0 && len(endpoint) > 0 {
		alerts = append(alerts, makeAlert("XDR_PHISHING_TO_ENDPOINT_EXECUTION", actor, []Event{email[0], login[0], endpoint[0]}, 0.86))
	}
	if len(login) > 0 && len(priv) > 0 && len(cloud) > 0 {
		alerts = append(alerts, makeAlert("XDR_IMPOSSIBLE_LOGIN_PRIVILEGE_CLOUD", actor, []Event{login[len(login)-1], priv[len(priv)-1], cloud[len(cloud)-1]}, 0.91))
	}
	return alerts
}

func makeAlert(alertType, actor string, events []Event, score float64) Alert {
	domains := map[string]bool{}
	ids := make([]string, 0, len(events))
	traceID := ""
	inboundTraceparent := ""
	for _, ev := range events {
		if ev.TelemetryType != "" {
			domains[ev.TelemetryType] = true
		}
		if ev.EventID != "" {
			ids = append(ids, ev.EventID)
		}
		if traceID == "" && ev.TraceID != "" {
			traceID = ev.TraceID
		}
		if inboundTraceparent == "" && ev.Traceparent != "" {
			inboundTraceparent = ev.Traceparent
		}
	}
	domainList := make([]string, 0, len(domains))
	for domain := range domains {
		domainList = append(domainList, domain)
	}
	sort.Strings(domainList)
	sort.Strings(ids)
	severity := "medium"
	if score >= 0.85 {
		severity = "critical"
	} else if score >= 0.65 {
		severity = "high"
	}
	sum := sha256.Sum256([]byte(alertType + "|" + actor + "|" + strings.Join(ids, ",")))
	evidence := map[string]any{
		"evidence_ids":            ids,
		"involved_users":          uniqueNonEmpty(events, func(ev Event) string { return ev.User }),
		"involved_hosts":          uniqueNonEmpty(events, func(ev Event) string { return ev.Host }),
		"involved_cloud_accounts": uniqueNonEmpty(events, func(ev Event) string { return ev.CloudAccount }),
		"involved_external_ips":   uniqueNonEmpty(events, func(ev Event) string { return ev.SourceIP }),
		"telemetry_domains":       domainList,
	}
	// Propagate demo lineage from contributing events when any event carries demo fields.
	// Non-demo events have empty DemoRunID and are completely unaffected by this block.
	demoRunIDs := uniqueNonEmpty(events, func(ev Event) string { return ev.DemoRunID })
	traceIDs := uniqueNonEmpty(events, func(ev Event) string { return ev.TraceID })
	srcEventIDs := uniqueNonEmpty(events, func(ev Event) string { return ev.SourceEventID })
	scenarioIDs := uniqueNonEmpty(events, func(ev Event) string { return ev.ScenarioID })
	tenantIDs := uniqueNonEmpty(events, func(ev Event) string { return ev.TenantID })
	if len(demoRunIDs) > 0 {
		evidence["demo_lineage_present"] = true
		evidence["demo_run_ids"] = demoRunIDs
		evidence["trace_ids"] = traceIDs
		evidence["source_event_ids"] = srcEventIDs
		if len(demoRunIDs) == 1 {
			evidence["demo_run_id"] = demoRunIDs[0]
		}
		if len(scenarioIDs) == 1 {
			evidence["scenario_id"] = scenarioIDs[0]
		} else if len(scenarioIDs) > 1 {
			evidence["scenario_ids"] = scenarioIDs
		}
		if len(tenantIDs) == 1 {
			evidence["tenant_id"] = tenantIDs[0]
		} else if len(tenantIDs) > 1 {
			evidence["tenant_ids"] = tenantIDs
		}
	}
	primaryTenantID := ""
	if len(tenantIDs) > 0 {
		primaryTenantID = tenantIDs[0]
	}

	return Alert{
		AlertID:     hex.EncodeToString(sum[:])[:40],
		AlertType:   alertType,
		Actor:       actor,
		ActorKey:    actor,
		Severity:    severity,
		Score:       score,
		Domains:     domainList,
		EvidenceIDs: ids,
		Evidence:    evidence,
		ShadowMode:  true,
		TraceID:     traceID,
		Traceparent: traceparent.Propagate(inboundTraceparent),
		TenantID:    primaryTenantID,
	}
}

func uniqueNonEmpty(events []Event, pick func(Event) string) []string {
	seen := map[string]bool{}
	for _, ev := range events {
		value := pick(ev)
		if value != "" {
			seen[value] = true
		}
	}
	out := make([]string, 0, len(seen))
	for value := range seen {
		out = append(out, value)
	}
	sort.Strings(out)
	return out
}

func anyTelemetry(events []Event, telemetryType string) bool {
	for _, ev := range events {
		if ev.TelemetryType == telemetryType {
			return true
		}
	}
	return false
}

func tail(events []Event, n int) []Event {
	if len(events) == 0 {
		return events
	}
	if len(events) <= n {
		return events
	}
	return events[len(events)-n:]
}

func dedupe(alerts []Alert) []Alert {
	seen := map[string]bool{}
	out := make([]Alert, 0, len(alerts))
	for _, alert := range alerts {
		key := alert.AlertType + "|" + alert.Actor + "|" + strings.Join(alert.EvidenceIDs, ",")
		if seen[key] {
			continue
		}
		seen[key] = true
		out = append(out, alert)
	}
	return out
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
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return fallback
	}
	var parsed int
	if _, err := fmt.Sscanf(value, "%d", &parsed); err == nil {
		return parsed
	}
	return fallback
}

// ---------------------------------------------------------------------------
// Threat intelligence IOC shadow enrichment (shadow-only, graceful degradation)
// ---------------------------------------------------------------------------

// IOC lookup + cache moved to internal/ioc (CODE-STRUCT-DECOMPOSE). ioc.Configure
// is called at the top of main() with the same env vars this package var block
// used to read directly.

func ruleIOCIPMatch(events []map[string]any, iocURL string) []shadowrules.EndpointAlert {
	var alerts []shadowrules.EndpointAlert
	for _, ev := range events {
		if shadowrules.EpStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		for _, field := range []string{"destination_ip", "source_ip"} {
			ip := shadowrules.EpStr(ev, "network", field)
			if ip == "" {
				continue
			}
			iocResult := ioc.Lookup(iocURL, "ip", ip)
			if iocResult == nil {
				continue
			}
			a := shadowrules.MakeEndpointAlert("ioc_ip_match", "v1", "Endpoint IOC IP Match",
				ioc.Severity(iocResult), ioc.Confidence(iocResult), ev)
			a.Evidence["ioc_id"] = iocResult["ioc_id"]
			a.Evidence["matched_ip"] = ip
			a.Evidence["matched_field"] = "network." + field
			a.Evidence["ioc_source"] = iocResult["source"]
			a.Evidence["ioc_tags"] = iocResult["tags"]
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleIOCDomainMatch(events []map[string]any, iocURL string) []shadowrules.EndpointAlert {
	var alerts []shadowrules.EndpointAlert
	for _, ev := range events {
		if shadowrules.EpStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		domain := strings.ToLower(shadowrules.EpStr(ev, "dns", "domain"))
		if domain == "" {
			continue
		}
		iocResult := ioc.Lookup(iocURL, "domain", domain)
		if iocResult == nil {
			continue
		}
		a := shadowrules.MakeEndpointAlert("ioc_domain_match", "v1", "Endpoint IOC Domain Match",
			ioc.Severity(iocResult), ioc.Confidence(iocResult), ev)
		a.Evidence["ioc_id"] = iocResult["ioc_id"]
		a.Evidence["matched_domain"] = domain
		a.Evidence["ioc_source"] = iocResult["source"]
		a.Evidence["ioc_tags"] = iocResult["tags"]
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleIOCHashMatch(events []map[string]any, iocURL string) []shadowrules.EndpointAlert {
	var alerts []shadowrules.EndpointAlert
	for _, ev := range events {
		if shadowrules.EpStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		for _, field := range [][]string{{"process", "hash"}, {"file", "hash"}} {
			h := strings.ToLower(shadowrules.EpStr(ev, field...))
			if h == "" {
				continue
			}
			iocResult := ioc.Lookup(iocURL, "file_hash", h)
			if iocResult == nil {
				continue
			}
			section := strings.Join(field, ".")
			a := shadowrules.MakeEndpointAlert("ioc_file_hash_match", "v1", "Endpoint IOC File Hash Match",
				ioc.Severity(iocResult), ioc.Confidence(iocResult), ev)
			a.Evidence["ioc_id"] = iocResult["ioc_id"]
			a.Evidence["matched_hash"] = h
			a.Evidence["matched_section"] = section
			a.Evidence["ioc_source"] = iocResult["source"]
			a.Evidence["ioc_tags"] = iocResult["tags"]
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func correlateEndpointShadowIOC(events []map[string]any, iocURL string) []shadowrules.EndpointAlert {
	if iocURL == "" {
		return nil
	}
	var alerts []shadowrules.EndpointAlert
	alerts = append(alerts, ruleIOCIPMatch(events, iocURL)...)
	alerts = append(alerts, ruleIOCDomainMatch(events, iocURL)...)
	alerts = append(alerts, ruleIOCHashMatch(events, iocURL)...)
	return alerts
}

func correlateEndpointShadowAll(events []map[string]any, iocURL string) []shadowrules.EndpointAlert {
	alerts := shadowrules.CorrelateEndpointShadow(events)
	alerts = append(alerts, correlateEndpointShadowIOC(events, iocURL)...)
	alerts = append(alerts, shadowrules.CorrelateEndpointShadowCrossDomain(events)...)
	alerts = append(alerts, shadowrules.CorrelateEndpointShadowStreaming(events)...)
	return shadowrules.DedupeEndpointAlerts(alerts)
}

// ---------------------------------------------------------------------------
// Endpoint shadow correlation — shadow-only, publishes to xdr.alerts.shadow.endpoint
// Does NOT affect the active identity/cloud/SaaS correlation path.
// ---------------------------------------------------------------------------

// Core endpoint shadow rules (ruleParentChildProcess, rulePowershellEncoded,
// ruleSuspiciousTempFile, ruleFailedLoginBurst, ruleSuspiciousDNS,
// ruleSuspiciousOutbound, ruleScheduledTaskPersistence, ruleNewServicePersistence,
// ruleC2BeaconPattern) plus their static lookup tables moved to
// internal/shadowrules (CODE-STRUCT-DECOMPOSE, seam 5), exported as
// shadowrules.RuleParentChildProcess etc.

// dedupeEndpointAlerts moved to internal/shadowrules.DedupeEndpointAlerts
// (CODE-STRUCT-DECOMPOSE, seam 2).

// Behavioral visibility, behavioral analytics, threat-hunting behavioral
// rules, and the correlateEndpointShadow aggregator moved to
// internal/shadowrules (CODE-STRUCT-DECOMPOSE, seam 6), exported as
// shadowrules.CorrelateEndpointShadow.

// Cross-domain shadow correlation moved to internal/shadowrules
// (CODE-STRUCT-DECOMPOSE, seam 3). See shadowrules.CorrelateEndpointShadowCrossDomain.

func (w *Worker) correlateEndpointShadowHTTP(rw http.ResponseWriter, r *http.Request) {
	started := time.Now()
	if r.Method != http.MethodPost {
		http.Error(rw, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	if err := verifyCorrelationToken(r.Header.Get("X-Internal-Service-Token")); err != nil {
		http.Error(rw, "unauthorized", http.StatusUnauthorized)
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 32*1024*1024))
	if err != nil {
		http.Error(rw, "read_failed", http.StatusBadRequest)
		return
	}
	var events []map[string]any
	if err := json.Unmarshal(body, &events); err != nil {
		http.Error(rw, "invalid_json", http.StatusBadRequest)
		return
	}
	shadowAlerts := correlateEndpointShadowAll(events, w.iocLookupURL)
	elapsed := time.Since(started).Milliseconds()
	writeJSON(rw, http.StatusOK, map[string]any{
		"events":        len(events),
		"shadow_alerts": shadowAlerts,
		"alert_count":   len(shadowAlerts),
		"latency_ms":    elapsed,
		"shadow_mode":   true,
		"topic":         w.shadowAlertsTopic,
	})
}

// Streaming endpoint shadow rules moved to internal/shadowrules
// (CODE-STRUCT-DECOMPOSE, seam 4). See shadowrules.CorrelateEndpointShadowStreaming.

// Network shadow rules (DNS/proxy/firewall) moved to internal/shadowrules
// (CODE-STRUCT-DECOMPOSE, seam 2). See shadowrules.CorrelateNetworkShadowAll.

func envBool(name string, fallback bool) bool {
	value := strings.ToLower(strings.TrimSpace(env(name, "")))
	if value == "" {
		return fallback
	}
	return value == "1" || value == "true" || value == "yes"
}
