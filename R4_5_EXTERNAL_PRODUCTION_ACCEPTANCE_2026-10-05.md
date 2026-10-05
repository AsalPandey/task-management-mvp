# External R4.5 — production environment acceptance

Acceptance assessment dated 5 October 2026 (Asia/Kathmandu). Evidence directory:
`output/r4-5-external-20261005/`. This report separates executed Linux checks,
earlier internal qualification, source-defined procedures, and unavailable external gates.

# 1. Executive summary

The release identity was verified. Starting HEAD differed from the qualified R6.1
implementation only by its report. A confirmed CI YAML defect prevented the
Linux workflow from parsing; it was repaired and committed locally. A fresh
native Linux checkout of the resulting candidate passed the available frontend,
workflow and packaging-boundary checks. Full PHP/database qualification and
actual hosting acceptance remain unavailable. No company environment was changed.

# 2. Final classification

**R4.5 BLOCKED — EXTERNAL ACCEPTANCE REQUIREMENTS REMAIN UNAVAILABLE**

Task Management v1.0 is **not production qualified** by this assessment. Missing
external evidence is not recorded as a product failure. The CI defect below is
fixed; a successful end-to-end Linux CI run remains required.

# 3. Release candidate SHA

**External Acceptance Candidate:** `05e61627dc4637c0afcf513c9ec3ba31f84ba3d4`.

Starting HEAD: `3b261fc5a704556d52af8dae93310722ea764ac2`.
Internally qualified R6.1 implementation: `f6afe566a50b8709c8895473e65222adb2e5ad89`.
All final checks performed in this phase use the External Acceptance Candidate.
The later report commit is documentation only and is not a different tested candidate.
Evidence: `candidate.txt`, `linux-candidate.txt`, `repair-commit.txt`.

# 4. Source-tree equivalence

Repository: `C:\xampp\htdocs\Task Management\task-management`; branch: `main`.
Starting tracked tree and index were clean; existing untracked audits, output,
`scripts/hostinger-initialize.php` and the independent audit test were retained.

`git diff --name-status f6afe566a50b8709c8895473e65222adb2e5ad89 3b261fc5a704556d52af8dae93310722ea764ac2`
returns only the added R6.1 report. The candidate additionally modifies only
`.github/workflows/ci.yml`. Application, tests, scripts, bootstrap, configuration,
migrations, routes, resources, public files, manifests, locks and analyzer/build
configuration match the R6.1 implementation; the explicit comparison exits zero.
The CI change means the old qualification is not an exact qualification of the
whole candidate. Its affected parsing/shell checks were rerun; full Linux jobs are pending.

Evidence: `start-*`, `candidate-equivalence.txt`, `runtime-tree-equivalence.txt`
and its exit record. Protected local `.env`, both root locks and the service
worker retain their starting hashes; no secret values were captured.

# 5. Clean Linux environment

Executed: Ubuntu 26.04 LTS, WSL2 x86_64,
kernel `6.6.87.2-microsoft-standard-WSL2`; native Node `v26.8.1`, npm `11.19.0`,
Python `3.14.4`. The maintained GitHub workflow specifies Node 20 and PHP 8.4;
this partial WSL run does not claim to reproduce that entire runner.

`git clone --no-local` created a new temporary Linux checkout, then detached the
exact candidate. No vendor, node_modules, .env, build output or cached Laravel
configuration was inherited. Native PHP, Composer and MySQL/MariaDB clients
were not found in the native PATH. Database server and browser versions are unqualified.
Docker's Linux engine was unavailable. No system packages were installed.

The first attempt inherited Windows npm through WSL PATH and failed at esbuild's
Windows postinstall/UNC path. Its logs remain in `superseded-path-attempt/`; it
does not count as a Linux dependency pass. The final attempt used a new checkout,
an explicitly native PATH and a `process.platform === 'linux'` precondition.
Evidence: `run-linux.sh`, `linux-session.txt`, `linux-checkout.txt`.

# 6. Linux quality gates

