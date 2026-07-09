// Package registry implements CONNECTOR-FRAMEWORK's config-driven parser
// registry: a JSON file mapping a marker string (e.g. a fixed prefix a
// simple appliance always emits) to a set of output field names sourced
// from a generic "key=value" extension, so a new log source can be
// onboarded by editing a config file, not by writing a new Go parser.
//
// This complements (does not replace) the named-format parsers internal/cef
// and internal/leef, which remain hand-written because CEF/LEEF have
// version-specific header grammars a flat field map cannot express. The
// registry is for the long tail of simple vendor formats that are just a
// marker followed by space-separated key=value pairs.
package registry

import (
	"encoding/json"
	"os"
	"regexp"
	"strings"
)

// SourceDefinition describes one config-driven source. Marker is matched as
// a plain substring anywhere in the line (like "CEF:"/"LEEF:"); everything
// after the marker is parsed as space-separated key=value pairs. FieldMap
// maps an output field name (e.g. "source_ip") to the key that carries it
// in the source's own key=value vocabulary (e.g. "src").
type SourceDefinition struct {
	Name           string            `json:"name"`
	Marker         string            `json:"marker"`
	TelemetryType  string            `json:"telemetry_type"`
	EventTypeField string            `json:"event_type_field"`
	FieldMap       map[string]string `json:"field_map"`
}

// Registry is a loaded set of source definitions, tried in file order.
type Registry struct {
	Sources []SourceDefinition `json:"sources"`
}

// Message is the parsed result of matching a line against a SourceDefinition.
type Message struct {
	SourceName    string
	TelemetryType string
	EventType     string
	// Fields holds only the entries FieldMap promoted (output field name -> value).
	Fields map[string]string
	// Extension holds every key=value pair found, verbatim, so no
	// source-specific detail is lost even if FieldMap didn't name it.
	Extension map[string]string
}

// Load reads a JSON registry config file. An empty path returns an empty,
// non-nil registry (Match always returns nil) — the zero-config default, so
// CEF/LEEF/raw dispatch behaves exactly as it did before this package
// existed unless an operator opts in.
func Load(path string) (*Registry, error) {
	if path == "" {
		return &Registry{}, nil
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	var reg Registry
	if err := json.Unmarshal(data, &reg); err != nil {
		return nil, err
	}
	return &reg, nil
}

// Match returns the first source definition whose marker appears in line,
// or nil if none match (including when r is nil, so callers with an unset
// registry don't need a separate nil check).
func (r *Registry) Match(line string) *SourceDefinition {
	if r == nil {
		return nil
	}
	for i := range r.Sources {
		if r.Sources[i].Marker != "" && strings.Contains(line, r.Sources[i].Marker) {
			return &r.Sources[i]
		}
	}
	return nil
}

var kvPattern = regexp.MustCompile(`([A-Za-z0-9_.]+)=`)

// parseKV extracts space-separated key=value pairs, using the same
// key-boundary technique as internal/cef's extension parser: values may
// contain spaces, so the next "key=" occurrence marks a value's end.
func parseKV(raw string) map[string]string {
	kv := map[string]string{}
	matches := kvPattern.FindAllStringSubmatchIndex(raw, -1)
	for i, m := range matches {
		key := raw[m[2]:m[3]]
		valStart := m[1]
		valEnd := len(raw)
		if i+1 < len(matches) {
			valEnd = matches[i+1][0]
		}
		value := strings.TrimSpace(raw[valStart:valEnd])
		if key != "" {
			kv[key] = value
		}
	}
	return kv
}

// Parse maps a line already matched by Match into a Message: FieldMap
// promotes named keys to output field names, while Extension preserves
// every key=value pair found, verbatim.
func (def *SourceDefinition) Parse(line string) *Message {
	raw := line
	if idx := strings.Index(line, def.Marker); idx != -1 {
		raw = line[idx+len(def.Marker):]
	}
	kv := parseKV(raw)

	fields := map[string]string{}
	for outField, sourceKey := range def.FieldMap {
		if v, ok := kv[sourceKey]; ok && v != "" {
			fields[outField] = v
		}
	}
	eventType := ""
	if def.EventTypeField != "" {
		eventType = kv[def.EventTypeField]
	}

	return &Message{
		SourceName:    def.Name,
		TelemetryType: def.TelemetryType,
		EventType:     eventType,
		Fields:        fields,
		Extension:     kv,
	}
}
