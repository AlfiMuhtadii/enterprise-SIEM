package main

import (
	"bufio"
	"bytes"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
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

type Worker struct {
	redpandaREST string
	inputTopic   string
	outputTopic  string
	dlqTopic     string
	group        string
	processed    atomic.Int64
	malformed    atomic.Int64
	forwarded    atomic.Int64
	publishErrors atomic.Int64
	consumerPolls         atomic.Int64
	consumerErrors        atomic.Int64
	queueDepth            atomic.Int64
	reconnectCount        atomic.Int64
	pollErrorCount        atomic.Int64
	consumerRecreateCount atomic.Int64
	queueCapacity int
	producerBatch int
	producerFlush time.Duration
	producerQueues []chan map[string]any
	nextProducer  atomic.Uint64
	mu           sync.Mutex
}

func main() {
	addr := flag.String("addr", env("XDR_NORMALIZER_ADDR", ":8092"), "listen address")
	file := flag.String("file", "", "optional JSONL file to normalize once")
	flag.Parse()

	validateNormalizerSecrets()

	w := &Worker{
		redpandaREST: env("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082"),
		inputTopic:   env("XDR_RAW_TOPIC", "telemetry.raw"),
		outputTopic:  env("XDR_NORMALIZED_TOPIC", "telemetry.normalized"),
		dlqTopic:     env("XDR_NORMALIZER_DLQ_TOPIC", "telemetry.normalized.dlq"),
		group:        env("XDR_NORMALIZER_GROUP", "normalizer-worker-v1"),
		queueCapacity: envInt("XDR_NORMALIZER_QUEUE_DEPTH", 200000),
		producerBatch: envInt("XDR_NORMALIZER_PRODUCER_BATCH", 5000),
		producerFlush: time.Duration(envInt("XDR_NORMALIZER_FLUSH_MS", 100)) * time.Millisecond,
	}
	producerCount := envInt("XDR_NORMALIZER_PRODUCERS", 4)
	if producerCount < 1 {
		producerCount = 1
	}
	w.producerQueues = make([]chan map[string]any, producerCount)
	for i := 0; i < producerCount; i++ {
		w.producerQueues[i] = make(chan map[string]any, max(1, w.queueCapacity/producerCount))
		go w.producerLoop(w.producerQueues[i])
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", w.health)
	mux.HandleFunc("/ready", w.ready)
	mux.HandleFunc("/metrics", w.metrics)
	mux.HandleFunc("/v1/normalize", w.normalizeHTTP)
	go func() {
		log.Printf("xdr normalizer metrics listening on %s", *addr)
		log.Fatal(http.ListenAndServe(*addr, mux))
	}()

	if *file != "" {
		if err := w.normalizeFile(*file); err != nil {
			log.Fatal(err)
		}
		return
	}
	if envBool("XDR_NORMALIZER_EVENT_LOOP_ENABLED", true) {
		go w.consumeLoop()
	}
	select {}
}

func (w *Worker) health(rw http.ResponseWriter, r *http.Request) {
	writeJSON(rw, http.StatusOK, map[string]any{"status": "ok", "service": "telemetry-normalizer", "input": w.inputTopic, "output": w.outputTopic})
}

func (w *Worker) ready(rw http.ResponseWriter, r *http.Request) {
	writeJSON(rw, http.StatusOK, map[string]any{"status": "ready", "service": "telemetry-normalizer"})
}

func (w *Worker) metrics(rw http.ResponseWriter, r *http.Request) {
	var mem runtime.MemStats
	runtime.ReadMemStats(&mem)
	writeJSON(rw, http.StatusOK, map[string]any{
		"processed":      w.processed.Load(),
		"malformed":      w.malformed.Load(),
		"forwarded":      w.forwarded.Load(),
		"publish_errors": w.publishErrors.Load(),
		"consumer_polls":          w.consumerPolls.Load(),
		"consumer_errors":         w.consumerErrors.Load(),
		"reconnect_count":         w.reconnectCount.Load(),
		"poll_error_count":        w.pollErrorCount.Load(),
		"consumer_recreate_count": w.consumerRecreateCount.Load(),
		"queue_depth":             w.queueDepth.Load(),
		"queue_capacity": w.queueCapacity,
		"goroutines":     runtime.NumGoroutine(),
		"heap_alloc_mb":  float64(mem.HeapAlloc) / 1024.0 / 1024.0,
	})
}

func (w *Worker) consumeLoop() {
	for {
		w.consumeOnce()
		w.reconnectCount.Add(1)
		log.Printf("normalizer consumer reconnecting in 5s")
		time.Sleep(5 * time.Second)
	}
}

func (w *Worker) consumeOnce() {
	w.consumerRecreateCount.Add(1)
	instance := fmt.Sprintf("normalizer-%d", time.Now().UnixNano())
	baseURI, err := w.consumerCreate(w.group, instance)
	if err != nil {
		w.consumerErrors.Add(1)
		log.Printf("normalizer consumer create failed: %v", err)
		return
	}
	if err := w.consumerSubscribe(baseURI, w.inputTopic); err != nil {
		w.consumerErrors.Add(1)
		log.Printf("normalizer consumer subscribe failed: %v", err)
		return
	}
	log.Printf("normalizer consuming topic=%s group=%s", w.inputTopic, w.group)
	for {
		records, err := w.consumerPoll(baseURI)
		w.consumerPolls.Add(1)
		if err != nil {
			w.pollErrorCount.Add(1)
			w.consumerErrors.Add(1)
			log.Printf("normalizer consumer poll failed: %v — reconnecting", err)
			return
		}
		if len(records) == 0 {
			continue
		}
		rawEvents := make([]map[string]any, 0, len(records))
		for _, record := range records {
			value, ok := record["value"].(map[string]any)
			if !ok {
				w.malformed.Add(1)
				_ = w.publish(w.dlqTopic, []map[string]any{{"error": "invalid_record_value", "record": record}})
				continue
			}
			rawEvents = append(rawEvents, value)
		}
		normalized, malformed := normalizeBatch(rawEvents)
		w.processed.Add(int64(len(rawEvents)))
		w.malformed.Add(int64(malformed))
		for _, event := range normalized {
			w.enqueue(event)
			w.queueDepth.Add(1)
		}
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
	req, _ := http.NewRequest(http.MethodGet, baseURI+"/records?timeout=1000&max_bytes=1048576", nil)
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

func (w *Worker) normalizeHTTP(rw http.ResponseWriter, r *http.Request) {
	started := time.Now()
	if r.Method != http.MethodPost {
		http.Error(rw, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	if err := verifyInternalToken(r.Header.Get("X-Internal-Service-Token")); err != nil {
		log.Printf("[SECURITY] normalizer internal auth failure path=%s ip=%s reason=%s", r.URL.Path, r.RemoteAddr, err)
		writeJSON(rw, http.StatusUnauthorized, map[string]any{"error": "unauthorized", "code": "invalid_internal_token"})
		return
	}
	body, err := io.ReadAll(io.LimitReader(r.Body, 8*1024*1024))
	if err != nil {
		http.Error(rw, "read_failed", http.StatusBadRequest)
		return
	}
	var rawEvents []map[string]any
	if err := json.Unmarshal(body, &rawEvents); err != nil {
		var one map[string]any
		if oneErr := json.Unmarshal(body, &one); oneErr != nil {
			http.Error(rw, "invalid_json", http.StatusBadRequest)
			return
		}
		rawEvents = []map[string]any{one}
	}
	if w.queueDepth.Load()+int64(len(rawEvents)) > int64(w.queueCapacity) {
		http.Error(rw, "normalizer_backpressure", http.StatusTooManyRequests)
		return
	}
	normalized, malformed := normalizeBatch(rawEvents)
	w.processed.Add(int64(len(rawEvents)))
	w.malformed.Add(int64(malformed))
	for _, event := range normalized {
		w.enqueue(event)
		w.queueDepth.Add(1)
	}
	writeJSON(rw, http.StatusAccepted, map[string]any{
		"processed":  len(rawEvents),
		"enqueued":   len(normalized),
		"malformed":  malformed,
		"queue_depth": w.queueDepth.Load(),
		"latency_ms": time.Since(started).Milliseconds(),
	})
}

func normalizeBatch(rawEvents []map[string]any) ([]map[string]any, int) {
	workers := envInt("XDR_NORMALIZER_WORKERS", runtime.NumCPU())
	if workers < 1 {
		workers = 1
	}
	if workers > len(rawEvents) && len(rawEvents) > 0 {
		workers = len(rawEvents)
	}
	type result struct {
		event map[string]any
		err   error
	}
	jobs := make(chan map[string]any, len(rawEvents))
	results := make(chan result, len(rawEvents))
	for i := 0; i < workers; i++ {
		go func() {
			for raw := range jobs {
				event, err := normalize(raw)
				results <- result{event: event, err: err}
			}
		}()
	}
	for _, raw := range rawEvents {
		jobs <- raw
	}
	close(jobs)
	normalized := make([]map[string]any, 0, len(rawEvents))
	malformed := 0
	for i := 0; i < len(rawEvents); i++ {
		item := <-results
		if item.err != nil {
			malformed++
			continue
		}
		normalized = append(normalized, item.event)
	}
	return normalized, malformed
}

func (w *Worker) enqueue(event map[string]any) {
	idx := int(w.nextProducer.Add(1) % uint64(len(w.producerQueues)))
	w.producerQueues[idx] <- event
}

func (w *Worker) producerLoop(events <-chan map[string]any) {
	ticker := time.NewTicker(w.producerFlush)
	defer ticker.Stop()
	batch := make([]map[string]any, 0, w.producerBatch)
	flush := func() {
		if len(batch) == 0 {
			return
		}
		if err := w.publish(w.outputTopic, batch); err != nil {
			w.publishErrors.Add(1)
			_ = w.publish(w.dlqTopic, []map[string]any{{"error": err.Error(), "count": len(batch)}})
		} else {
			w.forwarded.Add(int64(len(batch)))
		}
		w.queueDepth.Add(-int64(len(batch)))
		batch = make([]map[string]any, 0, w.producerBatch)
	}
	for {
		select {
		case event := <-events:
			batch = append(batch, event)
			if len(batch) >= w.producerBatch {
				flush()
			}
		case <-ticker.C:
			flush()
		}
	}
}

func max(a int, b int) int {
	if a > b {
		return a
	}
	return b
}

func (w *Worker) normalizeFile(path string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer f.Close()
	scanner := bufio.NewScanner(f)
	scanner.Buffer(make([]byte, 1024), 10*1024*1024)
	batch := make([]map[string]any, 0, envInt("XDR_NORMALIZER_BATCH", 500))
	for scanner.Scan() {
			w.processed.Add(1)
		var raw map[string]any
		if err := json.Unmarshal(scanner.Bytes(), &raw); err != nil {
			w.malformed.Add(1)
			_ = w.publish(w.dlqTopic, []map[string]any{{"error": "invalid_json", "raw": scanner.Text()}})
			continue
		}
		normalized, err := normalize(raw)
		if err != nil {
			w.malformed.Add(1)
			_ = w.publish(w.dlqTopic, []map[string]any{{"error": err.Error(), "event": raw}})
			continue
		}
		batch = append(batch, normalized)
		if len(batch) >= envInt("XDR_NORMALIZER_BATCH", 500) {
			if err := w.publish(w.outputTopic, batch); err != nil {
				return err
			}
			w.forwarded.Add(int64(len(batch)))
			batch = batch[:0]
		}
	}
	if len(batch) > 0 {
		if err := w.publish(w.outputTopic, batch); err != nil {
			return err
		}
		w.forwarded.Add(int64(len(batch)))
	}
	return scanner.Err()
}

func normalize(raw map[string]any) (map[string]any, error) {
	ts := first(raw, "ts", "timestamp", "event_time")
	telemetryType := strings.ToLower(fmt.Sprint(first(raw, "telemetry_type", "source_type", "category")))
	eventType := strings.ToLower(fmt.Sprint(first(raw, "event_type", "action", "operation")))
	if ts == "" || telemetryType == "" || eventType == "" {
		return nil, fmt.Errorf("missing_required_fields")
	}
	if telemetryType == "endpoint" {
		return normalizeEndpoint(raw)
	}
	if telemetryType == "dns" {
		return normalizeDns(raw)
	}
	if telemetryType == "proxy" {
		return normalizeProxy(raw)
	}
	if telemetryType == "firewall" {
		return normalizeFirewall(raw)
	}
	if telemetryType == "identity_provider" {
		return normalizeIdentityProvider(raw)
	}
	if telemetryType == "saas_audit" {
		return normalizeSaasAudit(raw)
	}
	if telemetryType == "ticket_sync" {
		return normalizeTicketSync(raw)
	}
	if telemetryType == "notification" {
		return normalizeNotificationEvent(raw)
	}
	if telemetryType == "sysmon" {
		return normalizeSysmon(raw)
	}
	if telemetryType == "powershell" {
		return normalizePowerShell(raw)
	}
	if telemetryType == "security_event" {
		return normalizeWindowsSecurityEvent(raw)
	}
	return map[string]any{
		"schema_version":  1,
		"ts":              ts,
		"event_id":        first(raw, "event_id", "id"),
		"telemetry_type":  telemetryType,
		"event_type":      eventType,
		"user":            first(raw, "user", "xdr_user", "principal", "actor"),
		"host":            first(raw, "host", "host_id", "device_name"),
		"source_ip":       first(raw, "source_ip", "src_ip", "client_ip"),
		"destination_ip":  first(raw, "destination_ip", "dst_ip", "server_ip"),
		"domain":          first(raw, "domain", "query", "url_domain"),
		"file_hash":       first(raw, "file_hash", "sha256"),
		"email_sender":    first(raw, "email_sender", "sender"),
		"email_recipient": first(raw, "email_recipient", "recipient"),
		"cloud_account":   first(raw, "cloud_account", "account_id", "tenant_id"),
		"action":          first(raw, "action", "operation"),
		"result":          first(raw, "result", "outcome", "status"),
		"risk_score":      number(first(raw, "risk_score", "risk", "score")),
		"event_source":    first(raw, "event_source", "source_adapter", "vendor"),
		"trace_id":        first(raw, "trace_id"),
		// Demo lineage metadata — injected by demo_feed.py; empty string for non-demo events.
		"demo_run_id":     first(raw, "demo_run_id"),
		"source_event_id": first(raw, "source_event_id"),
		"scenario_id":     first(raw, "scenario_id"),
		"tenant_id":       first(raw, "tenant_id"),
	}, nil
}

func normalizeEndpoint(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "endpoint-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            strings.ToLower(first(raw, "event_type", "action", "operation")),
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user"),
		"risk_score":            number(first(raw, "risk_score", "risk", "score")),
		"event_source":          first(raw, "event_source", "source_adapter", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"process": map[string]any{
			"name":         first(raw, "process_name"),
			"pid":          rawIntField(raw, "pid"),
			"ppid":         rawIntField(raw, "ppid"),
			"command_line": first(raw, "command_line"),
			"path":         first(raw, "process_path"),
			"hash":         first(raw, "file_hash", "sha256"),
		},
		"network": map[string]any{
			"source_ip":        first(raw, "source_ip", "src_ip", "client_ip"),
			"destination_ip":   first(raw, "destination_ip", "dst_ip", "server_ip"),
			"destination_port": rawIntField(raw, "destination_port"),
			"protocol":         strings.ToLower(first(raw, "protocol")),
		},
		"dns": map[string]any{
			"domain":       first(raw, "domain", "query", "url_domain"),
			"resolved_ips": raw["resolved_ips"],
		},
		"file": map[string]any{
			"path":      first(raw, "file_path"),
			"hash":      first(raw, "file_hash", "sha256"),
			"operation": strings.ToLower(first(raw, "operation")),
		},
		"auth": map[string]any{
			"action": strings.ToLower(first(raw, "action")),
			"result": strings.ToLower(first(raw, "result", "outcome")),
		},
	}, nil
}

// normalizeSysmon normalizes Sysmon telemetry events (telemetry_type=sysmon).
// Sysmon events already carry endpoint-like fields; we unify them with the
// standard endpoint envelope and add sysmon-specific metadata.
func normalizeSysmon(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	sysmonEventID := rawIntField(raw, "sysmon_event_id")
	out := map[string]any{
		"schema_version":        1,
		"normalization_version": "sysmon-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            strings.ToLower(first(raw, "event_type", "action")),
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user"),
		"event_source":          "sysmon",
		"trace_id":              first(raw, "trace_id"),
		"sysmon_event_id":       sysmonEventID,
		"is_advisory":           true,
		"process": map[string]any{
			"name":              first(raw, "process_name", "Image"),
			"command_line":      first(raw, "command_line", "CommandLine"),
			"parent_name":       first(raw, "parent_process_name", "ParentImage"),
			"integrity_level":   first(raw, "integrity_level", "IntegrityLevel"),
		},
		"network": map[string]any{
			"destination_ip":   first(raw, "destination_ip", "DestinationIp"),
			"destination_port": rawIntField(raw, "destination_port"),
		},
		"script": map[string]any{
			"is_encoded":      raw["is_encoded"],
			"decoded_preview": first(raw, "decoded_preview"),
			"script_hash":     first(raw, "script_hash"),
			"script_source":   first(raw, "script_source"),
		},
		"registry": map[string]any{
			"key":   first(raw, "registry_key", "TargetObject"),
			"value": first(raw, "registry_value", "Details"),
		},
	}
	return out, nil
}

// normalizePowerShell normalizes Windows PowerShell operational log events
// (telemetry_type=powershell).
func normalizePowerShell(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "powershell-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            "script_execution",
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user"),
		"event_source":          "powershell_operational",
		"trace_id":              first(raw, "trace_id"),
		"is_advisory":           true,
		"process": map[string]any{
			"name":    "powershell.exe",
			"command_line": first(raw, "command_line", "script_block", "ScriptBlockText"),
		},
		"script": map[string]any{
			"is_encoded":    raw["is_encoded"],
			"decoded_preview": first(raw, "decoded_preview"),
			"script_hash":   first(raw, "script_hash"),
			"script_source": first(raw, "script_source"),
			"ps_event_id":   rawIntField(raw, "ps_event_id"),
		},
	}, nil
}

// normalizeWindowsSecurityEvent normalizes Windows Security Event Log entries
// (telemetry_type=security_event). Handles 4688 (process create),
// 4672 (special privileges), 4697/4698 (service/task install).
func normalizeWindowsSecurityEvent(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	securityEventID := rawIntField(raw, "security_event_id")
	eventType := strings.ToLower(first(raw, "event_type", "action"))
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "security-event-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "event_time"),
		"telemetry_type":        "endpoint",
		"event_type":            eventType,
		"host":                  first(raw, "host", "host_id", "device_name"),
		"user":                  first(raw, "user", "SubjectUserName"),
		"event_source":          "security_event",
		"trace_id":              first(raw, "trace_id"),
		"security_event_id":     securityEventID,
		"is_advisory":           true,
		"process": map[string]any{
			"name":            first(raw, "process_name", "NewProcessName"),
			"command_line":    first(raw, "command_line", "CommandLine"),
			"integrity_level": first(raw, "integrity_level", "MandatoryLabel"),
			"parent_name":     first(raw, "parent_process_name", "ParentProcessName"),
		},
		"privilege": map[string]any{
			"escalation_type": first(raw, "escalation_type"),
			"persistence_type": first(raw, "persistence_type"),
		},
	}, nil
}

func rawIntField(raw map[string]any, key string) any {
	v, ok := raw[key]
	if !ok || v == nil {
		return nil
	}
	switch val := v.(type) {
	case float64:
		return int64(val)
	case int64:
		return val
	case int:
		return int64(val)
	default:
		return nil
	}
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

func first(row map[string]any, keys ...string) string {
	for _, key := range keys {
		if value, ok := row[key]; ok && value != nil && fmt.Sprint(value) != "" {
			return fmt.Sprint(value)
		}
	}
	return ""
}

func number(text string) float64 {
	value, err := strconv.ParseFloat(text, 64)
	if err != nil {
		return 0
	}
	return value
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
	value := strings.ToLower(strings.TrimSpace(env(name, "")))
	if value == "" {
		return fallback
	}
	return value == "1" || value == "true" || value == "yes"
}

func validateNormalizerSecrets() {
	if env("XDR_INTERNAL_AUTH_SECRET", "") == "" {
		log.Printf("[SECURITY-WARN] XDR_INTERNAL_AUTH_SECRET is not set — internal service auth uses fallback")
	}
	token := env("XDR_NORMALIZER_INTERNAL_TOKEN", "")
	if token == "" {
		log.Printf("[SECURITY-INFO] XDR_NORMALIZER_INTERNAL_TOKEN not set — /v1/normalize internal auth is permissive")
	}
}

// verifyInternalToken checks the X-Internal-Service-Token header when
// XDR_NORMALIZER_INTERNAL_TOKEN is configured. If the env var is not set,
// auth is permissive (backward compatible). Non-empty token must equal the
// configured value exactly.
// ---------------------------------------------------------------------------
// Network telemetry normalizers — dns-v1, proxy-v1, firewall-v1
// Preserves trace_id, event_id, occurred_at, source_service, host_id, user, source_ip.
// Advisory-only. No active network blocking.
// ---------------------------------------------------------------------------

func normalizeDns(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "dns-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "dns",
		"event_type":            "dns_query",
		"host_id":               first(raw, "host_id", "host", "device_name"),
		"agent_id":              first(raw, "agent_id"),
		"source_ip":             first(raw, "source_ip", "src_ip", "client_ip"),
		"user":                  first(raw, "user"),
		"queried_domain":        first(raw, "queried_domain", "domain", "query"),
		"query_type":            strings.ToUpper(first(raw, "query_type", "qtype")),
		"response_code":         strings.ToUpper(first(raw, "response_code", "rcode")),
		"resolved_ips":          raw["resolved_ips"],
		"source_service":        first(raw, "source_service", "event_source", "vendor"),
		"trace_id":              first(raw, "trace_id"),
	}, nil
}

func normalizeProxy(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	statusCode := 0
	if sc, ok := raw["status_code"].(float64); ok {
		statusCode = int(sc)
	}
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "proxy-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "proxy",
		"event_type":            "proxy_request",
		"host_id":               first(raw, "host_id", "host", "device_name"),
		"agent_id":              first(raw, "agent_id"),
		"source_ip":             first(raw, "source_ip", "src_ip", "client_ip"),
		"user":                  first(raw, "user"),
		"url":                   first(raw, "url"),
		"domain":                first(raw, "domain", "url_domain"),
		"http_method":           strings.ToUpper(first(raw, "http_method", "method")),
		"status_code":           statusCode,
		"user_agent":            first(raw, "user_agent", "ua"),
		"bytes_out":             raw["bytes_out"],
		"bytes_in":              raw["bytes_in"],
		"source_service":        first(raw, "source_service", "event_source", "vendor"),
		"trace_id":              first(raw, "trace_id"),
	}, nil
}

func normalizeFirewall(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "firewall-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "firewall",
		"event_type":            "firewall_flow",
		"host_id":               first(raw, "host_id", "host", "device_name"),
		"agent_id":              first(raw, "agent_id"),
		"source_ip":             first(raw, "source_ip", "src_ip"),
		"destination_ip":        first(raw, "destination_ip", "dst_ip"),
		"destination_port":      raw["destination_port"],
		"protocol":              strings.ToLower(first(raw, "protocol", "proto")),
		"action":                strings.ToLower(first(raw, "action", "verdict")),
		"bytes_out":             raw["bytes_out"],
		"bytes_in":              raw["bytes_in"],
		"rule_name":             first(raw, "rule_name", "policy_name"),
		"user":                  first(raw, "user"),
		"source_service":        first(raw, "source_service", "event_source", "vendor"),
		"trace_id":              first(raw, "trace_id"),
	}, nil
}

// ---------------------------------------------------------------------------
// Enterprise integration normalizers — advisory-only, inbound read
// No account actions, no credential harvesting, no bidirectional destructive sync.
// ---------------------------------------------------------------------------

func normalizeIdentityProvider(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	failedCount := 0
	if fc, ok := raw["failed_attempt_count"].(float64); ok {
		failedCount = int(fc)
	}
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "identity-provider-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "identity_provider",
		"event_type":            first(raw, "event_type", "action"),
		"provider":              strings.ToLower(first(raw, "provider", "idp")),
		"user_id":               first(raw, "user_id", "sub"),
		"user_email":            first(raw, "user_email", "email", "upn"),
		"source_ip":             first(raw, "source_ip", "ip_address", "client_ip"),
		"geo_country":           first(raw, "geo_country", "country"),
		"user_agent":            first(raw, "user_agent"),
		"mfa_used":              raw["mfa_used"],
		"is_failed":             raw["is_failed"],
		"failed_attempt_count":  failedCount,
		"raw_attributes":        raw["raw_attributes"],
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"no_account_action":     true,
	}, nil
}

func normalizeSaasAudit(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "saas-audit-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "saas_audit",
		"event_type":            first(raw, "action", "event_type", "operation"),
		"provider":              strings.ToLower(first(raw, "provider", "saas_platform")),
		"actor_id":              first(raw, "actor_id", "user_id", "sub"),
		"actor_email":           first(raw, "actor_email", "email", "user_email"),
		"actor_ip":              first(raw, "actor_ip", "source_ip", "ip_address"),
		"resource_type":         first(raw, "resource_type", "object_type"),
		"resource_id":           first(raw, "resource_id", "object_id"),
		"target_id":             first(raw, "target_id"),
		"target_email":          first(raw, "target_email"),
		"source_country":        first(raw, "source_country", "geo_country", "country"),
		"user_agent":            first(raw, "user_agent"),
		"additional_details":    raw["additional_details"],
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"no_account_action":     true,
	}, nil
}

