# R5.2 â€” Domain Contracts, Eligibility & Required Notification Reliability

Completion date: 2026-10-05 (Asia/Kathmandu).

Evidence root: `output/r5-2-20261004/`.

# 1. Executive summary

The nine owned R5 findings are repaired through shared domain contracts, sorted eligibility locks, database project identity, and a narrow durable workflow-notice ledger. One company remains one isolated Laravel application and database on shared hosting. No Redis, WebSockets, SaaS architecture, dependency upgrades, redesign, or later-phase work was introduced.

Qualification is local and disposable. Implementation commit: `755613449b8e082a957ace2b89f8ba0e506bdae9`. All required local gates are green. The nine owned findings are closed.

# 2. Final classification

`R5.2 PASSED — DOMAIN CONTRACTS, ELIGIBILITY AND REQUIRED NOTIFICATION RELIABILITY REPAIRED`

# 3. Starting Git state

Repository: `C:\xampp\htdocs\Task Management\task-management`; branch `main`; verified initial full HEAD `5e5eddd803b644714161a5dc47b04cf1a92b9401`. Initial tracked and staged changes were empty. The previous 20 commits, status, and remotes were captured before changes in `starting-commits.txt`, `starting-status.txt`, `starting-tracked.txt`, `starting-staged.txt`, and `remotes.txt`.

Historical untracked R4/R5 reports, audit files, `audit/`, `output/`, `DeepProductionAuditReconciliationTest.php`, and `hostinger-initialize.php` were retained and excluded from staging. Read the R5 audit and R5.1/R5.1A repair reports as the source of truth.

Runtime: PHP 8.4.20, Laravel 12.69.3, MariaDB 10.4.32, Node v22.14.0, npm 10.9.2, Playwright 1.63.0. Initial migration count 37; routes 84. The two new migrations bring the schema to 39. Remotes remain `origin` = `https://github.com/AsalPandey/task-management-final.git` and `production` = `https://github.com/AsalPandey/task-management-mvp.git`.

All phase evidence uses `output/r5-2-20261004/` and uniquely named `output/playwright/r5-2-20261004-*` directories. The directory date records phase start; report date records completion. A private loopback MariaDB instance on port 3362 and separately named disposable schemas provided qualification. Customer environment/database files were not changed.

# 4. Findings owned

Only R5-003, R5-004, R5-005, R5-006, R5-008, R5-009, R5-011, R5-013 and R5-016 are owned. Reopen and project-manager replacement changes are necessary alternate writers of the same eligibility and required-notification invariants. R5.1 findings R5-001, R5-002 and R5-007 remain closed by the maintained gates.

# 5. Pre-repair reproduction

RED evidence is retained, including unsuccessful harness attempts; failed probes were not presented as successful defect proof.

| Finding | Reproduced behavior / evidence |
|---|---|
| R5-003 | Held revision returned execution type/deadline; `red-domain.txt` / XML. |
| R5-004 | Original-HEAD classmap workers let create and reassignment return 200 after deactivation returned 200; `red-reviewer-baseline.txt`. |
| R5-005 | Cross-project PM membership remained an offered/accepted candidate; corrected original-source candidate probe `red-candidate-corrected*`. |
| R5-006 | Two real MariaDB workers passed validation and both stored case-equivalent projects; initial `red-races*` evidence. |
| R5-008 | Start-only update inverted the stored execution schedule; `red-domain.txt`. |
| R5-009 | Original-source provider failure committed a task with zero notices and zero recovery intents; `red-notification-baseline.txt`. |
| R5-011 | Detach succeeded with no withdrawal notice; `red-domain.txt`. |
| R5-013 | Five-digit date was accepted rather than rejected; `red-domain.txt`. |
| R5-016 | Rendered dashboards had two main landmarks; `red-domain.txt`. |

Baseline PHP snapshots are from the verified starting SHA and loaded only by disposable qualification workers, not production hooks. Early race harness timeouts, fixture setup mistakes, and engine-specific probes are diagnostic evidence, not qualification passes.

# 6. Domain invariant map

