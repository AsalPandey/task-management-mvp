# Production Runbook

## Baseline Stack

- Ubuntu VPS provisioned through Laravel Forge
- PHP 8.4, MySQL, queue worker, and one scheduler cron
- Sentry through `SENTRY_LARAVEL_DSN`
- Off-site backups through `spatie/laravel-backup` to an S3-compatible private bucket

## First Boot

Run the production-safe migrations and reference-data seeders:

```bash
php artisan migrate --seed --force
```

This default seed path creates only roles and permissions. It never creates users. Create the first manager through the interactive setup command so the password is entered at a hidden prompt:

Use one of these paths on a fresh database:

```bash
php artisan app:setup-company \
  --company="Client Company" \
  --name="First Manager" \
  --email="manager@example.com" \
  --timezone="Asia/Kathmandu" \
  --app-url="https://client.example.com"
```

Or set `APP_SETUP_TOKEN` and open `/setup` before any users exist. The web installer is disabled after setup and can only be reset with:

```bash
php artisan app:reset-installer
```

## Local Demo Data

Demo users are optional and must never be seeded in production. They require both a `local` or `testing` environment and explicit opt-in. Supply a unique local-only password through the environment without placing it in shell history:

```bash
export APP_ENV=local
export ALLOW_DEMO_SEEDING=true
read -rsp "Unique local demo password: " DEMO_SEED_PASSWORD
export DEMO_SEED_PASSWORD
php artisan config:clear
php artisan db:seed --class='Database\Seeders\DemoSeeder'
unset DEMO_SEED_PASSWORD ALLOW_DEMO_SEEDING
```

`UsersTableSeeder` is intentionally disabled. Never enable demo seeding or set a demo password in production environment files.

If any former sample credential may have been reused for another account or service, rotate that credential there as a precaution. This repository cannot determine whether reuse occurred and does not rotate external credentials.

## Deployment Scope

The Laravel application is the only deployable application. Configure the web document root to the repository's `public` directory. `resources/prototypes` is offline reference material, is excluded from `git archive` packages, and must not be copied, synchronized, linked, or served. The separate XAMPP `html-version` directory is quarantined reference material and is not a deployment source.

## Scheduler

Configure exactly one server cron in Forge:

