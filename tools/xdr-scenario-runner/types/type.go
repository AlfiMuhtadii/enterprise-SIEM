package types

import "time"

type SimulationScenario struct {
	Name         string   `yaml:"name"`
	SimulationID string   `yaml:"simulation_id"`
	MITREAttack  []string `yaml:"mitre_attack"`
	Stages       []Stage  `yaml:"stages"`
}

type Stage struct {
	Name         string  `yaml:"stage"`
	DelaySeconds int     `yaml:"delay_seconds"`
	Repeat       int     `yaml:"repeat,omitempty"`
	JitterMS     []int   `yaml:"jitter_ms,omitempty"`
	Events       []Event `yaml:"events"`
}

type Event struct {
	Type       string                 `yaml:"type"`
	EventClass string                 `yaml:"event_class"`
	Data       map[string]interface{} `yaml:"data"`
}

type TelemetryEvent struct {
	SimulationID string                 `json:"simulation_id"`
	ScenarioName string                 `json:"scenario_name"`
	Timestamp    time.Time              `json:"timestamp"`
	Type         string                 `json:"type"`
	EventClass   string                 `json:"event_class"`
	Data         map[string]interface{} `json:"data"`
	MITRE        []string               `json:"mitre_techniques,omitempty"`
}
