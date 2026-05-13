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
	"os"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

type Worker struct {
	redpandaREST string
	inputTopic   string
	outputTopic  string
	dlqTopic     string
	processed    atomic.Int64
	malformed    atomic.Int64
	forwarded    atomic.Int64
	publishErrors atomic.Int64
	queueDepth   atomic.Int64
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

	w := &Worker{
		redpandaREST: env("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082"),
		inputTopic:   env("XDR_RAW_TOPIC", "telemetry.raw"),
		outputTopic:  env("XDR_NORMALIZED_TOPIC", "telemetry.normalized"),
		dlqTopic:     env("XDR_NORMALIZER_DLQ_TOPIC", "telemetry.normalized.dlq"),
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
	select {}
}

func (w *Worker) health(rw http.ResponseWriter, r *http.Request) {
	writeJSON(rw, http.StatusOK, map[string]any{"status": "ok", "service": "telemetry-normalizer", "input": w.inputTopic, "output": w.outputTopic})
}

func (w *Worker) metrics(rw http.ResponseWriter, r *http.Request) {
	var mem runtime.MemStats
	runtime.ReadMemStats(&mem)
	writeJSON(rw, http.StatusOK, map[string]any{
		"processed":      w.processed.Load(),
		"malformed":      w.malformed.Load(),
		"forwarded":      w.forwarded.Load(),
		"publish_errors": w.publishErrors.Load(),
		"queue_depth":    w.queueDepth.Load(),
		"queue_capacity": w.queueCapacity,
		"goroutines":     runtime.NumGoroutine(),
		"heap_alloc_mb":  float64(mem.HeapAlloc) / 1024.0 / 1024.0,
	})
}

func (w *Worker) normalizeHTTP(rw http.ResponseWriter, r *http.Request) {
	started := time.Now()
	if r.Method != http.MethodPost {
		http.Error(rw, "method not allowed", http.StatusMethodNotAllowed)
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
	}, nil
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
	resp, err := http.DefaultClient.Do(req)
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

var _ = time.Now
