package generator

import "github.com/AlfiMuhtadii/enterprise-SIEM/simulators/xdr-scenario-runner/types"

func AddSimulationMetadata(event types.TelemetryEvent) types.TelemetryEvent {
	if event.Data == nil {
		event.Data = make(map[string]interface{})
	}
	event.Data["is_simulation"] = true
	event.Data["ground_truth"] = true
	return event
}