| Gate | Result | Evidence file prefix |
|---|---|---|
| Native locked npm ci | PASS | linux-npm-ci |
| Full npm audit, all severities | PASS; zero vulnerabilities | linux-npm-audit |
| npm audit --omit=dev | PASS; zero vulnerabilities | linux-npm-audit-production |
| JavaScript syntax | PASS | linux-js-syntax |
| Production Vite build | PASS | linux-build |
| Three lockfiles unchanged | PASS | linux-locks-before/after, linux-locks-unchanged |
| Workflow YAML / separate dense-roster step | PASS | linux-workflow-parse |
| Every workflow run block: bash -n | PASS | linux-session, linux-workflow-shell-exit |
| Deploy/cron/package/MariaDB/soak shell syntax | PASS | linux-*-syntax |
| Packaging dependency links and content boundaries | PASS on synthetic fixtures | linux-package-links/content |
| git diff --check; tracked source unchanged | PASS | linux-diff-check, linux-tracked-unchanged |
| Composer strict validation and both Composer audits | BLOCKED: native PHP/Composer unavailable | linux-session |
| Production Composer install and analyzer install | BLOCKED | linux-session |
| PHP syntax, Pint, Blade compilation/generated PHP syntax | BLOCKED | linux-session |
| PHPStan/Larastan reviewed baseline | BLOCKED | linux-session |

Each executed gate has a separate exit record; no nonzero exit is counted as a
pass. npm 11 reported esbuild's install script was not approved by its script
policy. The build nevertheless passed with the installed native binary. This
does not certify execution of that skipped postinstall hook or npm 10 behavior.

# 7. Linux SQLite/MariaDB

**BLOCKED.** No native PHP/Composer installation or disposable Linux MariaDB
target was available. Neither maintained suite was run in this phase. Earlier
Windows R6.1 results remain historical internal evidence; they are not Linux totals.
There are no new PHP passed/skipped/failed totals to aggregate.

# 8. Linux concurrency

**BLOCKED.** None of the twelve named MariaDB groups was executed on Linux,
including `R61AccountMariaDbConcurrencyTest` and
`R61ResponsibilityMariaDbConcurrencyTest`. Both R6.1 groups are included in the
twelve-group ledger in the R6.1 report; they are not additional duplicate passes.
`scripts/ci-mariadb-tests.sh` retains the guarded disposable-schema procedures.
Shell parsing of that script does not establish race coverage.

# 9. Linux capacity

**BLOCKED.** No 100-task, 1k-task, 10k-task, 1,000-person roster or 25k
administration gate was executed on Linux. No full-delivery or partial-integrity
gate is claimed. The required application limit remains 128M. No limit was raised.

Historical R6.1 evidence, on its stated Windows candidate only: the 25k gate
affected 18,750 unfinished tasks; PM replacement/delivery took 1,352,289.06 ms
(approximately 22.5 minutes), with 308,161.32 ms transaction time and 36 MiB peak.
This is a documented stress limitation, not external request-time evidence.

# 10. Release package

**BLOCKED.** No complete production deployment artifact was assembled: native
locked production Composer dependencies were unavailable. Windows vendor was
not substituted. The two packaging tests passed on synthetic fixture builds,
including materialization of internal regular-file links and rejection of
escaping/directory links and real .env files. They are not a package smoke test.

# 11. Package checksum/manifest

Deployment artifact filename/checksum: **not generated**. Candidate tracked
inventory is retained in `candidate-tracked-inventory.txt`. The actual native
Linux Vite manifest and all asset SHA-256 values are retained in
`linux-vite-manifest.json` and `linux-assets-sha256.txt`.
Manifest SHA-256: `60ef72eee7e1391fadaf98f8c466b76f9064dca09614825cd47bcee3ed935cd9`.
All four entries' referenced files appear in that Linux checksum inventory;
the validation exits zero. This checksum identifies a frontend manifest, not a release archive.

The temporary Linux checkout was no longer available to a later inspection;
the failed revisit is retained in `linux-asset-runtime-check.txt`. Manifest
verification used the persisted native Linux inventory, as disclosed in
`asset-inventory-validation.txt`. No persisted deployment directory is claimed.

# 12. Hostinger environment

**BLOCKED.** No explicitly intended isolated Hostinger staging URL, authenticated
access method, release directory or database identity was supplied. No Hostinger
connection, provisioning, deployment or runtime measurement was attempted.
Published hosting documentation is not runtime evidence.

# 13. PHP/extensions/runtime