| Finding | Invariant and authoritative boundary |
|---|---|
| R5-003 | State plus active revision identity determines one deadline kind/date/generation across model, SQL, candidate delivery, events and UI. |
| R5-004 | Responsibility writers check active/scoped/distinct participants using locked current accounts before project/task mutation. |
| R5-005 | An accepted assignment has existing read/execution scope; unrelated PM membership cannot create that scope. |
| R5-006 | Retained project identity is unique in the database, including soft-deleted projects. |
| R5-008 | New values plus locked persisted values form one valid start/execution-deadline pair. |
| R5-009 | Business mutation and required delivery identity commit together; delivery is independently recoverable. |
| R5-011 | Detach, history and a safe recipient notice commit coherently without reopening resource access. |
| R5-013 | Date-only input is canonical before Carbon/database normalization. |
| R5-016 | Shared layout owns the sole top-level main landmark. |

# 7. R5-003 root cause

The active-deadline predicates recognized revision in progress but omitted revision on hold. Hold/resume history and workflow notices read execution fields directly, and deadline-edit eligibility permitted an execution change during revision.

# 8. Deadline truth-table repair

| State | Initial execution context | Active revision context | Responsible account |
|---|---|---|---|
| not_started | execution | execution (an inconsistent retained cycle does not change this state) | assignee |
| in_progress | execution | revision when cycle and revision date exist | assignee |
| on_hold | execution | revision when cycle and revision date exist | assignee |
| submitted | review | review for current cycle | reviewer |
| in_review | review | review for current cycle | reviewer |
| revision_requested | revision | revision | assignee |
| completed | none | none | none |
| cancelled | none | none | none |

Dates are execution_due_date with legacy due_date fallback, review_due_date, or revision_due_date respectively. A missing date yields no delivery generation. The existing fingerprint uses kind, date, responsible account and workflow cycle; execution has no cycle. Hold/resume does not mint a new cycle or substitute the old execution deadline.

Model and `TaskDeadlineSql` implement this same table. Analytics and delivery candidates already consume those abstractions. Hold/resume history and events now describe the active kind/date/generation, and workflow notifications carry the same metadata. Cards/tables label the active deadline. Revision deadline editing is allowed while revision is held; execution editing is rejected in that context. Existing contextual review-deadline editing remains supported.

# 9. Deadline regression results

`R52DomainContractsTest` checks all eight states with and without revision identity, SQL/model date parity, generation-aware reminder candidates, and the real submit/review/revision/start/hold/resume sequence. An expired original execution deadline with a future held revision date is not falsely overdue. Existing execution, deadline, reminder, analytics and workflow tests pass in both engines. The clean-company browser workflow checks the held Revision label, allowed actions, retained revision generation/event metadata, resume, resubmit and approval.

# 10. R5-004 root cause

Reviewer reads were ordinary snapshot reads. Pre-validation could remain eligible while a separate account lifecycle transaction committed deactivation or demotion. The task writer did not serialize that participant account with lifecycle writers.

# 11. Reviewer lock/eligibility repair

Task creation locks sorted actor, assignee and reviewer accounts before project and task/dependents. Update prelocks the effective assignee and retained reviewer and rejects routing/participant changes against its snapshot. Reviewer reassignment prelocks the replacement account. Authoritative eligibility reads use `FOR UPDATE` after those accounts are held.

Reopen also prelocks the retained assignee/reviewer and any replacement, refreshes their locked current state, and requires executable assignment scope. Project create/update prelocks the selected project-manager account; replacement validates its current active management role before changing reviewers. Existing account lifecycle restrictions remain selective, rather than banning all deactivation.

# 12. Reviewer concurrency results

The final R5.2 group has 12 passing real-process tests, 92 assertions. Create and reviewer-reassign schedules reject a candidate whose deactivation or demotion committed first. When the task writer holds the account first, actual InnoDB lock waits are observed and the lifecycle operation later returns a controlled 409. Reopen and project-manager replacement have both deactivation orderings covered too. Participant distinctness and PM scope remain enforced. Evidence: `r52-v3-final.txt` / XML.

# 13. R5-005 product decision

Choose rejection of assignments that existing scope cannot execute. An unrelated Project Manager does not gain project/task visibility simply by being added as a member. Own PM assignments remain possible when membership exists and the reviewer is a different eligible manager. No read policy was broadened.

