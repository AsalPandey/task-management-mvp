# Deeper production audit — follow-up
Date: 14 September 2026  
Scope: current Laravel working tree; supplements the [initial audit](<C:/xampp/htdocs/Task Management/task-management/PRODUCTION_READINESS_AUDIT.md>).

**Conclusion:** The most serious remaining weaknesses are inconsistent authorization across delivery channels, missing workflow recovery paths, and incomplete preservation of user intent. Individual task transitions have safeguards, but the whole lifecycle still breaks under ordinary account changes, repeat revisions, stale browser tabs, and upgrades.

This pass added [13 targeted probes](<C:/xampp/htdocs/Task Management/task-management/audit/DeepAuditProbeTest.php>). All reproduced the observed behavior: **13 tests, 55 assertions**. See [raw results](<C:/xampp/htdocs/Task Management/task-management/audit-deep-probe-results.txt>). The original 9 probes remain separate, giving 22 audit probes across both passes. These are evidence tests that deliberately assert defects, not acceptance tests for a healthy application.

The existing full-suite result remains the result from the first pass: 303 passed, 2 failed, 11 skipped. It was not rerun because this pass changed no application code. No dependencies were upgraded, no production data was modified, and no actual concurrent MariaDB race, internal-network request, or real-device test was performed.

## 1. New task notifications outlive the recipient's authorization — P1

**Reproduction:** Create a task whose original creator is a project manager who no longer controls the task's current project. Confirm that the creator cannot view the task. Dispatch the submission notification. The creator still receives it.

[TaskNotificationDispatcher](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskNotificationDispatcher.php:93>) selects creator/reviewer/assignee recipients by relationship, then only filters nulls and duplicates. It does not require current task visibility. [TaskReviewWorkflowNotification](<C:/xampp/htdocs/Task Management/task-management/app/Notifications/TaskReviewWorkflowNotification.php:45>) includes the current title, actor identities, state, dates, and sometimes a feedback excerpt.

**Why this matters:** The protected task page can correctly return 403 while fresh information about the same task continues to reach the former manager. This is ongoing disclosure after authorization changes, not merely retention of old notifications. The probe exercised the dispatcher using fake delivery; it did not send messages to real users.

**Repair:** Apply a current-authorization recipient policy before creating each notification and before delivering queued content. Decide which minimal messages are appropriate when access itself is removed. Keeping someone in `created_by` must not permanently authorize future updates.

**Acceptance:** Transfer project ownership or move a task between projects; the old creator receives no subsequent protected task content. Existing legitimate recipients still receive required updates.

## 2. Submission notes are stored but reviewers cannot read them — P1 workflow defect

**Reproduction:** Submit a task with a unique submission note. Verify the note is in `task_submissions`. As the reviewer, open the task list, task detail endpoint, and timeline. The note is absent from all three.

[SubmitTask](<C:/xampp/htdocs/Task Management/task-management/app/TaskTransitions/SubmitTask.php:54>) and ResubmitTask persist the note, but [TaskViewData](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskViewData.php:15>) omits submissions, the task page does not display submitted notes, and the timeline emits only generic event labels.

**Why this matters:** A worker may place deliverable links, review instructions, or caveats in the submission dialog. The application accepts them, yet the reviewer must approve without access to that information through the tested read surfaces.

Approval comments follow a similar write-without-readable-detail pattern in source: they are stored and referenced, but no corresponding human-readable retrieval route was found. That extension was source-reviewed rather than separately exercised.

**Repair:** Present the current submission, its author/time, note, and revision-cycle linkage to authorized reviewers. Provide submission/approval history without exposing management-only reasons to inappropriate viewers.

**Acceptance:** A note entered during submission/resubmission is visible verbatim and safely escaped in the review workflow. Prior submissions remain distinguishable.

## 3. Reopened tasks tell workers to revise without showing instructions — P1 workflow defect

**Reproduction:** Approve a task, then reopen it with a unique reason. A revision cycle is created with `reopen_reason` populated and `formal_feedback` null. The assignee's task page, detail endpoint, and timeline do not show the reason.

