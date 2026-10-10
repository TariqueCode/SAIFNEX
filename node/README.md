# SAIFNEX Node Runtime (bootstrap)

This Go module is the first portable node agent for the SAIFNEX control plane. It authenticates to the existing internal node API, sends heartbeats, fetches assigned configuration deployments, validates the canonical snapshot SHA-256 and Ed25519 signature, atomically writes the verified snapshot, and acknowledges ACTIVE only after persistence succeeds.

## Current boundary

**This is not yet a production DNS resolver or traffic enforcement engine.** It proves the control-plane-to-node signed configuration path and durable activation record. Do not route production traffic through it until a DNS/data-plane adapter, policy enforcement, load/recovery tests, and operational hardening are implemented and verified.

## Build and test

Requires Go 1.23 or newer.

```sh
cd node
go test ./...
go build -o saifnex-node ./cmd/saifnex-node
```

## Configure

Copy `saifnex-node.example.json` to a local file outside version control. Replace every placeholder using the node registration response and the trusted Ed25519 public key provisioned out-of-band. Never commit a real bearer token or private signing key.

```sh
./saifnex-node -config /etc/saifnex/node.json
```

The control-plane URL must be HTTPS. The runtime does not disable TLS verification. Protect the settings file (mode 0600) and state directory (mode 0700); run under a dedicated low-privilege service account. The bearer token is never written to logs.

## DNS policy evaluator milestone

`internal/dnsfilter` now contains a deterministic domain-rule evaluator and `EvaluateSnapshot`, which reads per-device rules from a configuration snapshot after the caller has verified the snapshot signature/hash. It normalizes ASCII DNS names, supports explicit `DOMAIN_EXACT` and `DOMAIN_SUFFIX` matches, sorts by ascending priority then rule ID, skips disabled rules, rejects unsupported enabled rule types/actions, and requires an explicit `ALLOW` or `BLOCK` default action. Unit tests cover per-device isolation, deterministic ordering (including equal-priority ties), malformed snapshots, missing devices, and missing per-device rule lists. A device entry without a `rules` array is rejected rather than silently evaluated as an empty policy.

**Important:** this evaluator is not yet wired into the node runtime, does not listen on DNS ports, and does not intercept or filter live traffic. The runtime still only verifies and persists signed configuration snapshots. Before activation can enforce policies, the control-plane schema/compiler and runtime adapter must agree on target types, precedence, default behavior, schedule handling, and rollback semantics, followed by DNS protocol and security tests.

## Activation behavior

- Verifies the SHA-256 hash of Laravel-compatible canonical JSON.
- Verifies Ed25519 signatures against the configured public key.
- Checks a snapshot `network_id` when that field is present.
- Writes `active-config.json` using a temporary file, fsync, and atomic rename.
- Reports FAILED when verification or persistence fails; reports ACTIVE only after durable file activation.
- Retains the previous active file if a new activation fails.

## API compatibility

```
POST /api/internal/v1/nodes/{node}/heartbeat
GET  /api/internal/v1/nodes/{node}/configuration
POST /api/internal/v1/nodes/{node}/deployments/{deployment}/ack
```

This is an early runtime milestone. The next release must implement and test actual policy enforcement and node-side rollback/recovery before the platform is considered install-ready.
