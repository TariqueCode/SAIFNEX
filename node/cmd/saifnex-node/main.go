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
	dnsErrors := make(chan error, 2)
	if cfg.DNSEnabled {
		action := dnsfilter.Action(cfg.DNSDefaultAction)
		handler, err := dnsruntime.NewHandler(dnsruntime.Config{
			DeviceID: cfg.DNSDeviceID,
			ClientDeviceMap: cfg.DNSClientDeviceMap,
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
		go serveDNS(ctx, stop, "UDP", udpServer, dnsErrors)
		go serveDNS(ctx, stop, "TCP", tcpServer, dnsErrors)
		log.Printf("DNS policy listener enabled on %s (UDP/TCP); static_device=%t client_ip_mappings=%d upstream=%s", cfg.DNSListenAddress, cfg.DNSDeviceID != "", len(cfg.DNSClientDeviceMap), cfg.DNSUpstream)
	}

	client, err := agent.NewClient(cfg)
	if err != nil {
		log.Fatalf("initialize runtime: %v", err)
	}
	runErr := client.Run(ctx)
	if runErr != nil && !errors.Is(runErr, context.Canceled) {
		log.Printf("runtime stopped: %v", runErr)
	}
	if udpServer != nil {
		_ = udpServer.Shutdown()
	}
	if tcpServer != nil {
		_ = tcpServer.Shutdown()
	}
	select {
	case dnsErr := <-dnsErrors:
		log.Printf("DNS runtime failed; node is stopping to avoid silently running without policy enforcement: %v", dnsErr)
		os.Exit(1)
	default:
	}
	if runErr != nil && !errors.Is(runErr, context.Canceled) {
		os.Exit(1)
	}
}

func serveDNS(ctx context.Context, stop context.CancelFunc, protocol string, server *dns.Server, failures chan<- error) {
	if err := server.ActivateAndServe(); err != nil && ctx.Err() == nil {
		select {
		case failures <- errors.New("DNS " + protocol + " server stopped: " + err.Error()):
		default:
		}
		stop()
	}
}

func mustDuration(value string) time.Duration {
	duration, err := time.ParseDuration(value)
	if err != nil {
		log.Fatalf("invalid DNS timeout: %v", err)
	}
	return duration
}
