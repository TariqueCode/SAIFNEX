# SAIFNEX

**SAIFNEX — Network Intelligence & Control Platform**

SAIFNEX is a Laravel-based web control plane for managing networks, policy profiles/rules, node enrollment, configuration lifecycle, and deployment requests. The goal is to make advanced network controls manageable through a simple, secure workspace.

## Project status

The repository contains the Laravel web application and a Go node-agent milestone for authenticated heartbeat, signed configuration verification, atomic snapshot persistence, and deployment acknowledgements. The Go agent is **not a DNS resolver or traffic enforcement engine**; real network filtering must not be represented as operational until that data plane is implemented and tested.

Production installation is not considered complete until the release checks, hosting compatibility checks, security review, and post-deployment smoke tests are satisfied.

## Main capabilities in the repository

- Session authentication and authenticated workspace.
- Network records and owner-scoped workspace operations.
- Policy profiles and draft rule management.
- Node enrollment and hashed bearer-token storage.
- Configuration generation, validation, staging, and signed publishing workflow.
- Deployment request tracking and node acknowledgement APIs.
- Automated Laravel tests and a Go node-runtime CI workflow.

Feature availability should be judged from the current code and tests, not from mock/illustrative UI alone.

## Technology

- Laravel 13 / PHP 8.3+
- MySQL/MariaDB for cPanel production; SQLite for CI tests
- Blade, Vite, Tailwind CSS
- Go 1.23+ for the separate node-agent component

## Development

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan test
```

Go agent tests/build:

```sh
cd node
go test ./...
go build ./cmd/saifnex-node
```

## cPanel deployment

Read the [cPanel production deployment guide](docs/cpanel-installation.md) before configuring hosting. In particular, the domain document root must point to the Laravel `public/` directory, production debug must be disabled, and the correct PHP CLI extensions must be available.

Configuration signing additionally requires Sodium and a securely provisioned Ed25519 key pair; do not disable verification if the host is missing Sodium.

## Security principles

- Never commit `.env`, production credentials, bearer tokens, or private signing keys.
- Do not expose the Laravel project root as the public document root.
- Keep node credentials hashed at rest and avoid logging secrets.
- Verify signed configuration before activation and retain the previous known-good state on failure.
- AI functionality, if added, must remain optional and must not become a security authority.

## Repository

https://github.com/TariqueCode/SAIFNEX
