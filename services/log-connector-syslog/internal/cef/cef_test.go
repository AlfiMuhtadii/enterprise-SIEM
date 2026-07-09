package cef

import "testing"

func TestParseBareCEF(t *testing.T) {
	msg, err := Parse(`CEF:0|Palo Alto Networks|PAN-OS|10.1|threat|Suspicious DNS Query|5|src=10.0.0.5 dst=203.0.113.9 spt=51820 dpt=53 act=allowed`)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Version != 0 || msg.DeviceVendor != "Palo Alto Networks" || msg.DeviceProduct != "PAN-OS" {
		t.Fatalf("header fields not parsed correctly: %+v", msg)
	}
	if msg.SignatureID != "threat" || msg.Name != "Suspicious DNS Query" || msg.Severity != "5" {
		t.Fatalf("header fields not parsed correctly: %+v", msg)
	}
	want := map[string]string{"src": "10.0.0.5", "dst": "203.0.113.9", "spt": "51820", "dpt": "53", "act": "allowed"}
	for k, v := range want {
		if msg.Extension[k] != v {
			t.Fatalf("extension[%s] = %q, want %q", k, msg.Extension[k], v)
		}
	}
}

func TestParseWithRFC3164SyslogPrefix(t *testing.T) {
	line := `<134>Jul  9 10:15:00 fw01 CEF:0|Fortinet|FortiGate|7.0|0100|Traffic Allow|3|src=192.168.1.10 dst=8.8.8.8`
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.DeviceVendor != "Fortinet" || msg.Extension["dst"] != "8.8.8.8" {
		t.Fatalf("syslog prefix was not correctly discarded: %+v", msg)
	}
}

func TestParseWithRFC5424SyslogPrefix(t *testing.T) {
	line := `<134>1 2026-07-09T10:15:00Z fw02 fortigate - - - CEF:0|Fortinet|FortiGate|7.0|0100|Traffic Deny|7|src=10.1.1.1 dst=1.1.1.1 act=blocked`
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Name != "Traffic Deny" || msg.Extension["act"] != "blocked" {
		t.Fatalf("syslog prefix was not correctly discarded: %+v", msg)
	}
}

func TestParseWithNoExtension(t *testing.T) {
	msg, err := Parse(`CEF:0|Vendor|Product|1.0|100|Name Only|5`)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(msg.Extension) != 0 {
		t.Fatalf("expected empty extension map, got %v", msg.Extension)
	}
}

func TestParseHonorsEscapedPipeInHeaderField(t *testing.T) {
	msg, err := Parse(`CEF:0|Vendor|Product|1.0|100|Name with a \| pipe|5|msg=hello`)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Name != "Name with a | pipe" {
		t.Fatalf("expected escaped pipe preserved literally, got %q", msg.Name)
	}
}

func TestParseHonorsEscapedEqualsInExtensionValue(t *testing.T) {
	msg, err := Parse(`CEF:0|Vendor|Product|1.0|100|Name|5|msg=key\=value pair cs1=plain`)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Extension["msg"] != "key=value pair" {
		t.Fatalf("expected unescaped '=' inside value, got %q", msg.Extension["msg"])
	}
	if msg.Extension["cs1"] != "plain" {
		t.Fatalf("expected trailing key to still parse, got %q", msg.Extension["cs1"])
	}
}

func TestParseRejectsNonCEFLine(t *testing.T) {
	_, err := Parse(`<134>Jul  9 10:15:00 host sshd[123]: Accepted password for root`)
	if err != ErrNotCEF {
		t.Fatalf("expected ErrNotCEF, got %v", err)
	}
}

func TestParseRejectsTooFewHeaderFields(t *testing.T) {
	_, err := Parse(`CEF:0|Vendor|Product`)
	if err != ErrMalformed {
		t.Fatalf("expected ErrMalformed, got %v", err)
	}
}

func TestParseRejectsNonNumericVersion(t *testing.T) {
	_, err := Parse(`CEF:zero|Vendor|Product|1.0|100|Name|5|`)
	if err != ErrMalformed {
		t.Fatalf("expected ErrMalformed, got %v", err)
	}
}

func TestParseExtensionValueWithMultipleSpaces(t *testing.T) {
	msg, err := Parse(`CEF:0|Vendor|Product|1.0|100|Name|5|msg=multiple   word value here cs1=next`)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Extension["msg"] != "multiple   word value here" {
		t.Fatalf("expected full multi-word value preserved, got %q", msg.Extension["msg"])
	}
	if msg.Extension["cs1"] != "next" {
		t.Fatalf("expected next key correctly split, got %q", msg.Extension["cs1"])
	}
}
