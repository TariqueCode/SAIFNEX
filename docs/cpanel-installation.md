# SAIFNEX on cPanel (production deployment guide)

This guide deploys the **SAIFNEX Laravel website** to cPanel hosting. It does not install the Go node runtime or configure a DNS server. Keep deployment on GitHub until the release checklist is satisfied.

## 1. Hosting requirements

Confirm these with the hosting provider before deployment:

- PHP 8.3 or newer supported by the locked Laravel dependencies (the repository currently requires PHP `^8.3`).
- PHP extensions required by Laravel and the locked Composer packages, including PDO MySQL, OpenSSL, Mbstring, Tokenizer, XML, Ctype, JSON, BCMath, Fileinfo, and a working PHP CLI.
- Composer 2 available on the account, or a release package with production `vendor/` dependencies.
- MySQL/MariaDB database and database user.
- HTTPS certificate enabled for the domain.
- Writable `storage/` and `bootstrap/cache/` directories.
- Node.js/npm available on cPanel **or** a locally built `public/build/` directory included in the uploaded release.

The PHP CLI version used by SSH must match the domain's selected PHP version. On some cPanel servers, use the provider's versioned PHP binary rather than the generic `php` command.

## 2. Set the document root correctly

Preferred layout:

- Application code: outside the public web root, e.g. `/home/ACCOUNT/SAIFNEX`
- Domain document root: `/home/ACCOUNT/SAIFNEX/public`

Do **not** point the domain to the Laravel project root. Do not expose `.env`, `vendor/`, `storage/`, `bootstrap/`, or source files to the public web.

If the hosting plan does not allow changing the document root, stop and arrange a safe Laravel public-directory mapping with the host. Do not solve this by exposing the project root.

## 3. Prepare the database

1. In cPanel → MySQL Databases, create a database and a dedicated database user.
2. Grant that user only the permissions needed by the application.
3. Record the database name, username, and password locally. Never put credentials in GitHub or chat.
4. Take a database backup before deploying updates to an existing installation.

Do not drop existing tables to resolve migration drift. Review the migration status and repair schema non-destructively.

## 4. Get the release onto the account

Clone the repository into a directory outside the public document root, or upload a reviewed release archive. Example SSH workflow:

```sh
cd /home/ACCOUNT
git clone https://github.com/TariqueCode/SAIFNEX.git SAIFNEX
cd SAIFNEX
```

For subsequent updates, use the reviewed release commit/tag. Do not deploy an unreviewed feature branch.

Install PHP dependencies:

```sh
composer install --no-dev --prefer-dist --optimize-autoloader
```

If Composer is not globally installed, use the Composer executable supplied by your host. Never run `composer update` on production as a substitute for installing the lockfile.

Build front-end assets. If Node.js/npm is available on cPanel:

```sh
npm ci
npm run build
```

If Node.js is not available, build `public/build/` on a trusted local/CI environment using the repository's locked npm dependencies, then include that directory in the release upload. The built manifest must exist at `public/build/manifest.json`; otherwise pages using Vite assets can fail.

## 5. Create the first operator account

Self-service registration is intentionally disabled. After configuring `.env` and successfully running the migrations, create the first operator account from the server terminal in the application directory:

```sh
/opt/cpanel/ea-php84/root/usr/bin/php artisan saifnex:create-operator
```

Replace the PHP binary path with the versioned CLI binary provided by your host if it differs. The command asks for a name, email, and hidden password input, validates the email and password, and asks for confirmation before creating the account. Use a unique password of at least 12 characters with uppercase/lowercase letters and numbers. Do not put the password in the command line, GitHub, or chat. If the email already exists, the command stops instead of changing that account.

## 6. Configure production environment

Create `.env` from `.env.example` on the server. Keep it outside the document root and set values locally:

```dotenv
APP_NAME=SAIFNEX
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-DOMAIN
APP_KEY=

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=YOUR_CPANEL_DATABASE
DB_USERNAME=YOUR_CPANEL_DATABASE_USER
DB_PASSWORD=YOUR_DATABASE_PASSWORD

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
```

Use the actual database host shown by cPanel; it is not always `127.0.0.1`. Keep the existing `.env.example` as a template only. Generate the application key once on the server:

```sh
php artisan key:generate
```

Do not regenerate `APP_KEY` on every deployment; doing so invalidates encrypted data and sessions. Set `APP_DEBUG=false` before serving public traffic. Configure a real mail transport before enabling email-dependent flows.

## 7. Migrate and optimize

First verify the CLI PHP version and extensions. Then, from the application root:

```sh
php artisan about
php artisan migrate:status
php artisan migrate --force
php artisan storage:link
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Run migrations only after confirming the database target and making a backup when updating an existing installation. If any command fails, stop and resolve the error; do not continue with a partially migrated release. If route caching reports an unsupported route pattern, investigate and fix it instead of skipping all release checks.

Make sure `storage/` and `bootstrap/cache/` are writable by the account's PHP process, but do not make the entire project world-writable.

## 8. Configuration signing

Configuration publication requires the PHP Sodium extension and a valid Ed25519 key pair. This is a separate prerequisite from making the website pages load.

Check the **same PHP CLI binary used for Artisan**:

```sh
php -r 'var_dump(extension_loaded("sodium"));'
```

If it returns `bool(false)`, ask the host to enable Sodium for the CLI PHP version or use an explicitly reviewed compatible hosting/runtime solution. Do not disable signature checks or publish unsigned configurations.

Once Sodium is confirmed, generate keys in a trusted shell and store them in the server's secret environment/configuration. Never commit either key. The secret key must be kept private; provision the public key to trusted nodes out-of-band. Follow [configuration signing and node runtime](configuration-signing-and-node-runtime.md) for the exact variables and key handling.

## 9. Post-deployment smoke tests

Perform these checks using the production domain and a test account:

- HTTPS works and HTTP redirects to HTTPS.
- `/up` returns a healthy response without exposing debug details.
- Landing page, sign-in, sign-out, and authenticated workspace work.
- Creating a network and managing its policy records works with the correct owner only.
- Unauthenticated requests cannot access workspace pages.
- Static CSS/JS assets load successfully (no missing Vite manifest or 404s).
- Logs are written under `storage/logs` and contain no secrets.
- Migrations show no pending work.
- Backup and restore procedure has been tested.
- Signing remains unavailable until Sodium and keys are configured.
- The domain's document root points to `public/`, not the project root.

## 10. Release / rollback checklist

Before each update:

1. Select a reviewed commit or release tag.
2. Back up the database and current application files.
3. Install dependencies from lockfiles and build assets.
4. Run automated tests in CI.
5. Deploy files, migrate, and clear/rebuild caches.
6. Run smoke tests before declaring the release live.
7. If checks fail, put the site into maintenance mode if appropriate, restore the previous code release, and apply the documented database recovery plan. Database rollback is not always safe automatically.

## Current release caveats

- This guide documents deployment steps; it is not proof that a live cPanel deployment has been executed.
- Confirm the host's actual PHP CLI version/extensions and database details before installation.
- The website control plane does not by itself enforce DNS traffic. Do not advertise network enforcement as live until the relevant runtime integration has been implemented and tested.
