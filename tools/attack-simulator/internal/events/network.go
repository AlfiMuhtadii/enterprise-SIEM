package events

import (
	"fmt"
	"math/rand"
	"time"
)

func BuildNetworkEvent(eventType string, params map[string]interface{}, actor ActorConfig, index int) map[string]interface{} {
	now := time.Now().UTC()

	sourceIP := pickRandom(actor.SourceIPPool)
	if sourceIP == "" {
		sourceIP = "10.0.0.100"
	}

	destIP := getStringParam(params, "dest_ip", "192.168.1.1")
	destPort := getIntParam(params, "dest_port", 80)
	protocol := getStringParam(params, "protocol", "tcp")
	bytesIn := getIntParam(params, "bytes_in", rand.Intn(1000))
	bytesOut := getIntParam(params, "bytes_out", rand.Intn(500))

	event := map[string]interface{}{
		"event_id":    fmt.Sprintf("net-%s-%d", eventType, now.UnixNano()),
		"event_type":  eventType,
		"source":      "network",
		"timestamp":   now.Format(time.RFC3339),
		"flow": map[string]interface{}{
			"src_ip":    sourceIP,
			"src_port":  40000 + index,
			"dst_ip":    destIP,
			"dst_port":  destPort,
			"protocol":  protocol,
			"bytes_in":  bytesIn,
			"bytes_out": bytesOut,
		},
		"risk_score": 0.4,
		"simulator":  true,
	}

	if eventType == "dns.query" {
		domain := getStringParam(params, "domain", "evil.com")
		if domains, ok := params["domains"].([]interface{}); ok && len(domains) > 0 {
			domain = domains[index%len(domains)].(string)
		}
		event["dns"] = map[string]interface{}{
			"query":      domain,
			"query_type": "A",
			"response":   "192.168.255.1",
		}
	}

	return event
}
