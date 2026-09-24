# Production Release and Operations Runbook

This runbook describes the supported production baseline for one company on one
Laravel installation. It is an operations contract, not authorization to deploy.

## New Company Installation

This is the canonical, repeatable installation contract. A new company is one
isolated application plus one isolated empty database. No dump, copied database,
legacy record, demo seeder, or manual SQL is part of this procedure.

1. Provision the platform described in sections 1–2. Point the web document root
   at `public`, enable HTTPS, and grant the PHP/cron account write access only to
   `storage/**` and `bootstrap/cache`.
2. Deploy an immutable repository release. Create a new, empty `utf8mb4` database
   and a least-privilege application database account. Confirm it contains zero
   application tables.
3. Copy `.env.example` to a private server-only `.env`. Set the mandatory values:
   `APP_NAME`, `APP_ENV=production`, `APP_DEBUG=false`, canonical HTTPS `APP_URL`,
   `APP_TIMEZONE`, all `DB_*` values, database session/cache/queue settings, secure
   cookies, and a unique `APP_KEY` (generate it with `php artisan key:generate`).
4. Supply the one-time bootstrap secrets `INITIAL_COMPANY_NAME`,
   `INITIAL_MANAGER_NAME`, `INITIAL_MANAGER_EMAIL`, and a unique strong
   `INITIAL_MANAGER_PASSWORD`. These have no fallback and must differ between
   commercial installations. Do not pass the password on the command line.
5. Install and build from locks:

   ```bash
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   npm ci
   npm run build
   php artisan migrate --seed --force --no-interaction
   php artisan app:setup-company --no-interaction
   php artisan storage:link
   php artisan optimize
   ```

   `migrate --seed` creates schema plus the three roles and their permissions; it
   creates no users, projects, tasks, or demo data. `app:setup-company` creates the
   company record and exactly one highest-authority `manager`. A safe rerun with
   the same email returns the existing manager without resetting credentials or
   company data. Any conflicting existing account stops setup.
6. Remove `INITIAL_MANAGER_PASSWORD` from the active deployment environment after
   bootstrap if the platform permits one-time secrets, then rebuild the config
   cache. Retain it only in the approved secret escrow if disaster-recovery policy
   requires it. Leave `APP_SETUP_TOKEN` empty unless deliberately using the
   temporary first-boot web installer; clear it immediately after use.
7. Start a database queue worker for both `default` and `notifications`, for
   example a supervised `php artisan queue:work --queue=notifications,default`, or
   use the bounded shared-hosting worker in section 5. Install the one-minute cron
   entry `* * * * * php /absolute/path/artisan schedule:run` with captured failure
   output. Run `php artisan schedule:list` to confirm reminders, overdue checks,
   backup, cleanup, and backup monitoring.
8. Configure feature-specific services: working `MAIL_*` for mail/operations
   alerts; the VAPID subject/public/private key trio for Browser Push; and private
   off-site backup disk credentials plus `BACKUP_ARCHIVE_PASSWORD`. Browser Push
   is optional, but VAPID must be complete before enabling it. Sentry is optional.
9. Sign in with the initial manager. Verify `/up`, login/logout, Profile & Security,
   user creation and role boundaries, project/member creation, task assignment and
   lifecycle, notifications, analytics/reports, queue consumption, scheduler
   execution, and a restorable off-site backup. Never retain bootstrap test data in
   a real company installation.

### Environment classification

- **Mandatory:** `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL`,
  `APP_TIMEZONE`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
  `DB_USERNAME`, `DB_PASSWORD`, `SESSION_DRIVER`, `SESSION_SECURE_COOKIE`,
  `CACHE_STORE`, `QUEUE_CONNECTION`, `QUEUE_FAILED_DRIVER`, `FILESYSTEM_DISK`,
  and the four `INITIAL_*` values until first bootstrap completes.
- **Feature-specific:** `MAIL_*`; `WEBPUSH_VAPID_*`, `WEBPUSH_QUEUE`, and push
  delivery limits; `BACKUP_*` plus the selected disk's credentials; `SENTRY_*`;
  and `APP_SETUP_TOKEN` only for the temporary web installer.
