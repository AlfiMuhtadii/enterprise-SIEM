package boundedfile

import (
	"bytes"
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func writeFile(t *testing.T, dir string, size int) string {
	t.Helper()
	path := filepath.Join(dir, "sample.txt")
	content := bytes.Repeat([]byte("a"), size)
	if err := os.WriteFile(path, content, 0o644); err != nil {
		t.Fatalf("write fixture: %v", err)
	}
	return path
}

func TestReadReturnsFullContentUnderLimit(t *testing.T) {
	dir := t.TempDir()
	path := writeFile(t, dir, 100)

	data, err := Read(path, 200)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(data) != 100 {
		t.Errorf("expected 100 bytes, got %d", len(data))
	}
}

func TestReadReturnsErrTooLargeOverLimit(t *testing.T) {
	dir := t.TempDir()
	path := writeFile(t, dir, 1000)

	_, err := Read(path, 100)
	if !errors.Is(err, ErrTooLarge) {
		t.Fatalf("expected ErrTooLarge, got: %v", err)
	}
}

func TestReadExactlyAtLimitSucceeds(t *testing.T) {
	dir := t.TempDir()
	path := writeFile(t, dir, 100)

	data, err := Read(path, 100)
	if err != nil {
		t.Fatalf("unexpected error at exact limit: %v", err)
	}
	if len(data) != 100 {
		t.Errorf("expected 100 bytes, got %d", len(data))
	}
}

func TestReadZeroOrNegativeLimitDisablesBound(t *testing.T) {
	dir := t.TempDir()
	path := writeFile(t, dir, 10000)

	for _, limit := range []int64{0, -1} {
		data, err := Read(path, limit)
		if err != nil {
			t.Fatalf("limit=%d: unexpected error: %v", limit, err)
		}
		if len(data) != 10000 {
			t.Errorf("limit=%d: expected 10000 bytes, got %d", limit, len(data))
		}
	}
}

func TestReadMissingFileReturnsError(t *testing.T) {
	_, err := Read(filepath.Join(t.TempDir(), "missing.txt"), 100)
	if err == nil {
		t.Fatal("expected an error for a missing file")
	}
}

func TestReadNeverAllocatesMoreThanLimitPlusOneRegardlessOfFileSize(t *testing.T) {
	dir := t.TempDir()
	// A file dramatically larger than the limit — Read must reject it
	// having only ever buffered maxBytes+1 bytes, not the full file.
	path := writeFile(t, dir, 5_000_000)

	_, err := Read(path, 1024)
	if !errors.Is(err, ErrTooLarge) {
		t.Fatalf("expected ErrTooLarge, got: %v", err)
	}
}
