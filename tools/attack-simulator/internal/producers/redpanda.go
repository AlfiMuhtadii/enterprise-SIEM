package producers

import (
	"context"
	"fmt"
	"strings"
	"time"

	"github.com/twmb/franz-go/pkg/kgo"
)

type RedpandaProducer struct {
	client *kgo.Client
}

func NewRedpanda(brokers string) (*RedpandaProducer, error) {
	seeds := strings.Split(brokers, ",")
	for i := range seeds {
		seeds[i] = strings.TrimSpace(seeds[i])
	}

	cl, err := kgo.NewClient(
		kgo.SeedBrokers(seeds...),
		kgo.AllowAutoTopicCreation(),
		kgo.ProducerBatchMaxBytes(1000000),
		kgo.ProducerLinger(5 * time.Millisecond),
	)
	if err != nil {
		return nil, fmt.Errorf("create kafka client: %w", err)
	}

	return &RedpandaProducer{client: cl}, nil
}

func (r *RedpandaProducer) Produce(ctx context.Context, topic, key string, value []byte) error {
	record := &kgo.Record{
		Topic: topic,
		Key:   []byte(key),
		Value: value,
		Headers: []kgo.RecordHeader{
			{Key: "simulator", Value: []byte("detector-attack-simulator")},
			{Key: "timestamp", Value: []byte(time.Now().UTC().Format(time.RFC3339))},
		},
	}

	return r.client.ProduceSync(ctx, record).FirstErr()
}

func (r *RedpandaProducer) Close() error {
	r.client.Close()
	return nil
}