- **Optional/defaulted:** locale, bcrypt rounds, maintenance, logging retention,
  queue retry timing, and task-review SLA values. Review defaults before release.
  `ALLOW_DEMO_SEEDING` must remain false and `DEMO_SEED_PASSWORD` blank in
  production.

### Package responsibility

- **Included in repository:** Laravel source, all schema migrations, deterministic
  role/permission seeders, bootstrap command, frontend/build configuration, PWA
  manifest/icons/service worker/onboarding, queue jobs, scheduler definitions,
  health endpoint, tests, CI fresh-install proof, and this runbook.
- **Deployment-supplied:** `.env`, database/application/mail/object-store
  credentials, unique `APP_KEY`, canonical URL/timezone, initial manager secrets,
  VAPID private key, backup encryption secret, and optional monitoring DSN.
- **Infrastructure-supplied:** compatible PHP/extensions, Composer, build-time
  Node/npm, MariaDB/MySQL, HTTPS and web server, cron/worker execution, filesystem
  permissions, dump tooling, monitoring, and private off-site backup storage.

There is no fourth category of manual initialization data.

### Mobile PWA installation acceptance

The application automatically offers installation only after authentication on a
device identified as mobile by browser/device signals—not viewport width. It does
not promote installation on desktop. `Not Now` stores a 30-day device/browser
cooldown; Settings → Notifications → Install Task Management MVP remains available
throughout. Standalone mode and the `appinstalled` event suppress the offer.

When `beforeinstallprompt` is available, **Install App** invokes it only from the
user's click. Otherwise the same action reveals Android browser-menu guidance or
iPhone/iPad Safari Share → Add to Home Screen guidance. Installation never asks
for notification permission; Browser Push remains a separate Settings action.

After remote CI and HTTPS staging deployment, complete and record—not merely
simulate—the following physical-device checks:

- Android native: login, see the offer, confirm native install, find the icon,
  launch standalone, verify session/navigation; then separately enable and test
  Browser Push and notification deep links.
- Android fallback: use installation help, choose the browser's Install app/Add
  to Home screen action, confirm, and launch successfully.
- iPhone/iPad: login in Safari, follow Add to Home Screen guidance, launch the
  installed app, verify session/navigation, then separately test supported push.
- Desktop: login with no automatic install promotion and test normal application
  use; desktop push may be tested separately.

## 1. Hosting verdict

**Supported only if specific shared-hosting features are available.** A VPS is not
intrinsically required. Conventional shared hosting is acceptable when every
mandatory item below is available.

### Mandatory

- Linux hosting with PHP 8.4 and the extensions listed below.
- MariaDB 10.4.32 or newer, or MySQL 8.0 or newer, with InnoDB, transactions,
  foreign keys, row locks, and `utf8mb4`.
- The domain document root points to Laravel's `public` directory, or
  `public_html` is a symlink/bind target for that directory. Application source,
  `.env`, `vendor`, `storage`, audit files, and backups must not be web-accessible.
- A valid HTTPS certificate and HTTPS-only application URL.
- One cron invocation of `php artisan schedule:run` every minute.
- Either a persistent queue worker or a non-overlapping, bounded queue worker
  invoked every minute. PHP must be allowed to run long enough to drain normal
  notification volume.
- A persistent MariaDB/MySQL database, outbound HTTPS on TCP 443 for Web Push,
  and SMTP or another configured mail transport for operational alerts.
- Writable `storage` and `bootstrap/cache`; ability to create the public storage
  link if public uploads are introduced.
- A private off-site backup destination and access to `mysqldump`/`mariadb-dump`.

### Recommended

- SSH access, Composer 2.8+, a deployment atomic-release mechanism, cron failure
  alerts, log aggregation, and a persistent process supervisor.
