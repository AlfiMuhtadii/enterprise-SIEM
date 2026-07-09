package leef

import "testing"

func TestParseLEEF10WithTabDelimiter(t *testing.T) {
	line := "LEEF:1.0|Lancope|StealthWatch|1.0|41|src=192.0.2.1\tdst=192.0.2.2\tsev=5"
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Version != "1.0" || msg.Vendor != "Lancope" || msg.Product != "StealthWatch" ||
		msg.ProductVersion != "1.0" || msg.EventID != "41" {
		t.Fatalf("unexpected header fields: %+v", msg)
	}
	if msg.Delimiter != '\t' {
		t.Fatalf("expected default tab delimiter for LEEF 1.0, got %q", msg.Delimiter)
	}
	if msg.Extension["src"] != "192.0.2.1" || msg.Extension["dst"] != "192.0.2.2" || msg.Extension["sev"] != "5" {
		t.Fatalf("unexpected extension: %+v", msg.Extension)
	}
}

func TestParseLEEF20WithLiteralDelimiter(t *testing.T) {
	line := "LEEF:2.0|Vendor|Product|1.0|100|^|src=1.2.3.4^dst=5.6.7.8^act=blocked"
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Delimiter != '^' {
		t.Fatalf("expected '^' delimiter, got %q", msg.Delimiter)
	}
	if msg.Extension["src"] != "1.2.3.4" || msg.Extension["dst"] != "5.6.7.8" || msg.Extension["act"] != "blocked" {
		t.Fatalf("unexpected extension: %+v", msg.Extension)
	}
}

func TestParseLEEF20WithHexDelimiter(t *testing.T) {
	line := "LEEF:2.0|Vendor|Product|2.1|200|x09|src=10.0.0.1\tuser=alice"
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Delimiter != '\t' {
		t.Fatalf("expected hex-decoded tab delimiter, got %q", msg.Delimiter)
	}
	if msg.Extension["src"] != "10.0.0.1" || msg.Extension["user"] != "alice" {
		t.Fatalf("unexpected extension: %+v", msg.Extension)
	}
}

func TestParseLEEF20WithZeroXHexDelimiter(t *testing.T) {
	line := "LEEF:2.0|Vendor|Product|2.1|200|0x09|src=10.0.0.1\tuser=bob"
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Delimiter != '\t' {
		t.Fatalf("expected hex-decoded tab delimiter, got %q", msg.Delimiter)
	}
}

func TestParseWithRFC3164SyslogPrefix(t *testing.T) {
	line := "<134>Jul 10 10:00:00 fw-01 LEEF:1.0|Vendor|Product|1.0|10|act=allow"
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Vendor != "Vendor" || msg.Extension["act"] != "allow" {
		t.Fatalf("unexpected parse of prefixed line: %+v", msg)
	}
}

func TestParseHonorsEscapedPipeInHeaderField(t *testing.T) {
	line := `LEEF:1.0|Vendor|Fire\|Wall|1.0|10|act=deny`
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Product != "Fire|Wall" {
		t.Fatalf("expected escaped pipe preserved in product field, got %q", msg.Product)
	}
}

func TestParseHonorsEscapedDelimiterInExtensionValue(t *testing.T) {
	line := `LEEF:2.0|Vendor|Product|1.0|10|^|msg=blocked \^ retried^act=deny`
	msg, err := Parse(line)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if msg.Extension["msg"] != "blocked ^ retried" {
		t.Fatalf("expected escaped delimiter preserved in value, got %q", msg.Extension["msg"])
	}
	if msg.Extension["act"] != "deny" {
		t.Fatalf("expected subsequent field parsed correctly, got %+v", msg.Extension)
	}
}

func TestParseNoExtension(t *testing.T) {
	msg, err := Parse("LEEF:1.0|Vendor|Product|1.0|10|")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(msg.Extension) != 0 {
		t.Fatalf("expected empty extension, got %+v", msg.Extension)
	}
}

func TestParseRejectsNonLEEFLine(t *testing.T) {
	if _, err := Parse("this is not a LEEF-formatted line at all"); err != ErrNotLEEF {
		t.Fatalf("expected ErrNotLEEF, got %v", err)
	}
}

func TestParseRejectsUnsupportedVersion(t *testing.T) {
	if _, err := Parse("LEEF:3.0|Vendor|Product|1.0|10|act=deny"); err != ErrUnsupportedVersion {
		t.Fatalf("expected ErrUnsupportedVersion, got %v", err)
	}
}

func TestParseRejectsTooFewHeaderFieldsV1(t *testing.T) {
	if _, err := Parse("LEEF:1.0|Vendor|Product|act=deny"); err != ErrMalformed {
		t.Fatalf("expected ErrMalformed, got %v", err)
	}
}

func TestParseRejectsTooFewHeaderFieldsV2(t *testing.T) {
	if _, err := Parse("LEEF:2.0|Vendor|Product|1.0|10|act=deny"); err != ErrMalformed {
		t.Fatalf("expected ErrMalformed (missing delimiter field), got %v", err)
	}
}

func TestParseRejectsInvalidDelimiterField(t *testing.T) {
	if _, err := Parse("LEEF:2.0|Vendor|Product|1.0|10|notahex|act=deny"); err != ErrMalformed {
		t.Fatalf("expected ErrMalformed for invalid delimiter field, got %v", err)
	}
}
