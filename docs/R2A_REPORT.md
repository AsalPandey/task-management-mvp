# Phase R2A security qualification

Date: 16 September 2026. This report covers the accepted working tree, not a release
commit. Nothing was staged, committed, pushed or deployed. R2B was not started.

## Recovery and starting state

- Repository: `C:/xampp/htdocs/Task Management/task-management`.
- Branch: `phase-2-task-architecture`.
- HEAD: `00de68f8198f9cb4054aaeb3bd2f89ed3af95595`.
- Initial index empty; 27 modified tracked files and 28 untracked files.
- Initial tracked diff: 979 additions / 169 deletions. User work and R1 were already mixed.
- PHP 8.4.20; Laravel 12.64.0; SQLite 3.51.3 through PDO; available MariaDB 10.4.32.
- Configured application drivers are MySQL and database sessions. The configured
  application database was never connected to or migrated during R2A.

The recovery directory is `C:/xampp/htdocs/Task Management/r2a-safety-20260916`.
It contains a complete 22,188-file copy (including ignored and untracked files,
excluding `.git`), binary/full-index staged and unstaged patches, HEAD/branch,
index and untracked inventories, and initial Git status/statistics. Robocopy reported
zero failed or mismatched files. On resumption all 22,188 files and all 28 originally
untracked files were present. The original patch passes a reverse application check
against that copy. No snapshot restoration was needed. Treat this local copy as
private: it includes the original local environment file. It is not a deployment artifact.

The interruption left complete source files. PHP parsing, focused regressions and
JavaScript checks found no truncated files. The unfinished work was final qualification
and the push control's handling of the CSRF token after a password change.

## Initial validation

| Gate | Actual pre-implementation result |
|---|---|
| SQLite full suite | 362 passed, 17 MariaDB skips, 2,659 assertions |
| R1 focused | 83 passed, 384 assertions |
| Composer validation | Valid |
| Composer audit | 13 advisories / 3 production packages |
| npm audit | 4 development packages: 2 high, 1 moderate, 1 low |
| npm production audit | Zero vulnerabilities |
| Normal source Pint | Passed |
| Vite production build | Passed, 56 modules |
| Git whitespace check | Passed; existing line-ending warnings only |

An initial overly broad Pint invocation included generated `bootstrap/cache` files;
the corrected gate covers app/config/database/routes/tests and the two bootstrap
source files. Historical audit probes and generated caches are not production source.

## Objective-by-objective disposition

| Objective | Root cause and reproduction | Remediation | Final status |
|---|---|---|---|
| A Dependencies | Fresh registry audit reproduced vulnerable installed locks | Three exact compatible Composer updates; eight narrowly related npm updates | Fixed |
| B Account/email policy | `verified` middleware had no effect because User does not implement MustVerifyEmail; public registration is disabled and managers provision accounts | Explicit internal provisioning policy; removed misleading access gate; optional signed verification remains metadata, with accurate page copy | Fixed |
| C Password sessions | Existing database sessions authenticated by user ID after password change | Central stamp/fingerprint validation; password reset/admin change revoke all sessions; self-service preserves only current rotated session | Fixed |
| D Reactivation | An inactive-state check alone allowed a dormant session to return after reactivation | Every Eloquent active-state change rotates stamp and remember token atomically with the user update | Fixed |
| E Role/permissions | No durable session invalidation on role change | Role changes rotate stamp; fingerprint also includes current permission IDs; fresh server policy remains authoritative | Fixed |
| F Notification privacy | Relationship recipients lacked current authorization; stored notification payload remained readable after access loss | Current task/project policy at dispatch, serialization and queued push delivery; generic redaction without deleting history | Fixed |
| G Member/API privacy | Raw User models exposed preferences, email, timestamps and relation metadata | Explicit roster/account/self allow-lists; project mutation responses minimize nested users too | Fixed |
| H Application controls | Missing headers and throttles confirmed by failing HTTP tests | Security headers, authenticated no-store responses, bounded sensitive routes; existing login throttle retained | Fixed for R2A; CSP/environment hardening deferred |
| I CSRF integration | Rotated CSRF token left settings and push JavaScript using cached old token | JSON returns current token; settings updates meta and hidden tokens; push reads current token at request time | Fixed |

