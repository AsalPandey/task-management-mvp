# 1. Executive summary

R5.1 repairs the server contracts for R5-001, R5-002 and R5-007 in the existing single-company Laravel MVP. Fresh locked authorization prevents a replaced/deactivated/role-revoked PM from committing; lifecycle actions require the rendered task version; project deletion, task creation and supported project moves share a writer boundary. All three original defects were reproduced before application edits and no longer reproduce in the permanent regressions.

Overall qualification remains FAILED because the maintained WebKit smoke test reports background service-worker access-control console errors. Its workflow assertions succeed, but the console assertion fails. This was not hidden, skipped, or repaired outside R5.1 scope. Firefox cannot launch on this Windows host and is explicitly unavailable. There is also an evidence-preservation limitation described below.

Evidence is in `output/r5-1/` and R5.1-specific `output/playwright/r5-1-*` directories. These remain local, untracked audit artifacts; permanent tests, source and this report are committed.

# 2. Final classification

**R5.1 FAILED — INTEGRITY REPAIR INCOMPLETE**

The three owned root defects are repaired and regression-tested, but the requested overall browser qualification is not green. This classification does not claim that R5-001/002/007 still reproduce. A successful Chromium workflow does not convert the available WebKit failure into a pass. No production acceptance or deployment claim is made.

# 3. Starting Git state

Repository: `C:/xampp/htdocs/Task Management/task-management`. Branch: `main`. Starting HEAD exactly matched the audited revision: `38c85ea9af53721201158f0320a951e2179bb176`. Tracked and staged changes were empty. No reset was performed.

`output/r5-1/starting-head.txt`, `starting-branch.txt`, `starting-status.txt`, `starting-staged.txt`, `starting-commits.txt` (previous 20 commits), `remotes.txt`, and `migrations.txt` record the starting state. Historical reports, `audit/`, `output/`, audit result files, the untracked deep reconciliation test and Hostinger initialization script were present before this task and were not staged as repair code.

Remotes remain origin `AsalPandey/task-management-final` and production `AsalPandey/task-management-mvp`; URLs were not changed. Runtime: PHP 8.4.20, Laravel 12.69.3, MariaDB 10.4.32, REPEATABLE READ. The R5 audit recorded SQLite 574 passed/49 skipped and MariaDB 573 passed/50 skipped, with 49 separately qualified named cases; these are historical counts, not this repair's totals.

A new isolated MariaDB datadir was initialized under `output/r5-1/runtime/db` and bound to loopback port 3358. The initializer's service-registration attempt failed after bootstrap; no service was installed. A private process was launched instead. All database fixtures were disposable R5.1/R41/R43 schemas on that process. Customer `.env` and databases were not edited.

# 4. Findings owned

Only R5-001 (stale PM authorization), R5-002 (delayed lifecycle approval/intent), and R5-007 (delete/create race and false history) are owned. No SaaS, tenancy, distributed service, new architecture, dependency upgrade or R5.2 work was introduced.

Necessary adjacent changes: membership prelocks actor/member accounts before project; task metadata/project moves lock accounts and sorted projects before task; reopen locks its optional replacement account before project/task; project creation refreshes actor authorization inside its transaction. These avoid inversions or stale account assumptions at the repaired boundary. Reviewer eligibility semantics, PM assignee scope, deadline semantics and durable notification recovery were not expanded.

# 5. Pre-repair reproduction

| Finding | RED result | Evidence |
| --- | --- | --- |
| R5-001 | replacement 200, old PM update 200, old PM restored | `red-pm-race.txt` |
| R5-002 | UI intent version 4; revision/resubmission advanced to 8; approval omitted version, returned 200 and completed at 9 against submission 2 | `red-browser.txt`, `red-delayed-browser-approval.json` |
| R5-007A | delete and task create both 200; live task retained under deleted parent; start denied 403 | `red-delete-race.txt` |
| R5-007B | controlled delete failure 500 left false deleted history | `red-probes.txt`, `red-probes.xml` |

The relevant old probes also demonstrated missing/malformed lifecycle versions being accepted. Original unrelated reproduction branches, including project-name uniqueness, were not turned into R5.1 repairs.