# 14. Assignment eligibility repair

`TaskAssignmentCandidateService` defines active executable role/scope and uses the same predicate in per-project candidate data and locked lifecycle validation. Candidate queries accept active managers/team members with membership, or a PM who owns that project and is a member. The rendered dropdown also filters PM candidates by selected project ownership. Direct create/update input receives the same checks, including membership and distinct reviewer.

# 15. Candidate/read/execution results

Permanent HTTP tests accept ordinary members and the own PM, then read and start the resulting tasks as those assignees. They reject inactive accounts, self-review and forged unrelated-PM inputs, including update. The real UI creates an unrelated PM membership and confirms the PM is absent from the assignment options. The existing narrow PM read policy remains intact.

# 16. R5-006 root cause

Application uniqueness validation could succeed independently in two transactions. No database constraint serialized the name identity, so both inserts could commit.

# 17. Project identity/normalization policy

Retain the existing case-insensitive MariaDB identity using `utf8mb4_unicode_ci`, including its accent/collation equivalences. Laravel trims ordinary form input. The generated identity additionally trims outer ASCII spaces for all database writes; internal spacing is retained. Soft-deleted names remain reserved, and a restored project keeps the same identity. New records cannot reuse a retained deleted name. No `UNIQUE(name, deleted_at)` NULL loophole is used.

SQLite uses NOCASE for its local functional suite; its Unicode/accent behavior is not claimed equivalent to MariaDB. MariaDB is the authority for production collation and concurrency.

# 18. Migration/data reconciliation

`2026_10_04_000001_enforce_project_name_identity.php` checks all retained projects grouped by the intended trimmed/collated name before DDL. Conflicts stop with an actionable error and do not rename business records. It adds a virtual `name_identity` column and unique `projects_name_identity_unique` index, with resumable column/index checks.

Only disposable fixtures were inspected/reconciled. The migration regression inserts conflicting retained/soft-deleted case-and-space names, verifies failure preserves them, explicitly deletes the disposable duplicate, and verifies successful/idempotent migration. No customer conflict inventory or customer reconciliation is claimed.

# 19. Concurrent uniqueness results

Two independent MariaDB HTTP workers use a validation barrier: one commits a case-equivalent name while the other is paused, then the other receives controlled 422 and exactly one row remains. Constraint violations are translated only for the identity index into a friendly name validation message. Browser duplicate submission keeps the modal/draft and displays that validation error. Evidence: `r52-v3-final*`, `R52SchemaMigrationTest`, final browser gate.

# 20. R5-008 effective schedule repair

Creation and locked update evaluate the effective start date and persisted execution deadline together. Start-only changes cannot invert that pair. `ChangeTaskDeadline` independently checks a new execution deadline against the persisted start date. Existing explicit workflow deadline commands remain the supported edit path; generic update does not gain a new ability to mutate protected deadline fields.

# 21. Task-date regression

Tests cover a start before deadline, equality, inverted creation pair, start-only update, deadline-only command, invalid five-digit task start, and the company-midnight boundary. HTTP invalid pairs return established 422 validation responses with no state mutation. Browser start-only invalid editing retains the modal and draft while explaining the effective schedule error. Final focused domain matrix: 10 tests / 165 assertions; full suites include it.

# 22. R5-009 notification durability root cause

The business transaction was truthful but its after-commit send had no durable delivery identity. Provider failure or process death left a saved responsibility with no recoverable in-app signal, and business-operation replay could not repair delivery.

# 23. Durable dispatch design

`workflow_notification_intents` is a narrow task-workflow ledger, not a generic event bus. Unique task/version/recipient/transition identifies the signal; the intent UUID is also the database notification ID. Intents are written inside task create/update, required transitions and project-manager reviewer reconciliation. Delivery starts after commit and can also run through the scheduled `app:deliver-required-workflow-notifications` command every minute on the existing scheduler/cron.

The existing Mandatory category determines coverage: assignment, hold/resume, submit/resubmit, revision responsibility, reopen, cancel, reviewer reassignment and deadline change. Optional progress/start/review-start/completion/team updates retain their existing preference-controlled paths. Existing deadline and push delivery ledgers are preserved.

