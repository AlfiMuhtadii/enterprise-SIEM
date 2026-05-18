package producers

import (
	"context"
	"fmt"
)

type StdoutProducer struct{}

func NewStdout() *StdoutProducer {
	return &StdoutProducer{}
}

func (s *StdoutProducer) Produce(ctx context.Context, topic, key string, value []byte) error {
	fmt.Printf("[STDOUT] topic=%s key=%s value=%s", topic, key, string(value))
	return nil
}

func (s *StdoutProducer) Close() error {
	return nil
}