- MariaDB 10.6 LTS or newer / MySQL 8.0 current maintenance release.
- A staging environment on the same database family and PHP minor version.
- Optional Sentry error monitoring with personally identifiable data disabled.

### Optional

- Redis for cache/queues, a CDN for versioned local build assets, and S3-compatible
  object storage for future user uploads. No paid monitoring provider is required.

If the host cannot meet the document-root, one-minute cron, bounded queue,
outbound HTTPS, database-locking, or off-site-backup requirements, that shared
plan is unsupported; choose another plan or a VPS. Never compensate by copying
the Laravel source tree into `public_html`.

## 2. Platform requirements

| Component | Release requirement |
|---|---|
| PHP | 8.2–8.4, 64-bit recommended |
| PHP extensions | ctype, curl, DOM/XML/SimpleXML, fileinfo, filter, hash, iconv, JSON, libxml, mbstring (or Composer polyfill), OpenSSL, PCRE, PDO, pdo_mysql, session, tokenizer, Zip |
| Database | MariaDB >= 10.4.32 (qualified baseline) or MySQL >= 8.0; InnoDB and `utf8mb4` |
| Composer | Composer 2 at build/deploy time; install from the committed lock file |
| Node/npm | Node 20+ and npm 10+ at build time only; not required at runtime when `public/build` is deployed |
| Web server | Apache/Nginx-compatible rewrites; document root exactly `public` |
| Scheduler | Cron every minute with one active scheduler invocation |
| Queue | Database queue; persistent worker or non-overlapping bounded cron worker |
| Network | HTTPS ingress; outbound HTTPS/443 for push; SMTP and backup endpoint as configured |
| Filesystem | writable `storage/**` and `bootstrap/cache`; private backups and private local disk |

`pcntl`/`posix` are recommended for supervised long-running workers but are not
required by the bounded shared-hosting queue strategy. Node is never required to
serve requests.

## 3. Production environment contract

Copy `.env.example` to a server-only `.env`; do not commit it. Replace every
example value. Run `php artisan config:cache` only after the final environment is
present.

| Variable | Requirement |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | canonical `https://` origin, including any intentional path prefix |
| `APP_KEY` | unique `base64:` key generated once for this installation; preserve across releases |
| `APP_TIMEZONE` | company operating timezone; currently `Asia/Kathmandu` |
| `APP_SETUP_TOKEN` | empty after first setup unless the installer is deliberately enabled |
| `INITIAL_COMPANY_NAME` | required by non-interactive first bootstrap; remove from runtime environment afterward if possible |
| `INITIAL_MANAGER_NAME` / `INITIAL_MANAGER_EMAIL` | required identity for the first highest-authority manager |
| `INITIAL_MANAGER_PASSWORD` | required unique deployment secret for first bootstrap; never put it in command arguments or Git |
| `DB_*` | least-privilege application account; never database root |
| `SESSION_DRIVER` | `database` |
| `SESSION_SECURE_COOKIE` | `true` |
| `SESSION_HTTP_ONLY` | `true` |
| `SESSION_SAME_SITE` | `lax` |
| `CACHE_STORE` | `database` for a single shared-host instance |
| `QUEUE_CONNECTION` | `database`, never `sync` in production |
| `QUEUE_FAILED_DRIVER` | `database-uuids` |
| `DB_QUEUE_RETRY_AFTER` | `90`; must remain greater than the worker timeout |
| `FILESYSTEM_DISK` | `local` (private) unless an explicitly configured private object store is used |
| `LOG_CHANNEL` / `LOG_STACK` | `stack` / `daily` |
| `LOG_LEVEL` | `warning` or stricter after staging observation |
| `MAIL_*` | working production transport; backup alerts must not use the `log` mailer |
| `BROADCAST_CONNECTION` | `log`; realtime broadcasting is not required |
| `WEBPUSH_VAPID_*` | one valid subject/public/private key set per environment |
| `WEBPUSH_QUEUE` | `notifications` |
| `BACKUP_DISKS` | private off-site disk, normally `s3` |
| `BACKUP_ARCHIVE_PASSWORD` | strong server-only secret to enable AES archive encryption; blank explicitly disables archive encryption |
| `BACKUP_NOTIFICATION_EMAIL` | monitored operations mailbox |
| `SENTRY_LARAVEL_DSN` | optional; blank disables the paid integration |
| `SENTRY_RELEASE` | deployed Git SHA or immutable release identifier when Sentry is enabled |