Consumer order is recipient account, routing snapshot, project, task, intent, then relevant membership/delivery writes. It rechecks active account, current view permission, current responsibility and eligibility before producing protected data. Missing/deleted/revoked destinations become discarded. Route changes and send failures remain pending with bounded exponential retry (30 seconds up to one hour). The stored error is the exception class; logs use stable IDs rather than sensitive payloads.

Notice insertion and delivered state are one database transaction. Two competing consumers serialize; a rollback cannot leave the intent completed without the in-app notice. This is an in-app deduplication guarantee; external provider exactly-once delivery is not claimed. Business replay and notification retry remain separate. Existing task responses report saved plus delivery_failed truthfully; project replacement now does too.

The command selects indexed due pending rows with default limit 100, capped at 1000. It prunes only finished intents older than 30 days, in the same bounded batch size, retaining pending recovery state. Shared hosting needs its existing `schedule:run` cron, with no persistent daemon.

# 24. Crash/retry/dedup results

Permanent tests cover provider/send failure, exception after database-channel insertion, business rollback, replay without a second task/intent, retry twice producing one notice, pending review duty recovery, former-reviewer revocation, bounded retry/pruning and rollback guard. Replacement tests prove required intents exist before outer commit, disappear on rollback, and survive send failure after the reviewer/project change commits.

The MariaDB race gate kills only its own creator PHP process after business commit and before delivery. Two independent consumers then contend on a real lock and leave one notification with the stable intent UUID and delivered state. Final R5.2 concurrency: 12 passed / 92 assertions. Initial Windows sandbox process-kill denial was followed by an explicitly permitted isolated run; the assertion was not weakened.

# 25. R5-011 safe withdrawal notice

A removal transaction detaches membership, records history and persists the recipient-specific database notice together. Its payload is only `project_member_removed` and immutable generic text: Your membership and access to a project were removed. It contains no project ID/name, actor details, private notes or resource link. Addition retains its existing optional after-commit notice behavior. Existing TeamUpdates preferences continue to apply.

# 26. Notification privacy results

Tests prove one notice survives removal and is visible in the notification list, with no private project name or link, while former project/task access remains denied. Repeated removal is a no-op and adds no notice; a re-add/remove cycle creates a distinct truthful notice. Required notice retry discards revoked membership and a former reviewer without storing a protected payload. Maintained removed-assignee start/submission tests also continue to return 403. `NotificationAccess` and general resource policies were not bypassed.

# 27. R5-013 strict date contract

Shared `InputContracts::date()` uses `date_format:Y-m-d` and the broad MariaDB DATE range 1000-01-01 through 9999-12-31 before parsing. Projects and effective task schedule/deadline checks consume it. Leap-day validity, impossible February dates, zero month/day, month 13, three/five-digit years and timestamps are tested. Ordinary surrounding whitespace is trimmed by the established request middleware. UTC JSON serialization is interpreted back in Asia/Kathmandu; the expected prior UTC calendar day is not treated as a defect. No arbitrary narrow business-year restriction was invented.

# 28. R5-016 accessibility repair

Manager and Team dashboards use a div for their inner content wrapper, preserving classes/layout. The shared layout retains `main#main-content` as the single top-level landmark. PM uses the shared manager dashboard. No CSS redesign occurred. Rendered PHP assertions and real-browser axe rules `landmark-main-is-top-level` and `landmark-no-duplicate-main` remain enabled.

# 29. Lock-order review

Writers acquire sorted account rows before project rows, then task/dependent state. Task update orders multiple projects by ID. Reviewer/assignee and replacement-PM checks reread current locked participant state; stale task routing/version aborts rather than locking a new participant late. Delivery never locks an intent before its account/project/task; recipient and actor IDs in the ledger are immutable scalar destinations rather than user foreign keys that would implicitly take late account locks.

Qualification exposed a cancellation/submit deadlock caused by user FK locks on the first ledger schema. Those redundant FKs were removed before final migration/concurrency qualification. Task FK ownership remains; deleted users are safely discarded at delivery. All final named groups pass. R5.1 worker instrumentation reports business transaction attempts separately from intentional post-commit consumer transactions and still asserts at most one business attempt; it does not hide retries or relax the lock-wait oracle.

