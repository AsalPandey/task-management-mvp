# R6.1 — Targeted authorization, responsibility and workflow repairs

Local internal qualification, 5 October 2026. Evidence: `output/r6-1-20261005/` and the separately named `output/playwright/r6-1-*` directories. Earlier R6 evidence and pre-existing untracked audits were preserved.

## 1. Executive summary

Six R6 findings were reproduced before implementation. Repairs retain the single-company Laravel/MariaDB architecture and existing workflow model. Global account writes now revalidate authority under locks; administrative changes protect unfinished assignments; delayed required notices separate their historical event from the task's current state; historical reports retain inactive contributors; resubmission clears the old review-start timestamp; and project/member selectors load bounded pages.

All six findings are closed by the final local qualification on implementation SHA `f6afe566a50b8709c8895473e65222adb2e5ad89`. SQLite, MariaDB, all twelve independent race groups, roster/resource/dense gates, browser workflows, static checks, dependency audits and production build passed. Explicit skips and external acceptance limitations are disclosed below. Final evidence uses the `release-` prefix; `qualified-`, `accepted-`, `final-` and preflight attempts are superseded qualification attempts, retained for diagnosis.

## 2. Final classification

**R6.1 PASSED — TARGETED AUTHORIZATION, RESPONSIBILITY AND WORKFLOW REPAIRS COMPLETE**

This is local internal qualification. External R4.5 has not begun.

## 3. Starting Git state

Started on `main` at `f4b70d5f125231e23a49621db7c57d0938ad5035`, with a clean tracked working tree and index. Starting HEAD, thirty commits, status, remotes, tags, runtime versions and route inventory are saved in `start-*`, `php.txt`, `laravel.txt`, `mariadb-version.txt`, `node.txt` and related evidence files. Existing untracked audits, probes and output were retained. No remote was changed.

## 4. R6 findings owned

| Finding | Severity | Original failure |
|---|---|---|
| R6-001 | P1 | A revoked Manager's in-flight global account update still committed |
| R6-002 | P2 | Promotion or PM replacement stranded an unfinished assignee |
| R6-003 | P2 | A delayed submission notice advertised review of a currently cancelled task |
| R6-004 | P2 | Deactivation removed completed contributors from the performance breakdown |
| R6-005 | P3 | Resubmission retained the previous review generation's start timestamp |
| R6-006 | P2 | Dense 1,000-person project membership exhausted a 128M PHP process |

## 5. RED reproduction

The original-behavior HTTP-kernel harness passed its ten defect reproductions with 480 assertions; the desired-behavior regression suite failed five cases before the repair. Two independent demotion races and two deactivation races reproduced stale-authority account writes. The 250/500/1,000 fixtures and fresh-process route profiles were captured before production edits. The 1,000-person Projects request exited 255 with an allocation failure.

Evidence: `red-workflows.txt/xml`, `red-regression-tests.txt`, `red-account-races.txt/json`, `red-company-*-fixture.json`, `red-{projects,tasks,manager}-*.json` and their exit records. Harness errors and superseded attempts remain separate from product findings and final qualification.

## 6. R6-001 root cause

Initial middleware/policy authorization used the request's earlier User object. Locking the target alone did not protect the acting Manager's authority against a concurrent role or active-status change.

## 7. Account-writer inventory

`TeamManagementController` create, update (name/email/role/password), activate, deactivate and destroy use `AccountAdministrationWriter`. Self-service account deletion also uses its canonical lock order and fresh-account check. Self profile/password/preferences routes do not grant global authority or alter assignment eligibility; their existing session-security tests remain in qualification. The empty-company installer has a separate bootstrap contract and no already-privileged actor.

## 8. Authoritative actor revalidation design

Inside the mutation transaction, reload the actor and target. Require a current active Manager for global administration, and require the persisted security stamp to match the request actor. Keep the actor lock through commit. Self-service deletion permits the active account itself and still requires its current stamp. Global self-administration remains forbidden in favor of the self-service routes.

## 9. Account lock order

Manager role sentinel → current Manager accounts plus actor/target in ascending account ID → dependent projects → tasks. Prelocking current Managers prevents the last-Manager check from acquiring lower account IDs afterward. Existing project/task writers retain accounts → project → task and do not acquire the sentinel afterward. Transactions retain the established bounded retry behavior.