The VAPID private key, application key, database password, SMTP password, object
storage secret, setup token, and backup password are secrets. Do not place any
of them in command history, Git, build logs, or client-side JavaScript.

An empty `BACKUP_ARCHIVE_PASSWORD` is supported for destinations that provide an
approved equivalent encryption control, but it does not encrypt the ZIP itself.
Set a nonblank password when archive-level encryption is required, escrow it
separately from the archive, and include decryption in every restore rehearsal.

If TLS terminates at a reverse proxy, the host must pass trustworthy forwarded
scheme/host headers and Laravel proxy trust must be configured to the provider's
documented fixed proxy ranges. Do not trust every proxy (`*`) on a directly
reachable origin. Direct HTTPS termination needs no Laravel proxy exception.

## 4. Database, session, cache, and queue tables

Fresh migrations create `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, and `failed_jobs`, plus notification, task-delivery, and Browser
Push tables. These tables are mandatory when the production defaults above are
used. Never point a qualification command at the configured production database.

Use a database account with only the privileges required for the application and
approved migrations. Back up before schema change. The R3B migration gate must
test both `migrate:fresh` and an additive upgrade from the deployed schema on a
disposable database of the production family.

## 5. Queue architecture

Database notifications are written during the request/command transaction.
Browser Push is the only current `ShouldQueue` workload and is placed on the
`notifications` queue after commit. The job has three attempts and backoff of 60
then 300 seconds. Delivery rows and database claims are authoritative for
idempotency; queue overlap controls are defense in depth. Failed jobs are kept in
`failed_jobs` and must be monitored.

### Preferred supervised worker

```bash
php artisan queue:work database \
  --queue=notifications,default \
  --sleep=3 --tries=3 --timeout=60 --max-time=3600
```

Configure the supervisor to restart the process. During deploy, enter maintenance
mode, stop or quiesce workers, deploy/migrate/cache, run `php artisan queue:restart`,
and verify the replacement worker is consuming both queues.

### Shared-hosting bounded worker

Use a separate once-per-minute cron with an operating-system lock. Replace paths
with absolute host paths:

```cron
* * * * * cd /home/account/apps/tasks/current && /usr/bin/flock -n storage/framework/queue-cron.lock php artisan queue:work database --queue=notifications,default --stop-when-empty --max-time=50 --sleep=1 --tries=3 --timeout=60 >> storage/logs/queue-cron.log 2>&1
```

The provider must offer `flock` or an equivalent non-overlap facility. A worker
may finish its current job after `--max-time`; therefore the lock is mandatory.
Do not use `queue:listen`, an infinite unmonitored worker, or `QUEUE_CONNECTION=sync`
to fit a constrained plan. Alert on non-zero cron exit, increasing queue age,
and any `failed_jobs` row. Review with `php artisan queue:failed`; retry only after
the cause is corrected and the job's current authorization is understood.

## 6. Scheduler architecture

Configure exactly one scheduler cron:

```cron
* * * * * cd /home/account/apps/tasks/current && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

The application schedules:

- deadline reminders daily at 08:00 in `APP_TIMEZONE`;
- overdue processing hourly;
- database backup daily at 01:30 in `APP_TIMEZONE`;
- backup cleanup daily at 02:30 in `APP_TIMEZONE`;
- backup health monitoring daily at 03:00 in `APP_TIMEZONE`.

Every scheduled operation has overlap protection. Database delivery claims and
generation counters remain the source of truth for R2B idempotency. The host must
retain cron stdout/stderr or notify on failure; redirecting permanently to
`/dev/null` is not an acceptable production monitoring strategy. After deploy,
run `php artisan schedule:list` and observe at least one real scheduled cycle.

