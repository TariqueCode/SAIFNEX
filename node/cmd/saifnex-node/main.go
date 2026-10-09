package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"log"
	"os"
	"os/signal"
	"syscall"

	"github.com/TariqueCode/SAIFNEX/node/internal/agent"
)

func main() {
	configPath := flag.String("config", "saifnex-node.json", "Path to node runtime configuration")
	flag.Parse()

	cfg, err := agent.LoadSettings(*configPath)
	if err != nil {
		log.Fatalf("load settings: %v", err)
	}
	encoded, _ := json.Marshal(cfg.PublicSummary())
	log.Printf("starting SAIFNEX node runtime (%s)", encoded)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	client, err := agent.NewClient(cfg)
	if err != nil {
		log.Fatalf("initialize runtime: %v", err)
	}
	if err := client.Run(ctx); err != nil && !errors.Is(err, context.Canceled) {
		log.Fatalf("runtime stopped: %v", err)
	}
}
