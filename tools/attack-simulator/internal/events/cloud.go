package events

import (
	"fmt"
	"time"
)

func BuildCloudEvent(eventType string, params map[string]interface{}, actor ActorConfig, index int) map[string]interface{} {
	now := time.Now().UTC()

	cloudProvider := getStringParam(params, "cloud_provider", "aws")
	accountID := getStringParam(params, "account_id", "123456789012")
	region := getStringParam(params, "region", "us-east-1")
	user := getStringParam(params, "user", "admin@corp.com")
	action := getStringParam(params, "action", "PutObject")

	event := map[string]interface{}{
		"event_id":    fmt.Sprintf("cloud-%s-%d", eventType, now.UnixNano()),
		"event_type":  eventType,
		"source":      "cloud",
		"timestamp":   now.Format(time.RFC3339),
		"cloud": map[string]interface{}{
			"provider":   cloudProvider,
			"account_id": accountID,
			"region":     region,
		},
		"actor": map[string]interface{}{
			"user":         user,
			"ip":           pickRandom(actor.SourceIPPool),
			"user_agent":   "AWS-CLI/2.0",
		},
		"risk_score": 0.7,
		"simulator":  true,
	}

	if eventType == "iam.policy.change" {
		event["iam"] = map[string]interface{}{
			"action":        action,
			"resource":      getStringParam(params, "resource", "arn:aws:s3:::corp-data"),
			"effect":        "Allow",
			"principal":     user,
			"change_type":   "policy_attachment",
		}
	}

	if eventType == "login.anomaly" {
		event["login"] = map[string]interface{}{
			"success":       true,
			"mfa_used":      false,
			"new_device":    true,
			"new_location":  true,
			"country":       getStringParam(params, "country", "RU"),
		}
	}

	return event
}