Evidence preservation limitation: the first copied Playwright RED harness still pointed at historical `output/playwright/r5`; Playwright cleared that transient output directory. Historical screenshots/traces there were lost. The R5 report, source harnesses and retained JSON/text reproduction evidence remain, but complete historical artifact preservation cannot be claimed. All subsequent outputs use dedicated R5.1 directories. This mistake cannot be retroactively undone.

# 6. Lock-order analysis

The order was documented before application changes in `output/r5-1/lock-order.md`. Related writers acquire account rows in ascending ID, project rows in ascending ID, then task/dependent rows. Existing account lifecycle retains its outer manager-role sentinel; repaired writers do not acquire that sentinel. Scheduler/task-only event writers do not later acquire account/project locks.

Before repair, membership used project then member, task metadata used task then assignee, and reopen used task/project then replacement reviewer. These crossings were aligned. Repeated locks in eligibility/membership helpers operate on accounts already acquired before the project.

Task update/move and transition paths obtain a routing snapshot, acquire the appropriate project locks, then task lock; routing changes are rejected instead of mutating a task under an unlocked project. Policies use the locked project relation, preventing an older REPEATABLE READ relation snapshot from becoming authoritative. Dependency and membership eligibility use current locking reads where required. New project lock_version was unnecessary for the demonstrated authorization defect; no separate authorized-edit intent defect is claimed.

# 7. R5-001 root cause

An early policy decision used a route-bound project/actor snapshot. Later locking refreshed the row but did not refresh the authorization decision. PM-specific ownership assignment could then overwrite the manager's replacement.

# 8. R5-001 repair

`ProjectWriterLocks` reloads and locks current account rows, rejects absent/inactive actors, and supplies the current actor. Project update locks the project and reauthorizes with `Gate::forUser` before applying PM-specific ownership behavior and membership reconciliation. Create and delete also authorize the current actor inside their transaction. Early authorization remains a fast rejection. ProjectPolicy access scope was not broadened.

# 9. R5-001 race results

Separate MariaDB HTTP-kernel processes cover both replacement schedules. Replacement returns 200; the obsolete owner's delayed mutation returns 403; new ownership survives. Account deactivation and role revocation use existing account HTTP actions while a request is paused after binding, and the stale edit returns 403 with no project/history mutation. Archived project fixtures permit those legitimate account actions without bypassing existing account guards.

# 10. R5-002 root cause

Optional executor version checks and absent UI versions serialized current database state without serializing original user intent. Raw input casting allowed malformed versions to become plausible integers. An old approval could target a newer submission when its state returned to in_review.

# 11. Lifecycle endpoint/version inventory

Every entry is POST `/tasks/{task}/<suffix>`, handled by `TasksController`, a dedicated request extending `TaskTransitionRequest`, and the shared executor. Deadline expands to three tested route variants (16 concrete routes total).

| Suffix | Controller method | FormRequest | Command | UI |
| --- | --- | --- | --- | --- |
| start | start | StartTaskRequest | StartTask | tasks card/list |
| hold | hold | HoldTaskRequest | HoldTask | tasks reason prompt |
| resume | resume | ResumeTaskRequest | ResumeTask | tasks card/list |
| submit | submit | SubmitTaskRequest | SubmitTask | tasks submission prompt |
| review/start | startReview | StartTaskReviewRequest | StartTaskReview | tasks card/list |
| revision-request | requestRevision | RequestTaskRevisionRequest | RequestTaskRevision | feedback/deadline prompts |
| revision/start | startRevision | StartTaskRevisionRequest | StartTaskRevision | tasks card/list |
| resubmit | resubmit | ResubmitTaskRequest | ResubmitTask | tasks submission prompt |
| approve | approve | ApproveTaskRequest | ApproveTask | approval prompt |
| approve/override | overrideApprove | OverrideApproveTaskRequest | OverrideApproveTask | override reason prompt |
| reopen | reopen | ReopenApprovedTaskRequest | ReopenApprovedTask | completed-tasks prompt |
| cancel | cancel | CancelTaskRequest | CancelTask | cancellation prompt |
| reviewer/reassign | reassignReviewer | ReassignTaskReviewerRequest | ReassignTaskReviewer | management control |
| deadline/{execution,review,revision} | changeDeadline | ChangeTaskDeadlineRequest | ChangeTaskDeadline | management control |