No R2A blocker is intentionally deferred. CSP and infrastructure configuration are
explicit R3/deployment work, not silently disabled security checks.

## Session architecture and CSRF

`AccountSessionSecurity` rotates a random UUID stamp and remember token in the same
Eloquent user UPDATE as password/email/role/active changes. It does not replace R1
locking or the lifecycle service. A login stores an HMAC fingerprint of the stamp,
credential hash, account state, role and current permission IDs in the session.
`EnsureCurrentAccountSession` compares fresh account data on every session-authenticated
web request. Missing fingerprints require a fresh login after rollout. Database session
rows may remain until garbage collection, but cannot authorize a stale request.

Self-service password changes regenerate the session ID and CSRF token, clear password
confirmation state and bind the current session to the new fingerprint. Ordinary
non-security profile edits do not rotate state unnecessarily. Other browsers, password
broker resets, administrative password changes, role changes and active-state changes
require fresh authentication. No plaintext credential is placed in a session or model.

The CSRF regression uses actual database-backed independent cookie sessions and turns
CSRF enforcement on. It proves: password succeeds; session ID/token change; the old
token returns 419; the refreshed token succeeds for preferences and another profile
mutation; the old second session is rejected. A Node DOM harness executes the settings
handler and push handler to prove their next request carries the refreshed token.
CSRF enforcement and security-stamp revocation remain enabled.

## Notification and user serialization rules

Stored notification IDs, timestamps, read state and original database data are retained.
When a recipient loses current task/project access, serialized content becomes a generic
unavailable message without protected names, text or deep links. Management-only excerpts
are also removed when management-note permission is lost even if task access remains.
New unauthorized dispatches and queued push are suppressed. Already delivered device
notifications cannot be recalled. Mark-read remains owner-scoped, and task detail/timeline
routes still enforce current policies. There is no separate raw notification-data HTTP API.

| Interface | Returned user fields |
|---|---|
| Project members, add/remove-member responses, nested project users | id, name, active, role id/name |
| Manager account create/update response | Roster fields plus email and role_id |
| Own profile JSON | Account fields plus own timezone/preferences |
| Task detail | Existing TaskViewData id/name projections |
| Task/dashboard candidate JavaScript | Existing project-scoped candidate projections retained |
| Push status/subscription JSON | Device counts/status/public VAPID key; no raw User model |

The unused SettingsController update method also uses the self allow-list. Views using
models server-side were inventoried separately from JSON serialization. No notification
preference enforcement or roster/workflow authorization redesign was added.

## Dependency evidence

| Production package | Before | After | Dependency path / exposure |
|---|---|---|---|
| guzzlehttp/guzzle | 7.15.1 | 7.15.2 | Laravel `^7.8.2`, AWS `^7.4.5`, Pusher `^7.2`; application Web Push transport directly constructs a Guzzle client. R1 destination controls remain in place. |
| league/commonmark | 2.8.2 | 2.10.0 | Laravel `^2.8.1`; framework Markdown/mail infrastructure. No application endpoint accepting arbitrary Markdown or enabling the affected optional extensions was found. |
| paragonie/sodium_compat | 2.5.0 | 2.5.1 | Pusher `^1.6\|^2.0`; crypto compatibility library. Native sodium is absent locally. No application Ed25519 verification endpoint was found; Pusher encrypted-channel code uses secretbox. |

Exploitability of every upstream advisory was not asserted. All packages were patched;
no reachability exception or advisory suppression was used. Composer dry run and semantic
lock comparison showed exactly these three package changes. Pre-existing Symfony 3.7.1
deprecation-contracts and author-encoding changes in the dirty lockfile were preserved.

