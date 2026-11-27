package main

import (
	"fmt"
	"os"
	"syscall"
)

func lockDNSCheckpoint(f *os.File) error {
	if err := syscall.Flock(int(f.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return fmt.Errorf("cannot acquire exclusive DNS checkpoint lock: %w", err)
	}
	return nil
}
