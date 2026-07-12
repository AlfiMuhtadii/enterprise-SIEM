package main

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"detector-xdr-normalizer-worker/internal/kafkanative"

	"github.com/twmb/franz-go/pkg/kfake"
	"github.com/twmb/franz-go/pkg/kgo"
)

// ARCH-KAFKA-NATIVE / PERF-REST-POLL / PERF-REST-REBALANCE: these tests run
// against a real in-process fake Kafka broker (franz-go's kfake), speaking
// the genuine Kafka wire protocol -- not a mocked HTTP server like the
// Pandaproxy-path tests in main_test.go. There is no live Redpanda/Docker
// daemon in this environment, so this is the strongest verification
// available here; the CLAUDE.md-mandated live-pipeline verifier against a
// real Redpanda broker remains a separate, explicitly deferred step.

func newKfakeCluster(t *testing.T, topics ...string) []string {
	t.Helper()
	cluster, err := kfake.NewCluster(kfake.NumBrokers(1), kfake.SeedTopics(-1, topics...))
	if err != nil {
		t.Fatalf("kfake.NewCluster: %v", err)
	}
	t.Cleanup(cluster.Close)
	return cluster.ListenAddrs()
}

func newNativeTestWorker(t *testing.T, brokers []string, group, inputTopic string) *Worker {
	t.Helper()
	producer, err := kafkanative.NewProducer(brokers)
	if err != nil {
		t.Fatalf("kafkanative.NewProducer: %v", err)
	}
	t.Cleanup(producer.Close)

	w := &Worker{
		inputTopic:     inputTopic,
		outputTopic:    "telemetry.normalized",
		dlqTopic:       "telemetry.normalization_failed",
		group:          group,
		queueCapacity:  1000,
		producerBatch:  5000,
		producerFlush:  20 * time.Millisecond,
		nativeProducer: producer,
	}
	w.producerQueues = make([]chan queuedEvent, 1)
	w.producerQueues[0] = make(chan queuedEvent, 1000)
	go w.producerLoop(w.producerQueues[0])

	if inputTopic != "" {
		consumer, err := kafkanative.NewConsumer(brokers, group, []string{inputTopic})
		if err != nil {
			t.Fatalf("kafkanative.NewConsumer: %v", err)
		}
		t.Cleanup(consumer.Close)
		w.nativeConsumer = consumer
	}
	return w
}

// produceRaw writes a raw (un-normalized) event directly onto inputTopic via
// a plain kgo producer, standing in for whatever upstream service (the
// ingestion-gateway, in production) would have produced it.
func produceRaw(t *testing.T, brokers []string, topic string, value []byte) {
	t.Helper()
	cl, err := kgo.NewClient(kgo.SeedBrokers(brokers...))
	if err != nil {
		t.Fatalf("kgo.NewClient: %v", err)
	}
	defer cl.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	res := cl.ProduceSync(ctx, &kgo.Record{Topic: topic, Value: value})
	if err := res.FirstErr(); err != nil {
		t.Fatalf("produceRaw: %v", err)
	}
}

// consumeAllRaw drains every record currently on topic via a plain kgo
// consumer (no group -- direct partition consumption), used to inspect what
// publishNative()/consumeOnceNative() actually wrote.
func consumeAllRaw(t *testing.T, brokers []string, topic string, timeout time.Duration) [][]byte {
	t.Helper()
	cl, err := kgo.NewClient(
		kgo.SeedBrokers(brokers...),
		kgo.ConsumeTopics(topic),
		kgo.ConsumeResetOffset(kgo.NewOffset().AtStart()),
	)
	if err != nil {
		t.Fatalf("kgo.NewClient: %v", err)
	}
	defer cl.Close()

	var out [][]byte
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		ctx, cancel := context.WithTimeout(context.Background(), 500*time.Millisecond)
		fetches := cl.PollFetches(ctx)
		cancel()
		fetches.EachRecord(func(r *kgo.Record) {
			out = append(out, r.Value)
		})
		if len(out) > 0 {
			return out
		}
	}
	return out
}

func TestNativePublishWritesRealKafkaRecords(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.normalized")
	w := newNativeTestWorker(t, brokers, "test-group", "")

	if err := w.publish("telemetry.normalized", []map[string]any{
		{"event_id": "e1"}, {"event_id": "e2"},
	}); err != nil {
		t.Fatalf("publish: %v", err)
	}

	values := consumeAllRaw(t, brokers, "telemetry.normalized", 5*time.Second)
	if len(values) != 2 {
		t.Fatalf("expected 2 records on telemetry.normalized, got %d", len(values))
	}
	var seen []string
	for _, v := range values {
		var m map[string]any
		if err := json.Unmarshal(v, &m); err != nil {
			t.Fatalf("record value not valid JSON: %v", err)
		}
		seen = append(seen, m["event_id"].(string))
	}
	if !(seen[0] == "e1" || seen[1] == "e1") || !(seen[0] == "e2" || seen[1] == "e2") {
		t.Fatalf("expected e1 and e2 among published records, got %v", seen)
	}
}