**BLOCKED externally.** CLI/web PHP version and SAPI, required extensions,
memory/execution/input/post limits, timezone, OPcache, process restrictions and
dump tooling remain unmeasured. The lock/root manifest requires PHP `^8.4`;
use the PHP 8.4 handoff baseline rather than older illustrative runbook versions.
Extension acceptance must use `composer check-platform-reqs --no-dev` on the
actual production installation plus PDO MySQL, XML, mbstring, curl, Zip and
the configured integrations. No missing Hostinger extension is alleged without access.

# 14. External database

**BLOCKED.** Server/client version, SQL mode, InnoDB behavior, charset/collation,
timezone, permissions and connection limits are unmeasured. Require an isolated
database for this company and a distinct disposable restore/upgrade target.
No database password or customer database was inspected or changed.

# 15. Fresh migration

**BLOCKED externally.** Production migration count/status and setup were not
measured. Follow the canonical fresh-install path in `PRODUCTION_RUNBOOK.md`:
fresh empty DB, `migrate --seed --force`, then `app:setup-company`. Verify the
first Manager, safe second attempt, installer lock, reference-only seed data
and session/cache/jobs tables. No reset command was run on retained data.

# 16. Upgrade rehearsal

**BLOCKED.** No isolated external prior-schema/data copy was available. Retained
identity, project uniqueness, workflow intents, versions/provenance and status
normalization need the maintained upgrade checks and an external rehearsal.
Existing internal upgrade evidence is not relabeled as hosted acceptance.

# 17. Filesystem/document root

**BLOCKED externally.** Actual paths/document root and direct requests for source,
.env, .git, logs and private storage were not tested. Source deployment procedure
points public_html at the release's public directory. Its checks are procedures,
not proof of the actual server's response behavior.

# 18. Permissions/storage/symlink

**BLOCKED on Hostinger.** PHP write access to logs/cache/views/sessions/exports,
shared storage, bootstrap/cache and actual storage:link behavior are untested.
Linux synthetic dependency-link packaging passed; it does not prove Hostinger
symlink permissions or PHP/cron ownership. Do not use 0777 as a workaround.

# 19. Root/subdirectory behavior

**BLOCKED externally.** Assets, forms, redirects, login/logout, notification
targets, manifest and worker scope were not exercised on an external root or
mount. Earlier root/mounted browser coverage is internal evidence only.

# 20. HTTPS

**BLOCKED.** No target certificate, HTTP redirect, mixed-content or canonical-URL
network evidence exists in this phase.

# 21. Sessions/cookies/CSRF

**BLOCKED externally.** Secure/SameSite cookies, session rotation, CSRF, logout
denial, User A/B switch, private-cache isolation and old-tab revalidation require
real sessions on the staging domain. Local middleware/configuration is not hosted proof.

# 22. Security headers

**BLOCKED.** Actual CSP, frame protection, content-type options, referrer policy,
HSTS and authenticated cache-control were not measured from a staging response.

# 23. Real cron

**BLOCKED.** No cron entry was created and no provider invocation was observed.
The intended entry invokes site-specific `hostinger-cron.sh` once per minute
with an absolute path and private log capture. Repeated real execution and exit
statuses over several intervals remain mandatory.

# 24. Scheduler jobs

Source defines six schedules: required workflow recovery every minute; deadline
reminders at 08:00 company time; overdue notices hourly; DB backup at 01:30;
backup clean at 02:30; backup monitor at 03:00. The cron script uses
`/opt/alt/php84/usr/bin/php` and a shared runtime flock. **BLOCKED:** actual
schedule:list comparison, timezone and execution on the host. No scheduler was run.

# 25. Queue/recovery

Database queue architecture is retained. The existing cron script invokes
schedule:run followed by a bounded database worker on `notifications,default`
with stop-when-empty/max-time 50/timeout 60/tries 3. **BLOCKED:** real queue
consumption, failure retention/retry, lock behavior and backlog runtime under
the actual process/cron constraints. No Redis or daemon requirement was introduced.

# 26. Required notification recovery

**BLOCKED externally.** No controlled failure/pending-intent experiment was run.
Need real scheduler recovery, one deduplicated authorized in-app notice, correct
historical/current semantics, pending clearance and bounded backlog processing.
Required intent recovery is separate from optional mail/push queue consumption.