[ReopenApprovedTask](<C:/xampp/htdocs/Task Management/task-management/app/TaskTransitions/ReopenApprovedTask.php:76>) records the private reason, while the [task card](<C:/xampp/htdocs/Task Management/task-management/resources/views/tasks.blade.php:1230>) displays only `formal_feedback`. Notifications deliberately limit the reopen reason to management.

**Why this matters:** Privacy rules may intentionally hide management rationale, but the worker still needs an actionable description of the required work. The current reopen form does not capture a separate worker-visible instruction.

**Repair:** Preserve the private management reason and require a separate assignee-visible rework brief, or explicitly classify part of the reason for sharing. Do not fix this by indiscriminately exposing private notes.

**Acceptance:** Reopening requires useful worker-visible instructions, and the assignee can read them before starting revision.

## 4. Completed work can enter an unrecoverable reviewer dead end — P1

**Reproduction:** Approve a task, deactivate its assigned reviewer, and attempt to reopen as another active manager. Reopen returns 422. Attempt to replace the reviewer first: the reviewer reassignment endpoint returns 403 because the task is final.

The relevant rules are in [ReopenApprovedTask](<C:/xampp/htdocs/Task Management/task-management/app/TaskTransitions/ReopenApprovedTask.php:50>) and [TaskPolicy](<C:/xampp/htdocs/Task Management/task-management/app/Policies/TaskPolicy.php>). Reopen requires an eligible existing reviewer, but reviewer reassignment requires a non-final task.

**Why this matters:** This is stronger than the first audit's general handoff concern: the two policies create a circular dependency with no normal application route out. A departed reviewer's account would need reactivation or an operator intervention merely to reopen historical work.

**Repair:** Allow an authorized manager to choose a new eligible reviewer as part of the reopen transaction, preserving the identity of the historical approver. Similar handling is needed when the old assignee has left.

**Acceptance:** Reopen completed work whose former reviewer is inactive/deleted or no longer eligible, without changing historical approval attribution or requiring account reactivation.

## 5. Password changes do not invalidate an existing authenticated session — P1 security/recovery

**Reproduction:** Log in, change the persisted password through the same model-write pattern used by account management, clear the in-process auth guard, and request the protected task page using the existing session. Access remains valid.

[TeamManagementController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/TeamManagementController.php:61>), [ProfileController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProfileController.php:42>), and [PasswordController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/Auth/PasswordController.php>) update the hash without a shared session-revocation policy. The configured middleware does not include authentication-session hash checking. The probe represents a password changed externally to the existing session; it did not exercise a second physical browser or the password-reset form.

**Why this matters:** Changing a password to recover a potentially compromised account may leave an already authenticated session usable. Rotating a remember token alone does not establish revocation of active sessions.

**Repair:** Define which credential changes revoke all other sessions, which revoke all sessions, and whether push devices should also be revoked. Enforce the policy through centralized account-security logic and session-version/hash validation.

**Acceptance:** Two independent sessions log in; an administrative password reset invalidates the previously issued session on its next request. Ordinary self-service changes follow the explicitly chosen policy.

## 6. Dormant sessions survive deactivate/reactivate cycles — P2

**Reproduction:** Log in, deactivate the account, reactivate it before that session makes another request, clear the guard, and access the tasks page. The original session is accepted.

[EnsureUserIsActive](<C:/xampp/htdocs/Task Management/task-management/app/Http/Middleware/EnsureUserIsActive.php:13>) invalidates the session only when a request arrives while the account is inactive. Deactivation does not invalidate all issued sessions.

**Why this matters:** Deactivation blocks access while active=false, but it is not durable session revocation. A dormant browser can regain access on account reactivation without fresh authentication.

**Repair:** Increment a session/security version or revoke issued sessions during deactivation. Decide explicitly whether reactivation requires login.

**Acceptance:** A pre-deactivation session remains invalid after reactivation until the user authenticates again.

## 7. Row locking does not prevent stale browser edits from overwriting newer work — P1 data integrity

