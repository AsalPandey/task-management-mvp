# R5.4 internal qualification and external handoff

One company has one isolated Laravel application, MariaDB database, secrets,
storage, scheduler and database queue. The target remains separate Hostinger
shared hosting per company. No Redis, permanent daemon, WebSocket or shared tenant
database is required. PHP 8.4 is the qualified application runtime. Node is used
for the locked Vite build; static analysis is separate development tooling.

Read the R5.4 final report for the source candidate, final counts and evidence.
Record `git rev-parse HEAD` after its documentation commit as the release handoff
SHA; compare its application/test tree with the report's candidate. Documentation
commits do not authorize deployment. Linux CI must qualify the full handoff SHA
before external deployment testing.

The internal envelope uses 128 MiB PHP processes: 100/1k/10k task resource gates
retain the R4.3 query, hydration, output and 96 MiB peak budgets. Dense 10k/25k
administration retains the R5.3 64 MiB peak budget, real PM replacement and required
notice delivery, dependency rejection and integrity oracles. Full 25k replacement
and synchronous delivery can take many minutes locally. Memory survival is not
a request-time SLA; Hostinger timing and connection/process limits remain
unqualified. See [capacity contracts](R53_CAPACITY_AND_NAVIGATION.md).

Build with `npm ci`, `npm run check:js`, `npm run build`. Use the complete manifest
and asset set from the same revision. Root and mounted paths are qualified
locally, including analytics/print and task feature entries. Use database sessions,
cache and queue in production; once-per-minute scheduler cron and a bounded queue
worker are described in the deployment contract. Required workflow notice recovery
is documented in the [operations runbook](OPERATIONS_RUNBOOK.md). Keep encrypted
offsite backups and rehearse a restore; a successful local check is not a hosted
recovery qualification. Fresh/reset/retained-data migration checks are disposable
tests. Never reset a company database; review schema/code rollback compatibility.

Chromium and Playwright WebKit are the local maintained browser gates. The known
Windows Firefox launch failure is an unavailable environment, never a pass.
Emulation, worker lifecycle checks and axe do not replace installed PWAs, physical
devices, real Safari or live transports.

## Later R4.5 checklist — not performed by R5.4

- Record the exact full handoff SHA, clean tracked/staged state and clean Linux
  quality/MariaDB/concurrency/browser/resource/packaging CI evidence.
- Verify Hostinger CLI and web PHP 8.4, extensions, MariaDB/InnoDB, real 128 MiB
  limits and measured request, connection and cron constraints.
- Inspect the immutable deployment package, checksum and manifest; verify public
  webroot, symlinks/rewrite/mounted paths and private writable storage permissions.
- Verify HTTPS, secure cookies/database sessions, authentication and account
  switching on the actual domain and path.
- Observe real scheduler cron, bounded database queue, restart/recovery and durable
  workflow-intent retry/deduplication/revocation after an injected staging outage.
- Verify controlled SMTP receipt, Sentry reporting and real push permission,
  receipt/click/revocation with the company's intended services and secrets.
- Exercise installed PWAs and updates/offline/reconnect on physical Android,
  iOS/Home Screen and Safari; run Firefox on a supported environment.
- Verify encrypted offsite backup, original APP_KEY escrow, an actual isolated
  restore, counts/provenance and recovery timing.
- Run a staging soak and dense-workload timing assessment; check private logs and
  restart/recovery. Rehearse code/schema-compatible rollback before live changes.

External acceptance still requires separate authorization. R5.4 does not push,
deploy, tag, modify customer environments or start external R4.5 acceptance.