# 27. SMTP

**BLOCKED.** No staging mail provider or controlled recipient was supplied.
Acceptance, receipt, safe failure behavior and core transaction coherence were
not tested. No email was sent. Log-mail output is not delivery qualification.

# 28. Browser push

**BLOCKED if enabled/advertised.** No external VAPID configuration or controlled
real device was supplied. Subscription, permission, actual receipt/click and
invalid/revoked-subscription behavior are untested. Keep this optional feature
disabled until its intended deployment is qualified; no private key was captured.

# 29. Sentry/monitoring

**BLOCKED if intended.** No configured staging project/receiver was supplied.
Harmless exception receipt, candidate release label, environment, stack and
secret-redaction evidence remain pending. General operator DB/queue/cron/backup
health visibility is also unqualified. No monitoring platform was added.

# 30. Offsite backup

**BLOCKED.** No staging backup or remote archive was created. Source supports
configured backup disks (default s3), password-controlled encryption and
retention. This does not prove object-store access, encryption, integrity,
timestamp/size, cleanup or actual offsite presence.

# 31. Backup contents/security

**BLOCKED externally.** Scheduled backup is `backup:run --only-db`; define and
verify recovery of any required durable files separately. The source full-file
backup configuration is not evidence that it ran. Confirm archive inventory,
encryption and retention privately. Escrow the original APP_KEY and environment
recovery material securely; do not rotate an established key or print it in evidence.

# 32. Actual restore

**BLOCKED.** No real offsite staging backup was restored to a new directory and
new DB. Login, accounts/memberships, tasks, submission/revision/approval history,
notifications, analytics, files, sessions/security stamps, queue/scheduler schema
and new authorized writes remain unverified on a restored copy.

# 33. RPO/reconciliation

Source schedules a daily backup: nominal maximum snapshot age is 24 hours when
every backup succeeds. Actual RPO is unproven and can grow during failures.
After a restore, previously delivered external mail/push cannot be recalled;
quiesce delivery, reconcile restored generations/current authority against known
delivery history, check pending jobs/intents and communicate changed current
truth before controlled resumption. RTO and reconciliation are not rehearsed.

# 34. Deployment procedure

Source procedure: tracked source plus locked no-dev vendor and exact Vite assets;
validate private server configuration/DB; acquire deployment/cron runtime lock;
back up; enter maintenance; migrate/reference seed; link storage; build caches;
restart queue; switch current atomically; resume and smoke HTTPS/source isolation.
Failures after schema/publication retain maintenance for review; old releases
and private state are retained. **BLOCKED:** actual complete package, external
ordering and partial-deployment rehearsal. No live rollout is authorized by this report.

# 35. Cache/restart recovery

**BLOCKED.** No external config/route/view cache build, DB reconnect, same-artifact
redeployment or owned worker/cron interruption/recovery was executed. Shell
syntax does not establish successful production boot or stale-config exclusion.

# 36. Rollback rehearsal

**BLOCKED.** Code/schema compatibility and isolated restore-based rollback need
an actual rehearsal with representative writes. Never infer reversibility from
disposable migration reset or run migrate:rollback casually on company data.
The preserved previous release is a recovery input, not a proven rollback.

# 37. Chromium

**BLOCKED for this external candidate/environment.** No browser run was executed
in this phase. Earlier R6.1 Chromium evidence is internal and separately dated/scoped.

# 38. Firefox

**BLOCKED.** No supported-host Linux or external Firefox run was executed.
The historical Windows launch limitation remains an unavailable gate, not a pass.

# 39. Safari/WebKit

**BLOCKED externally.** No Safari or new WebKit run was executed. Earlier
Playwright WebKit evidence does not certify physical Safari or this staging target.

# 40. Android

**BLOCKED.** No physical Android Chrome workflow, keyboard/dialog, reconnect,
installation or notification checks were executed.

# 41. iOS/iPadOS

**BLOCKED.** No genuine iPhone/iPad Safari, Home Screen app or push acceptance
was executed. Emulated viewport coverage is not substituted.

# 42. PWA install

**BLOCKED.** Real installation/standalone launch, authentication, offline static
fallback, reconnect, logout/account switch and version update were not exercised
on an installed external app. Source manifest/worker presence is not install evidence.

