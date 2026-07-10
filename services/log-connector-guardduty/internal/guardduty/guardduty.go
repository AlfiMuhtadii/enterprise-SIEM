// Package guardduty parses AWS GuardDuty finding export files — GuardDuty's
// native "export findings" feature writes newline-delimited JSON (NDJSON,
// one finding object per line), gzip-compressed by default, to a configured
// S3 bucket (`.../AWSLogs/<account>/GuardDuty/<region>/...`).
//
// This is a materially different shape from CloudTrail's export (a single
// `{"Records": [...]}` JSON array per file) — GuardDuty is one JSON object
// per line — so it needed its own parser rather than reusing CloudTrail's.
//
// This is a pure, side-effect-free parser — no network/filesystem concerns
// — matching the internal/cloudtrail package pattern.
package guardduty

import (
	"bytes"
	"compress/gzip"
	"encoding/json"
	"io"
)

// Finding is one parsed GuardDuty finding. Resource/Service are kept as raw
// maps (not deeply typed structs) since their shape varies significantly by
// finding type (GuardDuty has dozens of distinct finding types, each with a
// different Service.Action variant) — Raw preserves the entire original
// finding so no detail is lost regardless of type.
type Finding struct {
	SchemaVersion string         `json:"SchemaVersion"`
	AccountID     string         `json:"AccountId"`
	Region        string         `json:"Region"`
	ID            string         `json:"Id"`
	Type          string         `json:"Type"`
	Severity      float64        `json:"Severity"`
	CreatedAt     string         `json:"CreatedAt"`
	UpdatedAt     string         `json:"UpdatedAt"`
	Title         string         `json:"Title"`
	Description   string         `json:"Description"`
	Resource      map[string]any `json:"Resource"`
	Service       map[string]any `json:"Service"`
	Raw           map[string]any `json:"-"`
}

// Parse decodes a GuardDuty findings export file: NDJSON (one finding
// object per line), optionally gzip-compressed (detected via the gzip
// magic bytes 0x1f 0x8b, the default GuardDuty S3 export format). A line
// that fails to decode is skipped rather than aborting the whole file — one
// poison line must not block every other finding in the same export.
func Parse(data []byte) ([]Finding, error) {
	if isGzip(data) {
		decompressed, err := gunzip(data)
		if err != nil {
			return nil, err
		}
		data = decompressed
	}

	lines := bytes.Split(data, []byte("\n"))
	findings := make([]Finding, 0, len(lines))
	for _, line := range lines {
		line = bytes.TrimSpace(line)
		if len(line) == 0 {
			continue
		}
		var raw map[string]any
		if err := json.Unmarshal(line, &raw); err != nil {
			continue
		}
		var f Finding
		if err := json.Unmarshal(line, &f); err != nil {
			continue
		}
		f.Raw = raw
		findings = append(findings, f)
	}

	return findings, nil
}

// RemoteIPAddress best-effort extracts a remote IP from whichever
// Service.Action.* variant this finding's type populated. GuardDuty
// findings carry different action shapes depending on Type
// (NetworkConnectionAction, AwsApiCallAction, DnsRequestAction,
// KubernetesApiCallAction, PortProbeAction, ...) — this checks the common
// ones and returns "" if none match, rather than guessing.
func (f Finding) RemoteIPAddress() string {
	action, _ := f.Service["Action"].(map[string]any)
	if action == nil {
		return ""
	}

	paths := [][]string{
		{"NetworkConnectionAction", "RemoteIpDetails", "IpAddressV4"},
		{"AwsApiCallAction", "RemoteIpDetails", "IpAddressV4"},
		{"DnsRequestAction", "RemoteIpDetails", "IpAddressV4"},
		{"KubernetesApiCallAction", "RemoteIpDetails", "IpAddressV4"},
	}
	for _, p := range paths {
		if ip := digString(action, p); ip != "" {
			return ip
		}
	}

	if probeAction, ok := action["PortProbeAction"].(map[string]any); ok {
		if details, ok := probeAction["PortProbeDetails"].([]any); ok && len(details) > 0 {
			if first, ok := details[0].(map[string]any); ok {
				if ip := digString(first, []string{"RemoteIpDetails", "IpAddressV4"}); ip != "" {
					return ip
				}
			}
		}
	}

	return ""
}

func digString(m map[string]any, path []string) string {
	var cur any = m
	for _, key := range path {
		mm, ok := cur.(map[string]any)
		if !ok {
			return ""
		}
		cur = mm[key]
	}
	s, _ := cur.(string)
	return s
}

func isGzip(data []byte) bool {
	return len(data) >= 2 && data[0] == 0x1f && data[1] == 0x8b
}

func gunzip(data []byte) ([]byte, error) {
	r, err := gzip.NewReader(bytes.NewReader(data))
	if err != nil {
		return nil, err
	}
	defer func() { _ = r.Close() }()
	return io.ReadAll(r)
}