## 10. R6-001 concurrency evidence

The permanent account race group pauses real HTTP-kernel writes after route binding or email validation, commits revocation in another process, then resumes the stale request. It repeats demotion/deactivation against update, activate, deactivate and delete twice each, and repeats account creation for both revocation types. Every stale attempt must return 403, preserve target attributes or absent account identity, and add no notices. A legitimate concurrent Manager-update pair must exhibit an actual InnoDB lock wait and both finish 200.

Final evidence: `release-R61AccountMariaDbConcurrencyTest.txt` — three tests, 147 assertions, zero failures/skips.

## 11. R6-002 root cause

The former role and ownership checks protected managed projects and reviewer duties but did not consistently preserve assignee execution eligibility. A retained assignment could become inaccessible after its account's role or project ownership changed.

## 12. Active assignee eligibility contract

An active Manager or Team Member can execute assigned work. An active PM can execute it when they own its project. Completed/cancelled work is final; every other machine state remains unfinished responsibility, including submitted/in-review work that may return for revision. Final assignments and provenance are retained without automatic reassignment.

## 13. Role-change preflight

A selective locking SQL existence query finds unfinished assignments that would lose the canonical execution rule under the proposed role. It rejects with 409 and the public `assignment_continuity` code. The UI displays a fixed actionable explanation: reassign or complete the work first. Role changes preserving owned-project execution remain allowed. The state matrix covers all eight machine states and checks unchanged assignee, version and history on rejection.

## 14. PM-replacement preflight

Evaluate the outgoing PM against proposed ownership while the authoritative accounts/project are locked. Reject replacement when unfinished assigned work would become inaccessible. Preserve final-task ownership/history. Reauthorize the locked project before returning a stale-owner refresh conflict, retaining 403 for a PM whose authority was revoked. Manager requests with obsolete ownership routing return a controlled 409 instead of acquiring an unplanned account lock after the project.

## 15. Responsibility race qualification

The new permanent process group exercises role change and PM replacement against reassignment, start and submit in both lock orders. Twelve schedules require real lock contention. Administrative changes reject while the responsibility exists; reassignment-first schedules allow the later administration. Accepted transitions increment exactly once and retain an eligible final assignee. Final result: one parameterized test, 104 assertions, zero failures/skips.

## 16. R6-003 root cause

Durable intents retained their original transition/action while delayed serialization read newer task state and stage details. Delivery identity remained correct, but its meaning could combine different workflow generations.

## 17. Required-notification semantic contract

Use existing intent transition, creation time and immutable task version as event identity. Same-version delivery can describe the current action. A later version produces an explicitly historical, non-actionable notice with the original event time/version, current status and a link to the current task. Later stage deadlines/feedback/private details are stripped. Benign edits conservatively make an old event historical rather than losing it. Existing current-recipient/access validation still decides whether delivery must be discarded.

## 18. Delayed-notice generation/relevance handling

`WorkflowNoticeSemantics` is shared by durable delivery and authorized reads of previously delivered notices. Submission followed by cancel/approve/newer resubmit, revision followed by resubmit/cancel, replaced deadlines and old review generations cannot advertise an obsolete action. A revoked former reviewer is discarded by current-recipient validation. Completion/reopen interpretation is historical, while optional completion notices retain their preference-controlled category and are not converted into required intents. The contract table is maintained in `docs/R61_REPAIR_CONTRACTS.md`.

## 19. Notification regression results

The maintained suites plus new tests cover submit/cancel, repeated revisions, deadline changes, holds/resumes, review generation replacement, approval/reopen interpretation, failure before/after delivery, retry after 5/60/420 minutes, competing consumers and exactly-once in-app identity. A full HTTP generation chain asserts original event identity, current status, non-actionability and absence of private reason markers. Process crash recovery and existing delivery/mutation race groups remain separate mandatory gates.

## 20. R6-004 root cause

The performance breakdown intersected report contributors with the current active roster. Deactivation therefore removed historical completed work from a breakdown even though aggregate completion totals retained it.

## 21. Historical report cohort design

