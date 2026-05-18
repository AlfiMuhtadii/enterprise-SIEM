package config

import "os"

type Config struct {
	IngestionURL string
	RedpandaURL  string
}

func Load() Config {
	return Config{
		IngestionURL: getEnv("INGESTION_URL", "http://localhost:8080/ingest"),
		RedpandaURL:  getEnv("REDPANDA_URL", "localhost:9092"),
	}
}

func getEnv(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}
	return fallback
}
