package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"errors"
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

	"detector-xdr-normalizer-worker/internal/kafkanative"
	"detector-xdr-normalizer-worker/internal/mtls"
	"detector-xdr-normalizer-worker/internal/normalize"
	"detector-xdr-normalizer-worker/internal/otlpexport"
	"detector-xdr-normalizer-worker/internal/traceparent"
)

// PoisonError is returned by consumerPoll when Pandaproxy reports it cannot
// deserialize a record (error_code 40801). It carries source coordinates so
// the caller can write a structured DLQ record and advance past the bad offset.
type PoisonError struct {
	HTTPStatus int
	ErrorCode  int
	Message    string
	Topic      string
	Partition  int
	Offset     int64
}

func (e *PoisonError) Error() string {
	return fmt.Sprintf("consumer_poll_failed status=%d body={\"error_code\":%d,\"message\":\"%s\"}",
		e.HTTPStatus, e.ErrorCode, e.Message)
}

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
	redpandaREST          string
	inputTopic            string
	outputTopic           string
	dlqTopic              string
	group                 string
	processed             atomic.Int64
	malformed             atomic.Int64
	forwarded             atomic.Int64
	publishErrors         atomic.Int64
	consumerPolls         atomic.Int64
	consumerErrors        atomic.Int64
	queueDepth            atomic.Int64
	reconnectCount        atomic.Int64
	pollErrorCount        atomic.Int64
	consumerRecreateCount atomic.Int64
	dlqWritten            atomic.Int64
	dlqWriteErrors        atomic.Int64
	poisonSkipped         atomic.Int64
	queueCapacity         int
	producerBatch         int
	producerFlush         time.Duration
	producerQueues        []chan queuedEvent
	nextProducer          atomic.Uint64
	mu                    sync.Mutex
	pool                  *normalizerPool
	poolOnce              sync.Once
	// OBS-OTEL-TRACING: OTLP/HTTP span export, disabled unless an endpoint
	// is configured. nil-safe (Export is a no-op on a nil *Exporter).
	otelExporter *otlpexport.Exporter
	// ARCH-KAFKA-NATIVE: nil unless XDR_KAFKA_TRANSPORT=native. publish()
	// and consumeLoop() branch on nativeProducer/nativeConsumer being set
	// rather than re-reading the env var, so the transport is decided once
	// at startup, not per-call.
	nativeProducer *kafkanative.Producer
	nativeConsumer *kafkanative.Consumer
}

// queuedEvent pairs a normalized event with the WaitGroup (if any) that must
// not resolve until the event has been through a completed publish attempt
// (success, or failure recorded to the DLQ). consumeOnce uses this to avoid
// advancing past a Pandaproxy fetch (which implicitly commits the previous
// batch's offsets on the *next* poll) before that batch has actually been
// published — see NORM-ASYNC-COMMIT-LOSS. The HTTP ingestion path
// (normalizeHTTP) passes a nil WaitGroup since it is intentionally
// fire-and-forget (202 Accepted before publish).
type queuedEvent struct {
	event map[string]any
	wg    *sync.WaitGroup
}

