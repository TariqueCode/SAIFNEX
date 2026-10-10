package dnsruntime

import (
	"context"
	"encoding/json"
	"net"
	"sync/atomic"
	"testing"
	"time"

	"github.com/TariqueCode/SAIFNEX/node/internal/dnsfilter"
	"github.com/miekg/dns"
)

func testSnapshot(t *testing.T, rules []dnsfilter.Rule) []byte {
	t.Helper()
	if rules == nil {
		rules = []dnsfilter.Rule{}
	}
	value := struct {
		SchemaVersion int `json:"schema_version"`
		Devices map[string]struct {
			Rules []dnsfilter.Rule `json:"rules"`
		} `json:"devices"`
	}{
		SchemaVersion: 1,
		Devices: map[string]struct {
			Rules []dnsfilter.Rule `json:"rules"`
		}{"device-1": {Rules: rules}},
	}
	data, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	return data
}

func startDNSServer(t *testing.T, handler dns.Handler) string {
	t.Helper()
	pc, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	server := &dns.Server{PacketConn: pc, Handler: handler}
	go func() { _ = server.ActivateAndServe() }()
	t.Cleanup(func() { _ = server.Shutdown() })
	return pc.LocalAddr().String()
}

func startDNSTCPServer(t *testing.T, handler dns.Handler) string {
	t.Helper()
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	server := &dns.Server{Listener: listener, Handler: handler}
	go func() { _ = server.ActivateAndServe() }()
	t.Cleanup(func() { _ = server.Shutdown() })
	return listener.Addr().String()
}

func queryTCP(t *testing.T, address, name string) *dns.Msg {
	t.Helper()
	msg := new(dns.Msg)
	msg.SetQuestion(dns.Fqdn(name), dns.TypeA)
	client := &dns.Client{Net: "tcp", Timeout: time.Second}
	response, _, err := client.Exchange(msg, address)
	if err != nil {
		t.Fatal(err)
	}
	return response
}

func query(t *testing.T, address, name string) *dns.Msg {
	t.Helper()
	msg := new(dns.Msg)
	msg.SetQuestion(dns.Fqdn(name), dns.TypeA)
	client := &dns.Client{Net: "udp", Timeout: time.Second}
	response, _, err := client.Exchange(msg, address)
	if err != nil {
		t.Fatal(err)
	}
	return response
}

func TestHandlerBlocksMatchingDomainWithoutForwarding(t *testing.T) {
	var forwarded atomic.Int32
	upstream := startDNSServer(t, dns.HandlerFunc(func(w dns.ResponseWriter, req *dns.Msg) {
		forwarded.Add(1)
		reply := new(dns.Msg)
		reply.SetReply(req)
		_ = w.WriteMsg(reply)
	}))
	snapshot := testSnapshot(t, []dnsfilter.Rule{{
		ID: 1, TargetType: "DOMAIN_SUFFIX", Target: "blocked.example",
		Priority: 10, Action: "BLOCK", Enabled: true,
	}})
	handler, err := NewHandler(Config{
		DeviceID: "device-1", Upstream: upstream, DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) { return snapshot, nil },
	})
	if err != nil {
		t.Fatal(err)
	}
	listener := startDNSServer(t, handler)
	response := query(t, listener, "ads.blocked.example")
	if response.Rcode != dns.RcodeNameError {
		t.Fatalf("rcode = %d, want NXDOMAIN", response.Rcode)
	}
	if got := forwarded.Load(); got != 0 {
		t.Fatalf("upstream received %d requests for blocked domain, want 0", got)
	}
}

