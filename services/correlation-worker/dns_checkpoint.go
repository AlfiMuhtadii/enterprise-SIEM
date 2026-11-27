package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"

	"detector-xdr-correlation-worker/internal/shadowrules"
)

const maxDNSCheckpointSize = 8 << 20

type dnsSource struct {
	Cluster string   `json:"cluster"`
	Topic   string   `json:"topic"`
	TopicID [16]byte `json:"topic_id"`
}

type dnsCheckpoint struct {
	Source  dnsSource                  `json:"source"`
	State   shadowrules.DNSWindowState `json:"state"`
	Anchor  string                     `json:"anchor,omitempty"`
	Pending []shadowrules.DNSWindow    `json:"pending,omitempty"`
}

type dnsCheckpointStore struct {
	path string
	lock *os.File
}

func openDNSCheckpointStore(path string) (*dnsCheckpointStore, error) {
	if !filepath.IsAbs(path) {
		return nil, fmt.Errorf("DNS checkpoint path must be absolute")
	}
	f, err := os.OpenFile(path+".lock", os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return nil, err
	}
	if err := lockDNSCheckpoint(f); err != nil {
		f.Close()
		return nil, err
	}
	return &dnsCheckpointStore{path: path, lock: f}, nil
}

func (s *dnsCheckpointStore) close() { s.lock.Close() }

func (s *dnsCheckpointStore) load() (*dnsCheckpoint, error) {
	f, err := os.Open(s.path)
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, err
	}
	defer f.Close()
	data, err := io.ReadAll(io.LimitReader(f, maxDNSCheckpointSize+1))
	if err != nil || len(data) > maxDNSCheckpointSize {
		return nil, fmt.Errorf("cannot read bounded DNS checkpoint")
	}
	var snapshot struct {
		Digest string          `json:"sha256"`
		Data   json.RawMessage `json:"checkpoint"`
	}
	if err := json.Unmarshal(data, &snapshot); err != nil {
		return nil, fmt.Errorf("invalid DNS checkpoint envelope")
	}
	digest := sha256.Sum256(snapshot.Data)
	if snapshot.Digest != hex.EncodeToString(digest[:]) {
		return nil, fmt.Errorf("DNS checkpoint checksum mismatch")
	}
	var checkpoint dnsCheckpoint
	if err := json.Unmarshal(snapshot.Data, &checkpoint); err != nil {
		return nil, fmt.Errorf("invalid DNS checkpoint data")
	}
	if _, _, err := checkpoint.State.Advance(nil); err != nil {
		return nil, err
	}
	anchor, err := hex.DecodeString(checkpoint.Anchor)
	if (checkpoint.Anchor == "" && (!checkpoint.State.Watermark.IsZero() || len(checkpoint.Pending) != 0)) ||
		(checkpoint.Anchor != "" && (err != nil || len(anchor) != sha256.Size || checkpoint.State.NextOffset == 0 ||
			checkpoint.State.Watermark.IsZero())) {
		return nil, fmt.Errorf("invalid DNS checkpoint anchor")
	}
	return &checkpoint, nil
}

// The cursor and pending findings are replaced together. Syncing the file and
// its directory makes the rename durable on the supported Linux local volume.
func (s *dnsCheckpointStore) save(checkpoint dnsCheckpoint) error {
	data, err := json.Marshal(checkpoint)
	if err != nil {
		return err
	}
	sum := sha256.Sum256(data)
	body, err := json.Marshal(struct {
		Digest string          `json:"sha256"`
		Data   json.RawMessage `json:"checkpoint"`
	}{hex.EncodeToString(sum[:]), data})
	if err != nil {
		return err
	}
	if len(body) > maxDNSCheckpointSize {
		return fmt.Errorf("DNS checkpoint exceeds size limit")
	}
	dirPath := filepath.Dir(s.path)
	f, err := os.CreateTemp(dirPath, ".dns-window-*")
	if err != nil {
		return err
	}
	defer os.Remove(f.Name())
	if _, err = f.Write(body); err == nil {
		err = f.Sync()
	}
	closeErr := f.Close()
	if err != nil {
		return err
	}
	if closeErr != nil {
		return closeErr
	}
	if err := os.Rename(f.Name(), s.path); err != nil {
		return err
	}
	dir, err := os.Open(dirPath)
	if err != nil {
		return err
	}
	defer dir.Close()
	return dir.Sync()
}
