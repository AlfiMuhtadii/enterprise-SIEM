package main

import (
	"flag"
	"log"
	"os"

	"github.com/google/uuid"
	"gopkg.in/yaml.v3"

	"github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/client"
	"github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/config"
	"github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/generator"
	"github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/types"
)

func main() {
	scenarioFile := flag.String("scenario", "scenarios/c2_stealth_persistence.yaml", "Path to scenario YAML")
	flag.Parse()

	cfg := config.Load()
	ingestionClient := client.NewClient(cfg.IngestionURL)

	// Load scenario
	data, err := os.ReadFile(*scenarioFile)
	if err != nil {
		log.Fatalf("Failed to read scenario: %v", err)
	}

	var scenario types.SimulationScenario
	if err := yaml.Unmarshal(data, &scenario); err != nil {
		log.Fatalf("Failed to parse YAML: %v", err)
	}

	if scenario.SimulationID == "" {
		scenario.SimulationID = "sim-" + uuid.New().String()[:8]
	}

	generator.RunScenario(scenario, ingestionClient)
}
