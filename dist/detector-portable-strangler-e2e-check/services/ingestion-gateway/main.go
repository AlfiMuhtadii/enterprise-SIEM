package main

import (
	"bytes"
	"crypto/hmac"
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
	"strconv"
	"sync"
	"time"
)

type Gateway struct {
	secret        string
	redpandaREST  string
	topic         string
	maxBatchSize  int
	requests      int64
	accepted      int64
	rejected      int64
	publishErrors int64
	mu            sync.Mutex
}

func main() {
	addr := flag.String("addr", env("XDR_INGEST_ADDR", ":8091"), "listen address")
	flag.Parse()

	gw := &Gateway{
		secret:       env("XDR_INGEST_SECRET", "dev-secret-change-me"),
		redpandaREST: env("XDR_REDPANDA_REST_URL", "http://127.0.0.1:8082"),
		topic:        env("XDR_RAW_TOPIC", "telemetry.raw"),
		maxBatchSize: envInt("XDR_MAX_BATCH_SIZE", 1000),
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/health", gw.health)
	mux.HandleFunc("/metrics", gw.metrics)
	mux.HandleFunc("/v1/ingest", gw.ingest)

	server := &http.Server{Addr: *addr, Handler: rateLimit(mux, envInt("XDR_INGEST_RPS", 50))}
	log.Printf("xdr ingestion gateway listening on %s topic=%s redpanda=%s", *addr, gw.topic, gw.redpandaREST)
	log.Fatal(server.ListenAndServe())
}

func (g *Gateway) health(w http.ResponseWriter, r *http.Request) {
	writeJSON(w, http.StatusOK, map[string]any{"status": "ok", "service": "ingestion-gateway", "topic": g.topic})
}

func (g *Gateway) metrics(w http.ResponseWriter, r *http.Request) {
	g.mu.Lock()
	defer g.mu.Unlock()
	writeJSON(w, http.StatusOK, map[string]any{
		"requests":       g.requests,
		"accepted":       g.accepted,
		"rejected":       g.rejected,
		"publish_errors": g.publishErrors,
	})
}

func (g *Gateway) ingest(w http.ResponseWriter, r *http.Request) {
	started := time.Now()
	g.add(&g.requests, 1)
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
	if err := g.publish(events); err != nil {
		g.add(&g.publishErrors, 1)
		http.Error(w, err.Error(), http.StatusBadGateway)
		return
	}
	g.add(&g.accepted, int64(len(events)))
	writeJSON(w, http.StatusAccepted, map[string]any{
		"accepted":   len(events),
		"latency_ms": time.Since(started).Milliseconds(),
	})
}

func (g *Gateway) publish(events []map[string]any) error {
	records := make([]map[string]any, 0, len(events))
	for _, event := range events {
		records = append(records, map[string]any{"value": event})
	}
	payload, _ := json.Marshal(map[string]any{"records": records})
	var lastErr error
	for attempt := 0; attempt < 3; attempt++ {
		req, _ := http.NewRequest(http.MethodPost, fmt.Sprintf("%s/topics/%s", g.redpandaREST, g.topic), bytes.NewReader(payload))
		req.Header.Set("Content-Type", "application/vnd.kafka.json.v2+json")
		req.Header.Set("Accept", "application/vnd.kafka.v2+json")
		resp, err := http.DefaultClient.Do(req)
		if err == nil && resp != nil && resp.StatusCode >= 200 && resp.StatusCode < 300 {
			_ = resp.Body.Close()
			return nil
		}
		if resp != nil {
			_ = resp.Body.Close()
			lastErr = fmt.Errorf("redpanda status=%d", resp.StatusCode)
		} else {
			lastErr = err
		}
		time.Sleep(time.Duration(100*(attempt+1)) * time.Millisecond)
	}
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
	g.add(&g.rejected, 1)
	writeJSON(w, status, map[string]any{"error": reason})
}

func (g *Gateway) add(target *int64, delta int64) {
	g.mu.Lock()
	*target += delta
	g.mu.Unlock()
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