func normalizeTicketSync(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "ticket-sync-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "ticket_sync",
		"event_type":            first(raw, "event_type", "action", "sync_type"),
		"provider":              strings.ToLower(first(raw, "provider", "ticketing_system")),
		"ticket_id":             first(raw, "ticket_id", "external_ticket_id"),
		"investigation_id":      first(raw, "investigation_id"),
		"sync_direction":        first(raw, "sync_direction", "direction"),
		"external_status":       first(raw, "external_status", "ticket_status"),
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"no_auto_close":         true,
	}, nil
}

func normalizeNotificationEvent(raw map[string]any) (map[string]any, error) {
	eventID := first(raw, "event_id", "id")
	return map[string]any{
		"schema_version":        1,
		"normalization_version": "notification-event-v1",
		"normalized_event_id":   eventID,
		"raw_event_id":          eventID,
		"ts":                    first(raw, "ts", "timestamp", "occurred_at", "event_time"),
		"telemetry_type":        "notification",
		"event_type":            first(raw, "notification_type", "event_type"),
		"channel":               strings.ToLower(first(raw, "channel")),
		"severity":              strings.ToLower(first(raw, "severity")),
		"subject":               first(raw, "subject"),
		"source_reference":      raw["source_reference"],
		"requires_approval":     raw["requires_analyst_approval"],
		"source_service":        first(raw, "source_service", "vendor"),
		"trace_id":              first(raw, "trace_id"),
		"advisory_only":         true,
		"simulated":             true,
	}, nil
}

func verifyInternalToken(token string) error {
	expected := env("XDR_NORMALIZER_INTERNAL_TOKEN", "")
	if expected == "" {
		return nil // permissive mode: token not required
	}
	if token == "" {
		return fmt.Errorf("missing_token")
	}
	if token != expected {
		return fmt.Errorf("invalid_token")
	}
	return nil
}

