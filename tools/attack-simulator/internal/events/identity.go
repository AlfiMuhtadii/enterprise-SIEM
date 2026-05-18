package events

import (
	"fmt"
	"time"
)

func BuildIdentityEvent(eventType string, params map[string]interface{}, actor ActorConfig, index int) map[string]interface{} {
	now := time.Now().UTC()

	sourceIP := pickRandom(actor.SourceIPPool)
	if sourceIP == "" {
		sourceIP = "10.0.0.100"
	}

	username := getStringParam(params, "username", "admin")
	if usernames, ok := params["usernames"].([]interface{}); ok && len(usernames) > 0 {
		username = usernames[index%len(usernames)].(string)
	}

	targetHost := getStringParam(params, "target_host", "dc01.corp.local")
	protocol := getStringParam(params, "protocol", "rdp")
	result := "failure"
	if eventType == "authentication.success" {
		result = "success"
	}

	event := map[string]interface{}{
		"event_id":     fmt.Sprintf("auth-%s-%d", result, now.UnixNano()),
		"event_type":   eventType,
		"source":       "identity",
		"timestamp":    now.Format(time.RFC3339),
		"actor": map[string]interface{}{
			"ip":        sourceIP,
			"username":  username,
			"user_agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
		},
		"target": map[string]interface{}{
			"hostname": targetHost,
			"ip":       resolveHostname(targetHost),
			"port":     defaultPort(protocol),
		},
		"protocol":      protocol,
		"result":        result,
		"risk_score":    calculateRisk(eventType, index),
		"simulator":     true,
	}

	if result == "failure" {
		event["failure_reason"] = "invalid_credentials"
	}

	return event
}

func calculateRisk(eventType string, index int) float64 {
	if eventType == "authentication.failure" {
		return 0.2 + float64(index)*0.01
	}
	return 0.8
}