func main() {
	addr := flag.String("addr", env("XDR_NORMALIZER_ADDR", ":8092"), "listen address")
	file := flag.String("file", "", "optional JSONL file to normalize once")
	flag.Parse()

	validateNormalizerSecrets()

	w := &Worker{
		redpandaREST:  env("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082"),
		inputTopic:    env("XDR_RAW_TOPIC", "telemetry.raw"),
		outputTopic:   env("XDR_NORMALIZED_TOPIC", "telemetry.normalized"),
		dlqTopic:      env("XDR_NORMALIZER_DLQ_TOPIC", "telemetry.normalization_failed"),
		group:         env("XDR_NORMALIZER_GROUP", "normalizer-worker-v1"),
		queueCapacity: envInt("XDR_NORMALIZER_QUEUE_DEPTH", 200000),
		producerBatch: envInt("XDR_NORMALIZER_PRODUCER_BATCH", 5000),
		producerFlush: time.Duration(envInt("XDR_NORMALIZER_FLUSH_MS", 100)) * time.Millisecond,
		otelExporter: &otlpexport.Exporter{
			Endpoint:    env("XDR_OTEL_EXPORTER_ENDPOINT", ""),
			ServiceName: "normalizer-worker",
		},
	}

	// ARCH-KAFKA-NATIVE: off by default (XDR_KAFKA_TRANSPORT=pandaproxy) --
	// zero behavior change unless an operator opts in. The consumer is only
	// needed when the event loop itself is enabled.
	if env("XDR_KAFKA_TRANSPORT", "pandaproxy") == "native" {
		brokers := splitBrokers(env("XDR_REDPANDA_KAFKA_BROKERS", "redpanda:9092"))
		producer, err := kafkanative.NewProducer(brokers)
		if err != nil {
			log.Fatalf("xdr normalizer: native kafka producer init failed: %v", err)
		}
		w.nativeProducer = producer
		if envBool("XDR_NORMALIZER_EVENT_LOOP_ENABLED", true) {
			consumer, err := kafkanative.NewConsumer(brokers, w.group, []string{w.inputTopic})
			if err != nil {
				log.Fatalf("xdr normalizer: native kafka consumer init failed: %v", err)
			}
			w.nativeConsumer = consumer
		}
		log.Printf("xdr normalizer: using native kafka transport brokers=%v", brokers)
	}

	producerCount := envInt("XDR_NORMALIZER_PRODUCERS", 4)
	if producerCount < 1 {
		producerCount = 1
	}
	w.producerQueues = make([]chan queuedEvent, producerCount)
	for i := 0; i < producerCount; i++ {
		w.producerQueues[i] = make(chan queuedEvent, max(1, w.queueCapacity/producerCount))
		go w.producerLoop(w.producerQueues[i])
	}

	// ENT-SEC-NO-TLS-INTERNAL (phase 2): internal mTLS, disabled by default.
	// Same mechanism proven on ingestion-gateway (phase 1) — see
	// scripts/xdr_generate_internal_mtls_certs.py to generate dev/test certs.
	mtlsEnabled := envBool("XDR_INTERNAL_MTLS_ENABLED", false)
	caFile := env("XDR_INTERNAL_MTLS_CA", "")
	serverTLSCfg, err := mtls.ServerConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_SERVER_CERT", ""),
		env("XDR_INTERNAL_MTLS_SERVER_KEY", ""),
		caFile,
	)
	if err != nil {
		log.Fatalf("xdr normalizer: internal mTLS server config error: %v", err)
	}
	clientTLSCfg, err := mtls.ClientConfig(mtlsEnabled,
		env("XDR_INTERNAL_MTLS_CLIENT_CERT", ""),
		env("XDR_INTERNAL_MTLS_CLIENT_KEY", ""),
		caFile,
	)
	if err != nil {
		log.Fatalf("xdr normalizer: internal mTLS client config error: %v", err)
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
	mux.HandleFunc("/v1/normalize", w.normalizeHTTP)
	httpServer := &http.Server{
		Addr:      *addr,
		Handler:   mux,
		TLSConfig: serverTLSCfg,
	}
	go func() {
		log.Printf("xdr normalizer metrics listening on %s internal_mtls=%v", *addr, mtlsEnabled)
		if serverTLSCfg != nil {
			log.Fatal(httpServer.ListenAndServeTLS("", ""))
		} else {
			log.Fatal(httpServer.ListenAndServe())
		}
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
	authMode := "permissive"
	if envBool("XDR_ENFORCE_INTERNAL_AUTH", false) {
		authMode = "enforced"
	}
	writeJSON(rw, http.StatusOK, map[string]any{
		"processed":               w.processed.Load(),
		"malformed":               w.malformed.Load(),
		"forwarded":               w.forwarded.Load(),
		"publish_errors":          w.publishErrors.Load(),
		"consumer_polls":          w.consumerPolls.Load(),
		"consumer_errors":         w.consumerErrors.Load(),
		"reconnect_count":         w.reconnectCount.Load(),
		"poll_error_count":        w.pollErrorCount.Load(),
		"consumer_recreate_count": w.consumerRecreateCount.Load(),
		"queue_depth":             w.queueDepth.Load(),
		"queue_capacity":          w.queueCapacity,
		"goroutines":              runtime.NumGoroutine(),
		"heap_alloc_mb":           float64(mem.HeapAlloc) / 1024.0 / 1024.0,
		"dlq_written":             w.dlqWritten.Load(),
		"dlq_write_errors":        w.dlqWriteErrors.Load(),
		"poison_skipped":          w.poisonSkipped.Load(),
		"internal_auth_mode":      authMode,
	})
}

func (w *Worker) consumeLoop() {
	for {
		if w.nativeConsumer != nil {
			w.consumeOnceNative()
		} else {
			w.consumeOnce()
		}
		w.reconnectCount.Add(1)
		log.Printf("normalizer consumer reconnecting in 5s")
		time.Sleep(5 * time.Second)
	}
}

// consumeOnceNative mirrors consumeOnce()'s shape exactly (poll, isolate any
// poison records, normalize+forward the rest, block until publish completes,
// then advance offsets) but over a native long-lived Kafka connection
// instead of Pandaproxy's REST consumer-instance protocol. There is no
// "instance" to recreate here -- see the kafkanative package docs for why
// that structurally removes the REST-rebalance-storm failure mode.
func (w *Worker) consumeOnceNative() {
	w.consumerRecreateCount.Add(1)
	log.Printf("normalizer consuming (native) topic=%s group=%s", w.inputTopic, w.group)
	for {
		pollCtx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		records, err := w.nativeConsumer.Poll(pollCtx)
		cancel()
		w.consumerPolls.Add(1)
		if err != nil {
			w.pollErrorCount.Add(1)
			w.consumerErrors.Add(1)
			log.Printf("normalizer consumer poll failed (native): %v — reconnecting", err)
			return
		}
		if len(records) == 0 {
			continue
		}

		rawEvents := make([]map[string]any, 0, len(records))
		cleanRecords := make([]*kafkanative.Record, 0, len(records))
		reconnectNeeded := false
		for _, record := range records {
			// A record whose bytes aren't a valid JSON object -- whether
			// malformed JSON entirely, or valid JSON that isn't an object
			// (e.g. an array or scalar) -- is treated as poison, the same
			// disposition Pandaproxy's REST path gives both cases today
			// (poison via error_code 40801, or "invalid_record_value" via
			// the non-object check a few lines below in the REST path).
			var value map[string]any
			if err := json.Unmarshal(record.Value, &value); err != nil {
				w.malformed.Add(1)
				if w.isolatePoisonRecordNative(record, err) {
					continue
				}
				log.Printf("normalizer DLQ isolation failed for poison at %s:%d offset=%d — reconnecting",
					record.Topic, record.Partition, record.Offset)
				reconnectNeeded = true
				break
			}
			rawEvents = append(rawEvents, value)
			cleanRecords = append(cleanRecords, record)
		}
		if reconnectNeeded {
			return
		}
		if len(cleanRecords) == 0 {
			continue
		}

		normalizeStart := time.Now()
		normalized, malformed, spans := w.normalizeBatch(rawEvents)
		w.exportSpans(spans, normalizeStart, time.Now())
		w.processed.Add(int64(len(rawEvents)))
		w.malformed.Add(int64(malformed))
		// Same NORM-ASYNC-COMMIT-LOSS invariant as the REST path: block
		// until every event in this batch has been through a completed
		// publish attempt (success, or failure recorded to the DLQ) before
		// the offset commit below advances past them.
		var wg sync.WaitGroup
		for _, event := range normalized {
			w.enqueue(event, &wg)
			w.queueDepth.Add(1)
		}
		wg.Wait()

		commitCtx, commitCancel := context.WithTimeout(context.Background(), 15*time.Second)
		err = w.nativeConsumer.Commit(commitCtx, cleanRecords...)
		commitCancel()
		if err != nil {
			log.Printf("normalizer offset commit failed (native): %v — reconnecting", err)
			return
		}
	}
}

// isolatePoisonRecordNative is the native-transport equivalent of
// isolatePoisonRecord: write a structured DLQ record for the poison message,
// then advance the consumer past its offset. Returns true only if both
// steps succeed -- on any failure the caller reconnects rather than
// silently skipping the record, exactly like the REST path.
func (w *Worker) isolatePoisonRecordNative(record *kafkanative.Record, parseErr error) bool {
	dlqRecord := map[string]any{
		"dlq_event_type":   "poison_message_isolated",
		"schema_version":   1,
		"isolation_reason": "invalid_json_value",
		"source_topic":     record.Topic,
		"source_partition": int(record.Partition),
		"source_offset":    record.Offset,
		"error_message":    parseErr.Error(),
		"isolated_at":      time.Now().UTC().Format(time.RFC3339),
		"consumer_group":   w.group,
	}
	if err := w.publish(w.dlqTopic, []map[string]any{dlqRecord}); err != nil {
		w.dlqWriteErrors.Add(1)
		log.Printf("normalizer DLQ write failed for poison %s:%d offset=%d: %v",
			record.Topic, record.Partition, record.Offset, err)
		return false
	}
	w.dlqWritten.Add(1)
	commitCtx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	if err := w.nativeConsumer.Commit(commitCtx, record); err != nil {
		w.dlqWriteErrors.Add(1)
		log.Printf("normalizer offset advance failed for poison %s:%d offset=%d: %v",
			record.Topic, record.Partition, record.Offset, err)
		return false
	}
	w.poisonSkipped.Add(1)
	log.Printf("normalizer isolated poison (native): topic=%s partition=%d offset=%d dlq=%s",
		record.Topic, record.Partition, record.Offset, w.dlqTopic)
	return true
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
			var pe *PoisonError
			if errors.As(err, &pe) {
				// Pandaproxy serialization failure: isolate to DLQ and advance offset.
				if w.isolatePoisonRecord(baseURI, pe) {
					continue
				}
				log.Printf("normalizer DLQ isolation failed for poison at %s:%d offset=%d — reconnecting",
					pe.Topic, pe.Partition, pe.Offset)
			} else {
				w.pollErrorCount.Add(1)
				w.consumerErrors.Add(1)
				log.Printf("normalizer consumer poll failed: %v — reconnecting", err)
			}
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
		normalizeStart := time.Now()
		normalized, malformed, spans := w.normalizeBatch(rawEvents)
		w.exportSpans(spans, normalizeStart, time.Now())
		w.processed.Add(int64(len(rawEvents)))
		w.malformed.Add(int64(malformed))
		// Block the next consumerPoll (which implicitly commits this batch's
		// offsets via Pandaproxy's fetch-time auto-commit) until every event
		// from this batch has been through a completed publish attempt —
		// otherwise a crash here could lose up to queueCapacity (default
		// 200k) already-committed-but-unpublished events. See
		// NORM-ASYNC-COMMIT-LOSS.
		var wg sync.WaitGroup
		for _, event := range normalized {
			w.enqueue(event, &wg)
			w.queueDepth.Add(1)
		}
		wg.Wait()
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
	req, _ := http.NewRequest(http.MethodGet, baseURI+"/records?timeout=1000&max_bytes=1048576", nil)
	req.Header.Set("Accept", "application/vnd.kafka.json.v2+json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		if pe := parsePoisonErr(resp.StatusCode, body); pe != nil {
			return nil, pe
		}
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

// parsePoisonErr returns a *PoisonError when the Pandaproxy response body
// contains error_code 40801 (serialization failure), or nil for any other error.
// Pandaproxy message format: "Unable to serialize value of record at offset N in topic:partition T:P"
func parsePoisonErr(httpStatus int, body []byte) *PoisonError {
	var resp struct {
		ErrorCode int    `json:"error_code"`
		Message   string `json:"message"`
	}
	if err := json.Unmarshal(body, &resp); err != nil || resp.ErrorCode != 40801 {
		return nil
	}
	pe := &PoisonError{
		HTTPStatus: httpStatus,
		ErrorCode:  resp.ErrorCode,
		Message:    resp.Message,
		Offset:     -1,
		Partition:  -1,
	}
	if idx := strings.Index(resp.Message, "at offset "); idx >= 0 {
		rest := resp.Message[idx+len("at offset "):]
		parts := strings.Fields(rest)
		if len(parts) >= 1 {
			if n, err := strconv.ParseInt(parts[0], 10, 64); err == nil {
				pe.Offset = n
			}
		}
	}
	if idx := strings.Index(resp.Message, "topic:partition "); idx >= 0 {
		rest := resp.Message[idx+len("topic:partition "):]
		parts := strings.Fields(rest)
		if len(parts) >= 1 {
			tp := parts[0]
			if colon := strings.LastIndex(tp, ":"); colon >= 0 {
				pe.Topic = tp[:colon]
				if p, err := strconv.Atoi(tp[colon+1:]); err == nil {
					pe.Partition = p
				}
			}
		}
	}
	if pe.Topic == "" {
		pe.Topic = "unknown"
	}
	return pe
}

// isolatePoisonRecord writes a structured DLQ record for the poison message and
// advances the consumer past its offset using Pandaproxy's offset commit API.
// Returns true only if BOTH the DLQ write and the offset advance succeed.
// On any failure the caller should reconnect rather than silently skip the record.
func (w *Worker) isolatePoisonRecord(baseURI string, pe *PoisonError) bool {
	if pe.Topic == "" || pe.Topic == "unknown" || pe.Offset < 0 || pe.Partition < 0 {
		log.Printf("normalizer: unparseable poison error coordinates — reconnecting")
		return false
	}
	dlqRecord := map[string]any{
		"dlq_event_type":   "poison_message_isolated",
		"schema_version":   1,
		"isolation_reason": "pandaproxy_serialization_failure",
		"source_topic":     pe.Topic,
		"source_partition": pe.Partition,
		"source_offset":    pe.Offset,
		"error_code":       pe.ErrorCode,
		"error_message":    pe.Message,
		"isolated_at":      time.Now().UTC().Format(time.RFC3339),
		"consumer_group":   w.group,
	}
	if err := w.publish(w.dlqTopic, []map[string]any{dlqRecord}); err != nil {
		w.dlqWriteErrors.Add(1)
		log.Printf("normalizer DLQ write failed for poison %s:%d offset=%d: %v",
			pe.Topic, pe.Partition, pe.Offset, err)
		return false
	}
	w.dlqWritten.Add(1)
	if err := w.consumerCommitOffset(baseURI, pe.Topic, pe.Partition, pe.Offset); err != nil {
		w.dlqWriteErrors.Add(1)
		log.Printf("normalizer offset advance failed for poison %s:%d offset=%d: %v",
			pe.Topic, pe.Partition, pe.Offset, err)
		return false
	}
	w.poisonSkipped.Add(1)
	log.Printf("normalizer isolated poison: topic=%s partition=%d offset=%d dlq=%s",
		pe.Topic, pe.Partition, pe.Offset, w.dlqTopic)
	return true
}

// consumerCommitOffset explicitly commits an offset via the Pandaproxy consumer
// instance. Offset N means "last consumed is N; next fetch starts at N+1".
func (w *Worker) consumerCommitOffset(baseURI, topic string, partition int, offset int64) error {
	payload, _ := json.Marshal(map[string]any{
		"offsets": []map[string]any{
			{
				"topic":     topic,
				"partition": partition,
				"offset":    offset,
			},
		},
	})
	req, _ := http.NewRequest(http.MethodPost, baseURI+"/offsets", bytes.NewReader(payload))
	req.Header.Set("Content-Type", "application/vnd.kafka.v2+json")
	resp, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusNoContent && (resp.StatusCode < 200 || resp.StatusCode >= 300) {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("offset_commit_failed status=%d body=%s", resp.StatusCode, string(body))
	}
	return nil
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
	normalizeStart := time.Now()
	normalized, malformed, spans := w.normalizeBatch(rawEvents)
	w.exportSpans(spans, normalizeStart, time.Now())
	w.processed.Add(int64(len(rawEvents)))
	w.malformed.Add(int64(malformed))
	for _, event := range normalized {
		w.enqueue(event, nil)
		w.queueDepth.Add(1)
	}
	writeJSON(rw, http.StatusAccepted, map[string]any{
		"processed":   len(rawEvents),
		"enqueued":    len(normalized),
		"malformed":   malformed,
		"queue_depth": w.queueDepth.Load(),
		"latency_ms":  time.Since(started).Milliseconds(),
	})
}

// normalizerPool is a bounded worker pool started once and reused across
// every normalizeBatch call (PERF-GO-OVERCONCURRENT), rather than the old
// behavior of spawning `workers` fresh goroutines (plus 2 fresh channels)
// on every single poll-batch cycle. Only the small per-batch results
// channel is still allocated per call — unavoidable since each batch needs
// its own completion signal — the actual GC-churn source (goroutine
// spawn+teardown at high sustained RPS) is what's eliminated.
type normalizeJob struct {
	raw    map[string]any
	result chan<- normalizeResult
}

type normalizeResult struct {
	event map[string]any
	span  *otlpexport.Span
	err   error
}

type normalizerPool struct {
	jobs chan normalizeJob
}

func newNormalizerPool(workers int) *normalizerPool {
	if workers < 1 {
		workers = 1
	}
	p := &normalizerPool{jobs: make(chan normalizeJob, workers*4)}
	for i := 0; i < workers; i++ {
		go func() {
			for job := range p.jobs {
				event, err := normalize.Event(job.raw)
				var span *otlpexport.Span
				if err == nil {
					span = buildNormalizeSpan(job.raw, event)
				}
				job.result <- normalizeResult{event: event, span: span, err: err}
			}
		}()
	}
	return p
}

// buildNormalizeSpan constructs the OTLP span for one event's hop through
// this worker (OBS-OTEL-TRACING) — raw carries the inbound traceparent
// (this span's parent), event carries the fresh outbound one normalize.Event
// already generated via traceparent.Propagate (this span's own id). Returns
// nil if event's outbound traceparent can't be parsed (defensive; should
// not happen since normalize.Event always sets a well-formed one).
func buildNormalizeSpan(raw, event map[string]any) *otlpexport.Span {
	outboundTP, _ := event["traceparent"].(string)
	parsed, err := traceparent.Parse(outboundTP)
	if err != nil {
		return nil
	}
	span := &otlpexport.Span{
		TraceID: parsed.TraceID,
		SpanID:  parsed.SpanID,
		Name:    "normalizer-worker.normalize",
		Kind:    otlpexport.SpanKindInternal,
	}
	inboundTP, _ := raw["traceparent"].(string)
	if inboundParsed, inErr := traceparent.Parse(inboundTP); inErr == nil {
		span.ParentSpanID = inboundParsed.SpanID
	}
	return span
}

func (p *normalizerPool) normalizeBatch(rawEvents []map[string]any) ([]map[string]any, int, []otlpexport.Span) {
	if len(rawEvents) == 0 {
		return nil, 0, nil
	}
	results := make(chan normalizeResult, len(rawEvents))
	for _, raw := range rawEvents {
		p.jobs <- normalizeJob{raw: raw, result: results}
	}
	normalized := make([]map[string]any, 0, len(rawEvents))
	spans := make([]otlpexport.Span, 0, len(rawEvents))
	malformed := 0
	for i := 0; i < len(rawEvents); i++ {
		item := <-results
		if item.err != nil {
			malformed++
			continue
		}
		normalized = append(normalized, item.event)
		if item.span != nil {
			spans = append(spans, *item.span)
		}
	}
	return normalized, malformed, spans
}

// normalizeBatch lazily starts the shared worker pool on first use (so any
// Worker construction path — main(), tests, one-off file processing — gets
// it without needing to remember an explicit init step) and reuses it for
// every subsequent call.
func (w *Worker) normalizeBatch(rawEvents []map[string]any) ([]map[string]any, int, []otlpexport.Span) {
	w.poolOnce.Do(func() {
		workers := envInt("XDR_NORMALIZER_WORKERS", runtime.NumCPU())
		w.pool = newNormalizerPool(workers)
	})
	return w.pool.normalizeBatch(rawEvents)
}

// exportSpans stamps start/end onto every span in the batch (the true
// wall-clock window of this normalizeBatch call) and fires the OTLP export
// in a goroutine so an unreachable/slow collector never adds latency to the
// actual telemetry normalization path — see the identical pattern on
// ingestion-gateway's publish().
func (w *Worker) exportSpans(spans []otlpexport.Span, start, end time.Time) {
	if len(spans) == 0 {
		return
	}
	for i := range spans {
		spans[i].Start = start
		spans[i].End = end
	}
	exporter := w.otelExporter
	go func() { _ = exporter.Export(spans) }()
}

// enqueue hands a normalized event to a producer queue for async batched
// publish. If wg is non-nil, wg.Add(1) is called before the event is queued
// and wg.Done() is called once that event has been through a completed
// publish attempt (success or DLQ-recorded failure) — callers that need to
// know "this event has at least been attempted" (e.g. before advancing the
// consumer offset) should pass a WaitGroup and Wait() on it. Fire-and-forget
// callers (the HTTP ingestion path) pass nil.
func (w *Worker) enqueue(event map[string]any, wg *sync.WaitGroup) {
	if wg != nil {
		wg.Add(1)
	}
	idx := int(w.nextProducer.Add(1) % uint64(len(w.producerQueues)))
	w.producerQueues[idx] <- queuedEvent{event: event, wg: wg}
}

func (w *Worker) producerLoop(events <-chan queuedEvent) {
	ticker := time.NewTicker(w.producerFlush)
	defer ticker.Stop()
	batch := make([]queuedEvent, 0, w.producerBatch)
	flush := func() {
		if len(batch) == 0 {
			return
		}
		payload := make([]map[string]any, 0, len(batch))
		for _, qe := range batch {
			payload = append(payload, qe.event)
		}
		if err := w.publish(w.outputTopic, payload); err != nil {
			w.publishErrors.Add(1)
			// Include the actual events, not just a count — a count-only DLQ
			// record can't be replayed; the events would be silently lost.
			_ = w.publish(w.dlqTopic, []map[string]any{{"error": err.Error(), "count": len(batch), "events": payload}})
		} else {
			w.forwarded.Add(int64(len(batch)))
		}
		w.queueDepth.Add(-int64(len(batch)))
		for _, qe := range batch {
			if qe.wg != nil {
				qe.wg.Done()
			}
		}
		batch = make([]queuedEvent, 0, w.producerBatch)
	}
	for {
		select {
		case qe := <-events:
			batch = append(batch, qe)
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
		normalized, err := normalize.Event(raw)
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

func (w *Worker) publish(topic string, events []map[string]any) error {
	if w.nativeProducer != nil {
		return w.publishNative(topic, events)
	}
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

func (w *Worker) publishNative(topic string, events []map[string]any) error {
	values := make([][]byte, len(events))
	for i, event := range events {
		b, err := json.Marshal(event)
		if err != nil {
			return fmt.Errorf("kafkanative: marshal event: %w", err)
		}
		values[i] = b
	}
	ctx, cancel := context.WithTimeout(context.Background(), httpClient.Timeout)
	defer cancel()
	return w.nativeProducer.Publish(ctx, topic, values)
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
		log.Printf("[SECURITY-WARN] XDR_INTERNAL_AUTH_SECRET is not set — internal service auth uses APP_KEY fallback")
	}
	enforced := envBool("XDR_ENFORCE_INTERNAL_AUTH", false)
	token := env("XDR_NORMALIZER_INTERNAL_TOKEN", "")
	if enforced {
		if token == "" {
			log.Fatalf("[SECURITY-FATAL] XDR_ENFORCE_INTERNAL_AUTH=true but XDR_NORMALIZER_INTERNAL_TOKEN is not set — refusing to start")
		}
		log.Printf("[SECURITY] internal auth enforced — /v1/normalize requires X-Internal-Service-Token")
	} else {
		if token == "" {
			log.Printf("[SECURITY-WARN] XDR_ENFORCE_INTERNAL_AUTH not set — /v1/normalize has no token enforcement (unsafe for non-local deployments)")
			log.Printf("[SECURITY-WARN] Set XDR_ENFORCE_INTERNAL_AUTH=true + XDR_NORMALIZER_INTERNAL_TOKEN=<secret> to harden")
		} else {
			log.Printf("[SECURITY-INFO] XDR_NORMALIZER_INTERNAL_TOKEN set (permissive mode — set XDR_ENFORCE_INTERNAL_AUTH=true to enforce)")
		}
	}
}

// verifyInternalToken checks the X-Internal-Service-Token header when
// XDR_NORMALIZER_INTERNAL_TOKEN is configured. If the env var is not set,
// auth is permissive (backward compatible). Non-empty token must equal the
// configured value exactly.

func verifyInternalToken(token string) error {
	enforced := envBool("XDR_ENFORCE_INTERNAL_AUTH", false)
	expected := env("XDR_NORMALIZER_INTERNAL_TOKEN", "")
	if enforced {
		// Strict mode: always require a valid token. If no token is configured
		// the service should have failed at startup; reject all requests.
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
	// Permissive mode: validate only when token is configured.
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
