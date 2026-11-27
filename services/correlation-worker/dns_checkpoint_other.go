//go:build !linux

package main

import (
	"fmt"
	"os"
)

func lockDNSCheckpoint(_ *os.File) error {
	return fmt.Errorf("DNS event-time checkpoint requires a Linux local volume")
}
