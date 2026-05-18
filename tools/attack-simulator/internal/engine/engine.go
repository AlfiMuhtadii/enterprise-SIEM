package engine

import (
	"context"
	"encoding/json"
	"fmt"
	"math/rand"
	"time"

	"github.com/alfimuhtadii/detector/attack-simulator/internal/events"
	"github.com/alfimuhtadii/detector/attack-simulator/internal/producers"
)

type Engine struct {
	producer producers.Producer
	speed    float64
	Stats    Stats
}

type Stats struct {
	EventsProduced int
	SimulatedTime  time.Duration
}

func New(p producers.Producer, speed float64) *Engine {
	return &Engine{
		producer: p,
		speed:    speed,
		Stats:    Stats{},
	}
}

func (e *Engine) Run(ctx context.Context, s *Scenario) error {
	var lastSimTime time.Duration

	for i, step := range s.Timeline {
		stepDuration, err := parseDuration(step.Time)
		if err != nil {
			return fmt.Errorf("step %d: invalid time %s: %w", i, step.Time, err)
		}

		// Wait until it's time for this step (adjusted by speed)
		waitTime := time.Duration(float64(stepDuration-lastSimTime) / e.speed)
		if waitTime > 0 {
			select {
			case <-ctx.Done():
				return ctx.Err()
			case <-time.After(waitTime):
			}
		}
		lastSimTime = stepDuration

		if err := e.executeStep(ctx, step, s.Actor); err != nil {
			return fmt.Errorf("step %d (%s): %w", i, step.EventType, err)
		}
	}

	e.Stats.SimulatedTime = lastSimTime
	return nil
}

func (e *Engine) executeStep(ctx context.Context, step TimelineStep, actor ActorConfig) error {
	count := step.Count
	if count <= 0 {
		count = 1
	}

	interval := time.Duration(0)
	if step.Interval != "" {
		var err error
		interval, err = parseDuration(step.Interval)
		if err != nil {
			return fmt.Errorf("invalid interval: %w", err)
		}
	}

	for i := 0; i < count; i++ {
		event, err := e.buildEvent(step, actor, i)
		if err != nil {
			return fmt.Errorf("build event: %w", err)
		}

		payload, err := json.Marshal(event)
		if err != nil {
			return fmt.Errorf("marshal event: %w", err)
		}

		topic := step.Topic
		if topic == "" {
			topic = fmt.Sprintf("%s.events", step.Source)
		}

		if err := e.producer.Produce(ctx, topic, event["event_id"].(string), payload); err != nil {
			return fmt.Errorf("produce: %w", err)
		}

		e.Stats.EventsProduced++

		// Apply jitter to interval
		if i < count-1 && interval > 0 {
			jittered := applyJitter(interval, actor.Jitter)
			jittered = time.Duration(float64(jittered) / e.speed)
			time.Sleep(jittered)
		}
	}

	return nil
}

func (e *Engine) buildEvent(step TimelineStep, actor ActorConfig, index int) (map[string]interface{}, error) {
	var event map[string]interface{}

	switch step.Source {
	case "identity":
		event = events.BuildIdentityEvent(step.EventType, step.Params, actor, index)
	case "endpoint":
		event = events.BuildEndpointEvent(step.EventType, step.Params, actor, index)
	case "network":
		event = events.BuildNetworkEvent(step.EventType, step.Params, actor, index)
	case "cloud":
		event = events.BuildCloudEvent(step.EventType, step.Params, actor, index)
	default:
		return nil, fmt.Errorf("unknown source: %s", step.Source)
	}

	return event, nil
}

func parseDuration(s string) (time.Duration, error) {
	return time.ParseDuration(s)
}

func applyJitter(d time.Duration, jitter float64) time.Duration {
	if jitter <= 0 {
		return d
	}
	factor := 1.0 + (rand.Float64()*2-1)*jitter
	return time.Duration(float64(d) * factor)
}
