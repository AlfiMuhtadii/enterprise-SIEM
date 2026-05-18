package engine

import (
	"fmt"
	"os"

	"github.com/alfimuhtadii/detector/attack-simulator/internal/events"
	"gopkg.in/yaml.v3"
)

type Scenario struct {
	ID          string         `yaml:"id"`
	Name        string         `yaml:"name"`
	Description string         `yaml:"description"`
	MITRE       MITREInfo      `yaml:"mitre"`
	Actor       ActorConfig    `yaml:"actor"`
	Timeline    []TimelineStep `yaml:"timeline"`
}

type MITREInfo struct {
	Tactic        string `yaml:"tactic"`
	Technique     string `yaml:"technique"`
	TechniqueName string `yaml:"technique_name"`
}

// gunakan alias langsung ke events.ActorConfig
type ActorConfig = events.ActorConfig

type TimelineStep struct {
	Time      string                 `yaml:"time"`
	Action    string                 `yaml:"action"`
	Source    string                 `yaml:"source"`
	EventType string                 `yaml:"event_type"`
	Count     int                    `yaml:"count"`
	Interval  string                 `yaml:"interval,omitempty"`
	Topic     string                 `yaml:"topic,omitempty"`
	Params    map[string]interface{} `yaml:"params"`
}

func LoadScenario(path string) (*Scenario, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("read file: %w", err)
	}

	var s Scenario
	if err := yaml.Unmarshal(data, &s); err != nil {
		return nil, fmt.Errorf("parse yaml: %w", err)
	}

	if s.ID == "" {
		return nil, fmt.Errorf("scenario missing id")
	}

	return &s, nil
}