# 30. SQLite results

Full maintained suite: **597 passed, 70 skipped, 0 failed; 5287 assertions; 87.34 seconds**. Evidence: `sqlite-final-v3.txt`, XML and exit file. Engine-specific process races are skipped by their guards, not counted as passed. SQLite is functional coverage, not concurrency qualification.

# 31. MariaDB results

Full maintained suite: **596 passed, 71 skipped, 0 failed; 5284 assertions; 143.57 seconds**. Evidence: `mariadb-final-v3.txt`, XML and exit file. One SQLite-specific test and separately guarded concurrency suites explain the engine-specific skip difference. Final source and date matrix are included. Named real-process groups run separately below.

# 32. R5.1/R5.2 concurrency results

| Named group | Passed | Assertions | Evidence |
|---|---:|---:|---|
| Core event/execution/lifecycle/R1 | 17 | 311 | core-v3-final* |
| R2a account | 1 | 10 | r2a-v3-final* |
| R2b5 notification delivery | 2 | 14 | r2b5-v3-final* |
| R2b5q push/notification mutation | 15 | 93 | r2b5q-v3-final* |
| R3A2 | 3 | 22 | r3a2-v3-final* |
| R3A3 | 8 | 71 | r3a3-v3-final* |
| R4.2 membership | 1 | 7 | r42-v3-final* |
| R4.3 notification | 2 | 21 | r43-v3-final* |
| R5.1 project writers | 9 | 97 | r51-v3-final* |
| R5.2 eligibility/identity/crash | 12 | 92 | r52-v3-final* |

Each final group has zero failures and its own newly created whitelisted database. File barriers and observed InnoDB lock waits provide the race ordering, rather than relying on sleep alone. No skipped tests are added to a fabricated combined total. Existing R5.1 authorization/version/project writers remain green.

# 33. Browser results

**53 passed, 3 skipped, 0 failed; 16.4 minutes** on the final fresh company database and implementation commit `755613449b8e082a957ace2b89f8ba0e506bdae9`. Evidence: `browser-revised.txt`, exit file, `output/playwright/r5-2-20261004-revised/`, and `revised-browser-metrics/`.

Chromium and WebKit smoke tests pass. Both engines pass root/subdirectory real-network offline behavior, service-worker update, account safety, absent PushManager handling, and honest optional status retry. WebKit navigation cancellation passes. R5.1A service-worker source was restored after temporary test updates, with no final tracked change.

The three skips are two large-fixture browser scenarios (the separate 10k/128M resource qualification passes) and Firefox's existing Windows launch limitation (`spawn UNKNOWN`, incorrect side-by-side configuration), captured in `firefox-launch-limitation.txt`. Firefox is unavailable, not passed.

Earlier diagnostic browser runs each had 52 passes / 3 skips / one new-harness assertion failure: first the error was shown in the existing SweetAlert dialog rather than inside the form; next the safe withdrawal text matched both header dropdown and notification page. Assertions were scoped to the actual dialog/page notice. The queued intermediate run was explicitly stopped before worker mutation after four tests, then restarted fresh so discovery loaded the correction. All attempt logs/traces remain retained; final results are not blended with them.

# 34. Accessibility results

The maintained axe WCAG A/AA gate, keyboard/focus checks, reduced motion, accessible controls, contrast and responsive matrix are retained. The added main-landmark test scans Manager, Team and PM dashboards without suppressing either rule. The final browser gate passes these checks; `r52-landmarks.json` contains zero violations for all three dashboards.

# 35. Clean-company acceptance

The final fresh browser database is `task_management_r41_browser_r52_revised`, with production-like config, database cache/session/queue, 128M PHP limit, root port 8098 and subdirectory port 8099. UI onboarding creates the Manager, PM and members, project/membership and canonical tasks, then executes/submits/reviews/requests revision, starts/holds revision, verifies active revision label and history, resumes/resubmits and approves. The extra UI cases verify unusable-PM exclusion, effective-date rejection retaining the draft, actual withdrawal notice after removal with revoked access, duplicate name UX, malformed five-digit project date UX, and dashboards' main landmarks. No service-layer substitute is used for these UI behaviors.

