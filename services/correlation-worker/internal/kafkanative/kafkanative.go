// Package kafkanative wraps github.com/twmb/franz-go/pkg/kgo to talk to
// Redpanda's native binary Kafka protocol directly (port 9092), instead of
// the Pandaproxy HTTP REST proxy this service otherwise uses by default.
//
// ARCH-KAFKA-NATIVE / PERF-REST-POLL / PERF-REST-REBALANCE: this is the
// first external Go dependency this service has ever taken on — every other
// package here is stdlib-only. franz-go was chosen because it's pure Go (no
// cgo), actively maintained, and ships a real in-process fake Kafka broker
// (pkg/kfake) that this package's own tests run against instead of needing
// a live Redpanda/Docker daemon.
//
// This package deliberately does not try to preserve Pandaproxy's REST
// consumer-instance concept: a native kgo.Client with ConsumerGroup holds a
// single long-lived connection with heartbeat-based group membership, which
// structurally eliminates the "reconnect regenerates a REST instance ID and
// forces a full rebalance" failure mode PERF-REST-REBALANCE describes —
// there is no "instance" to regenerate.
package kafkanative

import (
	"context"
	"errors"
	"fmt"

	"github.com/twmb/franz-go/pkg/kgo"
)

// Record is a consumed Kafka record. Aliased from kgo.Record so callers only
// ever need to import this package, never kgo directly.
type Record = kgo.Record

// Producer is a synchronous, all-or-nothing native Kafka producer, matching
// the existing Pandaproxy publish()'s "one HTTP POST, one batch, first error
// aborts" semantics.
type Producer struct {
	cl *kgo.Client
}

func NewProducer(brokers []string) (*Producer, error) {
	if len(brokers) == 0 {
		return nil, fmt.Errorf("kafkanative: no brokers configured")
	}
	cl, err := kgo.NewClient(kgo.SeedBrokers(brokers...))
	if err != nil {
		return nil, fmt.Errorf("kafkanative: new producer client: %w", err)
	}
	return &Producer{cl: cl}, nil
}

// Publish produces one record per value to topic and blocks until every
// record in the batch is acknowledged or the first error occurs.
func (p *Producer) Publish(ctx context.Context, topic string, values [][]byte) error {
	if len(values) == 0 {
		return nil
	}
	recs := make([]*kgo.Record, len(values))
	for i, v := range values {
		recs[i] = &kgo.Record{Topic: topic, Value: v}
	}
	results := p.cl.ProduceSync(ctx, recs...)
	if err := results.FirstErr(); err != nil {
		return fmt.Errorf("kafkanative: publish to %s: %w", topic, err)
	}
	return nil
}

func (p *Producer) Close() {
	p.cl.Close()
}

// Consumer is a native Kafka consumer-group client with auto-commit
// disabled -- callers must call Commit explicitly, and must only do so once
// any downstream effect of the records being committed (a forwarded publish,
// a DLQ write) is already durable. This mirrors, rather than weakens, the
// commit-after-publish invariant the existing Pandaproxy-based consumers
// already rely on (NORM-ASYNC-COMMIT-LOSS).
type Consumer struct {
	cl *kgo.Client
}

func NewConsumer(brokers []string, group string, topics []string) (*Consumer, error) {
	if len(brokers) == 0 {
		return nil, fmt.Errorf("kafkanative: no brokers configured")
	}
	if group == "" {
		return nil, fmt.Errorf("kafkanative: no consumer group configured")
	}
	cl, err := kgo.NewClient(
		kgo.SeedBrokers(brokers...),
		kgo.ConsumerGroup(group),
		kgo.ConsumeTopics(topics...),
		// Matches Pandaproxy's "auto.offset.reset": "earliest" default.
		kgo.ConsumeResetOffset(kgo.NewOffset().AtStart()),
		// Manual commit only -- see Commit's docs.
		kgo.DisableAutoCommit(),
	)
	if err != nil {
		return nil, fmt.Errorf("kafkanative: new consumer client: %w", err)
	}
	return &Consumer{cl: cl}, nil
}

// Poll fetches the next batch of records, blocking until at least one
// record arrives, ctx is done, or a fetch error occurs.
//
// A poll whose context expires before any record arrives is not a
// broker/protocol failure -- it is simply "no messages this cycle," the
// expected steady state on an idle topic. franz-go surfaces this case via a
// synthetic FetchError (Topic="", Partition=-1, wrapping ctx.Err()) rather
// than an empty, error-free Fetches, so it must be filtered out here.
// Otherwise every idle poll looks identical to a genuine broker error and
// callers using a bounded-timeout poll loop (as every caller of this
// package does) would force a full consumer-group reconnect every single
// cycle on an idle topic -- reintroducing, via a different bug, the exact
// rebalance-storm failure mode this native transport was built to
// eliminate (PERF-REST-REBALANCE). Confirmed against a live idle topic:
// without this filter, the consumer reconnected every ~30s indefinitely.
func (c *Consumer) Poll(ctx context.Context) ([]*Record, error) {
	fetches := c.cl.PollFetches(ctx)
	if errs := fetches.Errors(); len(errs) > 0 {
		var real []error
		for _, fe := range errs {
			if fe.Topic == "" && fe.Partition == -1 && (errors.Is(fe.Err, context.DeadlineExceeded) || errors.Is(fe.Err, context.Canceled)) {
				continue
			}
			real = append(real, fe.Err)
		}
		if len(real) > 0 {
			return nil, fmt.Errorf("kafkanative: poll: %v", real)
		}
	}
	return fetches.Records(), nil
}

// Commit marks recs as consumed. Per Kafka convention this commits, for
// each distinct topic-partition among recs, the highest offset+1 seen.
// Callers must only call this after any downstream effect of recs is
// already durable.
func (c *Consumer) Commit(ctx context.Context, recs ...*Record) error {
	if len(recs) == 0 {
		return nil
	}
	if err := c.cl.CommitRecords(ctx, recs...); err != nil {
		return fmt.Errorf("kafkanative: commit offsets: %w", err)
	}
	return nil
}

func (c *Consumer) Close() {
	c.cl.Close()
}
