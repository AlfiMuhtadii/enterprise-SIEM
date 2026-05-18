package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"time"

	"github.com/alfimuhtadii/detector/attack-simulator/internal/engine"
	"github.com/alfimuhtadii/detector/attack-simulator/internal/producers"
)

func main() {
	var (
		scenarioFile = flag.String("scenario", "", "Path to scenario YAML file")
		scenarioName = flag.String("name", "", "Scenario name (without .yml)")
		speed        = flag.Float64("speed", 1.0, "Speed multiplier (1.0 = real-time)")
		output       = flag.String("output", "redpanda", "Output target: redpanda or stdout")
		brokers      = flag.String("brokers", getEnv("REDPANDA_BROKERS", "redpanda:9092"), "Redpanda brokers")
	)
	flag.Parse()

	if *scenarioFile == "" && *scenarioName == "" {
		fmt.Println("Usage:")
		fmt.Println("  ./simulator --scenario scenarios/brute-force-rdp.yml")
		fmt.Println("  ./simulator --name brute-force-rdp --speed 2.0")
		os.Exit(1)
	}

	var filePath string
	if *scenarioFile != "" {
		filePath = *scenarioFile
	} else {
		filePath = fmt.Sprintf("scenarios/%s.yml", *scenarioName)
	}

	scenario, err := engine.LoadScenario(filePath)
	if err != nil {
		log.Fatalf("Failed to load scenario: %v", err)
	}

	fmt.Printf("[SIMULATOR] Loaded scenario: %s\n", scenario.Name)
	fmt.Printf("[SIMULATOR] MITRE: %s - %s\n", scenario.MITRE.Tactic, scenario.MITRE.TechniqueName)
	fmt.Printf("[SIMULATOR] Timeline steps: %d\n", len(scenario.Timeline))
	fmt.Printf("[SIMULATOR] Speed: %.1fx\n", *speed)
	fmt.Println("[SIMULATOR] Starting simulation...")

	var producer producers.Producer
	if *output == "redpanda" {
		p, err := producers.NewRedpanda(*brokers)
		if err != nil {
			log.Fatalf("Failed to connect to Redpanda: %v", err)
		}
		defer p.Close()
		producer = p
		fmt.Printf("[SIMULATOR] Connected to Redpanda: %s\n", *brokers)
	} else {
		producer = producers.NewStdout()
	}

	eng := engine.New(producer, *speed)
	ctx := context.Background()

	startTime := time.Now()
	if err := eng.Run(ctx, scenario); err != nil {
		log.Fatalf("Simulation failed: %v", err)
	}
	elapsed := time.Since(startTime)

	fmt.Printf("\n[SIMULATOR] Simulation complete!\n")
	fmt.Printf("[SIMULATOR] Events produced: %d\n", eng.Stats.EventsProduced)
	fmt.Printf("[SIMULATOR] Real elapsed time: %s\n", elapsed.Round(time.Second))
	fmt.Printf("[SIMULATOR] Simulated time: %s\n", eng.Stats.SimulatedTime)
}

func getEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