**Reproduction:** Keep an old payload containing the original title. Save a newer title from another edit. Submit the old payload with an unrelated description change. Both updates return success, and the newer title is overwritten.

[TaskLifecycleService::update](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskLifecycleService.php:121>) locks the current row, but accepts incoming fields without checking the version that the user originally read. The frontend submits complete form fields.

**Why this matters:** Database row locks serialize writes; they do not detect that the second request was based on obsolete data. Managers editing from two tabs can silently undo each other's work.

**Repair:** Introduce an explicit row revision/version or ETag and require it for editing. Return a conflict with current values so the user can reconcile. Apply the same principle to project and account forms where appropriate.

**Acceptance:** The second stale edit returns 409/412 or an equivalent explicit conflict; unrelated edits are not silently lost.

## 8. Timelines freeze after the oldest 100 events — P2

**Reproduction:** Insert 101 ordered task events. The timeline returns sequences **1–100**, omitting sequence 101.

[Task::events](<C:/xampp/htdocs/Task Management/task-management/app/Models/Task.php:143>) adds ascending sequence ordering. [TaskTimelineService](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskTimelineService.php:37>) appends descending ordering instead of replacing it, then applies a limit. Ascending ordering remains the primary sort.

**Why this matters:** The more active a task becomes, the more likely its latest approval, revision, or cancellation disappears from its visible audit trail. There is no pagination path for the omitted history.

**Repair:** Clear existing order before selecting the latest page, or use a dedicated event query. Provide cursor pagination for complete history.

**Acceptance:** With more than 100 events, the latest event is visible and all older events are reachable without gaps or duplication.

## 9. Deleted human actors are relabeled as “System” — P2 audit accuracy

**Reproduction:** Create a human-authored event, soft-delete the actor, and read the timeline. The database still retains the actor ID, but the displayed actor becomes “System.”

[TaskEvent::actor](<C:/xampp/htdocs/Task Management/task-management/app/Models/TaskEvent.php>) uses the default user scope, which hides soft-deleted users. The [timeline fallback](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskTimelineService.php:71>) substitutes “System.”

**Why this matters:** This changes the apparent attribution of a human action. It undermines incident investigation and makes a real person’s action look automated.

**Repair:** Preserve an appropriate immutable actor snapshot or resolve soft-deleted actors where authorized. Distinguish “former user,” “unknown actor,” and an actual system action.

**Acceptance:** Deleting an account cannot reclassify its historical human actions as system-generated.

## 10. Project member JSON exposes unnecessary account details — P2 data minimization

**Reproduction:** As a team member, request the project's member list. Another member's email, timezone, and notification preferences are included.

[ProjectsController::members](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProjectsController.php:109>) returns complete User models with role relationships rather than a purpose-built member resource. User hides passwords and remember tokens, but not the other account fields.

**Why this matters:** Access to a project roster unnecessarily becomes access to coworkers' account settings. Business email may be intentionally visible, but preference and account metadata exposure has no demonstrated roster requirement.

**Repair:** Use an explicit member DTO/resource with only approved roster fields. Apply the same serialization policy to membership-change responses.

**Acceptance:** A team member sees approved identification/role fields; unrelated preferences and account/security metadata are absent.

## 11. The documented upgrade path can leave existing tasks unable to update — P1 for upgrades

**Reproduction:** Set a task's UID to null, matching the state allowed by the canonical-field migration for pre-existing rows. A normal authorized update returns **500**, and the edit rolls back because event recording requires a valid UID.

[Canonical-field migration](<C:/xampp/htdocs/Task Management/task-management/database/migrations/2026_07_19_000001_add_canonical_fields_to_tasks_table.php:17>) adds a nullable UID without populating it. A [backfill command](<C:/xampp/htdocs/Task Management/task-management/app/Console/Commands/BackfillTaskUids.php:20>) exists, but the production deployment script does not invoke it or enforce a preflight check.

The workflow migration also introduces nullable reviewers without assigning existing work. Existing completed rows may lack approval/submission records needed for reopening. Older completed-task table contents are deliberately excluded by current runtime readers, so upgrades need a documented reconciliation/export strategy.

