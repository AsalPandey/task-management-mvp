# Production deployment guide

Use the complete [production contract](../PRODUCTION_RUNBOOK.md) for commands,
environment variables, PHP extensions, provisioning, and retention. This guide
adds release acceptance handoff. Intended target: Hostinger shared hosting, one
separate hosting installation and database per company. No multi-tenancy.

R4.5 is blocked until the actual host, external services, Linux CI, and physical
devices are qualified. Company SMTP will be configured later by the owner; log
mail is never proof of delivery. Do not describe this candidate as ready to deploy.

## Provision and build

1. Select a plan with PHP 8.4, InnoDB MariaDB/MySQL, HTTPS, public-only webroot,
   writable private Laravel directories, outbound HTTPS/SMTP, a dump executable,
   once-per-minute cron, and flock or equivalent non-overlap control. Verify CLI
   and web runtime versions separately. Read actual limits from the host.
2. Run the required Linux CI on an immutable SHA. Build from git archive, install
   Composer --no-dev --prefer-dist --no-interaction --optimize-autoloader, and use
   public/build from that same CI SHA. Node/npm are build tools, not host requirements.
   Package with scripts/hostinger-package.sh. Inspect the unpacked package and
   retain its SHA-256 and manifest privately.
3. Create a new empty utf8mb4 database and a least-privilege account. Create a
   private server .env from .env.example. Set production/debug-false, canonical
   HTTPS URL, company timezone, DB credentials, secure database sessions, database
   cache and queues, log retention, SMTP, private backup disk, and VAPID secrets.
   Generate APP_KEY once and escrow it. Never copy a different company's key/data.
4. Set ownership so web/cron/queue can write storage and bootstrap/cache only.
   Verify directory symlink and rewrite support; never use chmod 0777. The document
   root must expose only public, including on shared hosting.

## First installation

Run php artisan migrate --seed --force --no-interaction against the confirmed
empty company database. Roles/permissions are seeded; demo users must remain
disabled. The canonical CLI installer uses the four INITIAL_* secrets and
php artisan app:setup-company --no-interaction. Alternatively, for a disposable
browser acceptance installation use a temporary server-only APP_SETUP_TOKEN and
the normal setup UI. Verify replay cannot create a second Manager. Clear temporary
setup/initial-password values after installation; retain the company's APP_KEY.

Build caches with php artisan optimize. Verify the first Manager can log in and
create PM/member accounts, projects, membership, and tasks through the normal UI.
Do not insert final company records manually to bypass installer acceptance.

## Hostinger runtime

Use scripts/hostinger-cron.sh with the private .task-deploy layout described in
the production contract. Scheduler and bounded database worker must share the
deployment runtime lock. Drain notifications,default with --stop-when-empty,
--max-time=50, --tries=3, and --timeout=60; retry_after is 90 seconds. Confirm the
hosting execution limit and flock capability. Observe a real scheduled invocation
and queue restart; manual schedule:run is a diagnostic only. Keep cron logs.

The current CI deploy job can run on main only when HOSTINGER_DEPLOY_ENABLED=true
and all required jobs succeed. Do not push to main for an audit until the owner
authorizes deployment and the target is confirmed. SSH receiver setup is an
operator provisioning action; host keys and credentials remain private.

## Mail, push, and recovery

Configure company SMTP and controlled recipient addresses; test actual password
reset delivery, From identity, HTTPS links, expiry/replay, and controlled failure.
Generate per-installation VAPID keys; qualify actual notification permission,
subscription, OS receipt/click, account switching, and revocation on supported
devices. Keep private keys off reports and artifacts. Neither feature is qualified
by a fake/log transport.

Run database backups on the private off-site disk; current scheduling is DB-only
because the product has no user-upload workflow. Inventory any durable storage
and back it up separately if present. Escrow the environment separately. Restore
to a newly created isolated database, with matching code/assets and APP_KEY.
Compare table counts and deterministic content hashes, migration state, UIDs,
events, approvals, notifications, and settings; verify login and a new mutation.
Record time, checksums, retention, and off-site presence. See the runbook for
incident and restore steps. Never overwrite a customer database during rehearsal.

For upgrades and rollback follow [the release checklist](PRODUCTION_RELEASE_CHECKLIST.md).
Keep the previous complete release and a verified pre-migration backup. Do not
blindly reverse migrations. Rebuild caches, restart queues, restore cron, and
verify service-worker assets after any release switch. Physical Android/iOS,
installed PWA synchronization, Firefox, and hosted soak remain acceptance tasks.