# 43. External company workflow

**BLOCKED.** No external normal-HTTP/UI setup, Manager/PM/member onboarding,
project/membership/task creation, start/hold/resume, submit/review/revision/
resubmit/approval/reopen, deadline/reviewer/PM changes, continuity guard,
reports/notices/timeline or logout/login chain was executed.

# 44. R6.1 external regression

**BLOCKED.** Fresh authority/session handling, continuity warnings, recovered
historical notice coherence, inactive contributor history, review-generation
timestamp reset and bounded roster/search require practical external workflows.
The six closed internal findings were not reopened without new behavior evidence.
The CI indentation defect is a separate qualification-infrastructure finding.

# 45. External performance

**BLOCKED.** No external TTFB/navigation/PHP timings or memory measurements exist
for dashboard/tasks/projects/analytics/notices/team/timeline or normal writes,
handover and recovery. Vite build time is not application response-time evidence.

# 46. Shared-host limits

**BLOCKED.** Request, process, DB connection, disk and cron limits are unknown.
The measured historical 22.5-minute 25k handover must not be assumed to fit a web
request. Assess ordinary company scale first; plan supported operational CLI
handover where needed for extreme data volumes. No external capacity envelope is claimed.

# 47. Staging soak

**BLOCKED.** External observation duration: **zero**. No soak samples or recurring
job/session/backlog/log-growth observations were taken. Existing staging soak
script passed bash syntax only. Use it with authenticated workflows and private
operator metrics; /up alone is insufficient.

# 48. Public exposure/security

**BLOCKED externally.** No outside-host probes for .env/.git/logs/DBs/backups/
storage-private/source/tests/evidence, or harmless 404/403/422/server errors,
were performed. No production debug/secret-leakage claim is made from source alone.

# 49. External findings

| ID | Severity | Evidence | Disposition |
|---|---|---|---|
| R45-001 | P1 qualification blocker | Original CI YAML parser exit 1; block sequence/implicit map errors at line 272 | FIXED locally in 05e61627dc4637c0afcf513c9ec3ba31f84ba3d4; native Linux YAML and every shell block pass; actual CI execution pending |

The dense-roster step was indented into an invalid mapping following the large
browser run block. Repair changes indentation only and restores it as its own
browser step. No production business behavior or dependency changed.
Missing systems/devices are access limitations, not defect findings. The
Windows-npm attempt and failed temporary-directory revisit are harness/environment
limitations, preserved separately from the final successful Linux checks.

# 50. Acceptance matrix

| Gate | Result | Evidence / exact missing requirement | Blocker? |
|---|---|---|---|
| Exact candidate SHA | PASS | candidate.txt; linux-candidate.txt | No |
| Runtime source equivalence | PASS | runtime-tree-equivalence exit 0; CI delta disclosed | No |
| Clean Linux checkout | PASS | native detached clone; no inherited runtime state | No |
| Linux frontend/build/audits | PASS | individual final linux-* logs/exits | No |
| Linux workflow/package-boundary checks | PASS | YAML/shell/link/content final logs | No |
| Complete clean Linux qualification | BLOCKED | native PHP 8.4/Composer, DB, browser runtime and complete job evidence | Yes |
| Linux SQLite/MariaDB/races | BLOCKED | no executed suites or race groups | Yes |
| Linux resource/roster/25k gates | BLOCKED | no PHP/DB capacity execution | Yes |
| Complete release artifact/smoke | BLOCKED | no production vendor/archive/checksum/artisan smoke | Yes |
| Hostinger runtime/extensions | BLOCKED | isolated host access/runtime evidence absent | Yes |
| Fresh migration/company setup | BLOCKED | fresh external DB absent | Yes |
| Upgrade rehearsal | BLOCKED | isolated external prior-data copy absent | Yes |
| Filesystem/permissions/symlinks | BLOCKED | actual host behavior absent | Yes |
| Root/mounted deployment | BLOCKED | external route/worker evidence absent | Yes |
| HTTPS/session/headers | BLOCKED | actual domain/browser/network evidence absent | Yes |
| Real cron/scheduler | BLOCKED | no repeated provider execution | Yes |
| Queue/backlog/recovery | BLOCKED | no real bounded worker/backlog observation | Yes |
| Required notice recovery | BLOCKED | no controlled hosted failure/recovery | Yes |
| SMTP | BLOCKED | provider and controlled recipient absent | Yes |
| Real push | BLOCKED | configuration/device absent | If enabled/advertised |
| Sentry | BLOCKED | intended project/receiver not identified | If intended |
| Operator health visibility | BLOCKED | hosted app/DB/cron/queue/backup evidence absent | Yes |
| Offsite backup/contents/security | BLOCKED | no remote encrypted recovery artifact | Yes |
| Actual restore/RPO reconciliation | BLOCKED | independent restore target and rehearsal absent | Yes |
| Deployment/cache/restart | BLOCKED | no external package/deployment/recovery | Yes |
| Rollback rehearsal | BLOCKED | schema-compatible rollback/restore evidence absent | Yes |
| Chromium | BLOCKED | external browser execution absent | Yes |
| Firefox | BLOCKED | supported-host execution absent | Yes |
| Safari/WebKit | BLOCKED | external execution absent | Required accessible coverage pending |
| Android | BLOCKED | genuine device evidence absent | Yes |
| iOS/iPadOS | BLOCKED | genuine platform evidence absent | Coverage pending |
| PWA install | BLOCKED | installed-device evidence absent | Yes |
| External workflow/R6.1 regression | BLOCKED | normal hosted UI workflows absent | Yes |
| Performance/shared-host fit | BLOCKED | normal-operation host measurements absent | Yes |
| Soak | BLOCKED | no staging observation | Yes |
| Public exposure/error mode | BLOCKED | actual external response evidence absent | Yes |

