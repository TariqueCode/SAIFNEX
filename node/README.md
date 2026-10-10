# SAIFNEX Node Runtime

This Go module is the portable node agent for the SAIFNEX control plane. It authenticates to the internal node API, sends heartbeats, fetches configuration deployments, verifies canonical snapshot SHA-256 and Ed25519 signatures, atomically persists verified snapshots, and acknowledges activation after persistence succeeds.

## Build and test

Requires Go 1.23 or newer.

```sh
cd node
go mod tidy
go test ./...
go test -race ./...
go vet ./...
go build -o saifnex-node ./cmd/saifnex-node
```

## Configure the node agent

Copy `saifnex-node.example.json` to a local file outside version control. Replace the placeholders using the node registration response and trusted Ed25519 public key provisioned out-of-band. Never commit a real bearer token or private signing key.

```sh
./saifnex-node -config /etc/saifnex/node.json
```

The control-plane URL must use HTTPS. Poll intervals and HTTP timeouts must be positive durations. Protect the settings file (mode 0600) and state directory (mode 0700), and run under a dedicated low-privilege service account. The bearer token is not written to logs.

## Opt-in DNS runtime

The runtime now supports an **explicitly opt-in** DNS forwarding service. It remains disabled unless `dns_enabled` is set to `true`. When enabled, configuration must explicitly specify:

- `dns_listen_address`: bind address and port, such as `127.0.0.1:5353`
- `dns_upstream`: upstream resolver host and port, such as `1.1.1.1:53`
- `dns_device_id`: the exact device key present in the signed snapshot; it is not inferred from the node ID
- `dns_default_action`: `ALLOW` or `BLOCK`
- `dns_timeout`: positive duration (defaults to `5s` when DNS is enabled)

The handler listens on both UDP and TCP at the configured address. Blocked domain queries receive NXDOMAIN; allowed queries are forwarded over UDP to the configured upstream; missing, malformed, or invalid active policy fails closed with SERVFAIL. The local active snapshot is reloaded and its hash, Ed25519 signature, schema version, and network binding are re-verified before policy evaluation. A missing active snapshot therefore does not become an allow-all policy.

**Safety:** DNS remains disabled in the example configuration. Do not bind this service to a public interface or expose it as an unrestricted public resolver. Start with loopback in a controlled test environment. Before using a LAN-facing address, configure firewall rules and validate the intended device-to-policy mapping. This initial integration uses one configured device ID for all requests received by that listener; per-client device identification, encrypted upstream transport, caching, metrics, and production-grade operational recovery remain future work. Signed per-device snapshots now include schedule definitions and rule time bounds; the DNS evaluator enforces them at query time using the schedule timezone. A valid schedule outside its active window does not match; missing or malformed referenced schedule metadata returns an evaluation error, causing the DNS handler to return SERVFAIL rather than silently allow the query. The configured default action applies when no domain rule matches, so choose it intentionally.

## Policy evaluator

`internal/dnsfilter` evaluates signed per-device policy snapshots using deterministic ascending priority and then rule ID. It enforces `starts_at`, exclusive `expires_at` boundaries, and weekly schedules (including overnight windows) using the timezone embedded in the signed schedule definition. It supports Laravel's exact-domain `DOMAIN` rule plus the `DOMAIN_EXACT` compatibility alias and `DOMAIN_SUFFIX`. Rules default to enabled when the `enabled` field is omitted, matching the control-plane policy convention; an explicit `enabled: false` disables a rule, and a non-boolean value is rejected. Known non-domain target families (`IP`, `CIDR`, `KEYWORD`) are skipped because they are handled by other evaluators; unknown target types and unsupported actions on applicable domain rules fail closed. Only snapshot schema version 1 is supported. Missing device entries or rule arrays are errors, not empty allow policies.

## Active configuration safety

- Verifies SHA-256 and Ed25519 signature before activation.
- Requires matching envelope/snapshot schema versions and the enrolled network binding.
- Persists the signature metadata with the active snapshot so the DNS data plane can re-verify it when reading local state.
- Writes via a temporary file, fsync, and atomic rename.
- Reports FAILED on verification or persistence failure and ACTIVE only after durable persistence.

## Control-plane API

```
POST /api/internal/v1/nodes/{node}/heartbeat
GET  /api/internal/v1/nodes/{node}/configuration
POST /api/internal/v1/nodes/{node}/deployments/{deployment}/ack
```

This is an integration milestone, not yet an install-ready production release. Continue with real compiler-to-node integration tests, per-client device identity, real compiler-to-node integration tests, update/rollback recovery, load testing, and operational security review before production deployment.
