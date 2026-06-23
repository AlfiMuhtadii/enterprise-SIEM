package main

import (
	"bytes"
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
}

type Worker struct {
	redpandaREST      string
	inputTopic        string
	outputTopic       string
	dlqTopic          string
	correlationFailedTopic   string
	shadowAlertsTopic        string
	networkShadowAlertsTopic string
	iocLookupURL             string
	group             string
	scope             string
	processed atomic.Int64
	alerts    atomic.Int64
	latencyMS atomic.Int64
	published atomic.Int64
	publishErrors atomic.Int64
	consumerPolls         atomic.Int64
	consumerErrors        atomic.Int64
	reconnectCount        atomic.Int64
	pollErrorCount        atomic.Int64
	consumerRecreateCount atomic.Int64
	retryCount            atomic.Int64
	shadowAlertsPublished atomic.Int64
	dlqWritten            atomic.Int64
	dlqWriteErrors        atomic.Int64
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
	w := &Worker{
		redpandaREST:      env("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082"),
		inputTopic:        env("XDR_NORMALIZED_TOPIC", "telemetry.normalized"),
		outputTopic:       env("XDR_ALERTS_TOPIC", "xdr.alerts"),
		dlqTopic:          env("XDR_CORRELATION_DLQ_TOPIC", "xdr.alerts.dlq"),
		correlationFailedTopic:   env("XDR_CORRELATION_FAILED_TOPIC", "xdr.correlation_failed"),
		shadowAlertsTopic:        env("XDR_ENDPOINT_SHADOW_TOPIC", "xdr.alerts.shadow.endpoint"),
		networkShadowAlertsTopic: env("XDR_NETWORK_SHADOW_TOPIC",   "xdr.alerts.shadow.network"),
		iocLookupURL:      env("XDR_IOC_LOOKUP_URL", ""),
		group:             env("XDR_CORRELATION_GROUP", "correlation-worker-v1"),
		scope:             env("XDR_CORRELATION_SCOPE", "identity-cloud"),
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
	}
	go func() {
		stop := make(chan os.Signal, 1)
		signal.Notify(stop, os.Interrupt, syscall.SIGTERM)
		<-stop
		log.Printf("xdr correlation worker shutting down gracefully")
		_ = server.Close()
	}()
	log.Printf("xdr correlation worker listening on %s shadow_mode=true", *addr)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatal(err)
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
		"processed":     w.processed.Load(),
		"alerts":        w.alerts.Load(),
		"last_latency_ms": w.latencyMS.Load(),
		"published":     w.published.Load(),
		"publish_errors": w.publishErrors.Load(),
		"consumer_polls":          w.consumerPolls.Load(),
		"consumer_errors":         w.consumerErrors.Load(),
		"reconnect_count":         w.reconnectCount.Load(),
		"poll_error_count":        w.pollErrorCount.Load(),
		"consumer_recreate_count": w.consumerRecreateCount.Load(),
		"retry_count":             w.retryCount.Load(),
		"shadow_alerts_published": w.shadowAlertsPublished.Load(),
		"ioc_lookup_total":        iocLookupTotal.Load(),
		"ioc_match_total":         iocMatchTotal.Load(),
		"dlq_written":             w.dlqWritten.Load(),
		"dlq_write_errors":        w.dlqWriteErrors.Load(),
		"input_topic":             w.inputTopic,
		"output_topic":            w.outputTopic,
		"correlation_failed_topic": w.correlationFailedTopic,
		"goroutines":    runtime.NumGoroutine(),
		"heap_alloc_mb": float64(mem.HeapAlloc) / 1024.0 / 1024.0,
		"internal_auth_mode": func() string {
			if envBool("XDR_ENFORCE_INTERNAL_AUTH", false) { return "enforced" }
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
		if networkAlerts := correlateNetworkShadowAll(rawMaps); len(networkAlerts) > 0 {
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
		"name": name,
		"format": "json",
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
		"events": len(events),
		"alerts": alerts,
		"alert_count": len(alerts),
		"latency_ms": elapsed,
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
	demoRunIDs  := uniqueNonEmpty(events, func(ev Event) string { return ev.DemoRunID })
	traceIDs    := uniqueNonEmpty(events, func(ev Event) string { return ev.TraceID })
	srcEventIDs := uniqueNonEmpty(events, func(ev Event) string { return ev.SourceEventID })
	scenarioIDs := uniqueNonEmpty(events, func(ev Event) string { return ev.ScenarioID })
	tenantIDs   := uniqueNonEmpty(events, func(ev Event) string { return ev.TenantID })
	if len(demoRunIDs) > 0 {
		evidence["demo_lineage_present"] = true
		evidence["demo_run_ids"]         = demoRunIDs
		evidence["trace_ids"]            = traceIDs
		evidence["source_event_ids"]     = srcEventIDs
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

var (
	iocLookupTotal atomic.Int64
	iocMatchTotal  atomic.Int64
	iocHTTPClient  = &http.Client{Timeout: 3 * time.Second}
)

func lookupIOC(iocURL, iocType, value string) map[string]any {
	if iocURL == "" || value == "" {
		return nil
	}
	iocLookupTotal.Add(1)
	queryURL := fmt.Sprintf("%s/v1/lookup?type=%s&value=%s",
		strings.TrimRight(iocURL, "/"),
		iocType,
		url.QueryEscape(value))
	req, err := http.NewRequest(http.MethodGet, queryURL, nil)
	if err != nil {
		return nil
	}
	resp, err := iocHTTPClient.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return nil
	}
	var result map[string]any
	if err := json.NewDecoder(resp.Body).Decode(&result); err != nil {
		return nil
	}
	matched, _ := result["matched"].(bool)
	if !matched {
		return nil
	}
	iocMatchTotal.Add(1)
	return result
}

func iocSeverity(ioc map[string]any) string {
	if s, ok := ioc["severity"].(string); ok && s != "" {
		return s
	}
	return "medium"
}

func iocConfidence(ioc map[string]any) float64 {
	if c, ok := ioc["confidence"].(float64); ok && c > 0 {
		return c
	}
	return 0.70
}

func ruleIOCIPMatch(events []map[string]any, iocURL string) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		for _, field := range []string{"destination_ip", "source_ip"} {
			ip := epStr(ev, "network", field)
			if ip == "" {
				continue
			}
			ioc := lookupIOC(iocURL, "ip", ip)
			if ioc == nil {
				continue
			}
			a := makeEndpointAlert("ioc_ip_match", "v1", "Endpoint IOC IP Match",
				iocSeverity(ioc), iocConfidence(ioc), ev)
			a.Evidence["ioc_id"] = ioc["ioc_id"]
			a.Evidence["matched_ip"] = ip
			a.Evidence["matched_field"] = "network." + field
			a.Evidence["ioc_source"] = ioc["source"]
			a.Evidence["ioc_tags"] = ioc["tags"]
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleIOCDomainMatch(events []map[string]any, iocURL string) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		domain := strings.ToLower(epStr(ev, "dns", "domain"))
		if domain == "" {
			continue
		}
		ioc := lookupIOC(iocURL, "domain", domain)
		if ioc == nil {
			continue
		}
		a := makeEndpointAlert("ioc_domain_match", "v1", "Endpoint IOC Domain Match",
			iocSeverity(ioc), iocConfidence(ioc), ev)
		a.Evidence["ioc_id"] = ioc["ioc_id"]
		a.Evidence["matched_domain"] = domain
		a.Evidence["ioc_source"] = ioc["source"]
		a.Evidence["ioc_tags"] = ioc["tags"]
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleIOCHashMatch(events []map[string]any, iocURL string) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		for _, field := range [][]string{{"process", "hash"}, {"file", "hash"}} {
			h := strings.ToLower(epStr(ev, field...))
			if h == "" {
				continue
			}
			ioc := lookupIOC(iocURL, "file_hash", h)
			if ioc == nil {
				continue
			}
			section := strings.Join(field, ".")
			a := makeEndpointAlert("ioc_file_hash_match", "v1", "Endpoint IOC File Hash Match",
				iocSeverity(ioc), iocConfidence(ioc), ev)
			a.Evidence["ioc_id"] = ioc["ioc_id"]
			a.Evidence["matched_hash"] = h
			a.Evidence["matched_section"] = section
			a.Evidence["ioc_source"] = ioc["source"]
			a.Evidence["ioc_tags"] = ioc["tags"]
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func correlateEndpointShadowIOC(events []map[string]any, iocURL string) []EndpointAlert {
	if iocURL == "" {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleIOCIPMatch(events, iocURL)...)
	alerts = append(alerts, ruleIOCDomainMatch(events, iocURL)...)
	alerts = append(alerts, ruleIOCHashMatch(events, iocURL)...)
	return alerts
}

func correlateEndpointShadowAll(events []map[string]any, iocURL string) []EndpointAlert {
	alerts := correlateEndpointShadow(events)
	alerts = append(alerts, correlateEndpointShadowIOC(events, iocURL)...)
	alerts = append(alerts, correlateEndpointShadowCrossDomain(events)...)
	alerts = append(alerts, correlateEndpointShadowStreaming(events)...)
	return dedupeEndpointAlerts(alerts)
}

// ---------------------------------------------------------------------------
// Endpoint shadow correlation — shadow-only, publishes to xdr.alerts.shadow.endpoint
// Does NOT affect the active identity/cloud/SaaS correlation path.
// ---------------------------------------------------------------------------

type EndpointAlert struct {
	AlertID     string         `json:"alert_id"`
	RuleID      string         `json:"rule_id"`
	Version     string         `json:"version"`
	Title       string         `json:"title"`
	Severity    string         `json:"severity"`
	Confidence  float64        `json:"confidence"`
	Host        string         `json:"host"`
	User        string         `json:"user"`
	TraceID     string         `json:"trace_id"`
	ShadowMode  bool           `json:"shadow_mode"`
	EvidenceIDs []string       `json:"evidence_ids"`
	EventType   string         `json:"event_type"`
	Evidence    map[string]any `json:"evidence"`
}

func epStr(event map[string]any, path ...string) string {
	var current any = event
	for _, key := range path {
		m, ok := current.(map[string]any)
		if !ok {
			return ""
		}
		current = m[key]
	}
	if current == nil {
		return ""
	}
	return fmt.Sprint(current)
}

func epInt64(event map[string]any, path ...string) int64 {
	var current any = event
	for _, key := range path {
		m, ok := current.(map[string]any)
		if !ok {
			return 0
		}
		current = m[key]
	}
	if current == nil {
		return 0
	}
	switch v := current.(type) {
	case float64:
		return int64(v)
	case int64:
		return v
	case int:
		return int64(v)
	default:
		return 0
	}
}

func makeEndpointAlert(ruleID, version, title, severity string, confidence float64, event map[string]any) EndpointAlert {
	eventID := epStr(event, "normalized_event_id")
	if eventID == "" {
		eventID = epStr(event, "raw_event_id")
	}
	sum := sha256.Sum256([]byte(ruleID + "|" + epStr(event, "host") + "|" + eventID))
	return EndpointAlert{
		AlertID:     hex.EncodeToString(sum[:])[:40],
		RuleID:      ruleID,
		Version:     version,
		Title:       title,
		Severity:    severity,
		Confidence:  confidence,
		Host:        epStr(event, "host"),
		User:        epStr(event, "user"),
		TraceID:     epStr(event, "trace_id"),
		ShadowMode:  true,
		EvidenceIDs: []string{eventID},
		EventType:   epStr(event, "event_type"),
		Evidence:    map[string]any{},
	}
}

var suspiciousParentNames = map[string]bool{
	"winword.exe": true, "excel.exe": true, "powerpnt.exe": true,
	"outlook.exe": true, "msdt.exe": true, "mspub.exe": true,
}

var suspiciousChildNames = map[string]bool{
	"cmd.exe": true, "powershell.exe": true, "wscript.exe": true,
	"cscript.exe": true, "mshta.exe": true, "regsvr32.exe": true,
	"rundll32.exe": true, "certutil.exe": true,
}

var powershellEncodedIndicators = []string{" -enc ", " -e ", "-encodedcommand", "/enc ", "/e "}

var executableExtensions = []string{".exe", ".dll", ".bat", ".ps1", ".vbs", ".js", ".hta", ".scr"}

var tempPathPatterns = []string{`\temp\`, `\tmp\`, `\appdata\local\temp\`, "/tmp/", "/temp/"}

var internalIPPrefixes = []string{
	"10.", "172.16.", "172.17.", "172.18.", "172.19.", "172.20.", "172.21.",
	"172.22.", "172.23.", "172.24.", "172.25.", "172.26.", "172.27.", "172.28.",
	"172.29.", "172.30.", "172.31.", "192.168.", "127.", "169.254.",
}

var commonServicePorts = map[int64]bool{
	22: true, 25: true, 53: true, 80: true, 110: true, 143: true,
	443: true, 587: true, 993: true, 995: true, 3389: true, 8080: true, 8443: true,
}

const failedLoginBurstThreshold = 3
const dnsDomainLengthThreshold = 40
const c2BeaconMinConnections = 3

var scheduledTaskProcessNames = map[string]bool{
	"schtasks.exe": true, "at.exe": true, "taskeng.exe": true,
}

var serviceCreateProcessNames = map[string]bool{
	"sc.exe": true,
}

func ruleParentChildProcess(events []map[string]any) []EndpointAlert {
	pidToName := map[int64]string{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		pid := epInt64(ev, "process", "pid")
		name := strings.ToLower(epStr(ev, "process", "name"))
		if pid > 0 && name != "" {
			pidToName[pid] = name
		}
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		child := strings.ToLower(epStr(ev, "process", "name"))
		if !suspiciousChildNames[child] {
			continue
		}
		ppid := epInt64(ev, "process", "ppid")
		parentName := pidToName[ppid]
		if !suspiciousParentNames[parentName] {
			continue
		}
		a := makeEndpointAlert("suspicious_parent_child_process", "v1", "Suspicious Parent-Child Process", "high", 0.80, ev)
		a.Evidence["parent_process"] = parentName
		a.Evidence["child_process"] = child
		a.Evidence["ppid"] = ppid
		alerts = append(alerts, a)
	}
	return alerts
}

func rulePowershellEncoded(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process", "name"))
		if name != "powershell.exe" && name != "pwsh.exe" {
			continue
		}
		cmdLine := strings.ToLower(epStr(ev, "process", "command_line"))
		matched := false
		for _, indicator := range powershellEncodedIndicators {
			if strings.Contains(cmdLine, indicator) {
				matched = true
				break
			}
		}
		if !matched {
			continue
		}
		a := makeEndpointAlert("powershell_encoded_command", "v1", "PowerShell Encoded Command Execution", "high", 0.85, ev)
		a.Evidence["command_line"] = epStr(ev, "process", "command_line")
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleSuspiciousTempFile(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "file_write" {
			continue
		}
		filePath := strings.ToLower(epStr(ev, "file", "path"))
		op := strings.ToLower(epStr(ev, "file", "operation"))
		if op != "create" && op != "modify" && op != "overwrite" {
			continue
		}
		inTemp := false
		for _, pat := range tempPathPatterns {
			if strings.Contains(filePath, pat) {
				inTemp = true
				break
			}
		}
		if !inTemp {
			continue
		}
		hasExec := false
		for _, ext := range executableExtensions {
			if strings.HasSuffix(filePath, ext) {
				hasExec = true
				break
			}
		}
		if !hasExec {
			continue
		}
		a := makeEndpointAlert("suspicious_temp_file_write", "v1", "Executable Written to Temporary Directory", "high", 0.78, ev)
		a.Evidence["file_path"] = epStr(ev, "file", "path")
		a.Evidence["operation"] = op
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleFailedLoginBurst(events []map[string]any) []EndpointAlert {
	type loginKey struct{ host, user string }
	failedByKey := map[loginKey][]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "login_event" {
			continue
		}
		action := strings.ToLower(epStr(ev, "auth", "action"))
		if action != "login_failed" && action != "mfa_failed" {
			continue
		}
		key := loginKey{epStr(ev, "host"), epStr(ev, "user")}
		failedByKey[key] = append(failedByKey[key], ev)
	}
	var alerts []EndpointAlert
	for key, failedEvents := range failedByKey {
		if len(failedEvents) < failedLoginBurstThreshold {
			continue
		}
		a := makeEndpointAlert("failed_login_burst", "v1", "Failed Login Burst", "medium", 0.72, failedEvents[0])
		a.Host = key.host
		a.User = key.user
		a.Evidence["failed_count"] = len(failedEvents)
		a.Evidence["threshold"] = failedLoginBurstThreshold
		ids := make([]string, 0, len(failedEvents))
		for _, ev := range failedEvents {
			if id := epStr(ev, "normalized_event_id"); id != "" {
				ids = append(ids, id)
			}
		}
		a.EvidenceIDs = ids
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleSuspiciousDNS(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "dns_query" {
			continue
		}
		domain := strings.ToLower(epStr(ev, "dns", "domain"))
		if domain == "" {
			continue
		}
		reason := ""
		if len(domain) > dnsDomainLengthThreshold {
			reason = "high_length_possible_dga"
		} else {
			digits := 0
			for _, c := range domain {
				if c >= '0' && c <= '9' {
					digits++
				}
			}
			if len(domain) > 0 && float64(digits)/float64(len(domain)) > 0.40 {
				reason = "high_numeric_density"
			}
		}
		if reason == "" {
			continue
		}
		a := makeEndpointAlert("suspicious_dns_query", "v1", "Suspicious DNS Query", "medium", 0.68, ev)
		a.Evidence["domain"] = domain
		a.Evidence["reason"] = reason
		a.Evidence["domain_length"] = len(domain)
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleSuspiciousOutbound(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "network_connection" {
			continue
		}
		dstIP := epStr(ev, "network", "destination_ip")
		dstPort := epInt64(ev, "network", "destination_port")
		if dstIP == "" || dstPort == 0 {
			continue
		}
		isInternal := false
		for _, prefix := range internalIPPrefixes {
			if strings.HasPrefix(dstIP, prefix) {
				isInternal = true
				break
			}
		}
		if isInternal {
			continue
		}
		if commonServicePorts[dstPort] {
			continue
		}
		a := makeEndpointAlert("suspicious_outbound_connection", "v1", "Suspicious Outbound Network Connection", "medium", 0.65, ev)
		a.Evidence["destination_ip"] = dstIP
		a.Evidence["destination_port"] = dstPort
		a.Evidence["protocol"] = epStr(ev, "network", "protocol")
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleScheduledTaskPersistence(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := epStr(ev, "event_type")
		if evType != "process_start" && evType != "scheduled_task_create" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process", "name"))
		cmdLine := strings.ToLower(epStr(ev, "process", "command_line"))
		isScheduled := scheduledTaskProcessNames[name] ||
			(name == "schtasks.exe" && (strings.Contains(cmdLine, "/create") || strings.Contains(cmdLine, "-create"))) ||
			evType == "scheduled_task_create"
		if !isScheduled {
			continue
		}
		a := makeEndpointAlert("scheduled_task_persistence", "v1", "Scheduled Task Persistence", "high", 0.75, ev)
		a.Evidence["process_name"] = epStr(ev, "process", "name")
		a.Evidence["command_line"] = epStr(ev, "process", "command_line")
		a.Evidence["task_name"] = epStr(ev, "scheduled_task", "name")
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNewServicePersistence(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := epStr(ev, "event_type")
		if evType != "process_start" && evType != "service_install" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process", "name"))
		cmdLine := strings.ToLower(epStr(ev, "process", "command_line"))
		isServiceCreate := (serviceCreateProcessNames[name] && (strings.Contains(cmdLine, "create") || strings.Contains(cmdLine, "config"))) ||
			evType == "service_install"
		if !isServiceCreate {
			continue
		}
		a := makeEndpointAlert("new_service_persistence", "v1", "New Service Installed", "high", 0.78, ev)
		a.Evidence["process_name"] = epStr(ev, "process", "name")
		a.Evidence["command_line"] = epStr(ev, "process", "command_line")
		a.Evidence["service_name"] = epStr(ev, "service", "name")
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleC2BeaconPattern(events []map[string]any) []EndpointAlert {
	type connKey struct{ host, dst string; port int64 }
	byKey := map[connKey][]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "network_connection" {
			continue
		}
		dstIP := epStr(ev, "network", "destination_ip")
		dstPort := epInt64(ev, "network", "destination_port")
		if dstIP == "" || dstPort == 0 {
			continue
		}
		internal := false
		for _, prefix := range internalIPPrefixes {
			if strings.HasPrefix(dstIP, prefix) {
				internal = true
				break
			}
		}
		if internal {
			continue
		}
		key := connKey{epStr(ev, "host"), dstIP, dstPort}
		byKey[key] = append(byKey[key], ev)
	}
	var alerts []EndpointAlert
	for key, evs := range byKey {
		if len(evs) < c2BeaconMinConnections {
			continue
		}
		a := makeEndpointAlert("c2_beacon_pattern", "v1", "Possible C2 Beacon Pattern", "high", 0.72, evs[0])
		a.Host = key.host
		a.Evidence["destination_ip"] = key.dst
		a.Evidence["destination_port"] = key.port
		a.Evidence["connection_count"] = len(evs)
		a.Evidence["threshold"] = c2BeaconMinConnections
		ids := make([]string, 0, len(evs))
		for _, ev := range evs {
			if id := epStr(ev, "normalized_event_id"); id != "" {
				ids = append(ids, id)
			}
		}
		a.EvidenceIDs = ids
		alerts = append(alerts, a)
	}
	return alerts
}

func dedupeEndpointAlerts(alerts []EndpointAlert) []EndpointAlert {
	seen := map[string]bool{}
	out := make([]EndpointAlert, 0, len(alerts))
	for _, a := range alerts {
		if seen[a.AlertID] {
			continue
		}
		seen[a.AlertID] = true
		out = append(out, a)
	}
	return out
}

// ---------------------------------------------------------------------------
// Behavioral visibility rules — Phase 1 (2026-05-18)
// Shadow-only; consume enriched endpoint telemetry from behavioral agent.
// ---------------------------------------------------------------------------

var webServerProcessNames = map[string]bool{
	"nginx": true, "apache": true, "apache2": true, "httpd": true,
	"gunicorn": true, "uwsgi": true, "php-fpm": true, "tomcat": true,
	"mysqld": true, "postgres": true, "mongod": true, "redis-server": true,
}

var linuxShellNames = map[string]bool{
	"bash": true, "sh": true, "zsh": true, "dash": true, "ksh": true, "tcsh": true, "fish": true,
	"python": true, "python3": true, "python2": true, "perl": true, "ruby": true,
	"curl": true, "wget": true,
}

const longLivedThresholdSeconds = 3600 // 1 hour

func ruleParentChildChain(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		childName := strings.ToLower(epStr(ev, "process_name"))
		parentName := strings.ToLower(epStr(ev, "parent_process_name"))
		if !linuxShellNames[childName] {
			continue
		}
		if !webServerProcessNames[parentName] {
			continue
		}
		a := makeEndpointAlert("suspicious_parent_child_chain", "v1",
			"Suspicious Parent-Child Process Chain (Behavioral)", "high", 0.78, ev)
		a.Evidence["parent_process_name"] = parentName
		a.Evidence["child_process"] = childName
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleShellChain(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		childName := strings.ToLower(epStr(ev, "process_name"))
		parentName := strings.ToLower(epStr(ev, "parent_process_name"))
		if !linuxShellNames[childName] {
			continue
		}
		if !linuxShellNames[parentName] {
			continue
		}
		if childName == parentName {
			continue // ignore same-shell (e.g. sh → sh in scripts)
		}
		a := makeEndpointAlert("suspicious_shell_chain", "v1",
			"Suspicious Shell-to-Shell Execution Chain", "high", 0.75, ev)
		a.Evidence["parent_shell"] = parentName
		a.Evidence["child_shell"] = childName
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleLongLivedProcess(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		processName := strings.ToLower(epStr(ev, "process_name"))
		if !linuxShellNames[processName] {
			continue
		}
		dur := epInt64(ev, "duration_seconds")
		if dur < longLivedThresholdSeconds {
			continue
		}
		a := makeEndpointAlert("suspicious_long_lived_process", "v1",
			"Suspicious Long-Lived Interactive Process", "medium", 0.65, ev)
		a.Evidence["process_name"] = processName
		a.Evidence["duration_seconds"] = dur
		a.Evidence["threshold_seconds"] = longLivedThresholdSeconds
		alerts = append(alerts, a)
	}
	return alerts
}

func rulePersistenceEntry(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := epStr(ev, "event_type")
		if evType != "service_install" && evType != "scheduled_task_create" {
			continue
		}
		itemKey := epStr(ev, "persistence_item_key")
		if itemKey == "" {
			itemKey = epStr(ev, "service_name")
		}
		if itemKey == "" {
			itemKey = epStr(ev, "task_name")
		}
		a := makeEndpointAlert("suspicious_persistence_entry", "v1",
			"New or Unexpected Persistence Entry", "high", 0.72, ev)
		a.Evidence["event_type"] = evType
		a.Evidence["item_key"] = itemKey
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Behavioral analytics rules — Phase 1 (2026-05-18)
// Shadow-only; advisory findings only. No active containment.
// ---------------------------------------------------------------------------

var downloaderNames = map[string]bool{
	"curl": true, "wget": true,
}

var lolbinNames = map[string]bool{
	"curl": true, "wget": true, "bash": true, "sh": true,
	"python": true, "python3": true, "python2": true, "perl": true,
	"nc": true, "netcat": true, "ncat": true, "base64": true,
	"systemctl": true, "crontab": true, "dd": true, "awk": true,
}

const chainScoreThreshold = 0.50

func ruleExecutionChain(events []map[string]any) []EndpointAlert {
	// Build pid → name map
	pidToNameAnalytics := map[int64]string{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		pid := epInt64(ev, "pid")
		if pid > 0 {
			pidToNameAnalytics[pid] = strings.ToLower(epStr(ev, "process_name"))
		}
	}

	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process_name"))
		if !linuxShellNames[name] {
			continue
		}
		// Check if ancestry includes a downloader
		ppid := epInt64(ev, "ppid")
		parentName := pidToNameAnalytics[ppid]
		if !downloaderNames[parentName] && !linuxShellNames[parentName] {
			continue
		}

		score := 0.60
		if downloaderNames[parentName] {
			score += 0.20
		}
		if score < chainScoreThreshold {
			continue
		}
		a := makeEndpointAlert("suspicious_execution_chain", "v1",
			"Suspicious Process Execution Chain", "high", score, ev)
		a.Evidence["chain"] = parentName + " → " + name
		a.Evidence["score"] = score
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleBeaconPatternAnalytics(events []map[string]any) []EndpointAlert {
	type destKey struct{ ip string; port int64 }
	procDests := map[string]map[destKey]int{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "network_connection" {
			continue
		}
		proc := strings.ToLower(epStr(ev, "process_name"))
		ip   := epStr(ev, "remote_ip")
		port := epInt64(ev, "remote_port")
		if ip == "" || proc == "" {
			continue
		}
		if procDests[proc] == nil {
			procDests[proc] = map[destKey]int{}
		}
		procDests[proc][destKey{ip, port}]++
	}

	var alerts []EndpointAlert
	for proc, dests := range procDests {
		for dk, cnt := range dests {
			if cnt < 3 {
				continue
			}
			// Find a representative event
			var repEv map[string]any
			for _, ev := range events {
				if epStr(ev, "event_type") == "network_connection" &&
					strings.ToLower(epStr(ev, "process_name")) == proc &&
					epStr(ev, "remote_ip") == dk.ip {
					repEv = ev
					break
				}
			}
			if repEv == nil {
				continue
			}
			a := makeEndpointAlert("suspicious_beacon_pattern", "v1",
				"Suspicious Beacon-like Outbound Pattern", "high", 0.75, repEv)
			a.Evidence["process"]          = proc
			a.Evidence["destination"]      = fmt.Sprintf("%s:%d", dk.ip, dk.port)
			a.Evidence["connection_count"] = cnt
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleLolbinUsage(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process_name"))
		if !lolbinNames[name] {
			continue
		}
		parentName := strings.ToLower(epStr(ev, "parent_process_name"))

		confidence := 0.60
		if webServerProcessNames[parentName] {
			confidence += 0.15
		}
		cmdLine := strings.ToLower(epStr(ev, "command_line"))
		if strings.Contains(cmdLine, "base64") {
			confidence += 0.15
		}
		a := makeEndpointAlert("suspicious_lolbin_usage", "v1",
			"Living-off-the-Land Binary (LOLBin) Usage", "medium", confidence, ev)
		a.Evidence["lolbin"]         = name
		a.Evidence["parent_process"] = parentName
		a.Evidence["confidence"]     = confidence
		alerts = append(alerts, a)
	}
	return alerts
}

func rulePersistenceCorrelationAnalytics(events []map[string]any) []EndpointAlert {
	var persistEvents, shellEvents, networkEvents []map[string]any
	for _, ev := range events {
		evType := epStr(ev, "event_type")
		if evType == "service_install" || evType == "scheduled_task_create" {
			persistEvents = append(persistEvents, ev)
		} else if evType == "process_start" && linuxShellNames[strings.ToLower(epStr(ev, "process_name"))] {
			shellEvents = append(shellEvents, ev)
		} else if evType == "network_connection" {
			networkEvents = append(networkEvents, ev)
		}
	}
	if len(persistEvents) == 0 || len(shellEvents) == 0 || len(networkEvents) == 0 {
		return nil
	}
	// Emit one finding for the combination
	a := makeEndpointAlert("suspicious_persistence_correlation", "v1",
		"Persistence + Active Shell + Outbound Correlation", "high", 0.72, persistEvents[0])
	a.Evidence["persistence_events"] = len(persistEvents)
	a.Evidence["shell_events"]       = len(shellEvents)
	a.Evidence["network_events"]     = len(networkEvents)
	return []EndpointAlert{a}
}

func ruleRareParentChildAnalytics(events []map[string]any) []EndpointAlert {
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		parent := strings.ToLower(epStr(ev, "parent_process_name"))
		child  := strings.ToLower(epStr(ev, "process_name"))
		if !webServerProcessNames[parent] {
			continue
		}
		if !linuxShellNames[child] {
			continue
		}
		a := makeEndpointAlert("rare_parent_child_process", "v1",
			"Rare Parent-Child Process Relationship", "high", 0.82, ev)
		a.Evidence["parent"] = parent
		a.Evidence["child"]  = child
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Threat hunting behavioral rules — Phase 1 (2026-05-18)
// ---------------------------------------------------------------------------

func ruleRepeatedBehavioralChain(events []map[string]any) []EndpointAlert {
	// Detect same parent→child chain appearing multiple times in the batch
	type chainKey struct{ parent, child string }
	chainCounts := map[chainKey]int{}
	chainEvs    := map[chainKey]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		parent := strings.ToLower(epStr(ev, "parent_process_name"))
		child  := strings.ToLower(epStr(ev, "process_name"))
		if !linuxShellNames[child] && !downloaderNames[child] {
			continue
		}
		k := chainKey{parent, child}
		chainCounts[k]++
		if chainCounts[k] == 1 {
			chainEvs[k] = ev
		}
	}
	var alerts []EndpointAlert
	for k, cnt := range chainCounts {
		if cnt < 2 {
			continue
		}
		a := makeEndpointAlert("repeated_behavioral_chain", "v1",
			"Repeated Behavioral Execution Chain Pattern", "high", 0.75, chainEvs[k])
		a.Evidence["parent"]       = k.parent
		a.Evidence["child"]        = k.child
		a.Evidence["repeat_count"] = cnt
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleMultiHostBeaconPattern(events []map[string]any) []EndpointAlert {
	// Detect same destination targeted by multiple hosts
	type destKey struct{ ip string; port int64 }
	destHosts := map[destKey]map[string]bool{}
	destEvs   := map[destKey]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "network_connection" {
			continue
		}
		ip   := epStr(ev, "remote_ip")
		port := epInt64(ev, "remote_port")
		host := epStr(ev, "host")
		if ip == "" || host == "" {
			continue
		}
		k := destKey{ip, port}
		if destHosts[k] == nil {
			destHosts[k] = map[string]bool{}
		}
		destHosts[k][host] = true
		if _, exists := destEvs[k]; !exists {
			destEvs[k] = ev
		}
	}
	var alerts []EndpointAlert
	for k, hosts := range destHosts {
		if len(hosts) < 2 {
			continue
		}
		a := makeEndpointAlert("multi_host_beacon_pattern", "v1",
			"Multi-Host Beacon Pattern to Same Destination", "critical", 0.82, destEvs[k])
		a.Evidence["destination"] = fmt.Sprintf("%s:%d", k.ip, k.port)
		a.Evidence["host_count"]  = len(hosts)
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleRepeatedLolbinSequence(events []map[string]any) []EndpointAlert {
	lolbinCounts := map[string]int{}
	lolbinEvs    := map[string]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process_name"))
		if !lolbinNames[name] {
			continue
		}
		lolbinCounts[name]++
		if lolbinCounts[name] == 1 {
			lolbinEvs[name] = ev
		}
	}
	var alerts []EndpointAlert
	for name, cnt := range lolbinCounts {
		if cnt < 3 {
			continue
		}
		a := makeEndpointAlert("repeated_lolbin_sequence", "v1",
			"Repeated LOLBin Execution Sequence", "high", 0.72, lolbinEvs[name])
		a.Evidence["lolbin"]       = name
		a.Evidence["repeat_count"] = cnt
		alerts = append(alerts, a)
	}
	return alerts
}

func rulePersistenceReactivation(events []map[string]any) []EndpointAlert {
	// Detect persistence item key appearing in both service_install and a later snapshot's service_install
	seenPersist := map[string]int{}
	var alerts []EndpointAlert
	for _, ev := range events {
		evType := epStr(ev, "event_type")
		if evType != "service_install" && evType != "scheduled_task_create" {
			continue
		}
		key := epStr(ev, "service_name")
		if key == "" {
			key = epStr(ev, "task_name")
		}
		if key == "" {
			continue
		}
		seenPersist[key]++
		if seenPersist[key] == 2 {
			a := makeEndpointAlert("persistence_reactivation_pattern", "v1",
				"Persistence Item Reactivation Pattern", "high", 0.70, ev)
			a.Evidence["item_key"]     = key
			a.Evidence["event_type"]   = evType
			a.Evidence["repeat_count"] = seenPersist[key]
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func correlateEndpointShadow(events []map[string]any) []EndpointAlert {
	endpointEvents := make([]map[string]any, 0, len(events))
	for _, ev := range events {
		if epStr(ev, "telemetry_type") == "endpoint" {
			endpointEvents = append(endpointEvents, ev)
		}
	}
	if len(endpointEvents) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleParentChildProcess(endpointEvents)...)
	alerts = append(alerts, rulePowershellEncoded(endpointEvents)...)
	alerts = append(alerts, ruleSuspiciousTempFile(endpointEvents)...)
	alerts = append(alerts, ruleFailedLoginBurst(endpointEvents)...)
	alerts = append(alerts, ruleSuspiciousDNS(endpointEvents)...)
	alerts = append(alerts, ruleSuspiciousOutbound(endpointEvents)...)
	alerts = append(alerts, ruleScheduledTaskPersistence(endpointEvents)...)
	alerts = append(alerts, ruleNewServicePersistence(endpointEvents)...)
	alerts = append(alerts, ruleC2BeaconPattern(endpointEvents)...)
	// Phase 1 behavioral visibility rules (2026-05-18)
	alerts = append(alerts, ruleParentChildChain(endpointEvents)...)
	alerts = append(alerts, ruleShellChain(endpointEvents)...)
	alerts = append(alerts, ruleLongLivedProcess(endpointEvents)...)
	alerts = append(alerts, rulePersistenceEntry(endpointEvents)...)
	// Phase 1 behavioral analytics rules (2026-05-18)
	alerts = append(alerts, ruleExecutionChain(endpointEvents)...)
	alerts = append(alerts, ruleBeaconPatternAnalytics(endpointEvents)...)
	alerts = append(alerts, ruleLolbinUsage(endpointEvents)...)
	alerts = append(alerts, rulePersistenceCorrelationAnalytics(endpointEvents)...)
	alerts = append(alerts, ruleRareParentChildAnalytics(endpointEvents)...)
	// Threat hunting behavioral rules (2026-05-18)
	alerts = append(alerts, ruleRepeatedBehavioralChain(endpointEvents)...)
	alerts = append(alerts, ruleMultiHostBeaconPattern(endpointEvents)...)
	alerts = append(alerts, ruleRepeatedLolbinSequence(endpointEvents)...)
	alerts = append(alerts, rulePersistenceReactivation(endpointEvents)...)
	return dedupeEndpointAlerts(alerts)
}

// ---------------------------------------------------------------------------
// Cross-domain shadow correlation — Phase 1 (2026-05-18)
// Correlates endpoint events with identity/cloud/SaaS events in the same batch.
// All output → xdr.alerts.shadow.endpoint only. Advisory-only. No containment.
// ---------------------------------------------------------------------------

func correlateEndpointShadowCrossDomain(events []map[string]any) []EndpointAlert {
	if len(events) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleCrossDomainIdentityEndpoint(events)...)
	alerts = append(alerts, ruleCrossDomainIdentityPersistence(events)...)
	alerts = append(alerts, ruleCrossDomainSaaSBeacon(events)...)
	alerts = append(alerts, ruleCrossHostSharedDestinationLolbin(events)...)
	alerts = append(alerts, ruleCrossDomainAttackProgression(events)...)
	return alerts
}

func ruleCrossDomainIdentityEndpoint(events []map[string]any) []EndpointAlert {
	// Detect identity auth failure followed by endpoint shell execution for the same user.
	// Advisory-only: emits to shadow topic only.
	identityFailureUsers := map[string]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "identity" {
			continue
		}
		evType := epStr(ev, "event_type")
		if evType != "login_failed" && evType != "mfa_failed" {
			continue
		}
		user := epStr(ev, "user")
		if user != "" {
			identityFailureUsers[user] = ev
		}
	}
	if len(identityFailureUsers) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" || epStr(ev, "event_type") != "process_start" {
			continue
		}
		user    := epStr(ev, "user")
		process := strings.ToLower(epStr(ev, "process_name"))
		if user == "" || (!linuxShellNames[process] && !downloaderNames[process]) {
			continue
		}
		if identityEv, ok := identityFailureUsers[user]; ok {
			a := makeEndpointAlert("identity_endpoint_execution_chain", "v1",
				"Identity Failure Followed by Endpoint Shell Execution", "critical", 0.85, ev)
			a.Evidence["actor"]            = user
			a.Evidence["identity_event"]   = epStr(identityEv, "event_type")
			a.Evidence["endpoint_process"] = process
			a.Evidence["advisory"]         = "cross_domain_shadow_only"
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleCrossDomainIdentityPersistence(events []map[string]any) []EndpointAlert {
	// Detect identity privilege escalation followed by endpoint persistence entry.
	privEscUsers := map[string]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "identity" {
			continue
		}
		action := epStr(ev, "action")
		evType := epStr(ev, "event_type")
		if action != "privilege_escalation" && evType != "privilege_escalation" {
			continue
		}
		user := epStr(ev, "user")
		if user != "" {
			privEscUsers[user] = ev
		}
	}
	if len(privEscUsers) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		evType := epStr(ev, "event_type")
		if evType != "service_install" && evType != "scheduled_task_create" {
			continue
		}
		user := epStr(ev, "user")
		if user == "" {
			continue
		}
		if identityEv, ok := privEscUsers[user]; ok {
			a := makeEndpointAlert("identity_persistence_correlation", "v1",
				"Identity Privilege Escalation Correlated with Endpoint Persistence", "high", 0.80, ev)
			a.Evidence["actor"]             = user
			a.Evidence["identity_action"]   = epStr(identityEv, "action")
			a.Evidence["persistence_event"] = evType
			a.Evidence["advisory"]          = "cross_domain_shadow_only"
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleCrossDomainSaaSBeacon(events []map[string]any) []EndpointAlert {
	// Detect SaaS anomaly activity correlated with endpoint outbound beacon from the same source IP.
	saasSourceIPs := map[string]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "saas" {
			continue
		}
		ip := epStr(ev, "source_ip")
		if ip != "" {
			saasSourceIPs[ip] = ev
		}
	}
	if len(saasSourceIPs) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" || epStr(ev, "event_type") != "network_connection" {
			continue
		}
		srcIP := epStr(ev, "source_ip")
		if srcIP == "" {
			srcIP = epStr(ev, "host")
		}
		if saasEv, ok := saasSourceIPs[srcIP]; ok {
			a := makeEndpointAlert("saas_endpoint_beacon_chain", "v1",
				"SaaS Activity Correlated with Endpoint Beacon Pattern", "high", 0.77, ev)
			a.Evidence["source_ip"]         = srcIP
			a.Evidence["saas_event_type"]   = epStr(saasEv, "event_type")
			a.Evidence["endpoint_remote_ip"]= epStr(ev, "remote_ip")
			a.Evidence["advisory"]          = "cross_domain_shadow_only"
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleCrossHostSharedDestinationLolbin(events []map[string]any) []EndpointAlert {
	// Detect multiple hosts using LOLBin processes to connect to the same destination.
	hostLolbins := map[string]bool{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" || epStr(ev, "event_type") != "process_start" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process_name"))
		host := epStr(ev, "host")
		if lolbinNames[name] && host != "" {
			hostLolbins[host] = true
		}
	}
	if len(hostLolbins) == 0 {
		return nil
	}
	type destKey struct {
		ip   string
		port int64
	}
	destHosts := map[destKey]map[string]bool{}
	destEvs   := map[destKey]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" || epStr(ev, "event_type") != "network_connection" {
			continue
		}
		host := epStr(ev, "host")
		if !hostLolbins[host] {
			continue
		}
		ip   := epStr(ev, "remote_ip")
		port := epInt64(ev, "remote_port")
		if ip == "" {
			continue
		}
		k := destKey{ip, port}
		if destHosts[k] == nil {
			destHosts[k] = map[string]bool{}
		}
		destHosts[k][host] = true
		if _, exists := destEvs[k]; !exists {
			destEvs[k] = ev
		}
	}
	var alerts []EndpointAlert
	for k, hosts := range destHosts {
		if len(hosts) < 2 {
			continue
		}
		a := makeEndpointAlert("multi_host_shared_destination", "v1",
			"Multiple Hosts LOLBin Activity to Shared Destination", "critical", 0.88, destEvs[k])
		a.Evidence["destination"] = fmt.Sprintf("%s:%d", k.ip, k.port)
		a.Evidence["host_count"]  = len(hosts)
		a.Evidence["advisory"]    = "cross_domain_shadow_only"
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleCrossDomainAttackProgression(events []map[string]any) []EndpointAlert {
	// Detect same actor touching multiple telemetry domains including endpoint.
	// Requires endpoint involvement + at least one other domain.
	actorDomains := map[string]map[string]bool{}
	actorEvs     := map[string]map[string]any{}
	for _, ev := range events {
		user    := epStr(ev, "user")
		telType := epStr(ev, "telemetry_type")
		if user == "" || telType == "" {
			continue
		}
		if actorDomains[user] == nil {
			actorDomains[user] = map[string]bool{}
		}
		actorDomains[user][telType] = true
		if _, exists := actorEvs[user]; !exists {
			actorEvs[user] = ev
		}
	}
	var alerts []EndpointAlert
	for actor, domains := range actorDomains {
		if len(domains) < 2 || !domains["endpoint"] {
			continue
		}
		domainList := make([]string, 0, len(domains))
		for d := range domains {
			domainList = append(domainList, d)
		}
		sort.Strings(domainList)
		a := makeEndpointAlert("cross_domain_attack_progression", "v1",
			"Cross-Domain Attack Progression Detected", "critical", 0.82, actorEvs[actor])
		a.Evidence["actor"]        = actor
		a.Evidence["domains"]      = strings.Join(domainList, ",")
		a.Evidence["domain_count"] = len(domains)
		a.Evidence["advisory"]     = "cross_domain_shadow_only"
		alerts = append(alerts, a)
	}
	return alerts
}

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

// ---------------------------------------------------------------------------
// Streaming endpoint shadow rules — Phase 1
// Advisory-only. Publishes to xdr.alerts.shadow.endpoint only.
// No autonomous containment. No kernel telemetry.
// ---------------------------------------------------------------------------

func correlateEndpointShadowStreaming(events []map[string]any) []EndpointAlert {
	if len(events) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleStreamRapidShellChain(events)...)
	alerts = append(alerts, ruleStreamRapidPersistenceCreation(events)...)
	alerts = append(alerts, ruleStreamBurstOutboundActivity(events)...)
	alerts = append(alerts, ruleStreamShortLivedSuspiciousProcess(events)...)
	alerts = append(alerts, ruleStreamRapidExecutionBeaconPattern(events)...)
	return alerts
}

func ruleStreamRapidShellChain(events []map[string]any) []EndpointAlert {
	// Detect ≥3 shell execution events within a single batch for the same host.
	// Advisory-only — no autonomous response.
	hostShellCounts := map[string][]map[string]any{}
	shellNames := map[string]bool{"bash": true, "sh": true, "zsh": true, "cmd": true, "powershell": true,
		"python": true, "python3": true, "perl": true, "ruby": true, "wscript": true, "cscript": true}

	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		evType := epStr(ev, "event_type")
		if evType != "process_started" && evType != "shell_execution_detected" {
			continue
		}
		proc := strings.ToLower(epStr(ev, "process_name"))
		if !shellNames[proc] && evType != "shell_execution_detected" {
			continue
		}
		host := epStr(ev, "host_id")
		if host == "" {
			host = epStr(ev, "hostname")
		}
		if host != "" {
			hostShellCounts[host] = append(hostShellCounts[host], ev)
		}
	}

	var alerts []EndpointAlert
	for host, evs := range hostShellCounts {
		if len(evs) < 3 {
			continue
		}
		a := makeEndpointAlert("stream_rapid_shell_chain", "v1",
			"Rapid Shell Execution Chain Detected in Streaming Telemetry", "high", 0.78, evs[0])
		a.Evidence["host"]          = host
		a.Evidence["shell_count"]   = len(evs)
		a.Evidence["advisory"]      = "streaming_shadow_only"
		a.Evidence["no_autonomous"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleStreamRapidPersistenceCreation(events []map[string]any) []EndpointAlert {
	// Detect ≥3 persistence creation/modification events in one batch.
	hostPersistCounts := map[string][]map[string]any{}

	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		evType := epStr(ev, "event_type")
		if evType != "persistence_item_created" && evType != "persistence_item_modified" {
			continue
		}
		host := epStr(ev, "host_id")
		if host == "" {
			host = epStr(ev, "hostname")
		}
		if host != "" {
			hostPersistCounts[host] = append(hostPersistCounts[host], ev)
		}
	}

	var alerts []EndpointAlert
	for host, evs := range hostPersistCounts {
		if len(evs) < 3 {
			continue
		}
		a := makeEndpointAlert("stream_rapid_persistence_creation", "v1",
			"Rapid Persistence Creation Detected in Streaming Telemetry", "high", 0.80, evs[0])
		a.Evidence["host"]            = host
		a.Evidence["persistence_count"] = len(evs)
		a.Evidence["advisory"]        = "streaming_shadow_only"
		a.Evidence["no_autonomous"]   = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleStreamBurstOutboundActivity(events []map[string]any) []EndpointAlert {
	// Detect ≥10 outbound connection events from the same host in one batch.
	hostConnCounts := map[string][]map[string]any{}

	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" {
			continue
		}
		if epStr(ev, "event_type") != "outbound_connection_opened" {
			continue
		}
		host := epStr(ev, "host_id")
		if host == "" {
			host = epStr(ev, "hostname")
		}
		if host != "" {
			hostConnCounts[host] = append(hostConnCounts[host], ev)
		}
	}

	var alerts []EndpointAlert
	for host, evs := range hostConnCounts {
		if len(evs) < 10 {
			continue
		}
		destSet := map[string]bool{}
		for _, ev := range evs {
			if d := epStr(ev, "connection_dest"); d != "" {
				destSet[d] = true
			}
		}
		a := makeEndpointAlert("stream_burst_outbound_activity", "v1",
			"Burst Outbound Connection Activity Detected in Streaming Telemetry", "medium", 0.70, evs[0])
		a.Evidence["host"]              = host
		a.Evidence["connection_count"]  = len(evs)
		a.Evidence["unique_dests"]      = len(destSet)
		a.Evidence["advisory"]          = "streaming_shadow_only"
		a.Evidence["no_autonomous"]     = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleStreamShortLivedSuspiciousProcess(events []map[string]any) []EndpointAlert {
	// Detect processes that start AND terminate in the same batch with suspicious names.
	suspiciousNames := map[string]bool{
		"nc": true, "ncat": true, "nmap": true, "wget": true, "curl": true,
		"certutil": true, "bitsadmin": true, "mshta": true, "wscript": true, "cscript": true,
	}

	// Map pid@host → started event
	startedProcs := map[string]map[string]any{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" || epStr(ev, "event_type") != "process_started" {
			continue
		}
		name := strings.ToLower(epStr(ev, "process_name"))
		if !suspiciousNames[name] {
			continue
		}
		pid  := epStr(ev, "process_pid")
		host := epStr(ev, "host_id")
		if pid != "" && host != "" {
			startedProcs[pid+"@"+host] = ev
		}
	}
	if len(startedProcs) == 0 {
		return nil
	}

	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" || epStr(ev, "event_type") != "process_terminated" {
			continue
		}
		pid  := epStr(ev, "process_pid")
		host := epStr(ev, "host_id")
		key  := pid + "@" + host
		if startEv, ok := startedProcs[key]; ok {
			a := makeEndpointAlert("stream_short_lived_suspicious_process", "v1",
				"Short-Lived Suspicious Process Detected in Streaming Telemetry", "medium", 0.72, startEv)
			a.Evidence["host"]         = host
			a.Evidence["process_name"] = epStr(startEv, "process_name")
			a.Evidence["process_pid"]  = pid
			a.Evidence["advisory"]     = "streaming_shadow_only"
			a.Evidence["no_autonomous"]= true
			alerts = append(alerts, a)
		}
	}
	return alerts
}

func ruleStreamRapidExecutionBeaconPattern(events []map[string]any) []EndpointAlert {
	// Detect ≥4 process_started events for the same process name from the same host in one batch.
	// Consistent count suggests beaconing pattern.
	type hostProc struct{ host, proc string }
	counts := map[hostProc]int{}

	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "endpoint" || epStr(ev, "event_type") != "process_started" {
			continue
		}
		host := epStr(ev, "host_id")
		proc := strings.ToLower(epStr(ev, "process_name"))
		if host != "" && proc != "" {
			counts[hostProc{host, proc}]++
		}
	}

	var alerts []EndpointAlert
	for key, count := range counts {
		if count < 4 {
			continue
		}
		a := makeEndpointAlert("stream_rapid_execution_beacon_pattern", "v1",
			"Rapid Execution Beacon Pattern Detected in Streaming Telemetry", "medium", 0.68, map[string]any{})
		a.Evidence["host"]           = key.host
		a.Evidence["process_name"]   = key.proc
		a.Evidence["execution_count"]= count
		a.Evidence["advisory"]       = "streaming_shadow_only"
		a.Evidence["no_autonomous"]  = true
		alerts = append(alerts, a)
	}
	return alerts
}

// ---------------------------------------------------------------------------
// Network shadow rules — DNS/proxy/firewall Phase 1
// Advisory-only. Publishes to xdr.alerts.shadow.network only.
// No active blocking. No firewall rule push. No IP/domain blocking.
// No packet sniffing. No DPI inspection. No autonomous response.
// ---------------------------------------------------------------------------

func correlateNetworkShadowAll(events []map[string]any) []EndpointAlert {
	if len(events) == 0 {
		return nil
	}
	var alerts []EndpointAlert
	alerts = append(alerts, ruleNetworkSuspiciousDnsTld(events)...)
	alerts = append(alerts, ruleNetworkDnsTxtQueryPattern(events)...)
	alerts = append(alerts, ruleNetworkRepeatedNxdomain(events)...)
	alerts = append(alerts, ruleNetworkSuspiciousUserAgent(events)...)
	alerts = append(alerts, ruleNetworkHighVolumeProxyEgress(events)...)
	alerts = append(alerts, ruleNetworkRepeatedDeniedOutbound(events)...)
	alerts = append(alerts, ruleNetworkUnusualDestinationPort(events)...)
	alerts = append(alerts, ruleNetworkRareExternalDestination(events)...)
	alerts = append(alerts, ruleNetworkSuspiciousAllowAfterDeny(events)...)
	return alerts
}

func ruleNetworkSuspiciousDnsTld(events []map[string]any) []EndpointAlert {
	// Detect DNS queries to suspicious TLDs. Advisory-only, no blocking.
	suspiciousTlds := map[string]bool{
		".ru": true, ".cn": true, ".tk": true, ".ml": true, ".ga": true,
		".cf": true, ".gq": true, ".pw": true, ".xyz": true, ".top": true,
	}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "dns" {
			continue
		}
		domain := strings.ToLower(epStr(ev, "queried_domain"))
		if domain == "" {
			continue
		}
		parts := strings.Split(domain, ".")
		if len(parts) < 2 {
			continue
		}
		tld := "." + parts[len(parts)-1]
		if !suspiciousTlds[tld] {
			continue
		}
		host := epStr(ev, "host_id")
		key  := host + "|" + tld
		if seen[key] {
			continue
		}
		seen[key] = true
		a := makeEndpointAlert("suspicious_dns_tld", "v1", "Suspicious DNS TLD Query Detected", "medium", 0.72, ev)
		a.Evidence["host"]         = host
		a.Evidence["tld"]          = tld
		a.Evidence["domain"]       = domain
		a.Evidence["advisory"]     = "network_shadow_only"
		a.Evidence["no_blocking"]  = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkDnsTxtQueryPattern(events []map[string]any) []EndpointAlert {
	// Detect ≥2 TXT DNS queries from same host in batch. Advisory-only.
	hostTxtCount := map[string]int{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "dns" || strings.ToUpper(epStr(ev, "query_type")) != "TXT" {
			continue
		}
		host := epStr(ev, "host_id")
		if host != "" {
			hostTxtCount[host]++
		}
	}
	var alerts []EndpointAlert
	for host, count := range hostTxtCount {
		if count < 2 {
			continue
		}
		a := makeEndpointAlert("dns_txt_query_pattern", "v1", "Repeated DNS TXT Queries Detected", "medium", 0.68, map[string]any{})
		a.Evidence["host"]        = host
		a.Evidence["txt_count"]   = count
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkRepeatedNxdomain(events []map[string]any) []EndpointAlert {
	// Detect ≥5 NXDOMAIN responses for same host in batch. Advisory-only.
	hostNxCount := map[string]int{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "dns" {
			continue
		}
		if strings.ToUpper(epStr(ev, "response_code")) != "NXDOMAIN" {
			continue
		}
		host := epStr(ev, "host_id")
		if host != "" {
			hostNxCount[host]++
		}
	}
	var alerts []EndpointAlert
	for host, count := range hostNxCount {
		if count < 5 {
			continue
		}
		a := makeEndpointAlert("repeated_nxdomain", "v1", "Repeated NXDOMAIN Responses Detected", "medium", 0.74, map[string]any{})
		a.Evidence["host"]         = host
		a.Evidence["nxdomain_count"] = count
		a.Evidence["advisory"]     = "network_shadow_only"
		a.Evidence["no_blocking"]  = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkSuspiciousUserAgent(events []map[string]any) []EndpointAlert {
	// Detect suspicious user-agent strings in proxy events. Advisory-only.
	suspiciousUA := []string{"curl/", "python-requests/", "go-http-client/", "wget/", "sqlmap", "nikto", "masscan", "zgrab"}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "proxy" {
			continue
		}
		ua   := strings.ToLower(epStr(ev, "user_agent"))
		host := epStr(ev, "host_id")
		for _, pat := range suspiciousUA {
			if strings.Contains(ua, pat) && !seen[host+"|"+pat] {
				seen[host+"|"+pat] = true
				a := makeEndpointAlert("suspicious_user_agent", "v1", "Suspicious Proxy User-Agent Detected", "high", 0.80, ev)
				a.Evidence["host"]        = host
				a.Evidence["ua_pattern"]  = pat
				a.Evidence["advisory"]    = "network_shadow_only"
				a.Evidence["no_blocking"] = true
				alerts = append(alerts, a)
				break
			}
		}
	}
	return alerts
}

func ruleNetworkHighVolumeProxyEgress(events []map[string]any) []EndpointAlert {
	// Detect high total bytes_out via proxy per host in batch. Advisory-only.
	hostBytes := map[string]int64{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "proxy" {
			continue
		}
		host := epStr(ev, "host_id")
		if host == "" {
			continue
		}
		if b, ok := ev["bytes_out"].(float64); ok {
			hostBytes[host] += int64(b)
		}
	}
	const threshold int64 = 50 * 1024 * 1024
	var alerts []EndpointAlert
	for host, total := range hostBytes {
		if total < threshold {
			continue
		}
		a := makeEndpointAlert("high_volume_proxy_egress", "v1", "High Volume Proxy Egress Detected", "high", 0.76, map[string]any{})
		a.Evidence["host"]        = host
		a.Evidence["bytes_out"]   = total
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkRepeatedDeniedOutbound(events []map[string]any) []EndpointAlert {
	// Detect ≥5 denied firewall flows per host. Advisory-only.
	hostDenyCount := map[string]int{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		action := strings.ToLower(epStr(ev, "action"))
		if action != "deny" && action != "drop" {
			continue
		}
		host := epStr(ev, "host_id")
		if host != "" {
			hostDenyCount[host]++
		}
	}
	var alerts []EndpointAlert
	for host, count := range hostDenyCount {
		if count < 5 {
			continue
		}
		a := makeEndpointAlert("repeated_denied_outbound", "v1", "Repeated Denied Outbound Firewall Flows Detected", "high", 0.80, map[string]any{})
		a.Evidence["host"]        = host
		a.Evidence["deny_count"]  = count
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkUnusualDestinationPort(events []map[string]any) []EndpointAlert {
	// Detect connections to unusual/suspicious ports. Advisory-only.
	unusualPorts := map[int]bool{4444: true, 1337: true, 31337: true, 6666: true, 9999: true, 8888: true, 4321: true}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		port := 0
		if p, ok := ev["destination_port"].(float64); ok {
			port = int(p)
		}
		if !unusualPorts[port] {
			continue
		}
		host := epStr(ev, "host_id")
		key  := fmt.Sprintf("%s|%d", host, port)
		if seen[key] {
			continue
		}
		seen[key] = true
		a := makeEndpointAlert("unusual_destination_port", "v1", "Unusual Firewall Destination Port Detected", "high", 0.82, ev)
		a.Evidence["host"]        = host
		a.Evidence["port"]        = port
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkRareExternalDestination(events []map[string]any) []EndpointAlert {
	// Detect first-seen external destinations per host in batch.
	// Advisory-only — does NOT block the destination.
	type hostDest struct{ host, dest string }
	seen := map[hostDest]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		dest := epStr(ev, "destination_ip")
		host := epStr(ev, "host_id")
		if dest == "" || host == "" {
			continue
		}
		// Only flag RFC-1918 external destinations (simplified: non-private)
		if strings.HasPrefix(dest, "10.") || strings.HasPrefix(dest, "192.168.") ||
			strings.HasPrefix(dest, "172.16.") || strings.HasPrefix(dest, "127.") {
			continue
		}
		key := hostDest{host, dest}
		if seen[key] {
			continue
		}
		seen[key] = true
		a := makeEndpointAlert("rare_external_destination", "v1", "Rare External Firewall Destination Detected", "medium", 0.62, ev)
		a.Evidence["host"]        = host
		a.Evidence["destination"] = dest
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func ruleNetworkSuspiciousAllowAfterDeny(events []map[string]any) []EndpointAlert {
	// Detect destination that was denied then allowed in the same batch. Advisory-only.
	deniedDests := map[string]bool{}
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		action := strings.ToLower(epStr(ev, "action"))
		if action == "deny" || action == "drop" {
			if d := epStr(ev, "destination_ip"); d != "" {
				deniedDests[d] = true
			}
		}
	}
	if len(deniedDests) == 0 {
		return nil
	}
	seen := map[string]bool{}
	var alerts []EndpointAlert
	for _, ev := range events {
		if epStr(ev, "telemetry_type") != "firewall" {
			continue
		}
		if strings.ToLower(epStr(ev, "action")) != "allow" {
			continue
		}
		dest := epStr(ev, "destination_ip")
		host := epStr(ev, "host_id")
		if !deniedDests[dest] || seen[host+"|"+dest] {
			continue
		}
		seen[host+"|"+dest] = true
		a := makeEndpointAlert("suspicious_allow_after_deny", "v1", "Suspicious Allow After Prior Deny Detected", "high", 0.85, ev)
		a.Evidence["host"]        = host
		a.Evidence["destination"] = dest
		a.Evidence["advisory"]    = "network_shadow_only"
		a.Evidence["no_blocking"] = true
		alerts = append(alerts, a)
	}
	return alerts
}

func envBool(name string, fallback bool) bool {
	value := strings.ToLower(strings.TrimSpace(env(name, "")))
	if value == "" {
		return fallback
	}
	return value == "1" || value == "true" || value == "yes"
}