The original advisory IDs were:

- Guzzle: PKSA-gcrk-3vtt-1r14 (GHSA-v5mv-p594-2x33), PKSA-cnw1-2ytm-cgr8 (GHSA-f7vp-7xgx-4w4r).
- CommonMark: PKSA-zyf5-hrxv-hrd7, PKSA-nv44-1b4d-6gjg, PKSA-kr3s-894t-g5w2,
  PKSA-9q1p-3s19-bp1q, PKSA-5mzr-szzf-z6cn, PKSA-cqd6-fg4n-nxpf,
  PKSA-1q6p-sqkj-8mmj, PKSA-mc58-w91n-f5gv, PKSA-t21r-vtr5-3mdz, PKSA-scnn-p8mm-jbft.
- sodium_compat: PKSA-32g2-byr9-drtw (Ed25519 public-key validation).

The four npm advisories also had compatible fixes. Changes were limited to:

| npm package | Before | After |
|---|---|---|
| baseline-browser-mapping | 2.10.43 | 2.11.24 |
| browserslist | 4.28.6 | 4.29.0 |
| nanoid | 3.3.16 | 3.3.19 |
| postcss-selector-parser | 6.1.2 | 6.1.4 |
| caniuse-lite | 1.0.30001806 | 1.0.30001810 |
| electron-to-chromium | 1.5.393 | 1.5.430 |
| node-releases | 2.0.51 | 2.0.55 |
| update-browserslist-db | 1.2.3 | 1.3.3 |

The last four are browser-data/update dependencies of the targeted Browserslist fix.
Laravel 12.64.0, Vite 6.4.3 and both existing Tailwind package versions remain unchanged.
Root Composer/npm manifests were not changed. Final Composer, npm-all and npm-production
audits each report zero advisories/vulnerabilities.

## Final qualification

| Gate | Final result |
|---|---|
| SQLite full Laravel suite | 385 passed, 18 expected MariaDB-only skips; 2,793 assertions |
| Focused R2A HTTP/security suite | 23 passed; 134 assertions |
| Focused R1 suite | 83 passed; 384 assertions |
| MariaDB full Laravel suite | 403 passed, zero skips; 3,114 assertions |
| All concurrency tests, separately rerun | 18 passed; 321 assertions (17 existing plus 1 R2A) |
| Six R1 lifecycle races | Passed in full and separate concurrency runs |
| SQLite migrations | Fresh, additive upgrade, rollback and reapplication passed |
| MariaDB migrations | Fresh, additive upgrade, rollback and reapplication passed |
| Composer validate / audit | Valid; zero advisories, no abandoned packages |
| npm audit including development | Zero vulnerabilities |
| npm audit --omit=dev | Zero vulnerabilities |
| Normal source Pint | Passed |
| Vite build | Passed; Vite 6.4.3, 56 modules |
| JavaScript syntax | phase3.js, pwa.js and CSRF harness passed |
| JavaScript integration harness | Settings and push requests both use the rotated CSRF token |
| Changed PHP syntax checks | All passed; no partial/truncated files |
| Git whitespace / secret/debug/source-artifact scans | Passed; no secret/debug/generated database artifact in R2A source delta |
| Real local environment | Byte-for-byte unchanged |

Final MariaDB runs used `127.0.0.1:33317`, verified by `SELECT VERSION(), @@datadir`
as MariaDB 10.4.32 with data directory `C:/xampp/htdocs/Task Management/r2a-qa-mariadb-20260916`.
The test databases were `task_management_phase28_r2a_20260916` and
`task_management_phase28_r2a_20260916_migration`. The server had been stopped by the
interruption; it was restarted using only that disposable directory. After qualification,
both QA databases were dropped, that server was shut down, and its verified data directory
was removed. They contained disposable fixtures only and are not retained for recovery.
JUnit and dependency evidence remain in the recovery directory; the original snapshot
was preserved. No production/shared database or configured application database was used.

