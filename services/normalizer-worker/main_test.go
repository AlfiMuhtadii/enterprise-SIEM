package main

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

// newTestWorker builds a Worker wired to the given Pandaproxy mock server,
// with producer queues/loops started exactly like main() does.
func newTestWorker(t *testing.T, redpandaREST string) *Worker {
	t.Helper()
	w := &Worker{
		redpandaREST:  redpandaREST,
		inputTopic:    "telemetry.raw",
		outputTopic:   "telemetry.normalized",
		dlqTopic:      "telemetry.normalization_failed",
		group:         "normalizer-worker-test",
		queueCapacity: 1000,
		producerBatch: 5000,
		producerFlush: 20 * time.Millisecond,
	}
	w.producerQueues = make([]chan queuedEvent, 1)
	w.producerQueues[0] = make(chan queuedEvent, 1000)
	go w.producerLoop(w.producerQueues[0])
	return w
}

func normalizedRawEvent(id string) map[string]any {
	return map[string]any{
		"event_id":       id,
		"ts":             "2026-07-09T00:00:00Z",
		"telemetry_type": "identity",
		"event_type":     "login",
		"user":           "alice",
	}
}

// TestCommitDoesNotRaceAheadOfPublish is the core regression test for
// NORM-ASYNC-COMMIT-LOSS: the second Pandaproxy fetch (which implicitly
// commits the first batch's offsets) must never be observed by the mock
// server until the first batch's publish() call has already landed.
func TestCommitDoesNotRaceAheadOfPublish(t *testing.T) {
	var mu sync.Mutex
	var eventLog []string
	record := func(name string) {
		mu.Lock()
		eventLog = append(eventLog, name)
		mu.Unlock()
	}
	snapshotAtSecondPoll := make(chan []string, 1)

	pollCount := 0
	mux := http.NewServeMux()
	mux.HandleFunc("/consumers/normalizer-worker-test", func(rw http.ResponseWriter, r *http.Request) {
		// A fully-qualified URL with a throwaway host: normalizeConsumerBaseURI
		// rewrites scheme+host to redpandaREST's, keeping this path.
		writeJSON(rw, http.StatusOK, map[string]any{"instance_id": "x", "base_uri": "http://advertised-host:8082/consumers/normalizer-worker-test/instances/x"})
	})
	mux.HandleFunc("/consumers/normalizer-worker-test/instances/x/subscription", func(rw http.ResponseWriter, r *http.Request) {
		rw.WriteHeader(http.StatusOK)
	})
	mux.HandleFunc("/consumers/normalizer-worker-test/instances/x/records", func(rw http.ResponseWriter, r *http.Request) {
		pollCount++
		record(fmt.Sprintf("poll_%d", pollCount))
		if pollCount == 1 {
			body, _ := json.Marshal([]map[string]any{
				{"topic": "telemetry.raw", "partition": 0, "offset": 0, "value": normalizedRawEvent("evt-1")},
			})
			rw.Write(body)
			return
		}
		if pollCount == 2 {
			mu.Lock()
			snapshot := append([]string{}, eventLog...)
			mu.Unlock()
			snapshotAtSecondPoll <- snapshot
			// End the loop cleanly: a plain error response makes consumerPoll
			// return a non-poison error, which makes consumeOnce return.
			rw.WriteHeader(http.StatusInternalServerError)
			return
		}
		rw.WriteHeader(http.StatusInternalServerError)
	})
	mux.HandleFunc("/topics/telemetry.normalized", func(rw http.ResponseWriter, r *http.Request) {
		record("publish")
		rw.WriteHeader(http.StatusOK)
	})
	mux.HandleFunc("/topics/telemetry.normalization_failed", func(rw http.ResponseWriter, r *http.Request) {
		record("dlq")
		rw.WriteHeader(http.StatusOK)
	})

	server := httptest.NewServer(mux)
	defer server.Close()

	w := newTestWorker(t, server.URL)
	go w.consumeOnce()

	select {
	case snapshot := <-snapshotAtSecondPoll:
		found := false
		for _, e := range snapshot {
			if e == "publish" {
				found = true
				break
			}
		}
		if !found {
			t.Fatalf("second poll observed before publish completed for the first batch; event log at that point: %v", snapshot)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("timed out waiting for second poll — consumeOnce likely deadlocked")
	}
}

// TestEnqueueNilWaitGroupDoesNotBlock covers the fire-and-forget HTTP
// ingestion path (normalizeHTTP), which passes a nil WaitGroup.
func TestEnqueueNilWaitGroupDoesNotBlock(t *testing.T) {
	published := make(chan struct{}, 1)
	mux := http.NewServeMux()
	mux.HandleFunc("/topics/telemetry.normalized", func(rw http.ResponseWriter, r *http.Request) {
		published <- struct{}{}
		rw.WriteHeader(http.StatusOK)
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	w := newTestWorker(t, server.URL)
	done := make(chan struct{})
	go func() {
		w.enqueue(normalizedRawEvent("evt-http"), nil)
		close(done)
	}()

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("enqueue with nil WaitGroup blocked unexpectedly")
	}

	select {
	case <-published:
	case <-time.After(2 * time.Second):
		t.Fatal("event was never published by producerLoop")
	}
}

// TestEnqueueWaitGroupBlocksUntilPublishAttempted covers the success path:
// wg.Wait() must not return before producerLoop has actually flushed.
func TestEnqueueWaitGroupBlocksUntilPublishAttempted(t *testing.T) {
	release := make(chan struct{})
	mux := http.NewServeMux()
	mux.HandleFunc("/topics/telemetry.normalized", func(rw http.ResponseWriter, r *http.Request) {
		<-release // hold the publish call open until the test says so
		rw.WriteHeader(http.StatusOK)
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	w := newTestWorker(t, server.URL)
	var wg sync.WaitGroup
	w.enqueue(normalizedRawEvent("evt-wait"), &wg)

	waited := make(chan struct{})
	go func() {
		wg.Wait()
		close(waited)
	}()

	select {
	case <-waited:
		t.Fatal("wg.Wait() returned before the publish HTTP call completed")
	case <-time.After(100 * time.Millisecond):
		// Expected: still blocked while the mock publish handler holds open.
	}

	close(release)

	select {
	case <-waited:
	case <-time.After(2 * time.Second):
		t.Fatal("wg.Wait() never returned after publish completed")
	}
}

// TestPublishFailureDlqIncludesFullEvents covers the fix to the previous
// count-only DLQ record on a producer flush failure, which made failed
// events unreplayable.
func TestPublishFailureDlqIncludesFullEvents(t *testing.T) {
	dlqBody := make(chan []byte, 1)
	mux := http.NewServeMux()
	mux.HandleFunc("/topics/telemetry.normalized", func(rw http.ResponseWriter, r *http.Request) {
		rw.WriteHeader(http.StatusInternalServerError)
	})
	mux.HandleFunc("/topics/telemetry.normalization_failed", func(rw http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		dlqBody <- body
		rw.WriteHeader(http.StatusOK)
	})
	server := httptest.NewServer(mux)
	defer server.Close()

	w := newTestWorker(t, server.URL)
	var wg sync.WaitGroup
	w.enqueue(normalizedRawEvent("evt-fail"), &wg)
	wg.Wait()

	select {
	case body := <-dlqBody:
		if !strings.Contains(string(body), "evt-fail") {
			t.Fatalf("DLQ record on publish failure does not contain the original event data (unreplayable): %s", body)
		}
		if !strings.Contains(string(body), "\"count\"") {
			t.Fatalf("DLQ record missing count field: %s", body)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("DLQ write for the failed publish never happened")
	}
}

// ---------------------------------------------------------------------------
// PERF-GO-OVERCONCURRENT: normalizeBatch must reuse a persistent worker pool
// across calls instead of spawning fresh goroutines+channels every batch.
// ---------------------------------------------------------------------------

func TestNormalizeBatchReusesSamePoolAcrossCalls(t *testing.T) {
	w := newTestWorker(t, "http://unused")

	w.normalizeBatch([]map[string]any{normalizedRawEvent("evt-1")})
	first := w.pool
	if first == nil {
		t.Fatal("expected pool to be initialized after first normalizeBatch call")
	}

	w.normalizeBatch([]map[string]any{normalizedRawEvent("evt-2")})
	if w.pool != first {
		t.Error("expected the same pool instance to be reused across normalizeBatch calls, got a new one")
	}
}

func TestNormalizeBatchCorrectnessThroughPersistentPool(t *testing.T) {
	w := newTestWorker(t, "http://unused")

	raw := []map[string]any{
		normalizedRawEvent("evt-a"),
		normalizedRawEvent("evt-b"),
		{"event_id": "evt-malformed"}, // missing required fields → normalize.Event should error
		normalizedRawEvent("evt-c"),
	}

	normalized, malformed := w.normalizeBatch(raw)

	if malformed != 1 {
		t.Errorf("expected exactly 1 malformed event, got %d", malformed)
	}
	if len(normalized) != 3 {
		t.Errorf("expected 3 normalized events, got %d", len(normalized))
	}
}

func TestNormalizeBatchHandlesConcurrentCallsOnSamePool(t *testing.T) {
	w := newTestWorker(t, "http://unused")

	var wg sync.WaitGroup
	for i := 0; i < 20; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			normalized, malformed := w.normalizeBatch([]map[string]any{normalizedRawEvent(fmt.Sprintf("evt-concurrent-%d", i))})
			if malformed != 0 || len(normalized) != 1 {
				t.Errorf("call %d: expected 1 normalized/0 malformed, got %d/%d", i, len(normalized), malformed)
			}
		}(i)
	}
	wg.Wait()
}

func TestNormalizeBatchEmptyInputReturnsEmpty(t *testing.T) {
	w := newTestWorker(t, "http://unused")
	normalized, malformed := w.normalizeBatch(nil)
	if len(normalized) != 0 || malformed != 0 {
		t.Errorf("expected empty result for empty input, got %d normalized, %d malformed", len(normalized), malformed)
	}
}