Derive contributors from the authorized task creation cohort and join retained account identities, including soft-deleted accounts. Show live retained names with Active/Inactive/Removed status. Current active zero-work roster rows may remain but never filter historical contributors out. Project-manager scope comes from authorized tasks, not an unrestricted company employee-history query.

## 22. HTML/print/CSV reconciliation

HTML, print and CSV share the canonical performance results and account-status labels. The regression completes a task, records its CSV contribution, deactivates the assignee through the real controller, then verifies the retained contribution and inactive label. Existing cohort/date/scope/export-security tests remain mandatory. The browser checks the same inactive identity in all three formats.

## 23. R6-005 root cause

Resubmission changed state and submission metadata but retained `review_started_at` from the previous review generation.

## 24. Review-generation metadata repair

Resubmission clears current `review_started_at` and records old value → null in history/event changes. Starting the new review records the actual prior value. Past review-start history remains available, while the current row reports only the current generation. The regression uses distinct timestamps across review, revision and resubmission and checks the new start.

## 25. R6-006 root cause

Projects and task/dashboard selectors eagerly hydrated repeated project memberships and large account collections. Twelve dense projects multiplied account retrievals and HTML/embedded data as company membership grew.

## 26. Roster/member bounded-loading design

Projects renders five member previews per visible project plus total counts. Browsing returns twenty members/page. Candidate searches return twenty-five results plus at most one authorized selected identity. Assignment/reviewer/PM/filter selectors use lean payloads and escaped name search; responses contain no email/password/security stamp. Task/report filtering and PM authorization retain their existing scope. Membership mutation replies are bounded too.

PM default candidates remain operationally scoped; searching other active staff is limited to the existing authorized project-membership management action. Unrelated project candidate/member requests remain forbidden. Asynchronous selection is disabled while loading, and task/project submission waits before serializing disabled roster fields. Deterministic browser barriers cover reviewer selection and early task editing; ordinary single-flight/conflict/retry tests remain in the full browser gate.

## 27. 250/500/1000 roster before-after

| Employees | Route | Peak MiB before → after | Bytes before → after | User retrievals before → after | Final queries |
|---:|---|---|---|---|---:|
| 250 | /projects | 58 → 34 | 1,751,195 → 170,697 | 3155 → 89 | 19 |
| 250 | /tasks | 48 → 38 | 391,353 → 278,597 | 2081 → 356 | 28 |
| 250 | /manager | 46 → 34 | 198,193 → 104,814 | 1816 → 316 | 26 |
| 500 | /projects | 86 → 34 | 3,574,561 → 170,603 | 6405 → 89 | 19 |
| 500 | /tasks | 70 → 38 | 610,622 → 278,586 | 5331 → 356 | 28 |
| 500 | /manager | 66 → 36 | 395,113 → 104,814 | 4816 → 316 | 26 |
| 1000 | /projects | OOM → 38 | no response → 170,096 | not measured → 89 | 19 |
| 1000 | /tasks | 116 → 42 | 1,048,664 → 278,623 | 11831 → 356 | 28 |
| 1000 | /manager | 110 → 38 | 788,479 → 104,873 | 10816 → 316 | 26 |

Measurements use the same retained synthetic company fixtures and fresh 128M processes. Synthetic SQL fixture loading establishes scale; actual UI/controller tests qualify mutations. Timing is diagnostic under concurrent local qualification, not a throughput SLA.

## 28. Response-size before-after

The table above includes full response bytes and User retrievals, not only peak memory. The 1,000-person Projects response now completes below 512 KiB with 89 retrieved users. Tasks retrieves 356 users and Manager 316 instead of thousands of repeated identities. The permanent gate enforces ≤64 MiB peak, ≤512 KiB response, ≤800 User retrievals and ≤40 queries on Projects, Tasks, Manager, member paging and candidate search. It retains the unchanged 128M process limit.

## 29. SQLite

Final maintained suite: 617 passed, 74 explicitly skipped, 6,041 assertions. Schema-specific concurrency skips are expected here and are independently qualified below. The pre-existing excluded deep-audit test was not swept into the maintained suite. Evidence: `release-sqlite.txt` and exit record.

## 30. MariaDB