Browser behavior was qualified with independent database-backed cookie sessions through
the HTTP kernel and a Node DOM harness; this is not a claim of real-device/browser
production qualification.

## R1 preservation and adversarial evidence

All 83 focused R1 regressions pass. The full suite additionally exercises workflow
transition locking and related idempotency coverage. The following match the recovery
snapshot byte-for-byte: AccountLifecycleService, ProjectManagerReplacementService,
TaskAssignmentCandidateService, WebPushDestinationValidator, MinishlinkBrowserPushTransport,
TaskLifecycleService, TasksController, R1LifecycleMariaDbConcurrencyTest and its worker.

| Adversarial attempt | Observed behavior |
|---|---|
| Self-service, admin or broker password change with old session | Old session rejected; appropriate current self-service session remains usable |
| Dormant deactivate/reactivate session; attempted access while inactive | Old session remains rejected; fresh login works |
| Role downgrade and permission removal | Existing browser rejected |
| Old remember-me cookie | Cannot authenticate after active-state cycle |
| Pre-hardening session without fingerprint | Requires reauthentication |
| Old CSRF token after password change | 419 with CSRF enforcement enabled |
| New CSRF token and legitimate follow-up write | Successful; settings/push handlers send refreshed token |
| Former creator notification dispatch | No protected notification delivered |
| Lost task/project access, HTML and JSON serialization | Protected content and links redacted; stored history unchanged |
| Lost management-note rights while retaining task access | Management excerpts redacted |
| Another user's notification ID | Cannot mark that user's row read |
| Queued push after ownership change | Stops before destination validation/transport with an enabled subscription present |
| Crafted member/account requests | Team member cannot mutate management account; roster JSON contains only approved fields |
| Export/password confirmation flooding | 429 at configured limit |
| Out-of-project assignment, last manager lifecycle, unsafe push/rebinding/pinning | Existing R1 rejection and concurrency tests remain green |

## Git classification and checkpoint recommendation

`hunk-file-classification.csv` in the recovery directory lists every tracked/untracked
source file with before/after SHA-256, whether R2A changed it and its provenance class.
`r2a-only.patch` is a binary/full-index diff against the accepted recovery tree, normalized
to repository-relative paths. Its forward application to the snapshot and reverse
application to the final working tree are checked without modifying either tree.

Exact R2A hunks are now identifiable. Pre-R2A user-vs-R1 ownership remains unresolved
where it was already mixed; it was not guessed. Six files contain both an accepted
pre-R2A delta and new R2A changes: ProfileController, ProjectsController,
TeamManagementController, SendBrowserPushNotification, AppServiceProvider and composer.lock.
Their pre-R2A content is preserved. New R2A classes/tests/docs are R2A-only. Previously
clean files modified by R2A have no pre-R2A ownership ambiguity. All other initial
dirty files, R1 additions and historical audit artifacts remain unchanged.

A read-only `git apply --cached --check` against HEAD/index fails at the mixed-file
contexts: ProfileController import block; ProjectsController import block;
TeamManagementController import block; SendBrowserPushNotification imports;
AppServiceProvider imports; composer.lock's Guzzle entry. This is expected because HEAD
does not contain accepted R1. Applying only R2A on HEAD would not checkpoint the tested
functional baseline. No attempt was made to stage or rewrite those contexts.

Recommended procedure: retain this reviewed R2A patch and snapshot now. Before a Git
commit, explicitly authorize how the accepted mixed pre-R2A baseline should enter history.
Once that exact baseline is represented in history, apply/check the R2A patch, stage an
explicit path list or the reviewed patch, inspect `git diff --cached`, and validate the
candidate commit. Do not claim an R1-only reconstruction or commit only the easy-to-stage
R2A files. An alternative combined-baseline checkpoint requires explicit user approval.

Tests/support runners are intentional source; JUnit files, dependency evidence, patches,
snapshot, and disposable database files are local QA artifacts outside the repository.
Generated build/cache files remain ignored. The real `.env` is unchanged.

