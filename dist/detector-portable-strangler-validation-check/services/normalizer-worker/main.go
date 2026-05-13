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
	"strconv"
	"strings"
	"sync"
	"time"
)

type Worker struct {
	redpandaREST string
	inputTopic   string
	outputTopic  string
	dlqTopic     string
	processed    int64
	malformed    int64
	forwarded    int64
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
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", w.health)
	mux.HandleFunc("/metrics", w.metrics)
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
	w.mu.Lock()
	defer w.mu.Unlock()
	writeJSON(rw, http.StatusOK, map[string]any{"processed": w.processed, "malformed": w.malformed, "forwarded": w.forwarded})
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
		w.add(&w.processed, 1)
		var raw map[string]any
		if err := json.Unmarshal(scanner.Bytes(), &raw); err != nil {
			w.add(&w.malformed, 1)
			_ = w.publish(w.dlqTopic, []map[string]any{{"error": "invalid_json", "raw": scanner.Text()}})
			continue
		}
		normalized, err := normalize(raw)
		if err != nil {
			w.add(&w.malformed, 1)
			_ = w.publish(w.dlqTopic, []map[string]any{{"error": err.Error(), "event": raw}})
			continue
		}
		batch = append(batch, normalized)
		if len(batch) >= envInt("XDR_NORMALIZER_BATCH", 500) {
			if err := w.publish(w.outputTopic, batch); err != nil {
				return err
			}
			w.add(&w.forwarded, int64(len(batch)))
			batch = batch[:0]
		}
	}
	if len(batch) > 0 {
		if err := w.publish(w.outputTopic, batch); err != nil {
			return err
		}
		w.add(&w.forwarded, int64(len(batch)))
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
		"payload":         raw,
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

func (w *Worker) add(target *int64, delta int64) {
	w.mu.Lock()
	*target += delta
	w.mu.Unlock()
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
