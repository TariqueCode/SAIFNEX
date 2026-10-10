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
