package producers

import "context"

type Producer interface {
	Produce(ctx context.Context, topic, key string, value []byte) error
	Close() error
}