```cron
* * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler sends deadline reminders, overdue notifications, backup health checks, and cleanup tasks. Reminder commands use sent-at columns to avoid repeated notification spam.

## Logging

Use the rotating daily log channel in production:

```env
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_DAILY_DAYS=14
LOG_LEVEL=warning
```

Confirm the production service account can write to `storage/logs` and that
infrastructure monitoring alerts on repeated application errors. Sentry does
not replace local retention.

## Forge Deployment Script

Use this as the Forge deploy script for each client site:

Confirm the Forge site's web directory is `/public` before deploying.

```bash
php artisan down --render="errors::503" || true
php artisan backup:run --only-db
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan optimize
php artisan queue:restart
php artisan schedule:interrupt || true
php artisan up
curl --fail --silent --show-error "$APP_URL/up"
```

Trigger deployments locally instead of manual SSH:

```bash
scripts/deploy-client client-slug
```

On Windows:

```powershell
.\scripts\deploy-client.ps1 -Client client-slug
```

Set `FORGE_DEPLOY_HOOK_CLIENT_SLUG` or `FORGE_DEPLOY_HOOK` in your local environment.

## Deployment Rollback

Record the deployed commit and verify the pre-migration database backup before
running the deploy hook.

If a release fails before migrations run, redeploy the previously approved
commit, reinstall its locked dependencies, rebuild its assets, run
`php artisan optimize`, restart the queue, and verify `/up`.

If migrations have run, do not blindly call `migrate:rollback`. First confirm
the previous application release is compatible with the expanded schema. If
data must be reversed, enter maintenance mode, validate the pre-deployment
backup by restoring it to staging, obtain business approval, and then follow
the full restore procedure below. Record the failed release, database backup,
and recovery outcome before bringing the application back up.

## Backups

Required production environment values:

```env
BACKUP_DISKS=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=ap-south-1
AWS_BUCKET=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false
```

Retention defaults:

- 14 daily backups
- 8 weekly backups
- 12 monthly backups

Run a backup before every migration. Forge deploys do this with `php artisan backup:run --only-db`.

## Restore Procedure

1. Restore the selected backup to staging first.
2. Verify user counts, project counts, task counts, login, projects, tasks, history, analytics, and notifications.
3. Prefer targeted recovery for accidental deletes when soft-deleted records or audit history are sufficient.
4. Only perform a full production restore after confirming business approval and taking a fresh production backup.
5. After restore, run `php artisan optimize`, restart queue workers, and check `/up`.

## Error Tracking

Set:

```env
SENTRY_LARAVEL_DSN=
SENTRY_ENVIRONMENT=production
SENTRY_RELEASE=v1.0.0
```

Verify with:

```bash
php artisan app:sentry-smoke-test
```

Release only after the Sentry test event appears in the correct project and environment.

## Release Gate

Before tagging a release:

```bash
php -l app/Http/Controllers/TasksController.php
php artisan test
vendor/bin/pint --test
composer audit
npm audit
npm run build
```

Also run browser smoke tests for setup, login, dashboards, projects, tasks, history, analytics, settings, team management, and notifications.

## Progressive Web App and Browser Push

Browser push is optional and supplements database notifications. It requires
HTTPS outside `localhost`, a running queue worker, and user permission on each
browser/device. It is best-effort delivery: browser settings, operating-system
settings, network availability, and battery optimization can delay or suppress
notifications.

Generate a VAPID key pair once per environment without committing the private
key:

```bash
php -r "require 'vendor/autoload.php'; print_r(Minishlink\\WebPush\\VAPID::createVapidKeys());"
```

On XAMPP/Windows, if OpenSSL reports that it cannot create the EC key, point
PHP at XAMPP's OpenSSL configuration for that shell and retry:

```powershell
$env:OPENSSL_CONF = 'C:\xampp\apache\conf\openssl.cnf'
```

Set:

```env
WEBPUSH_VAPID_SUBJECT=mailto:operations@example.com
WEBPUSH_VAPID_PUBLIC_KEY=
WEBPUSH_VAPID_PRIVATE_KEY=
WEBPUSH_QUEUE=notifications
WEBPUSH_TTL=3600
WEBPUSH_STALE_AFTER_FAILURES=5
```

The subject must be a valid `mailto:` address or HTTPS URL. The public key is
returned only to authenticated users; the private key must remain in the server
environment. After changing these values, run `php artisan config:clear` during
verification and rebuild the production configuration cache.

Run a queue worker for the configured push queue:

```bash
php artisan queue:work --queue=notifications,default --tries=1
```

The existing scheduler remains required for deadline and overdue notifications;
browser push adds no new scheduled command.

Deployment checklist:

1. Back up the database and run the two browser-push migrations.
2. Serve `manifest.webmanifest`, `service-worker.js`, `/icons`, `/css`, and `/js`
   over HTTPS without redirecting them to login.
3. Confirm the service worker is served from the application root with a
   JavaScript content type and is not cached indefinitely by the web server/CDN.
4. Restart queue workers after deployment.
5. Sign in on a staging device, explicitly enable notifications in Settings,
   send a self-test, follow its link, disable that device, and verify database
   notifications remain available throughout.

Cache and update guidance:

- The service worker caches only versioned public static assets. It never caches
  authenticated documents or authorization-dependent JSON.
- Increment `CACHE_VERSION` in `public/service-worker.js` when changing its
  static cache contract.
- Do not remove the old service-worker URL during rollback. Roll back the
  application and assets together so installed clients can fetch a compatible
  worker.

Key rotation invalidates existing browser subscriptions because subscriptions
are bound to the application server key. Rotate only through a planned release:
replace both keys together, deploy, communicate that users must enable browser
notifications again, and disable stale subscription rows after verification.

Troubleshooting:

- `Configuration unavailable`: verify all three VAPID values and clear cached
  configuration.
- `Permission blocked`: the user must allow notifications in browser or
  operating-system settings; the application cannot override denial.
- No delivery: verify the queue worker, logs using subscription/notification
  IDs, HTTPS, service-worker registration, device focus/DND, and battery
  optimization. Never log endpoint URLs or encryption keys.
- HTTP 404/410 permanently disables an expired subscription. Temporary failures
  are counted and the subscription is marked stale only after the configured
  threshold.

Expected support:

- Android Chrome and Chromium browsers normally support install and Web Push.
- Desktop Chrome, Edge, Firefox, and supported Safari versions can receive Web
  Push subject to browser and OS permissions.
- On iPhone and iPad, Web Push may require installing the site to the Home
  Screen before enabling notifications.
- Private browsing, embedded browsers, managed devices, or restricted browsers
  may not expose the required APIs.

Real-device release gate: validate one Android device, one iPhone/iPad installed
web app where available, and one desktop browser. Confirm install, enable,
self-test, deep-link authorization, logout cleanup, account switching, and
disable-current-device. Do not promise guaranteed real-time delivery.

Rollback removes the PWA UI and Web Push channel with the application release.
Preserve the subscription tables during rollback; they contain operational
state and are harmless while no push jobs are dispatched. Stop/restart queue
workers on the rolled-back release, keep the service-worker URL available, and
verify database notifications.