func TestHandlerForwardsAllowedDomain(t *testing.T) {
	var forwarded atomic.Int32
	upstream := startDNSServer(t, dns.HandlerFunc(func(w dns.ResponseWriter, req *dns.Msg) {
		forwarded.Add(1)
		reply := new(dns.Msg)
		reply.SetReply(req)
		reply.Answer = []dns.RR{&dns.A{
			Hdr: dns.RR_Header{Name: req.Question[0].Name, Rrtype: dns.TypeA, Class: dns.ClassINET, Ttl: 60},
			A: net.ParseIP("192.0.2.42").To4(),
		}}
		_ = w.WriteMsg(reply)
	}))
	snapshot := testSnapshot(t, nil)
	handler, err := NewHandler(Config{
		DeviceID: "device-1", Upstream: upstream, DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) { return snapshot, nil },
	})
	if err != nil {
		t.Fatal(err)
	}
	listener := startDNSServer(t, handler)
	response := query(t, listener, "allowed.example")
	if response.Rcode != dns.RcodeSuccess || len(response.Answer) != 1 {
		t.Fatalf("unexpected forwarded response: rcode=%d answers=%d", response.Rcode, len(response.Answer))
	}
	if got := forwarded.Load(); got != 1 {
		t.Fatalf("upstream received %d requests, want 1", got)
	}
}

func TestHandlerBlocksMatchingDomainOverTCPWithoutForwarding(t *testing.T) {
	var forwarded atomic.Int32
	upstream := startDNSServer(t, dns.HandlerFunc(func(w dns.ResponseWriter, req *dns.Msg) {
		forwarded.Add(1)
		reply := new(dns.Msg)
		reply.SetReply(req)
		_ = w.WriteMsg(reply)
	}))
	snapshot := testSnapshot(t, []dnsfilter.Rule{{
		ID: 2, TargetType: "DOMAIN_SUFFIX", Target: "blocked.example",
		Priority: 1, Action: "BLOCK", Enabled: true,
	}})
	handler, err := NewHandler(Config{
		DeviceID: "device-1", Upstream: upstream, DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) { return snapshot, nil },
	})
	if err != nil {
		t.Fatal(err)
	}
	listener := startDNSTCPServer(t, handler)
	response := queryTCP(t, listener, "ads.blocked.example")
	if response.Rcode != dns.RcodeNameError {
		t.Fatalf("TCP rcode = %d, want NXDOMAIN", response.Rcode)
	}
	if got := forwarded.Load(); got != 0 {
		t.Fatalf("upstream received %d TCP-originated blocked requests, want 0", got)
	}
}

func TestHandlerServesDNSOverTCP(t *testing.T) {
	upstream := startDNSServer(t, dns.HandlerFunc(func(w dns.ResponseWriter, req *dns.Msg) {
		reply := new(dns.Msg)
		reply.SetReply(req)
		reply.Answer = []dns.RR{&dns.A{
			Hdr: dns.RR_Header{Name: req.Question[0].Name, Rrtype: dns.TypeA, Class: dns.ClassINET, Ttl: 60},
			A: net.ParseIP("192.0.2.43").To4(),
		}}
		_ = w.WriteMsg(reply)
	}))
	snapshot := testSnapshot(t, nil)
	handler, err := NewHandler(Config{
		DeviceID: "device-1", Upstream: upstream, DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) { return snapshot, nil },
	})
	if err != nil {
		t.Fatal(err)
	}
	listener := startDNSTCPServer(t, handler)
	response := queryTCP(t, listener, "tcp.example")
	if response.Rcode != dns.RcodeSuccess || len(response.Answer) != 1 {
		t.Fatalf("unexpected TCP DNS response: rcode=%d answers=%d", response.Rcode, len(response.Answer))
	}
	if got := response.Answer[0].(*dns.A).A.String(); got != "192.0.2.43" {
		t.Fatalf("answer = %s, want 192.0.2.43", got)
	}
}



func TestMatchesQuestionRejectsMismatchedDNSMetadata(t *testing.T) {
	request := new(dns.Msg)
	request.SetQuestion("example.com.", dns.TypeA)
	request.Question[0].Qclass = dns.ClassINET

	valid := new(dns.Msg)
	valid.SetReply(request)

	tests := []struct {
		name   string
		mutate func(*dns.Msg)
	}{
		{"transaction id", func(m *dns.Msg) { m.Id++ }},
		{"not a response", func(m *dns.Msg) { m.Response = false }},
		{"question name", func(m *dns.Msg) { m.Question[0].Name = "other.example." }},
		{"question type", func(m *dns.Msg) { m.Question[0].Qtype = dns.TypeAAAA }},
		{"question class", func(m *dns.Msg) { m.Question[0].Qclass = dns.ClassCHAOS }},
		{"multiple questions", func(m *dns.Msg) { m.Question = append(m.Question, m.Question[0]) }},
		{"missing question", func(m *dns.Msg) { m.Question = nil }},
	}

	if !matchesQuestion(valid, request) {
		t.Fatal("matching response should be accepted")
	}
	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			response := valid.Copy()
			tc.mutate(response)
			if matchesQuestion(response, request) {
				t.Fatal("mismatched response should be rejected")
			}
		})
	}

	if matchesQuestion(nil, request) || matchesQuestion(valid, nil) {
		t.Fatal("nil messages must be rejected")
	}
}

