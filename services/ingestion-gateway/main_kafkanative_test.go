package main

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"detector-xdr-ingestion-gateway/internal/kafkanative"
	"detector-xdr-ingestion-gateway/internal/otlpexport"

	"github.com/twmb/franz-go/pkg/kfake"
	"github.com/twmb/franz-go/pkg/kgo"
)

// ARCH-KAFKA-NATIVE: runs against a real in-process fake Kafka broker
// (franz-go's kfake), speaking the genuine Kafka wire protocol -- there is
// no live Redpanda/Docker daemon in this environment. The CLAUDE.md-
// mandated live-pipeline verifier against a real Redpanda broker remains a
// separate, explicitly deferred step.

func newKfakeCluster(t *testing.T, topics ...string) []string {
	t.Helper()
	cluster, err := kfake.NewCluster(kfake.NumBrokers(1), kfake.SeedTopics(-1, topics...))
	if err != nil {
		t.Fatalf("kfake.NewCluster: %v", err)
	}
	t.Cleanup(cluster.Close)
	return cluster.ListenAddrs()
}

func newNativeTestGateway(t *testing.T, brokers []string, topic string) *Gateway {
	t.Helper()
	producer, err := kafkanative.NewProducer(brokers)
	if err != nil {
		t.Fatalf("kafkanative.NewProducer: %v", err)
	}
	t.Cleanup(producer.Close)

	return &Gateway{
		topic:              topic,
		cb:                 newCircuitBreaker(5, 30*time.Second),
		maxPublishRetries:  3,
		publishTimeoutSecs: 5,
		otelExporter:       &otlpexport.Exporter{},
		nativeProducer:     producer,
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

func TestGatewayNativePublishWritesRealKafkaRecordsWithTraceparent(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.raw")
	gw := newNativeTestGateway(t, brokers, "telemetry.raw")

	events := []map[string]any{
		{"event_id": "e1"},
		{"event_id": "e2"},
	}
	if err := gw.publish(events); err != nil {
		t.Fatalf("publish: %v", err)
	}

	values := consumeAllRaw(t, brokers, "telemetry.raw", 5*time.Second)
	if len(values) != 2 {
		t.Fatalf("expected 2 records on telemetry.raw, got %d", len(values))
	}
	seenIDs := map[string]bool{}
	for _, v := range values {
		var m map[string]any
		if err := json.Unmarshal(v, &m); err != nil {
			t.Fatalf("record value not valid JSON: %v", err)
		}
		seenIDs[m["event_id"].(string)] = true
		// publish() must still stamp trace_id/traceparent for native
		// records exactly like it does for the REST path.
		if _, ok := m["trace_id"].(string); !ok || m["trace_id"] == "" {
			t.Fatalf("expected trace_id to be stamped, got %v", m["trace_id"])
		}
		if _, ok := m["traceparent"].(string); !ok || m["traceparent"] == "" {
			t.Fatalf("expected traceparent to be stamped, got %v", m["traceparent"])
		}
	}
	if !seenIDs["e1"] || !seenIDs["e2"] {
		t.Fatalf("expected e1 and e2 among published records, got %v", seenIDs)
	}
}

func TestGatewayNativePublishRecordsCircuitBreakerSuccess(t *testing.T) {
	brokers := newKfakeCluster(t, "telemetry.raw")
	gw := newNativeTestGateway(t, brokers, "telemetry.raw")

	if err := gw.publish([]map[string]any{{"event_id": "e1"}}); err != nil {
		t.Fatalf("publish: %v", err)
	}
	if !gw.cb.allow() {
		t.Fatalf("expected circuit breaker to remain closed (allow) after a successful publish")
	}
}

func TestGatewayNativePublishFailsWhenBrokerUnreachable(t *testing.T) {
	producer, err := kafkanative.NewProducer([]string{"127.0.0.1:1"}) // nothing listening
	if err != nil {
		t.Fatalf("kafkanative.NewProducer: %v", err)
	}
	t.Cleanup(producer.Close)

	gw := &Gateway{
		topic:              "telemetry.raw",
		cb:                 newCircuitBreaker(5, 30*time.Second),
		maxPublishRetries:  1,
		publishTimeoutSecs: 1,
		otelExporter:       &otlpexport.Exporter{},
		nativeProducer:     producer,
	}

	err = gw.publish([]map[string]any{{"event_id": "e1"}})
	if err == nil {
		t.Fatalf("expected publish to an unreachable broker to fail")
	}
}
