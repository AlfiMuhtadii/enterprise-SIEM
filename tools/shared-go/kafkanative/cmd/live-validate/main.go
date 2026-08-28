package main

import (
	"context"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"detector-xdr-shared-kafkanative"
)

type report struct {
	Status      string `json:"status"`
	Broker      string `json:"broker"`
	Topic       string `json:"topic"`
	Group       string `json:"group"`
	ValueSHA256 string `json:"value_sha256"`
	ElapsedMS   int64  `json:"elapsed_ms"`
	TLSVerified bool   `json:"tls_verified"`
}

func randomToken() (string, error) {
	raw := make([]byte, 16)
	if _, err := rand.Read(raw); err != nil {
		return "", err
	}
	return hex.EncodeToString(raw), nil
}

func main() {
	broker := flag.String("broker", "127.0.0.1:19093", "TLS Kafka broker")
	caFile := flag.String("ca", "", "trusted CA certificate")
	topic := flag.String("topic", "xdr.kafka.tls.validation", "temporary validation topic")
	output := flag.String("output", "", "optional JSON evidence path")
	timeout := flag.Duration("timeout", 20*time.Second, "validation timeout")
	flag.Parse()

	if strings.TrimSpace(*caFile) == "" {
		fmt.Fprintln(os.Stderr, "--ca is required")
		os.Exit(2)
	}
	token, err := randomToken()
	if err != nil {
		fmt.Fprintf(os.Stderr, "generate token: %v\n", err)
		os.Exit(1)
	}
	group := "xdr-kafka-tls-validation-" + token
	tlsConfig, err := kafkanative.LoadTLSConfig(true, *caFile, "", "")
	if err != nil {
		fmt.Fprintf(os.Stderr, "TLS config: %v\n", err)
		os.Exit(1)
	}

	started := time.Now()
	ctx, cancel := context.WithTimeout(context.Background(), *timeout)
	defer cancel()
	producer, err := kafkanative.NewProducerTLS([]string{*broker}, tlsConfig)
	if err != nil {
		fmt.Fprintf(os.Stderr, "producer: %v\n", err)
		os.Exit(1)
	}
	if err := producer.Publish(ctx, *topic, [][]byte{[]byte(token)}); err != nil {
		producer.Close()
		fmt.Fprintf(os.Stderr, "publish: %v\n", err)
		os.Exit(1)
	}
	producer.Close()

	consumer, err := kafkanative.NewConsumerTLS([]string{*broker}, group, []string{*topic}, tlsConfig)
	if err != nil {
		fmt.Fprintf(os.Stderr, "consumer: %v\n", err)
		os.Exit(1)
	}
	defer consumer.Close()
	found := false
	for ctx.Err() == nil && !found {
		records, pollErr := consumer.Poll(ctx)
		if pollErr != nil {
			fmt.Fprintf(os.Stderr, "poll: %v\n", pollErr)
			os.Exit(1)
		}
		for _, record := range records {
			if string(record.Value) == token {
				found = true
				break
			}
		}
	}
	if !found {
		fmt.Fprintf(os.Stderr, "validation token not consumed: %v\n", ctx.Err())
		os.Exit(1)
	}

	evidence := report{
		Status:      "PASS",
		Broker:      *broker,
		Topic:       *topic,
		Group:       group,
		ValueSHA256: fmt.Sprintf("%x", sha256.Sum256([]byte(token))),
		ElapsedMS:   time.Since(started).Milliseconds(),
		TLSVerified: true,
	}
	encoded, err := json.MarshalIndent(evidence, "", "  ")
	if err != nil {
		fmt.Fprintf(os.Stderr, "encode evidence: %v\n", err)
		os.Exit(1)
	}
	encoded = append(encoded, '\n')
	if *output != "" {
		if err := os.MkdirAll(filepath.Dir(*output), 0o755); err != nil {
			fmt.Fprintf(os.Stderr, "create evidence directory: %v\n", err)
			os.Exit(1)
		}
		if err := os.WriteFile(*output, encoded, 0o600); err != nil {
			fmt.Fprintf(os.Stderr, "write evidence: %v\n", err)
			os.Exit(1)
		}
	}
	fmt.Print(string(encoded))
}
