package agent

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

const runtimeVersion = "0.1.0"

type Client struct {
	settings Settings
	http     *http.Client
	poll     time.Duration
}

func NewClient(s Settings) (*Client, error) {
	timeout, err := time.ParseDuration(s.HTTPTimeout)
	if err != nil { return nil, err }
	poll, err := time.ParseDuration(s.PollInterval)
	if err != nil { return nil, err }
	if err := os.MkdirAll(s.StateDir, 0700); err != nil {
		return nil, fmt.Errorf("create state directory: %w", err)
	}
	return &Client{settings: s, http: &http.Client{Timeout: timeout}, poll: poll}, nil
}

func (c *Client) Run(ctx context.Context) error {
	ticker := time.NewTicker(c.poll)
	defer ticker.Stop()
	for {
		if err := c.cycle(ctx); err != nil && ctx.Err() == nil {
			// Avoid logging URLs, tokens, response bodies, or configuration data.
			fmt.Fprintf(os.Stderr, "SAIFNEX node cycle failed: %v\n", err)
		}
		select {
		case <-ctx.Done(): return ctx.Err()
		case <-ticker.C:
		}
	}
}

func (c *Client) cycle(ctx context.Context) error {
	version := c.activeVersion()
	hb := heartbeatPayload{
		Version: runtimeVersion,
		ConfigVersion: version,
		Capabilities: map[string]any{"signed_config_v1": true, "atomic_state_file": true},
	}
	if err := c.requestJSON(ctx, http.MethodPost, c.endpoint("/heartbeat"), hb, nil); err != nil {
		return fmt.Errorf("heartbeat: %w", err)
	}

	var response envelope[*configuration]
	if err := c.requestJSON(ctx, http.MethodGet, c.endpoint("/configuration"), nil, &response); err != nil {
		return fmt.Errorf("fetch configuration: %w", err)
	}
	if !response.Success {
		if response.Error != nil { return fmt.Errorf("control plane error %s", response.Error.Code) }
		return fmt.Errorf("control plane rejected configuration request")
	}
	if response.Data == nil { return nil }
	cfg := *response.Data
	canonical, err := verifyConfiguration(cfg, c.settings)
	if err != nil {
		_ = c.sendAck(ctx, cfg, "FAILED", err.Error())
		return fmt.Errorf("verify deployment %d: %w", cfg.DeploymentID, err)
	}
	if err := c.activate(cfg, canonical); err != nil {
		_ = c.sendAck(ctx, cfg, "FAILED", err.Error())
		return fmt.Errorf("activate deployment %d: %w", cfg.DeploymentID, err)
	}
	if err := c.sendAck(ctx, cfg, "ACTIVE", ""); err != nil {
		return fmt.Errorf("acknowledge deployment %d: %w", cfg.DeploymentID, err)
	}
	return nil
}

func (c *Client) endpoint(suffix string) string {
	return c.settings.ControlPlaneURL + "/api/internal/v1/nodes/" + c.settings.NodeID + suffix
}

func (c *Client) requestJSON(ctx context.Context, method, url string, payload any, out any) error {
	var body io.Reader
	if payload != nil {
		b, err := json.Marshal(payload)
		if err != nil { return err }
		body = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, url, body)
	if err != nil { return err }
	req.Header.Set("Authorization", "Bearer "+c.settings.BearerToken)
	req.Header.Set("Accept", "application/json")
	if payload != nil { req.Header.Set("Content-Type", "application/json") }
	resp, err := c.http.Do(req)
	if err != nil { return err }
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
		return fmt.Errorf("HTTP status %d", resp.StatusCode)
	}
	if out != nil {
		dec := json.NewDecoder(io.LimitReader(resp.Body, 4<<20))
		if err := dec.Decode(out); err != nil { return fmt.Errorf("decode response: %w", err) }
	}
	return nil
}

func (c *Client) sendAck(ctx context.Context, cfg configuration, status, message string) error {
	ack := ackPayload{Status: status, ConfigVersion: cfg.Version, SnapshotHash: cfg.SnapshotHash, ErrorMessage: truncate(message, 1900)}
	return c.requestJSON(ctx, http.MethodPost,
		c.endpoint("/deployments/"+strconv.FormatInt(cfg.DeploymentID, 10)+"/ack"), ack, nil)
}

func (c *Client) activate(cfg configuration, canonical []byte) error {
	// This agent safely persists a verified snapshot. It deliberately does not
	// claim to enforce DNS/network policy; the platform-specific data plane is a
	// separate component and must consume this state before production filtering.
	if strings.TrimSpace(string(canonical)) == "" { return fmt.Errorf("empty configuration snapshot") }
	if err := os.MkdirAll(c.settings.StateDir, 0700); err != nil { return err }
	active := map[string]any{
		"deployment_id": cfg.DeploymentID,
		"configuration_id": cfg.ConfigurationID,
		"version": cfg.Version,
		"schema_version": cfg.SchemaVersion,
		"snapshot_hash": cfg.SnapshotHash,
		"signature": cfg.Signature,
		"signature_algorithm": cfg.SignatureAlgorithm,
		"snapshot": json.RawMessage(canonical),
		"activated_at": time.Now().UTC().Format(time.RFC3339),
	}
	b, err := json.MarshalIndent(active, "", "  ")
	if err != nil { return err }
	tmp, err := os.CreateTemp(c.settings.StateDir, ".active-config-*")
	if err != nil { return err }
	tmpName := tmp.Name()
	defer os.Remove(tmpName)
	if err := tmp.Chmod(0600); err != nil { tmp.Close(); return err }
	if _, err := tmp.Write(append(b, '\n')); err != nil { tmp.Close(); return err }
	if err := tmp.Sync(); err != nil { tmp.Close(); return err }
	if err := tmp.Close(); err != nil { return err }
	if err := os.Rename(tmpName, filepath.Join(c.settings.StateDir, "active-config.json")); err != nil { return err }
	// Persist the parent directory entry where supported.
	if dir, err := os.Open(c.settings.StateDir); err == nil { _ = dir.Sync(); _ = dir.Close() }
	return nil
}

func (c *Client) activeVersion() int {
	b, err := os.ReadFile(filepath.Join(c.settings.StateDir, "active-config.json"))
	if err != nil {
		return 0
	}
	var current configuration
	if err := json.Unmarshal(b, &current); err != nil {
		return 0
	}
	if _, err := verifyConfiguration(current, c.settings); err != nil {
		return 0
	}
	return current.Version
}

func truncate(s string, max int) string {
	if len(s) > max { return s[:max] }
	return s
}

// HashText is intentionally exposed for diagnostics/tests without exposing secrets.
func HashText(value string) string {
	sum := sha256.Sum256([]byte(value))
	return hex.EncodeToString(sum[:])
}
