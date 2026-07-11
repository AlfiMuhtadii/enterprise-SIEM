// Package boundedfile provides a size-bounded file read for
// CONN-UNBOUNDED-FILE: connectors previously called os.ReadFile() with no
// ceiling at all, so one oversized export file (or an operator-facing
// directory an attacker can drop a file into) could load an arbitrarily
// large amount of data into memory and restart-loop the connector.
package boundedfile

import (
	"errors"
	"io"
	"os"
)

// ErrTooLarge is returned when the file's content exceeds the configured
// byte ceiling.
var ErrTooLarge = errors.New("file exceeds configured size limit")

// Read reads at most maxBytes+1 bytes from path — enough to detect an
// oversized file without ever holding more than maxBytes+1 bytes in memory
// at once, regardless of how large the file actually is on disk. Returns
// ErrTooLarge (with no further allocation) if the content exceeds maxBytes.
// maxBytes<=0 disables the limit, preserving the original unbounded
// os.ReadFile behavior.
func Read(path string, maxBytes int64) ([]byte, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer func() { _ = f.Close() }()

	if maxBytes <= 0 {
		return io.ReadAll(f)
	}

	data, err := io.ReadAll(io.LimitReader(f, maxBytes+1))
	if err != nil {
		return nil, err
	}
	if int64(len(data)) > maxBytes {
		return nil, ErrTooLarge
	}
	return data, nil
}