func TestHandlerRejectsUpstreamResponseForDifferentQuestion(t *testing.T) {
	upstream := startDNSServer(t, dns.HandlerFunc(func(w dns.ResponseWriter, req *dns.Msg) {
		reply := new(dns.Msg)
		reply.SetReply(req)
		reply.Question[0].Name = "different.example."
		_ = w.WriteMsg(reply)
	}))
	snapshot := testSnapshot(t, nil)
	handler, err := NewHandler(Config{
		DeviceID: "device-1", Upstream: upstream, DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) { return snapshot, nil },
	})
	if err != nil {
		t.Fatal(err)
	}

	listener := startDNSServer(t, handler)
	response := query(t, listener, "requested.example")
	if response.Rcode != dns.RcodeServerFailure {
		t.Fatalf("rcode = %d, want SERVFAIL for mismatched upstream question", response.Rcode)
	}
}

func TestHandlerFailsClosedWhenSnapshotUnavailable(t *testing.T) {
	upstream := startDNSServer(t, dns.HandlerFunc(func(w dns.ResponseWriter, req *dns.Msg) {
		t.Error("upstream must not be called when policy is unavailable")
	}))
	handler, err := NewHandler(Config{
		DeviceID: "device-1", Upstream: upstream, DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) {
			return nil, context.DeadlineExceeded
		},
	})
	if err != nil {
		t.Fatal(err)
	}
	listener := startDNSServer(t, handler)
	response := query(t, listener, "example.com")
	if response.Rcode != dns.RcodeServerFailure {
		t.Fatalf("rcode = %d, want SERVFAIL", response.Rcode)
	}
}

func TestHandlerFailsClosedWhenDeviceHasNoPolicyEntry(t *testing.T) {
	upstream := startDNSServer(t, dns.HandlerFunc(func(w dns.ResponseWriter, req *dns.Msg) {
		t.Error("upstream must not be called when the device has no policy entry")
	}))
	snapshot := testSnapshot(t, nil) // Contains device-1 only.
	handler, err := NewHandler(Config{
		DeviceID: "device-2", Upstream: upstream, DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) { return snapshot, nil },
	})
	if err != nil {
		t.Fatal(err)
	}
	listener := startDNSServer(t, handler)
	response := query(t, listener, "example.com")
	if response.Rcode != dns.RcodeServerFailure {
		t.Fatalf("rcode = %d, want SERVFAIL for missing device policy", response.Rcode)
	}
}

func TestNewHandlerRejectsInvalidConfiguration(t *testing.T) {
	base := Config{
		DeviceID: "device-1", Upstream: "127.0.0.1:53", DefaultAction: dnsfilter.Allow,
		Timeout: time.Second, Snapshot: func(context.Context) ([]byte, error) { return []byte("{}"), nil },
	}
	cases := []struct {
		name string
		change func(*Config)
	}{
		{"missing device", func(c *Config) { c.DeviceID = "" }},
		{"invalid upstream", func(c *Config) { c.Upstream = "not-an-address" }},
		{"invalid default", func(c *Config) { c.DefaultAction = "UNKNOWN" }},
		{"non-positive timeout", func(c *Config) { c.Timeout = 0 }},
		{"missing snapshot provider", func(c *Config) { c.Snapshot = nil }},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			cfg := base
			tc.change(&cfg)
			if _, err := NewHandler(cfg); err == nil {
				t.Fatal("expected configuration validation error")
			}
		})
	}
}