Final maintained suite on a new private MariaDB schema: 616 passed, 75 explicitly skipped, 6,038 assertions. Dedicated process tests are excluded from the ordinary schema so their committed fixtures cannot contaminate later transaction-based tests. Evidence: `release-full-mariadb.txt` and exit record. All named process groups run independently on new approved private schemas with zero skips.

## 31. Existing race groups

| Independent group | Passed | Assertions | Failed/skipped |
|---|---:|---:|---|
| R2aAccountMariaDbConcurrencyTest | 1 | 10 | 0 / 0 |
| TaskNotificationDeliveryMariaDbConcurrencyTest | 2 | 14 | 0 / 0 |
| BrowserPushMariaDbConcurrencyTest | 3 | 21 | 0 / 0 |
| TaskNotificationMutationMariaDbConcurrencyTest | 12 | 72 | 0 / 0 |
| R3A2MariaDbConcurrencyTest | 3 | 22 | 0 / 0 |
| R3A3MariaDbConcurrencyTest | 8 | 71 | 0 / 0 |
| ProjectMembershipMariaDbConcurrencyTest | 1 | 7 | 0 / 0 |
| R43NotificationMariaDbConcurrencyTest | 2 | 21 | 0 / 0 |
| R51ProjectWriterMariaDbConcurrencyTest | 9 | 97 | 0 / 0 |
| R52EligibilityMariaDbConcurrencyTest | 12 | 92 | 0 / 0 |
| R61AccountMariaDbConcurrencyTest | 3 | 147 | 0 / 0 |
| R61ResponsibilityMariaDbConcurrencyTest | 1 | 104 | 0 / 0 |

Each group used the dedicated private server on port 3371 and a new schema matching its existing guard. On Windows, crash recovery required permission to terminate its own disposable PHP child process; the permission-enabled rerun passed. No customer's server/schema was used. The dedicated server's cumulative counter recorded four deadlocks and zero lock timeouts across all attempts. Its latest deadlock was in the retained R3A.2 direct-service worker, which locks a target before the last-Manager aggregate; its existing three-attempt retry completed the expected outcome. This is not evidence of zero server-wide deadlocks. New R6.1 HTTP-writer tests use the canonical account order and passed their coordinated lock-wait/serialization cases. See `release-db-deadlock-metrics.txt`, `release-innodb-status-readable.txt` and the retained worker source.

## 32. New R6.1 race groups

Account group: three passed/147 assertions. Responsibility group: one passed/104 assertions. Each has zero skips/failures and maintained worker/barrier support. Both groups and the 250/500/1,000 roster gate are wired into `scripts/ci-mariadb-tests.sh`. Dense roster browser qualification is also wired into the existing browser CI job.

## 33. 10k resource qualification

| Tasks | Classification | Fresh processes | Largest peak MiB | Largest response bytes | Highest query count |
|---:|---|---:|---:|---:|---:|
| 100 | PASS | 22 | 38 | 311,419 | 412 |
| 1,000 | PASS | 22 | 40 | 322,234 | 3997 |
| 10,000 | PASS | 22 | 44 | 322,375 | 39997 |

Retained 100/1,000/10,000 task resource gates run at 128M with independent totals/scope oracles and the existing memory/query/hydration/response budgets. Separate 10,000-task browser qualification passed Manager pages, pagination, analytics/print/notifications, mobile member dashboard and PM search scope. Evidence: `release-resource-*.json` and `release-resource-browser.txt`.

The highest query counts above belong to the full CLI overdue-notification scan, not an interactive page request. Route-specific measurements and their unchanged budgets are recorded individually in each JSON result.

## 34. 25k administration qualification

PASS on 25,000 tasks; 18,750 unfinished tasks affected. Independent retained gate verifies unchanged final tasks, 18,750 reviewer histories/events, 56,250 required intents and zero unexpected pending notices.

| Operation | HTTP | Peak MiB | Wall ms | Transaction ms |
|---|---:|---:|---:|---:|
| role-demote | 409 | 28 | 17.46 | not applicable |
| pm-replace | 200 | 36 | 1,352,289.06 | 308161.32 |
| role-demote | 200 | 28 | 109.63 | 108.7 |

Evidence: `release-dense25000.json`, its zero exit record and `release-capacity-complete.txt`. PM replacement took about 22.5 minutes including synchronous delivery; about 5.1 minutes was transaction work. The role-demotion conflict before replacement and success afterward are expected.