No reachable additional dedicated lifecycle route was found. Generic task metadata edit is distinct and retains its existing edit contract; it does not regain permission to write lifecycle state. Payloads retain their action-specific fields plus mandatory `expected_version`.

# 12. Version validation contract

The shared request uses existing `InputContracts::id()` / `PositiveResourceId`: required scalar positive native integer or canonical positive digit string within PHP_INT_MAX. Null, zero, negative, decimal, garbage, arrays, object payloads, booleans, decimal strings and overflow strings return 422. Input normalization remains Laravel's established policy. Lifecycle `lock_version` and `version` aliases are prohibited. Conversion occurs only after validation. Internal executor calls must also supply a positive typed expected version.

`R51LifecycleIntentTest` has three tests and 694 assertions: malformed/missing/stale matrix across all 16 concrete routes, no effects, executor missing-context rejection, and valid native/digit-string versions. Existing state-appropriate workflow tests exercise valid versions across the lifecycle rather than requiring invalid-state matrix fixtures to succeed.

# 13. UI payload repair

Rendered card/list execution buttons and management controls carry `data-task-version`; action serialization uses that value, including before multi-step prompts. Reopen in completed-tasks also carries its rendered version. No last-minute freshness fetch substitutes a newer version for the user's original intent.

409 displays “Task changed” and offers “Reload latest task” or keeping the page. Buttons recover in finally. Existing metadata draft-preservation/reload behavior remains covered by the maintained browser suite.

# 14. Executor conflict behavior

The executor requires a version, locks current actor/project/task, authorizes, then compares intent with locked lock_version before command validation/application. A valid mismatch returns established 409; no state, version, history, approval, event or notification effect executes. Unauthorized actors return 403. Deleted destinations resolve as unavailable 404, preserving existing resource semantics. Injected infrastructure failures remain truthful errors, not false success.

Every successful submission/resubmission changes task version within the executor. No evidence showed supported submission identity replacement without a version change; no redundant submission token or migration was added.

# 15. Delayed approval browser regression

The real UI holds the original version-4 approval prompt while another session requests revision, the assignee starts revision/resubmits, and review starts at version 8. Releasing the original prompt sends expected_version 4 and returns 409. The task stays in_review/version 8; approvals remain empty; event counts stay 9/9, histories 8/8 and notifications 97/97. “Task changed” is shown. Reloading and approving current work succeeds.

Permanent regression: `tests/Browser/core-workflow.spec.js`. Evidence: `delayed-browser-approval.json`, `approval-final.txt` (one passed), and `output/playwright/r5-1-approval-final/stale-approval.png`. The RED companion demonstrates the actual earlier failure; a service test was not substituted for browser acceptance.

# 16. R5-007 root cause

Delete's no-task guard, history and mutation were not a single authoritative transaction, and another writer could invalidate the guard. Recording history before a later failed delete left false provenance.

# 17. Project writer-boundary repair

Deletion locks current actor and project, authorizes, performs a current locking task dependency check, records deletion history and soft-deletes within one transaction. Create and supported project moves acquire the same project writer lock while checking live/current eligibility. Membership and PM replacement participate in compatible ordering.

# 18. Task-create/delete serialization

Delete-first: deletion 200, blocked create resumes and returns 404; no task is inserted into the deleted project. Create-first: creation 200, deletion resumes and returns 409; active parent/task remain and no deletion history exists. Different manager actors ensure the project lock itself is exercised rather than only an actor lock.

Project moves are supported by metadata update. Source/destination project IDs are sorted before task lock. Delete-first rejects a move into the deleted destination (404), preserving the source. Move-first commits then destination deletion returns 409. Both schedules are permanent MariaDB tests.

# 19. Deletion rollback/history atomicity

A controlled Project::deleting exception occurs after the history path. HTTP returns 500; project remains active, no deleted history remains, membership is preserved. Canonical state and history roll back together. Successful empty-project deletion through UI returns 200 and removes the project from the live list.

# 20. Deadlock/retry observations

Nine coordinated R5.1 tests assert outer writer transaction attempts <=1. All pass; no deadlock retry is needed for those schedules. Existing retry counts remain three and were not increased. Query-boundary files synchronize processes; polling only waits for barriers/observed InnoDB state, not a guessed race delay.

