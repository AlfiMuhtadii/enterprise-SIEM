package main

import (
	"context"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"strconv"
	"sync/atomic"
	"time"

	"detector-xdr-correlation-worker/internal/shadowrules"

	"github.com/twmb/franz-go/pkg/kgo"
	"github.com/twmb/franz-go/pkg/kmsg"
)

const dnsAdvisoryTopic = "xdr.alerts.shadow.network"

type dnsWindowLane struct {
	store      *dnsCheckpointStore
	broker     *kgo.Client
	checkpoint dnsCheckpoint
	publish    func([]shadowrules.EndpointAlert) bool
	ready      atomic.Bool
	cursor     atomic.Int64
	late       atomic.Uint64
	invalid    atomic.Uint64
	failed     atomic.Bool
}

func inspectDNSStream(ctx context.Context, client *kgo.Client, topic string) (dnsSource, error) {
	request := kmsg.NewPtrMetadataRequest()
	request.Topics = []kmsg.MetadataRequestTopic{{Topic: &topic}}
	request.AllowAutoTopicCreation = false
	reply, err := request.RequestWith(ctx, client)
	if err != nil {
		return dnsSource{}, err
	}
	if reply.ErrorCode != 0 || reply.ClusterID == nil || *reply.ClusterID == "" ||
		*reply.ClusterID == "redpanda.initializing" || len(reply.Topics) != 1 {
		return dnsSource{}, fmt.Errorf("DNS source cluster identity unavailable")
	}
	stream := reply.Topics[0]
	if stream.Topic == nil || *stream.Topic != topic || stream.ErrorCode != 0 || stream.IsInternal ||
		len(stream.Partitions) != 1 || stream.Partitions[0].Partition != 0 || stream.Partitions[0].ErrorCode != 0 {
		return dnsSource{}, fmt.Errorf("DNS event-time pilot requires exactly one readable input partition")
	}
	return dnsSource{Cluster: *reply.ClusterID, Topic: topic, TopicID: stream.TopicID}, nil
}

func openDNSWindowLane(ctx context.Context, w *Worker, brokers []string, cfg *tls.Config, path, start string) (*dnsWindowLane, error) {
	if w.nativeProducer == nil || w.networkShadowAlertsTopic != dnsAdvisoryTopic ||
		w.outputTopic == dnsAdvisoryTopic || w.inputTopic != "telemetry.normalized" || w.inputTopic == w.outputTopic ||
		w.scope != "identity-cloud" {
		return nil, fmt.Errorf("DNS event-time pilot requires native Kafka, identity-cloud scope, and the dedicated network advisory topic")
	}
	store, err := openDNSCheckpointStore(path)
	if err != nil {
		return nil, err
	}
	ok := false
	defer func() {
		if !ok {
			store.close()
		}
	}()
	checkpoint, err := store.load()
	if err != nil {
		return nil, err
	}
	fresh := checkpoint == nil
	if fresh {
		offset, err := strconv.ParseInt(start, 10, 64)
		if err != nil || offset < 0 || offset == 1<<63-1 {
			return nil, fmt.Errorf("new DNS checkpoint requires an explicit reviewed start offset")
		}
		checkpoint = &dnsCheckpoint{State: shadowrules.NewDNSWindowState(offset)}
	}
	offset := checkpoint.State.NextOffset
	if checkpoint.Anchor != "" {
		offset--
	}
	opts := []kgo.Opt{
		kgo.SeedBrokers(brokers...), kgo.FetchMaxBytes(2 << 20),
		kgo.FetchMaxPartitionBytes(2 << 20), kgo.ConsumeResetOffset(kgo.NoResetOffset()),
		kgo.ConsumePartitions(map[string]map[int32]kgo.Offset{
			w.inputTopic: {0: kgo.NoResetOffset().At(offset)},
		}),
	}
	if cfg != nil {
		opts = append(opts, kgo.DialTLSConfig(cfg))
	}
	broker, err := kgo.NewClient(opts...)
	if err != nil {
		return nil, err
	}
	defer func() {
		if !ok {
			broker.Close()
		}
	}()
	source, err := inspectDNSStream(ctx, broker, w.inputTopic)
	if err != nil {
		return nil, err
	}
	if fresh {
		checkpoint.Source = source
		if err := store.save(*checkpoint); err != nil {
			return nil, err
		}
	} else if checkpoint.Source != source {
		return nil, fmt.Errorf("DNS checkpoint source identity does not match the broker")
	}
	lane := &dnsWindowLane{
		store: store, broker: broker, checkpoint: *checkpoint,
		publish: func(alerts []shadowrules.EndpointAlert) bool {
			return w.publishShadowFindings(dnsAdvisoryTopic, "network-shadow", alerts)
		},
	}
	lane.updateMetrics()
	ok = true
	return lane, nil
}