Final read-only SQL reconciliation (`release-dense-provenance.txt`) recorded 25,000 tasks, 18,750 reassignment histories, 18,750 reassignment events, 37,500 delivered intents and 18,750 discarded intents. The latter were obsolete recipients rejected by current-access checks; no pending intents remained.

This gate enforces the existing 64 MiB peak budget at 128M, preserves final-state provenance, reconciles actual reviewer changes with histories/events/intents, and leaves zero unexpected pending required notices. Synchronous durable delivery is included in wall time; transaction work is reported separately. No capacity claim is inferred from a partial run.

## 35. Timeline regression

The maintained timeline/browser tests qualify bounded 100-row first pages, deterministic traversal beyond 100 events, legacy/sparse histories, structured completion/reopen events, actor labels, and role-based removal of private management references. The long revision-chain regression keeps one task identity through repeated cycles and verifies ordered history/event provenance. Final browser evidence is `release-browser-full.txt`, including passing R53 timeline and R54 completion/reopen/conflict tests.

## 36. Browser workflow

Final full browser run: **66 passed, 4 explicitly skipped, zero failures**, 22.8 minutes. Two resource cases skipped in the clean-company run passed separately on 10,000 tasks (2 passed/1 irrelevant clean-company search skipped). The dense roster case skipped in the full run passed separately (1 passed). The remaining Firefox Windows launch limitation was not executed. Evidence: `release-browser-full.txt`, `release-resource-browser.txt`, `release-large-browser.txt`, zero exit records and corresponding Playwright output directories.

The real-cookie browser gate starts an empty private production-like company and exercises onboarding, Team/project/membership creation, canonical task creation, approval/revision/deadlines, stale-edit conflict recovery, role confirmation, invalid input/transport recovery and single-flight writes. New targeted tests cover fresh-session revocation and blocked/then-resolved administration. Superseded failures are retained with their diagnosed cause; only final `release-` reruns support closure.

## 37. Large-roster browser result

The separate 1,000-person browser exercises five previews, twenty-row member paging, keyboard staff search, an off-initial-page assignee, real task creation, searchable task retrieval, and a real PM login. PM selectors return only the PM's permitted choices. Project and task-form axe checks exclude serious/critical violations. Screenshot evidence is retained with the browser output. Final committed-test rerun passed in 47.0 seconds. Evidence: `release-large-browser.txt` and `output/playwright/r6-1-20261005-large-release/r61-large-assignment.png`. Screenshot was visually inspected; it shows the created task assigned to Employee 999. Repeated qualification tasks are retained in this separate synthetic browser fixture; resource measurement used fresh `task_management_r6_company_1000` with the original 3,400 tasks.

## 38. Historical-report browser result

The targeted UI deactivates a completed contributor and retains their identity and inactive label in analytics HTML, print and downloaded CSV. This uses a real session and HTTP account mutation, with the backend cohort/export regressions as the numeric oracle.

## 39. Notification browser result

The browser opens a controlled recovered submission notice after cancellation, sees an explicit historical event and original-event time, finds no private cancellation marker, and follows its safe link to the current cancelled task. It does not advertise review as the current required action.

## 40. PWA/account-isolation regression

All maintained PWA/account-isolation cases passed in the final full browser log, including Chromium/WebKit root and mounted worker safety, unavailable push capability, failure/retry recovery and WebKit navigation cancellation. Earlier cross-tab/logout/stamp cases passed too. These use private servers on ports 8140/8141 and real browser cookies.

The maintained Chromium/WebKit root and mounted-path tests cover static-only caching, offline truth, lifecycle/mutation recovery, optional push capability failure, account switch, logout/session invalidation, old-tab redirection and current account stamps. No user A payload may survive into user B's session.

## 41. Accessibility

Final Chromium/WebKit smoke, core axe A/AA, keyboard/focus/landmark and responsive checks passed. Targeted responsibility warnings and dense project/task forms passed serious/critical axe checks. Dense staff selection used keyboard input and reached Employee 999 beyond the first page. Browser output and metrics are retained under the separately named release directories.