# 36. Resource regression

Fresh fixture: **10,000 tasks, 20 projects, 10,000 initial notices**, unchanged **128M** limit. Existing resource qualification passes across **22 fresh PHP processes**, peak **44 MiB**, with independent aggregates, scope, bounded hydration/pagination, response-size and scheduler checks. Evidence: `resource-final-fresh-fixture.json`, `resource-final-fresh-results.json` and exit file.

Representative queries: Manager/PM/Member dashboards 37/29/26; task page 38; projects 28; notifications 42; analytics service 7; CSV 22; bulk mark-all one UPDATE. Existing scheduler paths remain within their maintained bounds and repeat without duplicate delivery. An attempted reuse of an already-mutated resource fixture failed its dashboard budget; that diagnostic is retained, and final qualification uses a newly created fixture as required. R5-010 large administration OOM is not repaired or closed.

# 37. Notification performance sanity

The current-source HTTP sanity sample has 52 task-create queries and 107 reviewer-reassignment queries. One assignment recovery consumes 25 queries / 24.21 ms; retrying its completed intent consumes one query and leaves one notice. An idle retry/prune pass uses three queries. The sample has four intents after one create and a three-recipient reviewer reassignment, with zero duplicate notices. Pending delay and finished-only bounded cleanup have permanent regression tests.

Compared with starting-source classmap samples: task creation 37 -> 52 queries (128.12 -> 166.78 ms); project creation 16 -> 20 (149.18 -> 151.85 ms); reassignment 73 -> 107 (90.59 -> 132.08 ms); notification list stays 29 (62.95 -> 63.60 ms). These are individual local sanity observations, not statistical benchmarks. The bounded increase funds current-state eligibility, durable intent persistence and immediate per-recipient delivery. No unrelated R5-017 read optimization was attempted. Evidence: `profile-baseline-v2.json`, `profile-current-final.json`, `profile-notification-final.json`.

# 38. Migration qualification

Fresh migrate, reset, remigrate all exit 0 on disposable MariaDB; representative retained-data down/up/idempotency and conflict-preflight tests pass in both engines. No historical migration was rewritten. Conflict checking precedes the project constraint, and retry after partial DDL is supported. The notification ledger is additive; task ownership cascades deletion, while destinations are revalidated scalar IDs. The new schema reaches 39 migrations.

Rollback requires planned maintenance and compatible application/schema changes. First drain pending required intents through the scheduler/command; the ledger down migration refuses to drop pending recovery state. Backup retained records before schema rollback. Dropping project uniqueness intentionally removes concurrency protection, so it must accompany an application rollback rather than silently weakening the repaired contract. No customer migration, rename or database modification was performed.

Qualification logs include an initial strict-mode GROUP BY failure, corrected before final gates by projecting only the aggregate count. Evidence: `migration-qualified-fresh*`, `migration-qualified-reset*`, `migration-qualified-remigrate*`, `migration-qualified-schema.txt`, `schema-durability*`, `R52SchemaMigrationTest`, full suites.

# 39. Dependency/build gates

All required gates pass: Composer validate --strict; Composer audit (zero advisories/abandoned packages); npm ci; npm audit and npm audit --omit=dev (zero vulnerabilities); npm run build. Network-restricted first attempts are retained; successful registry calls used approved phase caches and changed no dependency lockfiles.

Pint checks all tracked PHP plus explicit R5.2 additions; the final date-test edit has its own passing check. PHP syntax: 429 files including generated Blade, zero failures; JS syntax: 36 files, zero failures, plus the final selector check. Blade compile and generated PHP syntax pass. No separate JS lint command is configured. Staged/working diff checks pass. No PHPStan/Larastan cleanup was begun. Protected SHA-256 comparisons confirm `.env`, composer.lock and package-lock.json are unchanged.

# 40. Files changed

43 implementation/test files, plus this report. The exact implementation manifest is retained in `implementation-files.json`.

