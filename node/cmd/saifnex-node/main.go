package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"log"
	"net"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/TariqueCode/SAIFNEX/node/internal/agent"
	"github.com/TariqueCode/SAIFNEX/node/internal/dnsfilter"
	"github.com/TariqueCode/SAIFNEX/node/internal/dnsruntime"
	"github.com/miekg/dns"
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

	var udpServer, tcpServer *dns.Server
	if cfg.DNSEnabled {
		action := dnsfilter.Action(cfg.DNSDefaultAction)
		handler, err := dnsruntime.NewHandler(dnsruntime.Config{
			DeviceID: cfg.DNSDeviceID,
			Upstream: cfg.DNSUpstream,
			DefaultAction: action,
			Timeout: mustDuration(cfg.DNSTimeout),
			Snapshot: func(context.Context) ([]byte, error) {
				return agent.LoadActiveSnapshot(cfg.StateDir, cfg)
			},
		})
		if err != nil {
			log.Fatalf("initialize DNS policy handler: %v", err)
		}

		packetConn, err := net.ListenPacket("udp", cfg.DNSListenAddress)
		if err != nil {
			log.Fatalf("listen for DNS over UDP: %v", err)
		}
		tcpListener, err := net.Listen("tcp", cfg.DNSListenAddress)
		if err != nil {
			_ = packetConn.Close()
			log.Fatalf("listen for DNS over TCP: %v", err)
		}
		udpServer = &dns.Server{PacketConn: packetConn, Handler: handler}
		tcpServer = &dns.Server{Listener: tcpListener, Handler: handler}
		go serveDNS(ctx, "UDP", udpServer)
		go serveDNS(ctx, "TCP", tcpServer)
		log.Printf("DNS policy listener enabled on %s (UDP/TCP); device=%s upstream=%s", cfg.DNSListenAddress, cfg.DNSDeviceID, cfg.DNSUpstream)
	}

	client, err := agent.NewClient(cfg)
	if err != nil {
		log.Fatalf("initialize runtime: %v", err)
	}
	if err := client.Run(ctx); err != nil && !errors.Is(err, context.Canceled) {
		log.Fatalf("runtime stopped: %v", err)
	}
	if udpServer != nil {
		_ = udpServer.Shutdown()
	}
	if tcpServer != nil {
		_ = tcpServer.Shutdown()
	}
}

func serveDNS(ctx context.Context, protocol string, server *dns.Server) {
	if err := server.ActivateAndServe(); err != nil && ctx.Err() == nil {
		log.Printf("DNS %s server stopped: %v", protocol, err)
	}
}

func mustDuration(value string) time.Duration {
	duration, err := time.ParseDuration(value)
	if err != nil {
		log.Fatalf("invalid DNS timeout: %v", err)
	}
	return duration
}