Existing high-confidence WCAG A/AA, keyboard, focus, landmarks, Unicode/contrast and responsive checks remain. New checks include the dependency warning, member search and loaded task form. Firefox's installed Windows binary has the previously documented side-by-side launch limitation; this is disclosed rather than counted as an executed pass. Clean Linux/browser coverage remains external acceptance work.

## 42. Migration impact

No migrations, schema changes or historical migration rewrites were required. Existing immutable intent version/transition/time are sufficient. Existing migration/backfill/upgrade tests run in both maintained database suites. The application remains a single-company installation with its existing deploy/rollback schema contract.

## 43. Static analysis

PHPStan/Larastan passed against the reviewed existing configuration/baseline, with no baseline expansion or new ignores. Final formatting, source/generated PHP and JavaScript syntax are separate gates. Evidence: `release-static.txt`, `release-pint.txt`, `release-source-php*`, `release-generated-php*`, `release-inline-js*`, `release-js.txt`, `release-blade.txt`.

## 44. Dependency/security/build gates

Composer strict validation, application locked audit and analyzer audit all exited 0 with no reported advisories. Final `npm ci` installed 108 packages and reported zero vulnerabilities; both explicit npm audits (full and production) exited 0. Production build passed in 2.42 seconds. PHP source syntax passed for 390 files, compiled Blade PHP for 59 views, maintained JavaScript syntax for 32 files, and rendered inline JavaScript for 8 scripts across Projects/Tasks/Manager/Analytics. Pint, PHPStan, CI shell syntax and `git diff --check` passed. Evidence: the matching `release-*-exit.txt` and logs. No audit exclusions or baseline relaxation were added.

Locked package identities were preserved. Composer strict validation, locked application audit, isolated analyzer audit, npm clean install/audits and production build are required. Caches and ephemeral runtime configuration stay inside isolated evidence paths. No `.env` edit, dependency upgrade or production service install occurred. A late `.env` hash is recorded; it is not misrepresented as a before/after hash captured at the start.

## 45. Files changed

```text
M	.github/workflows/ci.yml
M	app/Exceptions/AccountLifecycleException.php
M	app/Http/Controllers/AnalyticsController.php
M	app/Http/Controllers/ManagerDashboardController.php
M	app/Http/Controllers/ProfileController.php
A	app/Http/Controllers/ProjectRosterController.php
M	app/Http/Controllers/ProjectsController.php
M	app/Http/Controllers/TasksController.php
M	app/Http/Controllers/TeamManagementController.php
A	app/Notifications/Concerns/InterpretsRequiredIntent.php
M	app/Notifications/TaskAssignedNotification.php
M	app/Notifications/TaskReviewWorkflowNotification.php
M	app/Notifications/TaskWorkflowTransitionNotification.php
A	app/Services/AccountAdministrationWriter.php
M	app/Services/AccountLifecycleService.php
M	app/Services/NotificationAccess.php
M	app/Services/ProjectManagerReplacementService.php
M	app/Services/RequiredWorkflowNotifications.php
M	app/Services/TaskAnalyticsService.php
M	app/Services/TaskAssignmentCandidateService.php
A	app/Support/WorkflowNoticeSemantics.php
M	app/TaskTransitions/ResubmitTask.php
M	app/TaskTransitions/StartTaskReview.php
A	docs/R61_REPAIR_CONTRACTS.md
M	public/js/team-forms.js
M	resources/js/tasks/lifecycle-actions.js
M	resources/views/analytics-export.blade.php
M	resources/views/analytics.blade.php
M	resources/views/manager-dashboard.blade.php
M	resources/views/notifications/all.blade.php
M	resources/views/projects.blade.php
M	resources/views/tasks.blade.php
M	routes/web.php
M	scripts/ci-mariadb-tests.sh
A	scripts/r61-check-roster.php
A	scripts/r61-prepare-roster.php
A	scripts/r61-profile-roster.php
A	tests/Browser/z-r61-repairs.spec.js
A	tests/Browser/z-r61-roster.spec.js
A	tests/Feature/R61AccountMariaDbConcurrencyTest.php
A	tests/Feature/R61AdministrationContractTest.php
A	tests/Feature/R61NotificationGenerationTest.php
A	tests/Feature/R61ResponsibilityMariaDbConcurrencyTest.php
A	tests/Feature/R61RosterReadTest.php
A	tests/Feature/R61WorkflowRegressionTest.php
A	tests/Support/R61ProcessHarness.php
A	tests/Support/r61_browser_fixture.php
A	tests/Support/r61_http_worker.php
A	tests/Support/r61_roster_browser_identity.php
```