## Files changed by R2A

Paths below are relative to the Laravel repository. All added hunks relative to the
recovery snapshot belong to R2A; the snapshot and CSV retain the earlier provenance boundary.

| File | R2A change / category |
|---|---|
| app/Http/Controllers/Auth/PasswordController.php | Preserve current session after password change; R2A |
| app/Http/Controllers/ProfileController.php | Security-state rotation, CSRF response, self allow-list; mixed |
| app/Http/Controllers/ProjectsController.php | Member/project JSON allow-lists; mixed |
| app/Http/Controllers/SettingsController.php | Self JSON allow-list; R2A |
| app/Http/Controllers/TeamManagementController.php | Manager JSON allow-list; mixed |
| app/Http/Middleware/EnsureCurrentAccountSession.php | New session fingerprint enforcement |
| app/Http/Middleware/SecurityHeaders.php | New response security headers |
| app/Jobs/SendBrowserPushNotification.php | Current task authorization before delivery; mixed |
| app/Models/AuthorizedDatabaseNotification.php | New current-access serialization |
| app/Models/User.php | Notification relation and hidden security stamp; R2A |
| app/Providers/AppServiceProvider.php | Login/user-update/notification listeners; mixed |
| app/Services/AccountSessionSecurity.php | New centralized stamp/fingerprint logic |
| app/Services/NotificationAccess.php | New task/project notification access policy |
| app/Services/TaskNotificationDispatcher.php | Filter unauthorized transition recipients; R2A |
| app/Support/UserPayload.php | New explicit JSON field allow-lists |
| bootstrap/app.php | Register session/header middleware; R2A |
| composer.lock | Three production security fixes; mixed |
| database/migrations/2026_09_16_000001_add_security_stamp_to_users.php | New additive migration |
| docs/R2A_SECURITY_MODEL.md | New security model and rollout notes |
| docs/R2A_REPORT.md | New qualification report |
| package-lock.json | Eight scoped security/browser-data updates; R2A |
| public/js/pwa.js | Read current CSRF token at request time; R2A |
| resources/views/auth/verify-email.blade.php | Accurate optional-verification copy; R2A |
| resources/views/settings.blade.php | Adopt rotated CSRF token; R2A |
| routes/auth.php | Active-account enforcement and sensitive-route throttles; R2A |
| routes/web.php | Internal-account policy and scoped throttles; R2A |
| tests/Feature/MobileUiModernizationTest.php | Create authorized task fixture; every existing assertion retained |
| tests/Feature/R2aAccountMariaDbConcurrencyTest.php | New genuine concurrent account-change regression |
| tests/Feature/R2aSecurityTest.php | New 23-test adversarial HTTP/session/privacy suite |
| tests/Support/r2a_account_security_worker.php | New guarded concurrency worker |
| tests/Support/r2a_migration_check.php | New guarded migration/upgrade/rollback runner |
| tests/Support/r2a_settings_csrf_check.cjs | New settings/push JavaScript CSRF integration harness |

## Remaining work outside R2A

R2B/R2C retain reminder rearming/recipients/preferences, deadline-stage semantics,
submission/reopen instructions, completed-reviewer recovery, stale task edits,
project membership transactions, PM execution and project closure, and null-UID upgrades.
R3 retains profile rendering, analytics/metrics/input validation, timezone integration,
timeline pagination/deleted-user display, performance, CDN/CSP, service-worker cache
ownership, push retry architecture and operational/browser release qualification.
No priority engine, dashboard redesign, deployment or shared-database work was performed.

Operational notes: apply the additive security-stamp migration before serving this code;
pre-existing sessions will require login. Use a shared cache for cross-worker rate limits.
Email verification remains optional; usable email and a configured mailer are required
for email-based password recovery. HTTPS/HSTS, proxy/host trust and secure cookie settings
must be qualified in the deployment environment. This phase is not production deployment sign-off.

PHASE R2A PASSED — SECURITY BASELINE HARDENED