`SHOW ENGINE INNODB STATUS` confirms actual LOCK WAIT. On this MariaDB version INNODB_LOCK_WAITS did not reliably surface the held waits, so tests inspect engine status. Aggregate private-server observations: zero current waits at capture, 112 row waits, maximum 19081 ms. Those include deliberately paused RED/diagnostic barriers and are not production latency statistics. Tested interleavings do not prove all possible schedules deadlock-free.

# 21. Authorization regression

Final relevant project/PM/membership subset: 24 passed (`project-final.txt`). Full suites also retain Manager/PM/Member authorization matrices. UI proves former PM loses this project after replacement and the replacement gains it. Manager normal creation/edit/task creation still work. No company-wide PM visibility or R5-005 scope expansion was introduced.

# 22. Provenance/integrity verification

Stale PM edit commits no mutation/misleading history. Stale task action commits no state/version/approval/event/history/notification. Failed deletion commits no deletion/history. Successful lifecycle state and history/events remain transactional; notifications retain existing after-commit behavior. Durable notification recovery (R5-009) is still open.

Test helpers explicitly capture current version at each intended call site while preserving any supplied version. Raw missing/malformed requests remain in the dedicated contract tests; HTTP behavior was not overridden to auto-inject versions. Internal transition fixtures now carry explicit context versions.

# 23. SQLite results

Final source run: **577 passed, 58 skipped, 5035 assertions**, exit 0, 93.59 seconds. Evidence: `sqlite-final-code.txt`, matching XML and exit file. Engine/name guarded tests skip as designed. SQLite is functional regression coverage, not race qualification.

# 24. MariaDB results

Final maintained suite: **576 passed, 59 skipped, 5032 assertions**, exit 0, 163.81 seconds. Evidence: `mariadb-final-code.txt`, matching XML and exit file. Disposable main schema ran separately from each guarded concurrency group. Counts are not combined with separate group passes.

# 25. R5.1 concurrency results

`R51ProjectWriterMariaDbConcurrencyTest`: **9 passed, 97 assertions**, exit 0, 21.44 seconds (`r51-final.txt/xml`). Covers stale ownership in both schedules, inactive/role-revoked actor, create/delete in both schedules, move/delete in both schedules, and failure rollback. Permanent HTTP worker: `tests/Support/r51_project_writer.php`. CI adds a fresh guarded `task_management_r51_concurrency_ci` gate.

# 26. Existing concurrency regression

| Separate group | Passed | Assertions | Final evidence |
| --- | ---: | ---: | --- |
| Phase28 task event/execution/lifecycle + R1 accounts | 17 | 311 | core-final.txt |
| R2A account/settings | 1 | 10 | task_management_phase28_r2a_r51-v2.txt |
| R2B5 | 2 | 14 | task_management_phase28_r2b5_r51-v2.txt |
| R2B5Q | 15 | 93 | task_management_phase28_r2b5q_r51.txt |
| R3A2 | 3 | 22 | task_management_phase28_r3a2_r51-v2.txt |
| R3A3 | 8 | 71 | task_management_phase28_r3a3_r51-v2.txt |
| R4.2 membership | 1 | 7 | task_management_r42_membership_r51-v2.txt |
| R4.3 notification contention | 2 | 21 | task_management_r43_concurrency_r51-v2.txt |

All final named groups exit 0. Earlier diagnostic failures are retained. A PowerShell scalar argument expansion initially produced “Test file t not found”; proper string-array invocation corrected the harness. Concurrency helpers capture explicit intent and acquire prelocks in production order. R1's account worker now resolves actor before the transaction as HTTP authentication does; the old inside-transaction read could establish an artificial pre-sentinel REPEATABLE READ snapshot. AccountLifecycle application code was not changed, and its final six R1 cases pass within the 17-case group. Assertions were not disabled to hide those diagnostics.

# 27. Browser results

Maintained full run (`final-browser.txt`): **39 passed, 3 skipped, 1 failed**, exit 1, 21.3 minutes. Chromium workflows, stale approval, draft recovery, keyboard/responsive/axe and root/subdirectory checks pass. The failed available WebKit smoke completes login, creation, start, core pages and logout but fails its strict console assertion on service-worker/push background fetch access-control errors during navigation. An unmodified focused WebKit diagnostic repeats the console failure (`webkit-diagnostic.txt`); the unchanged `public/js/pwa.js` hash is recorded. Baseline causation is not asserted merely from unchanged source. No error filtering or engine suppression was added.

