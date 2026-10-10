package agent

import (
	"encoding/base64"
	"os"
	"path/filepath"
	"testing"
)

func writeSettingsFixture(t *testing.T, poll, timeout string) string {
	t.Helper()
	dir := t.TempDir()
	key := base64.StdEncoding.EncodeToString(make([]byte, 32))
	body := `{
		"control_plane_url":"https://saifnex.example.com",
		"node_id":"node-1",
		"network_id":"42",
		"bearer_token":"test-token",
		"ed25519_public_key":"` + key + `",
		"state_dir":"` + filepath.ToSlash(filepath.Join(dir, "state")) + `",
		"poll_interval":"` + poll + `",
		"http_timeout":"` + timeout + `"
	}`
	path := filepath.Join(dir, "settings.json")
	if err := os.WriteFile(path, []byte(body), 0600); err != nil {
		t.Fatal(err)
	}
	return path
}

func TestLoadSettingsRejectsNonPositivePollInterval(t *testing.T) {
	for _, value := range []string{"0s", "-1s"} {
		t.Run(value, func(t *testing.T) {
			if _, err := LoadSettings(writeSettingsFixture(t, value, "10s")); err == nil {
				t.Fatal("expected non-positive poll interval to be rejected")
			}
		})
	}
}

func TestLoadSettingsRejectsNonPositiveHTTPTimeout(t *testing.T) {
	for _, value := range []string{"0s", "-1s"} {
		t.Run(value, func(t *testing.T) {
			if _, err := LoadSettings(writeSettingsFixture(t, "30s", value)); err == nil {
				t.Fatal("expected non-positive HTTP timeout to be rejected")
			}
		})
	}
}

func TestLoadSettingsAcceptsPositiveIntervals(t *testing.T) {
	settings, err := LoadSettings(writeSettingsFixture(t, "30s", "10s"))
	if err != nil {
		t.Fatalf("expected valid settings, got %v", err)
	}
	if settings.PollInterval != "30s" || settings.HTTPTimeout != "10s" {
		t.Fatalf("unexpected parsed durations: poll=%q timeout=%q", settings.PollInterval, settings.HTTPTimeout)
	}
}

func TestLoadSettingsRequiresExplicitDNSDeviceWhenEnabled(t *testing.T) {
	path := writeSettingsFixture(t, "30s", "10s")
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	body := string(data)
	body = body[:len(body)-1] + `, "dns_enabled":true, "dns_listen_address":"127.0.0.1:5353", "dns_upstream":"1.1.1.1:53", "dns_default_action":"ALLOW"}`
	if err := os.WriteFile(path, []byte(body), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := LoadSettings(path); err == nil {
		t.Fatal("expected enabled DNS to require explicit dns_device_id")
	}
}

func TestLoadSettingsAcceptsExplicitDNSConfiguration(t *testing.T) {
	path := writeSettingsFixture(t, "30s", "10s")
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	body := string(data)
	body = body[:len(body)-1] + `, "dns_enabled":true, "dns_listen_address":"127.0.0.1:5353", "dns_upstream":"1.1.1.1:53", "dns_device_id":"device-1", "dns_default_action":"BLOCK"}`
	if err := os.WriteFile(path, []byte(body), 0600); err != nil {
		t.Fatal(err)
	}
	settings, err := LoadSettings(path)
	if err != nil {
		t.Fatalf("expected valid DNS settings, got %v", err)
	}
	if settings.DNSTimeout != "5s" {
		t.Fatalf("default DNS timeout = %q, want 5s", settings.DNSTimeout)
	}
}
