# Production release checklist

One company = one isolated application and one isolated database. This checklist
does not authorize production deployment. Record PASS, FAIL, BLOCKED, or NOT
APPLICABLE with justification for each item; unavailable access is BLOCKED.
Use [the deployment guide](PRODUCTION_DEPLOYMENT_GUIDE.md) and the existing
[production contract](../PRODUCTION_RUNBOOK.md).

## Pre-deploy

- Record owner, change window, full immutable SHA, branch, clean tracked/staged
  status, previous release SHA, artifact SHA-256, and exact CI run URL.
- Require successful Linux quality, MariaDB, concurrency, 10k resource, browser
  Chromium/Firefox/WebKit, accessibility, packaging, clean install, build, and
  full Composer/npm audits for this exact SHA. Record every skip separately.
- Verify PHP/extensions, database version, HTTPS, public-only document root,
  writable private storage/cache, outbound SMTP/HTTPS, cron, and bounded worker
  support on the actual shared-hosting plan. Keep separate credentials per company.
- Record staging installation, multi-role lifecycle, real cron/queue recovery,
  controlled-recipient email, physical-device push, Android and Safari/Home Screen
  PWA acceptance, draft conflicts, account switching, and worker update results.
- Record measured staging load/soak, log review, and fault recovery.
- Back up the current database and any durable files to private off-site storage.
  Record checksum, size, timestamp, version, and successful isolated restore
  rehearsal. Escrow environment secrets separately, including APP_KEY.
- Inspect the production package: no .env, credentials, Git internals, test
  accounts/databases, node_modules, audit output, logs, or generated caches.
- Determine migration compatibility and rollback path before running migrations.
- Stop if mandatory evidence is unavailable, required CI is red, secrets leak,
  or P0/P1 defects remain. Do not tag a blocked/failed candidate.

## Deploy

- Obtain separate owner authorization for live changes. Confirm the target company
  and database without printing secrets. Disable any unexpected automatic deploy.
- Keep the previous complete release; enter maintenance and quiesce queue/cron
  using the deployment runtime lock. Confirm the pre-migration backup is usable.
- Transfer the exact verified artifact using verified SSH host keys. Preserve
  server-only .env and shared storage; expose only release/public.
- Run migration/seeding, storage link where needed, production cache build, and
  queue restart using the hosting PHP 8.4 binary. Never migrate:fresh in production.
- Switch the current release atomically; resume bounded workers and scheduler.
- Leave maintenance only after boot checks succeed. Record actual deployed SHA.

## Post-deploy

- Verify TLS, redirects, HTTPS links, secure/HttpOnly/SameSite cookies, security
  headers, static assets, manifest MIME, service-worker scope, and no mixed content.
- Probe .env, composer.json/lock, package.json, .git/config, logs, storage internals,
  database files, tests, and vendor source: none may be retrieved publicly.
- Check /up plus a database-backed authenticated page; /up alone is not DB proof.
- Complete approved smoke login, role authorization, task mutation/history,
  notifications, analytics/export, logout and shared-profile account switching.
- Observe real cron cycles, queue age/consumption, failed jobs, controlled SMTP
  receipt, push receipt/click, and backup off-site presence.
- Inspect web/PHP/Laravel/worker/cron logs and disk quota. Record evidence and skips.

## Rollback trigger

Stop release operations for migration failure, unsafe error output, public private
files, broken login/authorization/lifecycle, missing assets, growing queue backlog,
lost scheduler execution, or inconsistent data. Keep evidence and maintenance;
do not hide failure by deleting failed jobs or rebuilding browser baselines.

## Rollback

- Quiesce writes, workers, and cron. Record failed SHA and migration state.
- If schema remains compatible, switch to the previous complete code/asset release.
- Never blindly migrate:rollback. If schema/data recovery is required, obtain
  explicit owner approval and use the rehearsed backup with matching code.
- Restore durable files and server secrets from the same recovery inventory.
  Preserve APP_KEY; rebuild caches and restart workers/cron.

## Post-rollback verification

- Compare schema/migration state, users, projects, memberships, tasks, UIDs, events,
  approvals, notifications, and settings against the recovery snapshot.
- Verify login and a new authorized mutation, full history, queue/cron progression,
  HTTPS, assets, worker updates, and safe account switching.
- Keep maintenance until checks pass. Record recovery time, lost-work window,
  backup identity, deployed SHA, and owner decision. Preserve incident evidence.