## 7. PWA and Browser Push

- HTTPS is mandatory outside localhost. The service worker is served from the
  application root and its scope follows the application path.
- `manifest.webmanifest`, root `service-worker.js`, icons, local CSS/JS, and the
  Vite build must be anonymously reachable as static assets.
- The worker caches only same-origin static destinations. It never intercepts or
  caches authenticated HTML or application JSON, and it removes only cache names
  owned by this application.
- Push targets are constrained to same-origin paths and still pass authentication
  and task authorization. Logout/account switching unsubscribes the local device.
- The server rechecks account status, authorization, preferences, and endpoint
  safety before transport. Logs use subscription/notification IDs, never endpoint
  URLs or encryption keys.
- Browser Push is best effort. Real-device Android, iOS installed-web-app, and
  desktop validation remains an R3C gate and is not claimed by server tests.

Generate VAPID keys once per environment and store only in server secrets. Key
rotation invalidates existing subscriptions and requires a planned re-enrolment.

## 8. Security headers and static dependencies

Core JavaScript, CSS, and fonts are local and locked by `package-lock.json`; the
application does not require a third-party CDN to operate. Production responses
set CSP, anti-framing, MIME-sniffing, referrer, and permissions headers. HTTPS
responses set one-year HSTS including subdomains. Confirm every subdomain is HTTPS
before release; otherwise remove `includeSubDomains` in a reviewed code change.

The current CSP permits inline scripts/styles because legacy Blade templates
still contain inline behavior. It blocks third-party script origins, objects,
foreign frames, and foreign workers. Replacing inline code with nonce/hash-based
assets is a future hardening task, not a reason to weaken the current policy.

## 9. Storage and permissions

`FILESYSTEM_DISK=local` resolves to `storage/app/private`. Do not expose it. The
`public` disk resolves to `storage/app/public` and becomes web-visible only after
`php artisan storage:link`; use it solely for deliberately public files. Backups
belong on a private off-site disk and must never be written under `public`.

The PHP/web/cron account needs read access to the release and write access only to:

```text
storage/app
storage/framework/cache
storage/framework/sessions
storage/framework/views
storage/logs
bootstrap/cache
```

Use host-appropriate ownership with directories normally `0750`/`0770` and files
`0640`/`0660`; never solve permissions with world-writable `0777`. Verify the
queue and cron user is the same account or shares the correct group.

## 10. Logging, health, and observability

Use rotating daily logs with 14-day local retention and collect them off-host if
required by policy. Production debug and Debugbar are disabled. Sentry is optional;
the application remains functional with an empty DSN.

`GET /up` is a public, detail-free liveness endpoint. HTTP 200 proves Laravel can
boot and route the request; it does **not** prove database connectivity, queues,
scheduler delivery, push transport, or backup validity. Monitor `/up` externally,
database connectivity separately, queue age/failed jobs, scheduler log freshness,
and backup health. Never expose environment data, credentials, paths, or traces.

## 11. Backups and restore

The scheduler creates a database backup daily, cleans retained backups, and checks
health. The default retention is 14 daily, 8 weekly, 12 monthly, and 2 yearly,
subject to a 5 GB cleanup threshold. Production requires a private off-site disk,
an operations mailbox, and encryption when the storage/provider supports it.
`mysqldump` or `mariadb-dump` must be executable by the scheduler account (normally
through its non-interactive `PATH`); verify this from cron rather than only from an
SSH shell.

The scheduled `backup:run --only-db` intentionally covers the database only. The
current release has no user-upload workflow, and its durable application state is
in MariaDB. If persistent files are added under `storage/app/private` or a private
object-store prefix, back them up separately on a matching schedule and restore
the database and file snapshot as one recovery point. Do not put `.env` inside a
routine application archive: escrow its required values in the approved secrets
store and retain a redacted configuration manifest with the release record.