func (l *dnsWindowLane) updateMetrics() {
	l.cursor.Store(l.checkpoint.State.NextOffset)
	l.late.Store(l.checkpoint.State.Late)
	l.invalid.Store(l.checkpoint.State.Invalid)
}

func dnsRecordAnchor(record *kgo.Record) string {
	payload, _ := json.Marshal([]any{record.Offset, record.Timestamp.UnixMilli(), record.Value})
	sum := sha256.Sum256(payload)
	return hex.EncodeToString(sum[:])
}

func (l *dnsWindowLane) publishPending() error {
	if len(l.checkpoint.Pending) == 0 {
		return nil
	}
	var alerts []shadowrules.EndpointAlert
	for _, window := range l.checkpoint.Pending {
		alerts = append(alerts, window.Findings()...)
	}
	if len(alerts) == 0 || !l.publish(alerts) {
		return fmt.Errorf("DNS advisory publication unconfirmed; retained checkpoint outbox")
	}
	next := l.checkpoint
	next.Pending = nil
	if err := l.store.save(next); err != nil {
		return err
	}
	l.checkpoint = next
	return nil
}

func (l *dnsWindowLane) persistInput(records []*kgo.Record) error {
	if len(records) == 0 {
		return nil
	}
	if len(l.checkpoint.Pending) != 0 {
		return fmt.Errorf("cannot advance DNS input with pending publications")
	}
	inputs := make([]shadowrules.DNSWindowRecord, 0, len(records))
	for i, record := range records {
		if record.Topic != l.checkpoint.Source.Topic || record.Partition != 0 ||
			record.Offset != l.checkpoint.State.NextOffset+int64(i) {
			return fmt.Errorf("unexpected DNS input partition or offset")
		}
		var event map[string]any
		if err := json.Unmarshal(record.Value, &event); err != nil || event == nil {
			return fmt.Errorf("invalid normalized DNS source record at offset %d", record.Offset)
		}
		inputs = append(inputs, shadowrules.DNSWindowRecord{
			Offset: record.Offset, ArrivedAt: record.Timestamp,
			Query: shadowrules.DNSWindowQueryFromNormalized(event, shadowEvidenceOrigin(event)),
		})
	}
	state, closed, err := l.checkpoint.State.Advance(inputs)
	if err != nil {
		return err
	}
	next := l.checkpoint
	next.State = state
	next.Anchor = dnsRecordAnchor(records[len(records)-1])
	for _, window := range closed {
		if len(window.Findings()) != 0 {
			next.Pending = append(next.Pending, window)
		}
	}
	if err := l.store.save(next); err != nil {
		return err
	}
	l.checkpoint = next
	l.updateMetrics()
	return nil
}

func (l *dnsWindowLane) run(ctx context.Context) {
	defer l.store.close()
	defer l.broker.Close()
	if err := l.consume(ctx); err != nil && ctx.Err() == nil {
		l.failed.Store(true)
		log.Printf("DNS event-time lane stopped; checkpoint retained: %v", err)
	}
	l.ready.Store(false)
}

func (l *dnsWindowLane) consume(ctx context.Context) error {
	needAnchor := l.checkpoint.Anchor != ""
	if !needAnchor {
		l.ready.Store(true)
	}
	lastMetadataCheck := time.Now()
	for ctx.Err() == nil {
		pollCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
		fetches := l.broker.PollRecords(pollCtx, 256)
		pollErr := pollCtx.Err()
		cancel()
		for _, fetch := range fetches.Errors() {
			if fetch.Topic == "" && pollErr != nil {
				continue
			}
			return fmt.Errorf("DNS event-time fetch failed: %w", fetch.Err)
		}
		if time.Since(lastMetadataCheck) >= 15*time.Second {
			metaCtx, done := context.WithTimeout(ctx, 5*time.Second)
			source, err := inspectDNSStream(metaCtx, l.broker, l.checkpoint.Source.Topic)
			done()
			if err != nil || source != l.checkpoint.Source {
				return fmt.Errorf("DNS input identity or partition count changed")
			}
			lastMetadataCheck = time.Now()
		}
		records := fetches.Records()
		if len(records) == 0 {
			continue
		}
		if needAnchor {
			first := records[0]
			if first.Topic != l.checkpoint.Source.Topic || first.Partition != 0 ||
				first.Offset != l.checkpoint.State.NextOffset-1 || dnsRecordAnchor(first) != l.checkpoint.Anchor {
				return fmt.Errorf("DNS checkpoint anchor no longer matches the retained source")
			}
			records = records[1:]
			if err := l.publishPending(); err != nil {
				return err
			}
			needAnchor = false
			l.ready.Store(true)
			if len(records) == 0 {
				continue
			}
		}
		if err := l.persistInput(records); err != nil {
			return err
		}
		if err := l.publishPending(); err != nil {
			return err
		}
	}
	return ctx.Err()
}