- `app/Console/Commands/DeliverRequiredWorkflowNotifications.php`
- `app/Http/Controllers/ProjectsController.php`
- `app/Http/Controllers/TasksController.php`
- `app/Http/Requests/TaskStoreRequest.php`
- `app/Http/Requests/TaskUpdateRequest.php`
- `app/Models/Project.php`
- `app/Models/Task.php`
- `app/Models/WorkflowNotificationIntent.php`
- `app/Notifications/ProjectMemberRemoved.php`
- `app/Notifications/TaskWorkflowTransitionNotification.php`
- `app/Services/NotificationPreferencePolicy.php`
- `app/Services/ProjectManagerReplacementService.php`
- `app/Services/ProjectMembershipService.php`
- `app/Services/RequiredWorkflowNotifications.php`
- `app/Services/TaskAssignmentCandidateService.php`
- `app/Services/TaskLifecycleService.php`
- `app/Services/TaskNotificationDispatcher.php`
- `app/Services/TaskTransitionExecutor.php`
- `app/Services/TaskViewData.php`
- `app/Support/InputContracts.php`
- `app/Support/TaskDeadlineRules.php`
- `app/Support/TaskDeadlineSql.php`
- `app/TaskTransitions/ChangeTaskDeadline.php`
- `app/TaskTransitions/HoldTask.php`
- `app/TaskTransitions/ReassignTaskReviewer.php`
- `app/TaskTransitions/ReopenApprovedTask.php`
- `app/TaskTransitions/ResumeTask.php`
- `database/migrations/2026_10_04_000001_enforce_project_name_identity.php`
- `database/migrations/2026_10_04_000002_create_workflow_notification_intents.php`
- `resources/views/manager-dashboard.blade.php`
- `resources/views/tasks.blade.php`
- `resources/views/team-dashboard.blade.php`
- `routes/console.php`
- `scripts/ci-mariadb-tests.sh`
- `tests/Browser/core-workflow.spec.js`
- `tests/Browser/r44-quality.spec.js`
- `tests/Browser/z-r52-domain-contracts.spec.js`
- `tests/Feature/R52DomainContractsTest.php`
- `tests/Feature/R52EligibilityMariaDbConcurrencyTest.php`
- `tests/Feature/R52RequiredWorkflowNotificationTest.php`
- `tests/Feature/R52SchemaMigrationTest.php`
- `tests/Support/r51_project_writer.php`
- `tests/Support/r52_writer.php`

# 41. Commits

Implementation: `755613449b8e082a957ace2b89f8ba0e506bdae9` â€” `fix: align domain contracts and recover required workflow notices` (43 files). Shared controller/transaction changes make a single coherent implementation commit more reviewable than artificially splitting overlapping finding IDs.

The final report is a separate local documentation commit. Its exact resulting HEAD is recorded in `final-head.txt` and final Git evidence after commit; a committed report cannot contain its own future SHA. No historical untracked report/output was added incidentally.

# 42. Remaining R5 findings

Closed: R5-003, R5-004, R5-005, R5-006, R5-008, R5-009, R5-011, R5-013 and R5-016. R5-001, R5-002 and R5-007 stay closed.

Remain open: R5-010 large administration OOM; R5-012 timeline >100 navigation; R5-014 PHPStan/Larastan baseline; R5-015 obsolete code cleanup; R5-017 authorization/read overhead; R5-018 Chart.js loading; R5-019 frontend modularization. R5.3 and R5.4 have not started. R5.3 is a future phase requiring a separate instruction.

# 43. Final Git state

Final branch remains main. After the intentional implementation and documentation commits, tracked and staged changes are empty, as verified in final Git evidence. Historical and phase-local untracked evidence remains deliberately present. Final full HEAD, commit log, tracked/staged status, protected hashes and unchanged remotes are captured in phase evidence. All eight phase-owned PHP servers and the private MariaDB instance were identity-verified and stopped after qualification (`phase-process-cleanup-verified.json`); private databases, isolated phase caches, logs, screenshots and traces remain as evidence. Application bootstrap caches were not used for this phase.

# 44. Deployment state

No push, deployment, release tag, remote change, customer `.env` edit or customer database write occurred. Only disposable local qualification schemas were created. No company/SaaS tenancy changes, Redis dependency or WebSocket dependency were introduced. Shared hosting continues to use the existing scheduler cron; future deployment must apply the two additive migrations safely and retain that cron for required-notification recovery.
