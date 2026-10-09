package agent

import (
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"strings"
	"time"
)

type Settings struct {
	ControlPlaneURL string `json:"control_plane_url"`
	NodeID          string `json:"node_id"`
	NetworkID       string `json:"network_id"`
	BearerToken     string `json:"bearer_token"`
	PublicKey       string `json:"ed25519_public_key"`
	StateDir        string `json:"state_dir"`
	PollInterval    string `json:"poll_interval,omitempty"`
	HTTPTimeout     string `json:"http_timeout,omitempty"`
}

func LoadSettings(path string) (Settings, error) {
	var s Settings
	b, err := os.ReadFile(path)
	if err != nil {
		return s, fmt.Errorf("read settings file: %w", err)
	}
	if err := json.Unmarshal(b, &s); err != nil {
		return s, fmt.Errorf("parse settings file: %w", err)
	}
	s.ControlPlaneURL = strings.TrimRight(strings.TrimSpace(s.ControlPlaneURL), "/")
	if s.ControlPlaneURL == "" || s.NodeID == "" || s.NetworkID == "" || s.BearerToken == "" || s.PublicKey == "" || s.StateDir == "" {
		return s, errors.New("control_plane_url, node_id, network_id, bearer_token, ed25519_public_key and state_dir are required")
	}
	if !strings.HasPrefix(s.ControlPlaneURL, "https://") {
		return s, errors.New("control_plane_url must use HTTPS")
	}
	key, err := base64.StdEncoding.DecodeString(s.PublicKey)
	if err != nil || len(key) != 32 {
		return s, errors.New("ed25519_public_key must be a Base64-encoded 32-byte public key")
	}
	if s.PollInterval == "" {
		s.PollInterval = "30s"
	}
	if s.HTTPTimeout == "" {
		s.HTTPTimeout = "10s"
	}
	if _, err := time.ParseDuration(s.PollInterval); err != nil {
		return s, fmt.Errorf("invalid poll_interval: %w", err)
	}
	if _, err := time.ParseDuration(s.HTTPTimeout); err != nil {
		return s, fmt.Errorf("invalid http_timeout: %w", err)
	}
	return s, nil
}

func (s Settings) PublicSummary() map[string]any {
	return map[string]any{
		"control_plane_url": s.ControlPlaneURL,
		"node_id": s.NodeID,
		"network_id": s.NetworkID,
		"state_dir": s.StateDir,
		"poll_interval": s.PollInterval,
	}
}
