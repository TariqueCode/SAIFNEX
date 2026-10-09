# SAIFNEX configuration signing

Published configuration snapshots are signed with Ed25519. The private key is used only by the Laravel control plane; data-plane nodes must verify the signature using the corresponding public key before activation.

## Generate a key pair

Run this once in a trusted shell using the PHP CLI binary. Do not paste the secret key into chat, issue trackers, logs, or source control.

```bash
/opt/cpanel/ea-php84/root/usr/bin/php -r '$kp=sodium_crypto_sign_keypair(); echo "SAIFNEX_CONFIG_SIGNING_SECRET_KEY=".base64_encode(sodium_crypto_sign_secretkey($kp)).PHP_EOL; echo "SAIFNEX_CONFIG_SIGNING_PUBLIC_KEY=".base64_encode(sodium_crypto_sign_publickey($kp)).PHP_EOL;'
```

Store both values in the production environment or the hosting provider's secret manager:

- `SAIFNEX_CONFIG_SIGNING_SECRET_KEY`: private, 64-byte Ed25519 secret key encoded as Base64. Laravel needs this to publish a configuration.
- `SAIFNEX_CONFIG_SIGNING_PUBLIC_KEY`: public, 32-byte Ed25519 public key encoded as Base64. Provision this to trusted nodes out-of-band.

Keep the secret key backed up securely. If it is lost, published snapshots remain verifiable only if the public key is retained, but new snapshots cannot be signed. If the key is compromised, rotate it using a planned key-versioning procedure before accepting new node deployments.

After setting environment values, clear Laravel's cached configuration:

```bash
/opt/cpanel/ea-php84/root/usr/bin/php artisan config:clear
/opt/cpanel/ea-php84/root/usr/bin/php artisan config:cache
```

Never enable configuration publishing until the signing secret is configured. The API returns the signature and SHA-256 hash with each configuration; the node must independently canonicalize the snapshot, verify the hash, verify the Ed25519 signature, check the network/schema/capabilities, and only then atomically activate it. Retain the last-known-good configuration if any check fails.

## Node runtime API

All endpoints require HTTPS and the per-node Bearer token returned exactly once when a node is registered. Only the SHA-256 token hash is stored by the control plane.

- `POST /api/v1/internal/v1/nodes/{node}/heartbeat`
- `GET /api/v1/internal/v1/nodes/{node}/configuration`
- `POST /api/v1/internal/v1/nodes/{node}/deployments/{deployment}/ack`

The node must store its token in a protected secret store and must never include it in logs. Revoke a node by setting its status to `REVOKED`; token rotation and automated expiry are follow-up hardening work.