A successful backup command proves archive creation, not recoverability. Before
each migration record the archive identity, size, checksum, off-site presence, and
database server/version. R3B must restore a selected archive to staging, run
integrity counts and application smoke tests, and record recovery time. Include
private uploaded files in the backup plan if that feature is introduced.

Restore procedure:

1. Keep production online while first restoring the selected archive to isolated staging.
2. Verify schema, user/project/task/event counts, login, workflow, notifications, analytics, and exports.
3. Obtain business approval for a full production restore; take a fresh emergency backup.
4. Enter maintenance mode and stop queue/scheduler execution.
5. Restore the matching database and matching code/assets; never mix schema generations.
6. Rebuild caches, restart workers/cron, verify `/up`, then complete authenticated smoke tests.
7. Record the archive, release SHA, approver, checks, and recovery outcome.

## 12. Pre-deployment checklist

1. Approve and record the immutable release SHA and compare it to the staged tree.
2. Confirm the release qualification report, dependency audits, lock files, and
   production build were generated from that SHA.
3. Verify the complete environment matrix without printing secrets.
4. Verify PHP/extensions, database server/version, disk quota, cron, queue runtime,
   outbound HTTPS, SMTP, document root, rewrites, and filesystem permissions.
5. Take and identify an off-site backup; confirm the most recent restore rehearsal.
6. Confirm migration order and backward compatibility with the previous code.
7. Put the site in maintenance mode and stop queue processing immediately before
   code/schema replacement. Preserve the previous complete release directory.

## 13. Deployment sequence

Run from an immutable release directory. Do not deploy a dirty checkout and do
not run `git pull` as the release mechanism.

```bash
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan storage:link
php artisan optimize
php artisan queue:restart
```

When assets are built in CI, deploy the verified `public/build` artifact and Node
is not needed on the host. `storage:link` is idempotent once established. Ensure
cron points to the new `current` release, start the worker strategy, then leave
maintenance mode only after the post-deployment checks pass.

First installation follows **New Company Installation** above. The production
seeder creates roles/permissions only. Create the first manager non-interactively
from the `INITIAL_*` deployment secrets with `php artisan app:setup-company
--no-interaction`. Interactive secret input or the temporary server-only setup
token are recovery alternatives, not the canonical automated path. Clear setup
secrets after setup where supported. Never enable demo seeding in production.

## 14. Post-deployment checklist

1. Verify HTTPS redirect/certificate, `/up`, security headers, and no debug output.
2. Verify login/logout, account/session revocation, manager and member access.
3. Create and transition a staging/smoke task through assignment, execution,
   submission, review, approval, reopen/cancel paths as applicable.
4. Verify a queued push job is consumed, database notification persists, and no
   push secret or endpoint appears in logs.
5. Verify `schedule:list`, scheduler log freshness, and one controlled reminder run.
6. Verify analytics totals, CSV export, print export, and authorization boundaries.
7. Inspect application, queue, web-server, and cron logs plus `queue:failed`.
8. Confirm the off-site backup job and monitor configuration; do not claim restore
   readiness until the R3B rehearsal succeeds.
9. Complete the R3C physical-device PWA/push matrix before commercial launch.

## 15. Rollback

Do not blindly run `migrate:rollback`.

1. Enter maintenance mode; stop workers and suspend scheduler/queue cron.
2. Record the failed SHA, logs, schema state, and in-flight/failed job counts.
3. If migrations did not run, atomically repoint `current` to the previous complete
   release, rebuild/clear caches, restart workers/cron, and verify health/smoke.
4. If migrations ran, first determine whether the previous code is compatible with
   the expanded schema. Prefer code rollback with additive schema left in place.
5. If data/schema restoration is necessary, use the approved, staging-verified
   pre-deployment backup and its matching code/assets. Obtain business approval.
6. Run `php artisan optimize`, restart queue processing, restore scheduler cron,
   leave maintenance mode, and repeat the post-deployment checks.

Keep the service-worker URL available and roll back application code and static
assets together. Preserve Browser Push subscription/delivery tables unless an
approved restore requires the entire matching database.
