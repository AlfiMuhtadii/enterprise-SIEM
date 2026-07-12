package main

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"detector-xdr-correlation-worker/internal/kafkanative"

	"github.com/twmb/franz-go/pkg/kfake"
	"github.com/twmb/franz-go/pkg/kgo"
)

// ARCH-KAFKA-NATIVE / PERF-REST-POLL / PERF-REST-REBALANCE: these tests run
// against a real in-process fake Kafka broker (franz-go's kfake) speaking
// the genuine Kafka wire protocol -- there is no live Redpanda/Docker
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
		inputTopic:               inputTopic,
		outputTopic:              "xdr.alerts",
		dlqTopic:                 "xdr.alerts.dlq",
		correlationFailedTopic:   "xdr.correlation_failed",
		shadowAlertsTopic:        "xdr.alerts.shadow.endpoint",
		networkShadowAlertsTopic: "xdr.alerts.shadow.network",
		group:                    group,
		scope:                    "identity-cloud",
		nativeProducer:           producer,
	}

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

func benignEvent(id string) []byte {
	b, _ := json.Marshal(map[string]any{
		"event_id":       id,
		"ts":             "2026-07-12T00:00:00Z",
		"telemetry_type": "identity",
		"event_type":     "login_success",
		"user":           "alice",
	})
	return b
}

func TestCorrelationNativePublishWritesRealKafkaRecords(t *testing.T) {
	brokers := newKfakeCluster(t, "xdr.alerts")
	w := newNativeTestWorker(t, brokers, "test-group", "")

	if err := w.publish("xdr.alerts", []map[string]any{{"alert_id": "a1"}}); err != nil {
		t.Fatalf("publish: %v", err)
	}

	values := consumeAllRaw(t, brokers, "xdr.alerts", 5*time.Second)
	if len(values) != 1 {
		t.Fatalf("expected 1 record on xdr.alerts, got %d", len(values))
	}
	var m map[string]any
	if err := json.Unmarshal(values[0], &m); err != nil {
		t.Fatalf("record not valid JSON: %v", err)
	}
	if m["alert_id"] != "a1" {
		t.Fatalf("expected alert_id=a1, got %v", m["alert_id"])
	}
}

func TestCorrelationNativeConsumeOnceCommitsSoRecordIsNotRedelivered(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.normalized", "xdr.alerts", "xdr.correlation_failed",
		"xdr.alerts.shadow.endpoint", "xdr.alerts.shadow.network")
	w := newNativeTestWorker(t, brokers, "test-group-commit", "telemetry.normalized")

	produceRaw(t, brokers, "telemetry.normalized", benignEvent("ev-native-1"))

	done := make(chan struct{})
	go func() {
		w.consumeOnceNative()
		close(done)
	}()

	// Wait for at least one poll cycle to have processed the record before
	// forcing the consumer closed -- poll long-polls internally so give it
	// real time rather than racing the goroutine.
	deadline := time.Now().Add(10 * time.Second)
	for w.processed.Load() == 0 && time.Now().Before(deadline) {
		time.Sleep(50 * time.Millisecond)
	}
	w.nativeConsumer.Close()
	<-done

	if w.processed.Load() != 1 {
		t.Fatalf("expected processed=1, got %d", w.processed.Load())
	}

	freshConsumer, err := kafkanative.NewConsumer(brokers, "test-group-commit", []string{"telemetry.normalized"})
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

func TestCorrelationNativeConsumeOnceIsolatesPoisonRecordToDLQ(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.normalized", "xdr.alerts", "xdr.correlation_failed",
		"xdr.alerts.shadow.endpoint", "xdr.alerts.shadow.network")
	w := newNativeTestWorker(t, brokers, "test-group-poison", "telemetry.normalized")

	produceRaw(t, brokers, "telemetry.normalized", []byte("not-valid-json"))

	done := make(chan struct{})
	go func() {
		w.consumeOnceNative()
		close(done)
	}()

	dlqValues := consumeAllRaw(t, brokers, "xdr.correlation_failed", 10*time.Second)
	w.nativeConsumer.Close()
	<-done

	if len(dlqValues) != 1 {
		t.Fatalf("expected 1 DLQ record for the poison message, got %d", len(dlqValues))
	}
	var dlq map[string]any
	if err := json.Unmarshal(dlqValues[0], &dlq); err != nil {
		t.Fatalf("DLQ value not valid JSON: %v", err)
	}
	if dlq["dlq_event_type"] != "correlation_parse_error" {
		t.Fatalf("expected dlq_event_type=correlation_parse_error, got %v", dlq["dlq_event_type"])
	}
	if dlq["source_topic"] != "telemetry.normalized" {
		t.Fatalf("expected source_topic=telemetry.normalized, got %v", dlq["source_topic"])
	}
}