# 51. Remaining limitations

Native Linux PHP/Composer/database/browser acceptance facilities are missing;
available WSL tooling qualified a subset only. The final npm run uses Node 26/npm
11 and leaves esbuild postinstall unexecuted under its script policy; Node 20 CI
still needs complete execution. The temporary Linux build is not a retained release.

No explicitly intended isolated Hostinger target, fresh/upgrade/restore DBs,
service/recipient configuration, physical devices or meaningful observation
window was supplied. These are the exact reasons hosted/transport/recovery/device
gates remain blocked. Historical R6.1 stress timing is disclosed in sections 9/46.

# 52. Production release recommendation

| Decision | Answer |
|---|---|
| Is Task Management v1.0 production qualified? | **NO** |
| Can the first paying customer be onboarded as production-qualified? | **NO** |
| Is Hostinger qualified? | **NO** |
| Is backup restoration proven externally? | **NO** |
| Is rollback proven externally? | **NO** |
| What browsers/devices are actually qualified by this external phase? | **None** |

Retain the exact candidate. Complete Linux PHP/database/race/capacity/browser
gates and assemble/smoke a checksummed production package. Then use only an
explicit isolated staging site and separate DB/restore targets for the matrix.
Obtain real service receipt, recurring-job operation, recovery, device and soak
evidence before recommending a live release. Do not push main to seek CI without
first checking the deployment switch: existing CI can deploy on a main push.
No production tag or live rollout commands are recommended while acceptance is blocked.

# 53. Final Git state

Local repair commit: `05e61627dc4637c0afcf513c9ec3ba31f84ba3d4`.
The report-only completion commit preserves that candidate's CI/runtime tree;
its full HEAD and final status are recorded after commit in `final-head.txt`,
`final-status.txt`, `final-staged.txt`, and `final-candidate-diff.txt`.
Existing untracked materials and new private evidence are preserved.

Starting remotes: origin `https://github.com/AsalPandey/task-management-final.git`;
production `https://github.com/AsalPandey/task-management-mvp.git`. Read-only
ls-remote observed origin default HEAD `2fbfd96be23b4642ace37040a5c859401c2a13fa`
and production main/default HEAD `305540397175dc0fde163d8511c52d93605c1015`.
Neither observed HEAD is this local candidate; no remote run is claimed.
No tags were present locally and none were created. No remote history was changed.

# 54. Deployment state

**No push, release tag, live deployment, external staging deployment, remote
merge, customer environment/database change, cron provisioning, mail/push send,
backup cleanup, restore or rollback occurred.** One company remains one isolated
Laravel application and database. The production environment is not qualified.