**Why this matters:** Fresh-database tests can pass while an upgrade leaves real historical work uneditable or operationally inaccessible. The probe isolated the null-UID failure; a complete old-release database migration rehearsal was not performed.

**Repair:** Define supported upgrade starting versions, inventory legacy rows, run resumable backfills, reconcile historical completion data, and block traffic until invariants are satisfied. Do not fabricate historical approvals to make validation pass.

**Acceptance:** Upgrade a representative sanitized old database. Existing task identities, histories, editability, and explicitly supported reopen behavior must reconcile before enabling the application.

## 12. Editing a revision deadline can replace the active review deadline — P2

**Reproduction:** Put a resubmitted task in Submitted state with an active revision-cycle record, no current revision deadline, and a review deadline five days away. Change its revision deadline to two days away. The request succeeds and `activeDeadline()` now returns two days away, while the task remains Submitted.

[ChangeTaskDeadline](<C:/xampp/htdocs/Task Management/task-management/app/TaskTransitions/ChangeTaskDeadline.php:19>) permits revision-date edits in Submitted/InReview. [Task::activeDeadline](<C:/xampp/htdocs/Task Management/task-management/app/Models/Task.php:83>) prefers any populated active-cycle revision deadline before considering the review state.

**Why this matters:** An edit to revision metadata changes review-stage overdue calculations and reminders. Two individually valid methods disagree about which workflow stage controls the deadline.

**Repair:** Select the active deadline by current state first, with cycle context inside each state. Separate historical revision-date correction from the current active deadline, or prohibit the ambiguous edit.

**Acceptance:** A resubmitted task remains governed by its review deadline; correction of an earlier revision date cannot silently change review urgency.

## 13. Performance concern now has measured query evidence

This strengthens F16 in the initial report rather than adding an unrelated defect.

A warmed analytics request with **10 members and zero tasks made 58 SQL queries**. With **20 members and zero tasks it made 78 SQL queries**. Adding 10 members added exactly 20 queries. The probe warmed view/session/role initialization before comparing requests.

This confirms per-member query growth even without task volume. It is not a production latency benchmark: the measurements used SQLite in-memory and cannot be used to claim a specific response time or user capacity.

**Repair:** Aggregate workload and completion counts for all visible members in grouped queries. Avoid loading unused task relationships. Set a request-query budget and measure production-like datasets on the real database family.

**Acceptance:** Increasing roster size does not add two queries per member; query counts stay bounded for the summary page, and representative latency/memory limits are met.

## Additional source-level deployment issue: inconsistent subdirectory URLs

[Projects frontend](<C:/xampp/htdocs/Task Management/task-management/resources/views/projects.blade.php>) hardcodes root-relative routes such as `/projects/...`; the tasks frontend likewise uses `/tasks/...`. Other controls correctly use generated Laravel routes.

If the app is hosted at a subdirectory such as the XAMPP path used in this workspace, those hardcoded calls target the origin root rather than the application prefix. A dedicated production virtual host at the origin root avoids this particular issue, so this is conditional, not a demonstrated production outage.

Replace hardcoded paths with generated URLs/data attributes or explicitly document root-only hosting. A browser test at the actual configured base URL is still needed.

## What these findings change about the repair plan

1. **Treat permissions as a rule across all channels.** Task pages, notifications, queued push, history, and roster APIs need consistent current authorization and field visibility.
2. **Define account and work recovery before extending features.** Reopening after departure, replacing actors, and revoking sessions need first-class operations.
3. **Preserve user intent.** Submission notes must be readable; stale edits must conflict; reopened work must contain visible instructions.
4. **Audit the history itself.** Pagination, human attribution, and migration compatibility are part of correctness.
5. **Test transitions between components.** Add two-session security tests, stale-tab tests, ownership-transfer tests, old-database upgrade tests, and reviewer-facing read assertions.
6. **Measure instead of relying on pagination labels.** A paginated page can still issue unbounded relationship and per-user queries.

The next production-readiness milestone should be closing these verified failure paths and converting the probes into regression tests for correct behavior. The application source remains unchanged by this audit.