Firefox's installed binary cannot start (Windows side-by-side/spawn UNKNOWN), explicitly skipped with evidence. Two large-fixture-only cases are skipped in the clean-company run. Separate 10k browser run: **2 passed, 1 skipped**, exit 0 (`large-browser.txt`), with clean-company search case inapplicable there.

After adding/strengthening R5.1 browser tests: delayed approval focused run **1 passed**; final corrected project UI acceptance **1 passed** (`project-browser-verified.txt`). Early project fixture runs omitted required fields and therefore sent no create request; fields were corrected, request/200 assertions retained, and the final test passes. These focused results are separate, not an invented all-green full-suite total. Earlier invalid browser/cache/npm-overlap runs remain diagnostic only.

# 28. Clean-company results

The main run used fresh disposable `task_management_r41_browser_r51_final`, process-only configuration, isolated caches and ports 8063/8064. Setup, manager/PM/member provisioning, project/membership/task, start/submit/review/revision/resubmit/approve occurred through real UI. The read-only database oracle verifies persisted workflow state.

Permanent final project acceptance creates a new replacement PM/project, proves old/new access, adds a member and task using required fields, rejects protected deletion 409 and deletes a separate empty project 200. The approved current-work workflow and stale-work rejection both pass. These do not certify customer environments, real push/SMTP or deployed hosting.

# 29. Resource/performance regression

R4.3 10k fixture: PASS (`resource-results.json`, `resource-exit.txt`). 22 fresh PHP processes preserve the 128M limit; highest measured peak is 44 MiB. Existing query/row/output/memory budgets and repeat scheduler assertions pass. Two large-fixture browser cases pass. These are bounded fixture checks; lock-barrier timings are not request latency claims. R5-010 and R5-017 optimization remain outside scope.

# 30. Dependency/build gates

Composer validate --strict, Composer audit, npm ci, full npm audit, npm audit --omit=dev and npm run build all finally exit 0. Composer advisories/abandoned arrays are empty; npm audits report zero vulnerabilities. Network/cache-restricted initial attempts failed and are retained; authorized network runs completed unchanged dependency resolution. No lockfile upgrades.

Final Pint on tracked PHP plus new files passes (`pint-complete-exit.txt`=0). PHP syntax records 421 successful invocations including generated Blade/new files (some explicitly added files are also tracked); JavaScript syntax checks 34 files with zero failures. Blade compilation and generated syntax pass. No additional configured JS lint gate exists. `git diff --check` passes. PHPStan cleanup was not introduced. Final `.env`, composer.lock, package-lock.json and PWA source hashes match R5 in `final-protected-hashes.json`.

# 31. Migration impact

No migration added or rewritten. Existing task lock_version is sufficient. Disposable SQLite/MariaDB fresh migration and named fixture initialization paths were exercised. No production schema or customer database was changed.

# 32. Files changed

The following source/test/documentation paths changed relative to the starting revision. Qualification evidence remains local under the dedicated output directories.

