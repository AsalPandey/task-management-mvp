# Task Management Hostinger deployment

Only the dedicated Task Management PHP site is in scope. Other domains on the
hosting account are unrelated applications and must not be modified.

## Push-to-deploy contract

The `CI` workflow tests the exact `main` commit with SQLite/quality checks and
MariaDB migration/bootstrap/concurrency qualification. Its dependent deployment
job runs only when both jobs succeed and the repository variable
`HOSTINGER_DEPLOY_ENABLED` is `true`. Main-branch runs are not interrupted during
a deployment by a subsequent push.

The job packages tracked source using `git archive`, installs locked production
PHP dependencies with PHP 8.4, and includes the frontend artifact from the
successful quality job. Local untracked files, `.env`, databases, audit artifacts,
and private keys are never part of this release archive.

Repository Actions secrets:

- `HOSTINGER_DEPLOY_KEY`: a dedicated key, not a personal/shared SSH key.
- `HOSTINGER_KNOWN_HOSTS`: the independently verified SSH host key.
- `HOSTINGER_SSH_HOST`, `HOSTINGER_SSH_USER`, `HOSTINGER_SSH_PORT`.

The host's authorized key has a forced command and SSH `restrict` options. It
accepts only `deploy <40-character SHA>` and does not permit an interactive shell,
forwarding, or PTY. Shared hosting still runs PHP under one Unix account: this is
not OS-level isolation between websites on the same hosting account.

## Site-only server layout

```text
<task-site>/
  public_html -> .task-deploy/current/public
  .task-deploy/
    hostinger-deploy.sh
    hostinger-cron.sh
    runtime.lock
    current -> releases/<sha>.<unique suffix>
    releases/
    incoming/
    shared/.env
    shared/storage/
    legacy-public_html-<timestamp>/  # recoverable initial checkout
```

Install the two shell scripts outside the document root. Shared `.env` and storage
must be provisioned first; keep `.env` mode `0600`, preserve its unique APP_KEY,
and never overwrite credentials from a release. PHP CLI is explicitly
`/opt/alt/php84/usr/bin/php`; the account's default `php` may be an older version.

The receiver validates the archive and production database connection, takes a
database backup, enters maintenance, applies migrations/reference seed data,
links storage, and builds caches. It then switches `current` and checks HTTPS
health/login and private-source isolation. Previous code and private state are
retained. A schema-stage or post-publication failure retains maintenance for
review. Never automatically roll back migrations or assume an older release is
compatible with the new schema; follow `PRODUCTION_RUNBOOK.md` recovery steps.

After validating the first release, disable hPanel's original Git auto-deployment
for this site. Two deployment systems must not write `public_html` concurrently.
Keep the GitHub connection/remotes; do not delete the repository or old local
checkpoint folders.

## Scheduler and worker

Create one site-specific hPanel custom cron, every minute:

```sh
/usr/bin/bash /absolute/task-site/.task-deploy/hostinger-cron.sh >> /absolute/task-site/.task-deploy/shared/storage/logs/runtime-cron.log 2>&1
```

It uses the same operating-system lock as deployment, invokes `schedule:run`, and
drains both `notifications` and `default` with a bounded worker. Do not change
existing unrelated cron entries. Inspect exit status, log freshness, failed jobs,
and actual scheduled execution after provisioning.

## First installation and remaining production services

Use the first-company bootstrap contract in `PRODUCTION_RUNBOOK.md`. A deliberately
enabled, unique `APP_SETUP_TOKEN` can be used for the one-time web installer only
after migrations. The company/manager details and unique password are supplied by
the operator. Clear the token immediately after setup. No universal password or
demo data is provided by deployment.

Working SMTP, monitored backup email, private off-site backup storage/encryption,
and a restore rehearsal are separate production requirements. A temporary `log`
mailer/local backup is not full production sign-off. Browser Push is optional
until VAPID keys and device testing are complete.

## Local development

The only live local source folder is
`C:\xampp\htdocs\Task Management\task-management`. From PowerShell:

```powershell
Set-Location -LiteralPath 'C:\xampp\htdocs\Task Management\task-management'
php artisan serve --host=127.0.0.1 --port=8000
```

`artisan` is in that folder, not its parent. Plain `git push` from this checkout
uses the `main` upstream on the canonical `production` remote. Old remotes and
checkpoint folders remain available for recovery.
