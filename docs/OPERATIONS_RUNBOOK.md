# Operations runbook

Use with [the production contract](../PRODUCTION_RUNBOOK.md) and
[release checklist](PRODUCTION_RELEASE_CHECKLIST.md). Each company has its own
host, database, secrets, backups, queues, and cron. Use the actual host PHP binary
and absolute release paths. Never publish logs or environment values.

## Routine checks

- Check HTTPS /up externally, then a database-backed authenticated page. /up is
  liveness only. Record timestamps and deployed SHA.
- Confirm database connectivity via a private authenticated operator session.
- Check jobs count and oldest created_at in the private database; growing queue
  age needs investigation. php artisan queue:failed lists durable failures.
- Check scheduler/worker log modification times and actual cron exit status.
  php artisan schedule:list explains expected timezone/times, not real execution.
- Inspect daily Laravel logs (14-day default), web/PHP logs, disk quota, private
  backup health, and off-site archive timestamp/checksum. Cron logs need a host
  rotation/quota policy in addition to Laravel daily rotation.

## Application 500 or database unavailable

Record time/route and correlation ID when supplied. Inspect private Laravel/PHP
logs; never enable production debug. Verify the current SHA, PHP/extensions,
storage permissions, cached configuration, and DB service/connection limits.
Do not print passwords or SQL bindings. Restore service, then check login and an
authorized mutation/history. A failed mutation must not be presented as success.
Do not repeatedly submit writes during an outage.

## Queue stopped or failed jobs

Check provider cron/process limits, queue names notifications,default, runtime
lock ownership, oldest job age, failed_jobs, DB connectivity, and outbound HTTPS.
Use the supported bounded worker or monitored daemon; do not switch to sync.
After correcting the cause run php artisan queue:restart and observe consumption.

Use php artisan queue:failed, identify one UUID, inspect the cause privately, and
check current account/subscription authorization before php artisan queue:retry
<uuid>. Observe the retry and resulting business state. Remove a failure with
php artisan queue:forget <uuid> only after an explicit retention/incident decision.
Never flush failures automatically or use queue:retry all without review.

## Cron stopped

Check the provider schedule, absolute paths/PHP binary, cron timezone, permissions,
runtime lock, exit logs, and schedule:list. Restore cron and observe real execution.
Deadline reminders run at 08:00 company time and overdue work hourly. A missed
daily reminder does not imply automatic catch-up; assess current eligible tasks
and run the existing command once only after reviewing the business timing.
Generation-based idempotency protects repeated delivery, not an external cron
availability guarantee. Do not clear scheduler locks while a worker is active.

## Required workflow notice recovery

Mandatory in-app notices have durable `workflow_notification_intents` written in
the workflow transaction. The scheduler runs
`app:deliver-required-workflow-notifications` every minute with non-overlap.
Database queue processing for optional push/mail does not replace this recovery.
Inspect pending count, oldest `available_at`, and safe deferred-retry warnings
privately after a database or delivery outage. Restore the cause, then run
`php artisan app:deliver-required-workflow-notifications --limit=100` and observe
pending intents drain. A successful command exit means the bounded scan ran;
individual delivery failures can still remain pending for later retry.

Delivery rechecks recipient authorization and deduplicates its database notice.
Revoked recipients can be discarded; membership withdrawal uses a restricted
notice without revealing the former project's private content. Do not manually
insert notices or mark pending intents delivered. The command prunes only old
finished delivered/discarded intents (default 30 days), in bounded batches.
Pending intents are retained. A host cron/recovery rehearsal remains an external
acceptance requirement.

## Mail or push failing

Mail: inspect safe transport failures, SMTP host/port/scheme, provider limits,
sender verification, controlled-recipient delivery, and HTTPS APP_URL. Preserve
reset token secrecy. Restore working configuration and rebuild caches. Confirm
receipt; log-mail output is not delivery. Company email setup remains deferred.

Push: check HTTPS, service-worker scope/update, permissions, subscription ownership,
VAPID configuration, notifications queue, outbound HTTPS, and invalid-subscription
retirement. Never log endpoint encryption keys. Re-enrol devices after planned
VAPID rotation. Verify OS receipt/click and shared-account behavior; do not send to
uncontrolled real users during diagnosis.

## Storage permissions or disk quota

Check host ownership/group and storage/app, framework/cache/sessions/views, logs,
and bootstrap/cache. Fix only required writable directories; do not use 0777.
Confirm web, cron, and queue identities agree. Preserve backups and incident logs;
apply documented retention after checking restore availability.

## Failed migration or frontend deployment

Keep maintenance, stop workers/cron, preserve failure logs, current SHA, and schema
state. Never migrate:fresh or blindly migrate:rollback. Select code rollback only
after checking schema compatibility; otherwise use the rehearsed recovery point
with owner approval. For missing assets inspect manifest and complete asset set,
public webroot, cache build, and service-worker version. Restore code and assets
together; preserve drafts while users receive the update notice.

## Restore from backup

1. Select a private archive and verify SHA-256, database version, timestamp,
   matching release, and environment escrow inventory.
2. Create a separate clean restore target; extract privately. Import the SQL
   using the compatible database client without putting passwords on the command
   line. Restore durable files from the matching snapshot, if any.
3. Supply server secrets and the original APP_KEY; point only this isolated copy
   at the restore DB. Recreate writable directory structure and permissions.
4. Compare migration state and deterministic table counts/hashes, including UIDs,
   events, approvals, notifications, settings, and memberships. Build caches.
5. Boot HTTPS staging; log in, inspect history, and perform a new authorized
   mutation. Observe queue/cron and controlled mail/push. Record recovery time.
6. Restore production only after separate owner approval and a fresh emergency
   backup. Quiesce writes/queues/cron, restore matching DB/files/code/assets and
   secrets, rebuild caches, restart runtime, and repeat verification before up.

Retain the backup and failed-release evidence. A local dump/import does not qualify
hosted/off-site business recovery until this full rehearsal succeeds.

## Controlled staging soak

From a dedicated staging operator shell run:

```bash
R45_STAGING_ONLY=1 STAGING_URL=https://your-dedicated-staging.example R45_SAMPLES=240 \
  bash scripts/r45-staging-soak.sh > /private/evidence/soak.csv
```

This performs one /up request per minute for approximately four hours and records
status, duration, and transport errors. It does not qualify authenticated work,
database health, memory, or installed-device behavior by itself. During the same
window perform a modest approved multi-role task lifecycle and inspect PHP memory,
DB connections/errors, queue age/backlog, cron overlap, log growth, authentication,
freshness, and service-worker errors. Record those observations alongside the CSV.
No soak has been run without staging access; this is a repeatable future procedure.
