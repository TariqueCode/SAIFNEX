# SAIFNEX Node Runtime (bootstrap)

This Go module is the first portable node agent for the SAIFNEX control plane. It authenticates to the existing internal node API, sends heartbeats, fetches assigned configuration deployments, validates the canonical snapshot SHA-256 and Ed25519 signature, atomically writes the verified snapshot, and acknowledges ACTIVE only after persistence succeeds.

## Current boundary

**The node process is not yet a production DNS resolver or traffic enforcement engine.** The repository now has a tested DNS policy forwarding handler as a separate component, but it is not yet wired into the node process or connected to a live active-snapshot reload path. Do not route production traffic through it until integration, recovery, load, security, and operational tests pass.

## Build and test

Requires Go 1.23 or newer.

```sh
cd node
go test ./...
go test -race ./...
go vet ./...
go build -o saifnex-node ./cmd/saifnex-node
```

## Configure

Copy `saifnex-node.example.json` to a local file outside version control. Replace every placeholder using the node registration response and the trusted Ed25519 public key provisioned out-of-band. Never commit a real bearer token or private signing key.

```sh
./saifnex-node -config /etc/saifnex/node.json
```

The control-plane URL must be HTTPS. Poll intervals and HTTP timeouts must be valid positive durations; zero or negative values are rejected during settings validation. The runtime does not disable TLS verification. Protect the settings file (mode 0600) and state directory (mode 0700); run under a dedicated low-privilege service account. The bearer token is never written to logs.

## DNS policy evaluator and forwarding handler

`internal/dnsfilter` contains a deterministic domain-rule evaluator and `EvaluateSnapshot`, which reads per-device rules from a configuration snapshot after the caller has verified the snapshot signature/hash. It accepts only snapshot `schema_version: 1`, normalizes ASCII DNS names, supports explicit `DOMAIN_EXACT` and `DOMAIN_SUFFIX` matches, sorts by ascending priority then rule ID, skips disabled rules, rejects unsupported enabled rule types/actions, and requires an explicit `ALLOW` or `BLOCK` default action. Missing or unknown schema versions are rejected instead of being guessed.

`internal/dnsruntime` now provides a DNS handler that evaluates one explicitly configured device, returns NXDOMAIN for blocked names, forwards allowed queries to a configured upstream over UDP, returns SERVFAIL when the policy snapshot is unavailable or invalid, and validates required runtime settings. Tests cover block-without-forwarding, successful upstream forwarding, fail-closed behavior, and invalid handler configuration.

**Integration limitations:** the handler is not yet started by `cmd/saifnex-node`; it needs a secure configuration surface, an active verified-snapshot provider/reloader, and an explicit device ID mapping (node ID is not assumed to equal device ID). The current handler uses a configured default action and does not yet consume schedule state, support encrypted upstream transports, or provide per-client device identification. These must be resolved and tested before production filtering. Do not expose an unrestricted public recursive resolver.

## Activation behavior

- Verifies the SHA-256 hash of Laravel-compatible canonical JSON.
- Verifies Ed25519 signatures against the configured public key.
- Requires matching snapshot/envelope schema versions and validates the network binding against the enrolled network; missing or conflicting network identity is rejected.
- Writes `active-config.json` using a temporary file, fsync, and atomic rename.
- Reports FAILED when verification or persistence fails; reports ACTIVE only after durable file activation.
- Retains the previous active file if a new activation fails.

## API compatibility

```
POST /api/internal/v1/nodes/{node}/heartbeat
GET  /api/internal/v1/nodes/{node}/configuration
POST /api/internal/v1/nodes/{node}/deployments/{deployment}/ack
```

This is an early runtime milestone. Integration, node-side rollback/recovery, and security/operational validation are still required before the platform is considered install-ready.
