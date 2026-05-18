package events

import (
	"fmt"
	"time"
)

func BuildEndpointEvent(eventType string, params map[string]interface{}, actor ActorConfig, index int) map[string]interface{} {
	now := time.Now().UTC()

	process := getStringParam(params, "process", "cmd.exe")
	parent := getStringParam(params, "parent", "explorer.exe")
	commandLine := getStringParam(params, "command_line", "cmd.exe /c whoami")
	hostname := getStringParam(params, "hostname", "WORKSTATION-01")
	user := getStringParam(params, "user", "corp\\administrator")

	event := map[string]interface{}{
		"event_id":     fmt.Sprintf("endpoint-%s-%d", eventType, now.UnixNano()),
		"event_type":   eventType,
		"source":       "endpoint",
		"timestamp":    now.Format(time.RFC3339),
		"host": map[string]interface{}{
			"hostname": hostname,
			"ip":       "192.168.1.50",
			"os":       "Windows 10 Enterprise",
		},
		"process": map[string]interface{}{
			"name":         process,
			"pid":          1000 + index,
			"parent_name":  parent,
			"parent_pid":   800,
			"command_line": commandLine,
			"hash_md5":     "d41d8cd98f00b204e9800998ecf8427e",
			"hash_sha256":  "e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855",
		},
		"user":       user,
		"risk_score": 0.6,
		"simulator":  true,
	}

	if eventType == "file.creation" {
		filePath := getStringParam(params, "file_path", "C:\\Users\\Admin\\AppData\\Roaming\\malware.exe")
		event["file"] = map[string]interface{}{
			"path":      filePath,
			"size":      204800,
			"extension": "exe",
		}
	}

	return event
}