The repair includes the account writer/continuity guards, shared notice interpreter, analytics contributor query/presentation, two task transition metadata changes, bounded roster controller/routes/readers and browser controls, permanent tests/workers, capacity helpers and CI wiring. Earlier untracked audit files were not added to the implementation commits.

## 46. Commits

Implementation commits (newest first):

```text
f6afe566a50b8709c8895473e65222adb2e5ad89 fix: limit assignment search to usable membership choices
61020ec3b71c350d550d87ac3fd1c8b7fd2ae24f fix: await bounded roster loading before form submission
df5186e2f01af4f3ebd5cb3bae624b3749561307 test: complete dense roster form and accessibility qualification
ded3ab4af913659c9f5953b2337dd38c7bbf690f fix: preserve roster selection and isolated qualification contracts
55eaba94711e0e2944f6921caa41ff0f60937695 test: correct standalone roster qualification bootstrap
263fe2abc455fae89a89b65e25dafb0f771023ed fix: close R6 authorization responsibility and workflow boundaries
```

Exact qualified implementation: `f6afe566a50b8709c8895473e65222adb2e5ad89`. A final report-only commit records this qualification; its SHA is saved in `output/r6-1-20261005/final-head.txt`.

All commits are local. No history rewrite, push, tag or release was performed. Final qualification is tied to the exact implementation candidate; any subsequent report-only commit must preserve its application/test/build trees.

## 47. R6 closure ledger

| Finding | Result | Final proof |
|---|---|---|
| R6-001 | CLOSED | Actor/stamp locked at global write; 20 revoked in-flight writes denied; authorized serialization; actual browser session denial |
| R6-002 | CLOSED | Role/PM continuity preflight; all unfinished-state matrix; 12 two-order process schedules; blocked/resolved UI |
| R6-003 | CLOSED | Immutable event metadata/current-state formatter; repeated revision-chain and outage tests; safe recovered browser notice |
| R6-004 | CLOSED | Retained contributor cohort; numeric backend oracle; inactive identity in HTML/print/CSV through real browser |
| R6-005 | CLOSED | Resubmit old review timestamp → null history; new generation start tracked; repeated-cycle assertions |
| R6-006 | CLOSED | Fresh 250/500/1000 five-route budget checks; real dense keyboard selection/task creation; scoped PM login |

All entries are backed by the final gates on the same frozen implementation SHA.

## 48. Remaining known limitations

This is local Windows/MariaDB qualification. It does not establish clean Linux CI or Hostinger acceptance. Firefox's local launch limitation remains explicit. The retained 25k fixture has expensive synchronous durable delivery; bounded memory does not imply interactive administrative latency. The roster budgets cover the demonstrated twelve-project dense fixture, not unlimited project-count scalability. Historical notices conservatively become non-actionable after any version advancement, including benign edits.

## 49. External R4.5 readiness

External R4.5 was not begun. After local closure it must qualify the exact final SHA, clean Linux CI, actual Hostinger PHP/MariaDB/filesystem/permissions/symlink behavior, HTTPS/cookies/session behavior, real cron, external mail, actual browser push, backup and rollback. No external readiness claim replaces those checks.

## 50. Final Git state

Completion evidence records final HEAD/branch/status, the report-only comparison with the qualified implementation, protected-file hashes and disposable runtime cleanup. The final commit adds only this report. See `final-head.txt`, `final-status.txt`, `final-source-diff.txt`, `final-protected-lock-hashes.json`, `final-cleanup.txt` and `final-evidence-sha256.json` in the evidence directory.

Tracked/index cleanliness and the report-only delta are verified after the report commit. Pre-existing untracked evidence remains deliberately preserved. Private PHP servers, the dedicated MariaDB instance and isolated cached configurations are cleaned up after qualification without touching customer services/data.

## 51. Deployment state

No push, deployment, tag, customer `.env` edit, customer database modification or external R4.5 execution occurred. These repairs are local and reviewable.