- `app/Http/Controllers/ProjectsController.php`
- `app/Http/Controllers/TasksController.php`
- `app/Http/Requests/ApproveTaskRequest.php`
- `app/Http/Requests/CancelTaskRequest.php`
- `app/Http/Requests/ChangeTaskDeadlineRequest.php`
- `app/Http/Requests/HoldTaskRequest.php`
- `app/Http/Requests/OverrideApproveTaskRequest.php`
- `app/Http/Requests/ReassignTaskReviewerRequest.php`
- `app/Http/Requests/ReopenApprovedTaskRequest.php`
- `app/Http/Requests/RequestTaskRevisionRequest.php`
- `app/Http/Requests/ResubmitTaskRequest.php`
- `app/Http/Requests/ResumeTaskRequest.php`
- `app/Http/Requests/StartTaskRequest.php`
- `app/Http/Requests/StartTaskReviewRequest.php`
- `app/Http/Requests/StartTaskRevisionRequest.php`
- `app/Http/Requests/SubmitTaskRequest.php`
- `app/Http/Requests/TaskTransitionRequest.php`
- `app/Services/ProjectMembershipService.php`
- `app/Services/ProjectWriterLocks.php`
- `app/Services/TaskLifecycleService.php`
- `app/Services/TaskTransitionExecutor.php`
- `app/TaskTransitions/ReopenApprovedTask.php`
- `app/ValueObjects/TaskOperationContext.php`
- `resources/views/completed-tasks.blade.php`
- `resources/views/tasks.blade.php`
- `scripts/ci-mariadb-tests.sh`
- `tests/Browser/README.md`
- `tests/Browser/core-workflow.spec.js`
- `tests/Browser/z-r51-project-integrity.spec.js`
- `tests/Feature/CanonicalTaskLifecycleTest.php`
- `tests/Feature/CoreWorkflowIntegrityTest.php`
- `tests/Feature/CorrectnessNeighborsTest.php`
- `tests/Feature/ProjectMembershipTest.php`
- `tests/Feature/R51LifecycleIntentTest.php`
- `tests/Feature/R51ProjectWriterMariaDbConcurrencyTest.php`
- `tests/Feature/SingleCompanyReleaseBlockerTest.php`
- `tests/Feature/TaskApprovalWorkflowTest.php`
- `tests/Feature/TaskDeadlineGenerationTest.php`
- `tests/Feature/TaskDeadlineOwnerRoutingTest.php`
- `tests/Feature/TaskExecutionMariaDbConcurrencyTest.php`
- `tests/Feature/TaskExecutionTransitionsTest.php`
- `tests/Feature/TaskLifecycleMariaDbConcurrencyTest.php`
- `tests/Feature/TaskNotificationAfterCommitTest.php`
- `tests/Feature/TaskNotificationIsolationTest.php`
- `tests/Feature/TaskOptimisticConcurrencyTest.php`
- `tests/Feature/TaskReopenAndCancellationWorkflowTest.php`
- `tests/Feature/TaskRevisionWorkflowTest.php`
- `tests/Feature/TaskSubmissionReviewTest.php`
- `tests/Feature/TaskTransitionExecutorTest.php`
- `tests/Feature/TaskWorkflowEndToEndTest.php`
- `tests/Feature/WorkflowLockdownTest.php`
- `tests/Support/r1_lifecycle_concurrency_worker.php`
- `tests/Support/r3a2_concurrency_worker.php`
- `tests/Support/r3a3_concurrency_worker.php`
- `tests/Support/r42_legacy_upgrade.php`
- `tests/Support/r51_project_writer.php`
- `tests/Support/task_notification_race_worker.php`
- `tests/Support/transition_task.php`
- `tests/TestCase.php`

# 33. Commits

- `f598660bd692428b23c8d86b5c6b48fcfbb8796d` — fix: repair project authorization and transactional lifecycle intent (interdependent source boundaries).
- `e956cef32ff0a5da8af8b26770e5f96fe1135634` — test: qualify R5.1 authorization intent and project writer integrity (permanent regressions, intent-aware fixtures, CI, final fresh actor create boundary).
- This report is committed separately as `docs: record R5.1 repair qualification and browser limitation`; its SHA is available from final Git evidence/current HEAD. No history was rewritten to retroactively split the shared boundary.

# 34. Remaining R5 findings

Closed by R5.1 at the defect level: **R5-001, R5-002, R5-007**. Their root reproductions are prevented by source changes and permanent tests. Overall qualification remains FAILED for the reported available-engine browser gate, plus the historical evidence preservation limitation.

Still open for later phases: **R5-003, R5-004, R5-005, R5-006, R5-008, R5-009, R5-010, R5-011, R5-012, R5-013, R5-014, R5-015, R5-016, R5-017, R5-018, R5-019**. No later finding is marked repaired. R5.2 was not started.

# 35. Final Git state

Branch main; source and maintained tests committed. This report is the final documentation commit. Tracked/staged Git is clean after that commit; preexisting untracked audit material and new local R5.1 evidence remain untracked. `output/r5-1/final-git-*` records the post-report HEAD, status, staged status, diff check and remotes.

Identity-verified private PHP servers on 8061–8065 were stopped; private MariaDB was shut down and its disappearance verified. Only generated R5.1 config-cache files were removed using checked absolute paths. Logs, traces, fixtures and disposable DB files remain local evidence. No customer XAMPP process was stopped.

# 36. Deployment state

No push, deploy, release tag, production remote change, customer `.env` edit or customer DB mutation occurred. This is local repair work with an explicitly failed overall qualification classification, not a release authorization.
