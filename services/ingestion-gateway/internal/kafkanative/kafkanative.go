// Package kafkanative wraps franz-go for native Kafka transport.
package kafkanative

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"os"

	"github.com/twmb/franz-go/pkg/kgo"
)

// Record is a consumed Kafka record. Callers do not need to import kgo.
type Record = kgo.Record

// LoadTLSConfig builds broker-authenticated TLS configuration. A client
// certificate is optional, but its certificate and key must be supplied as a
// pair. Disabled mode deliberately ignores all paths and preserves plaintext.
func LoadTLSConfig(enabled bool, caFile, clientCertFile, clientKeyFile string) (*tls.Config, error) {
	if !enabled {
		return nil, nil
	}
	if caFile == "" {
		return nil, fmt.Errorf("kafkanative: CA file is required when TLS is enabled")
	}
	if (clientCertFile == "") != (clientKeyFile == "") {
		return nil, fmt.Errorf("kafkanative: client certificate and key must be configured together")
	}

	caPEM, err := os.ReadFile(caFile)
	if err != nil {
		return nil, fmt.Errorf("kafkanative: read CA file: %w", err)
	}
	roots := x509.NewCertPool()
	if !roots.AppendCertsFromPEM(caPEM) {
		return nil, fmt.Errorf("kafkanative: CA file contains no valid certificates")
	}

	cfg := &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots}
	if clientCertFile != "" {
		cert, err := tls.LoadX509KeyPair(clientCertFile, clientKeyFile)
		if err != nil {
			return nil, fmt.Errorf("kafkanative: load client certificate: %w", err)
		}
		cfg.Certificates = []tls.Certificate{cert}
	}
	return cfg, nil
}

func clientOptions(brokers []string, tlsConfig *tls.Config, extra ...kgo.Opt) []kgo.Opt {
	opts := []kgo.Opt{kgo.SeedBrokers(brokers...)}
	if tlsConfig != nil {
		opts = append(opts, kgo.DialTLSConfig(tlsConfig))
	}
	return append(opts, extra...)
}

// Producer is a synchronous, all-or-nothing native Kafka producer.
type Producer struct {
	cl *kgo.Client
}

func NewProducer(brokers []string) (*Producer, error) {
	return NewProducerTLS(brokers, nil)
}

func NewProducerTLS(brokers []string, tlsConfig *tls.Config) (*Producer, error) {
	if len(brokers) == 0 {
		return nil, fmt.Errorf("kafkanative: no brokers configured")
	}
	cl, err := kgo.NewClient(clientOptions(brokers, tlsConfig)...)
	if err != nil {
		return nil, fmt.Errorf("kafkanative: new producer client: %w", err)
	}
	return &Producer{cl: cl}, nil
}

func (p *Producer) Publish(ctx context.Context, topic string, values [][]byte) error {
	if len(values) == 0 {
		return nil
	}
	recs := make([]*kgo.Record, len(values))
	for i, value := range values {
		recs[i] = &kgo.Record{Topic: topic, Value: value}
	}
	if err := p.cl.ProduceSync(ctx, recs...).FirstErr(); err != nil {
		return fmt.Errorf("kafkanative: publish to %s: %w", topic, err)
	}
	return nil
}

func (p *Producer) Close() {
	p.cl.Close()
}

// Consumer disables auto-commit; callers commit only after downstream effects
// are durable.
type Consumer struct {
	cl *kgo.Client
}

func NewConsumer(brokers []string, group string, topics []string) (*Consumer, error) {
	return NewConsumerTLS(brokers, group, topics, nil)
}

func NewConsumerTLS(brokers []string, group string, topics []string, tlsConfig *tls.Config) (*Consumer, error) {
	if len(brokers) == 0 {
		return nil, fmt.Errorf("kafkanative: no brokers configured")
	}
	if group == "" {
		return nil, fmt.Errorf("kafkanative: no consumer group configured")
	}
	cl, err := kgo.NewClient(clientOptions(
		brokers,
		tlsConfig,
		kgo.ConsumerGroup(group),
		kgo.ConsumeTopics(topics...),
		kgo.ConsumeResetOffset(kgo.NewOffset().AtStart()),
		kgo.DisableAutoCommit(),
	)...)
	if err != nil {
		return nil, fmt.Errorf("kafkanative: new consumer client: %w", err)
	}
	return &Consumer{cl: cl}, nil
}

func (c *Consumer) Poll(ctx context.Context) ([]*Record, error) {
	fetches := c.cl.PollFetches(ctx)
	if errs := fetches.Errors(); len(errs) > 0 {
		var real []error
		for _, fetchErr := range errs {
			if fetchErr.Topic == "" && fetchErr.Partition == -1 &&
				(errors.Is(fetchErr.Err, context.DeadlineExceeded) || errors.Is(fetchErr.Err, context.Canceled)) {
				continue
			}
			real = append(real, fetchErr.Err)
		}
		if len(real) > 0 {
			return nil, fmt.Errorf("kafkanative: poll: %v", real)
		}
	}
	return fetches.Records(), nil
}

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
