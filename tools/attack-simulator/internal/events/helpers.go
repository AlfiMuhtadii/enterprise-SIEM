package events

import (
	"fmt"
	"math/rand"
)

type ActorConfig struct {
	Profile      string   `yaml:"profile"`
	Jitter       float64  `yaml:"jitter"`
	SourceIPPool []string `yaml:"source_ip_pool"`
}

func pickRandom(pool []string) string {
	if len(pool) == 0 {
		return ""
	}
	return pool[rand.Intn(len(pool))]
}

func getStringParam(params map[string]interface{}, key, fallback string) string {
	if v, ok := params[key]; ok {
		if s, ok := v.(string); ok {
			return s
		}
	}
	return fallback
}

func getIntParam(params map[string]interface{}, key string, fallback int) int {
	if v, ok := params[key]; ok {
		if f, ok := v.(float64); ok {
			return int(f)
		}
		if i, ok := v.(int); ok {
			return i
		}
	}
	return fallback
}

func defaultPort(protocol string) int {
	switch protocol {
	case "rdp":
		return 3389
	case "ssh":
		return 22
	case "ldap":
		return 389
	case "smb":
		return 445
	case "http":
		return 80
	case "https":
		return 443
	default:
		return 0
	}
}

func resolveHostname(hostname string) string {
	// Simple mock resolver
	return fmt.Sprintf("192.168.%d.%d", rand.Intn(255), rand.Intn(255))
}