func TestNativeConsumeOnceForwardsCleanRecordsToOutputTopic(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.raw", "telemetry.normalized", "telemetry.normalization_failed")
	w := newNativeTestWorker(t, brokers, "test-group-consume", "telemetry.raw")

	produceRaw(t, brokers, "telemetry.raw", mustJSON(t, normalizedRawEvent("native-1")))

	done := make(chan struct{})
	go func() {
		w.consumeOnceNative()
		close(done)
	}()

	values := consumeAllRaw(t, brokers, "telemetry.normalized", 10*time.Second)
	w.nativeConsumer.Close() // force the poll loop to error out and return
	<-done

	if len(values) != 1 {
		t.Fatalf("expected 1 forwarded record, got %d: %v", len(values), values)
	}
	var forwarded map[string]any
	if err := json.Unmarshal(values[0], &forwarded); err != nil {
		t.Fatalf("forwarded value not valid JSON: %v", err)
	}
	if forwarded["event_id"] != "native-1" {
		t.Fatalf("expected event_id=native-1, got %v", forwarded["event_id"])
	}
}

func TestNativeConsumeOnceCommitsOffsetSoRecordIsNotRedelivered(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.raw", "telemetry.normalized", "telemetry.normalization_failed")
	w := newNativeTestWorker(t, brokers, "test-group-commit", "telemetry.raw")

	produceRaw(t, brokers, "telemetry.raw", mustJSON(t, normalizedRawEvent("native-commit-1")))

	done := make(chan struct{})
	go func() {
		w.consumeOnceNative()
		close(done)
	}()
	_ = consumeAllRaw(t, brokers, "telemetry.normalized", 10*time.Second)
	w.nativeConsumer.Close()
	<-done

	// A fresh consumer under the SAME group must see nothing new -- the
	// offset was committed, so it starts past the already-processed record.
	freshConsumer, err := kafkanative.NewConsumer(brokers, "test-group-commit", []string{"telemetry.raw"})
	if err != nil {
		t.Fatalf("NewConsumer: %v", err)
	}
	defer freshConsumer.Close()
	pollCtx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	records, err := freshConsumer.Poll(pollCtx)
	if err != nil && pollCtx.Err() == nil {
		t.Fatalf("Poll: %v", err)
	}
	if len(records) != 0 {
		t.Fatalf("expected no redelivered records after commit, got %d", len(records))
	}
}

func TestNativeConsumeOnceIsolatesPoisonRecordToDLQAndSkipsIt(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.raw", "telemetry.normalized", "telemetry.normalization_failed")
	w := newNativeTestWorker(t, brokers, "test-group-poison", "telemetry.raw")

	produceRaw(t, brokers, "telemetry.raw", []byte("not-valid-json"))
	produceRaw(t, brokers, "telemetry.raw", mustJSON(t, normalizedRawEvent("native-after-poison")))

	done := make(chan struct{})
	go func() {
		w.consumeOnceNative()
		close(done)
	}()

	dlqValues := consumeAllRaw(t, brokers, "telemetry.normalization_failed", 10*time.Second)
	forwardedValues := consumeAllRaw(t, brokers, "telemetry.normalized", 10*time.Second)
	w.nativeConsumer.Close()
	<-done

	if len(dlqValues) != 1 {
		t.Fatalf("expected 1 DLQ record for the poison message, got %d", len(dlqValues))
	}
	var dlq map[string]any
	if err := json.Unmarshal(dlqValues[0], &dlq); err != nil {
		t.Fatalf("DLQ value not valid JSON: %v", err)
	}
	if dlq["dlq_event_type"] != "poison_message_isolated" {
		t.Fatalf("expected dlq_event_type=poison_message_isolated, got %v", dlq["dlq_event_type"])
	}
	if dlq["source_topic"] != "telemetry.raw" {
		t.Fatalf("expected source_topic=telemetry.raw, got %v", dlq["source_topic"])
	}

	if len(forwardedValues) != 1 {
		t.Fatalf("expected the clean record after the poison one to still be forwarded, got %d", len(forwardedValues))
	}
	var forwarded map[string]any
	if err := json.Unmarshal(forwardedValues[0], &forwarded); err != nil {
		t.Fatalf("forwarded value not valid JSON: %v", err)
	}
	if forwarded["event_id"] != "native-after-poison" {
		t.Fatalf("expected event_id=native-after-poison, got %v", forwarded["event_id"])
	}
	if w.poisonSkipped.Load() != 1 {
		t.Fatalf("expected poisonSkipped=1, got %d", w.poisonSkipped.Load())
	}
}

func mustJSON(t *testing.T, v any) []byte {
	t.Helper()
	b, err := json.Marshal(v)
	if err != nil {
		t.Fatalf("json.Marshal: %v", err)
	}
	return b
}
