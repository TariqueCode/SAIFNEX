package dnsruntime

import (
	"context"
	"errors"
	"fmt"
	"net"
	"strings"
	"time"

	"github.com/TariqueCode/SAIFNEX/node/internal/dnsfilter"
	"github.com/miekg/dns"
)

// SnapshotProvider returns the latest locally persisted, already-verified
// configuration snapshot. Implementations must never fetch unverified policy.
type SnapshotProvider func(context.Context) ([]byte, error)

// Config controls a single-device DNS forwarding handler.
type Config struct {
	DeviceID      string
	Upstream      string
	DefaultAction dnsfilter.Action
	Timeout       time.Duration
	Snapshot      SnapshotProvider
}

// Handler evaluates policy for one explicitly configured device and forwards
// allowed DNS messages to the configured upstream resolver.
type Handler struct {
	deviceID      string
	upstream      string
	defaultAction dnsfilter.Action
	timeout       time.Duration
	snapshot      SnapshotProvider
	client        *dns.Client
}

func NewHandler(cfg Config) (*Handler, error) {
	if strings.TrimSpace(cfg.DeviceID) == "" {
		return nil, errors.New("device_id is required")
	}
	if _, _, err := net.SplitHostPort(cfg.Upstream); err != nil {
		return nil, fmt.Errorf("upstream must be host:port: %w", err)
	}
	if cfg.DefaultAction != dnsfilter.Allow && cfg.DefaultAction != dnsfilter.Block {
		return nil, fmt.Errorf("unsupported default action %q", cfg.DefaultAction)
	}
	if cfg.Timeout <= 0 {
		return nil, errors.New("timeout must be greater than zero")
	}
	if cfg.Snapshot == nil {
		return nil, errors.New("snapshot provider is required")
	}
	return &Handler{
		deviceID: cfg.DeviceID,
		upstream: cfg.Upstream,
		defaultAction: cfg.DefaultAction,
		timeout: cfg.Timeout,
		snapshot: cfg.Snapshot,
		client: &dns.Client{Net: "udp", Timeout: cfg.Timeout},
	}, nil
}

// ServeDNS implements dns.Handler. A policy lookup failure fails closed with
// SERVFAIL; malformed queries receive FORMERR. Blocked names receive NXDOMAIN.
func (h *Handler) ServeDNS(w dns.ResponseWriter, req *dns.Msg) {
	if req == nil || req.Response || req.Opcode != dns.OpcodeQuery || len(req.Question) != 1 {
		reply := new(dns.Msg)
		if req != nil {
			reply.SetRcode(req, dns.RcodeFormatError)
		}
		_ = w.WriteMsg(reply)
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), h.timeout)
	defer cancel()
	snapshot, err := h.snapshot(ctx)
	if err != nil {
		h.writeError(w, req, dns.RcodeServerFailure)
		return
	}

	decision, err := dnsfilter.EvaluateSnapshot(snapshot, h.deviceID, req.Question[0].Name, h.defaultAction)
	if err != nil {
		if errors.Is(err, dnsfilter.ErrInvalidDomain) {
			h.writeError(w, req, dns.RcodeFormatError)
			return
		}
		h.writeError(w, req, dns.RcodeServerFailure)
		return
	}
	if decision.Action == dnsfilter.Block {
		reply := new(dns.Msg)
		reply.SetRcode(req, dns.RcodeNameError)
		reply.Authoritative = true
		_ = w.WriteMsg(reply)
		return
	}

	response, _, err := h.client.ExchangeContext(ctx, req, h.upstream)
	if err != nil || response == nil || !matchesQuestion(response, req) {
		h.writeError(w, req, dns.RcodeServerFailure)
		return
	}
	_ = w.WriteMsg(response)
}

// matchesQuestion prevents an upstream response for a different DNS question
// from being returned to the client. DNS names are case-insensitive.
func matchesQuestion(response, request *dns.Msg) bool {
	if response == nil || request == nil || response.Id != request.Id ||
		!response.Response || len(response.Question) != 1 || len(request.Question) != 1 {
		return false
	}

	got := response.Question[0]
	want := request.Question[0]
	return strings.EqualFold(dns.Fqdn(got.Name), dns.Fqdn(want.Name)) &&
		got.Qtype == want.Qtype &&
		got.Qclass == want.Qclass
}

func (h *Handler) writeError(w dns.ResponseWriter, req *dns.Msg, rcode int) {
	reply := new(dns.Msg)
	reply.SetRcode(req, rcode)
	_ = w.WriteMsg(reply)
}
