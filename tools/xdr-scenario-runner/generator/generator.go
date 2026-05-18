package generator

import (
	"fmt"
	"math/rand"
	"time"

	"github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/client"
	"github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/types"
)

func RunScenario(scenario types.SimulationScenario, client *client.Client) {
	fmt.Printf("[+] Running scenario: %s\n", scenario.Name)

	for _, stage := range scenario.Stages {
		fmt.Printf("   → Stage: %s\n", stage.Name)

		for i := 0; i < max(1, stage.Repeat); i++ {
			for _, ev := range stage.Events {
				telemetry := types.TelemetryEvent{
					SimulationID: scenario.SimulationID,
					ScenarioName: scenario.Name,
					Timestamp:    time.Now(),
					Type:         ev.Type,
					EventClass:   ev.EventClass,
					Data:         ev.Data,
					MITRE:        scenario.MITREAttack,
				}

				telemetry = AddSimulationMetadata(telemetry)

				if err := client.Send(telemetry); err != nil {
					fmt.Printf("   Error sending event: %v\n", err)
				} else {
					fmt.Printf("   ✓ Event sent: %s/%s\n", ev.Type, ev.EventClass)
				}
			}

			// Jitter
			if len(stage.JitterMS) > 0 {
				jitter := rand.Intn(stage.JitterMS[1]-stage.JitterMS[0]) + stage.JitterMS[0]
				time.Sleep(time.Duration(jitter) * time.Millisecond)
			}
		}

		if stage.DelaySeconds > 0 {
			time.Sleep(time.Duration(stage.DelaySeconds) * time.Second)
		}
	}

	fmt.Printf("[✓] Scenario %s completed\n", scenario.Name)
}

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}
