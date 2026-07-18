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
