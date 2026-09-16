# Production readiness audit

**Follow-up:** The [deeper audit](<C:/xampp/htdocs/Task Management/task-management/DEEP_PRODUCTION_AUDIT.md>) adds 13 targeted probes covering session revocation, notification authorization, stale edits, missing submission details, timeline correctness, and upgrade failures.
Date: 14 September 2026  
Application: Task Management — Laravel 12.64.0, PHP 8.4.20  
Baseline: Git HEAD `00de68f`, including the pre-existing uncommitted working-tree changes.

**Verdict: do not approve this working tree for production yet.** The application has a useful foundation and extensive tests, but there are confirmed security, workflow, reporting, and usability defects. A successful build does not establish operational readiness.

## Scope and evidence

The deployable application examined was `task-management`. The parent directory also contains an HTML prototype and three historical release/baseline copies. Those copies were identified, not independently audited as current applications. This review covers routes, authentication, authorization, task transitions, project/user management, notifications, reporting, frontend templates, PWA behavior, tests, dependencies, and deployment documentation.

No application source fixes, dependency upgrades, live data mutations, or deployments were made. Audit evidence and explicit reproduction tests were added. The frontend build regenerated ignored build assets. Existing edits were preserved.

| Verification | Actual result |
|---|---|
| Existing full suite, `php artisan test --compact` | **303 passed, 2 failed, 11 skipped; 2,364 assertions** |
| Additional isolated audit probes | **9 passed; 21 assertions confirming observed defects** |
| Existing formatting gate, `php vendor/bin/pint --test` | Passed before adding audit probes |
| `npm run build` | Passed, Vite 6.4.3, 56 modules |
| Composer security audit | **13 advisories across 3 packages** |
| npm security audit | **4 affected packages: 2 high, 1 moderate, 1 low** |
| Browser check of local XAMPP URL | Connection refused; no running application was available there |
| Production database concurrency | Not verified; 11 database-specific tests skipped |
| Real-device PWA, load, backup restore, deployed infrastructure | Not verified |

The probes use the existing PHPUnit configuration with SQLite `:memory:`. They assert the broken behavior to preserve audit evidence; their passing result must **not** be interpreted as acceptance tests passing. Convert them into expectations for the repaired behavior when implementing fixes.

Evidence: [full suite](<C:/xampp/htdocs/Task Management/task-management/audit-test-results.txt>), [audit probes](<C:/xampp/htdocs/Task Management/task-management/audit/ProductionAuditProbeTest.php>), [probe results](<C:/xampp/htdocs/Task Management/task-management/audit-probe-results.txt>), [Composer results](<C:/xampp/htdocs/Task Management/task-management/audit-composer-results.json>), [npm results](<C:/xampp/htdocs/Task Management/task-management/audit-npm-results.json>), [formatting results](<C:/xampp/htdocs/Task Management/task-management/audit-style-results.txt>).

Priority meanings: **P1** = resolve before production release; **P2** = material defect to resolve before broader rollout, or explicitly scope out; **P3** = lower-impact product/maintenance improvement. No production compromise or exploited critical vulnerability was established.

## What is already working well

- Dedicated task commands enforce start, hold, resume, submit, review, revise, approve, reopen, and cancel operations.
- The transition executor locks the task row, rechecks authorization, writes history/events in a transaction, and uses bounded transaction retries.
- Canonical task IDs and retained history avoid moving work between unrelated runtime tables on completion.
- Reviewer separation, role/project policies, active-user middleware, CSRF protection, and login throttling are present.
- Tests cover stored XSS, CSV injection, project-manager account boundaries, retired completion routes, notification ordering, and push ownership.
- Default production seeding avoids demo accounts. Setup requires an explicit token or console command.
- The UI includes skip navigation, responsive styling, modal keyboard handling, and push status feedback.
- A production runbook exists with backup, rollback, queue, scheduler, and restore guidance.

These are meaningful strengths. The gaps below occur particularly where otherwise sound components interact.

## P1 findings: release blockers

### F01 — Dependencies have unresolved security advisories

**Evidence:** Current lockfile contains Guzzle 7.15.1, CommonMark 2.8.2, and sodium_compat 2.5.0. The registry audit reports 13 PHP advisories: 9 high, 3 medium, and 1 with no severity supplied. npm identifies Browserslist and nanoid as high, baseline-browser-mapping as moderate, and postcss-selector-parser as low.

**Impact:** The PHP packages are production dependencies. The npm packages are in the development/build dependency tree; their reported severity does not mean there is a remotely exploitable browser vulnerability in this application. Application reachability of every advisory was not demonstrated.

**Action:** Update affected packages through a reviewed lockfile change and rerun functional, build, and audit checks. The Guzzle maintainer lists 7.15.2 as a patched 7.x release. The CommonMark audit includes an affected range below 2.10.0. Confirm all advisories are resolved rather than stopping after one package upgrade. Sources: [Guzzle maintainer advisory](https://github.com/guzzle/guzzle/security/advisories/GHSA-v5mv-p594-2x33), [CommonMark maintainer advisory](https://github.com/thephpleague/commonmark/security/advisories/GHSA-8rr7-cvq3-gmfh).

**Release test:** Audits return no unresolved applicable advisories, with documented reachability decisions for any accepted exception.

### F02 — Browser push accepts arbitrary HTTPS destinations, including loopback

**Evidence:** [subscription validation](<C:/xampp/htdocs/Task Management/task-management/app/Http/Requests/StoreBrowserPushSubscriptionRequest.php:30>) only requires an HTTPS URL. [registration](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/BrowserPushController.php:35>) stores it, and [the transport](<C:/xampp/htdocs/Task Management/task-management/app/Services/MinishlinkBrowserPushTransport.php:15>) passes that endpoint into WebPush. An isolated HTTP probe successfully registered `https://127.0.0.1/internal`.

**Impact:** With valid subscription encryption material and configured push delivery, an authenticated account can influence a server-side outbound request destination. This creates an SSRF risk, constrained by the transport, TLS, and network environment. The probe verified acceptance and the code path, **not** successful access to an internal service; no internal network requests were made.

**Action:** Apply a documented push-provider/destination policy, reject private and special-use destinations, validate DNS results at connection time, and enforce outbound network restrictions. Cover IPv6, redirects where supported, alternate host representations, and DNS changes. Add registration quotas and throttling.

**Release test:** Private/loopback/link-local destinations are rejected before any transport attempt; valid supported browser providers still work.

### F03 — Self-service deletion bypasses business safeguards and can remove the last manager

**Evidence:** [ProfileController::destroy](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProfileController.php:85>) checks the password and deletes the account without checking assignments, reviewer duties, active projects, or remaining managers. Team-management deletion has some of those checks, but the profile endpoint bypasses them. The audit probe deleted the only manager successfully.

**Impact:** A company can lose its only management account. An assignee or reviewer can disappear while tasks still depend on that account. Soft deletion preserves rows but does not provide an operational handoff.

**Action:** Centralize account closure policy for every endpoint. Protect the last active manager, require reassignment of open responsibilities, and perform checks and updates atomically.

**Release test:** Reject last-manager deletion and deletion with unresolved responsibilities; allow closure after a verified handoff.

### F04 — Ownership and account changes can strand active workflow

**Evidence:** [project update](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProjectsController.php:67>) changes the manager without reconciling task reviewers. [reviewer eligibility](<C:/xampp/htdocs/Task Management/task-management/app/Services/ReviewerEligibilityService.php:12>) requires a project-manager reviewer to remain the current manager of the project. A probe confirmed that replacing the project manager leaves the old reviewer assigned but ineligible. [deactivation](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/TeamManagementController.php:124>) and role changes also lack a workflow handoff.

**Impact:** Start/submit/review actions can stop working immediately after a normal management change. Reviewer reassignment exists, but the change does not identify or repair affected work. More seriously, an inactive assignee in submitted/revision work cannot be replaced through the generic update path: [task details are frozen after submission](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskLifecycleService.php:345>), and no dedicated assignee-handoff route is present.

**Action:** Add an audited reassignment workflow covering active execution, review, and revision. Make manager replacement, deactivation, and role changes either block with an affected-work list or perform an explicit handoff.

**Release test:** Transfer a project manager and deactivate an assignee in every non-final task state; each task must retain a valid next actor and recovery path.

### F05 — Notification failures can return failure after the business operation succeeded

**Evidence:** [notification dispatch](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskNotificationDispatcher.php:243>) throws after database commit. Controllers do not convert this into a successful business-operation response with separate delivery status. The existing [delivery-failure test](<C:/xampp/htdocs/Task Management/task-management/tests/Feature/TaskNotificationDeliveryFailureTest.php>) explicitly confirms that completion remains committed while an exception propagates.

**Impact:** A user can see an error even though a task was created or approved. Retrying creation can create duplicate work. Approval retries can report state conflicts. There is also a crash window between commit and dispatch without durable delivery intent.

**Action:** Persist a notification/outbox record in the transaction, deliver asynchronously, and make delivery retryable and idempotent. Return the actual committed task state independently of notification transport success. Add an idempotency mechanism for create requests.

**Release test:** Simulate queue/notification failure during create and approve. The response must accurately describe the saved operation; retry must not duplicate it.

### F06 — Assignment candidate scope fails existing tests

**Evidence:** [TasksController candidate query](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/TasksController.php:142>) loads all active project managers and team members, without limiting candidates to visible-project membership. Both failing tests in [TaskAssignmentCandidateDataTest](<C:/xampp/htdocs/Task Management/task-management/tests/Feature/TaskAssignmentCandidateDataTest.php:40>) cover this boundary. A similar global query appears in the manager dashboard.

**Impact:** Users receive employee IDs/names beyond the expected project scope, and assignment options do not match actual server-side membership requirements. This is a data-minimization and workflow consistency defect; it does not prove that arbitrary cross-project task assignment is accepted.

**Action:** Share one candidate-selection service between the task screen and dashboard. If company-wide discovery is intentional, document the authorization change and update all related tests and UX together.

**Release test:** Existing candidate-scope tests pass; outside-project candidates are absent under the intended policy.

### F07 — Reminder flags suppress later deadlines and workflow stages

**Evidence:** Reminder commands exclude tasks once task-level sent-at fields are set. [ChangeTaskDeadline](<C:/xampp/htdocs/Task Management/task-management/app/TaskTransitions/ChangeTaskDeadline.php:81>) does not reset them. Submit, resubmit, and reopen likewise do not establish separate reminder identity for each stage. The audit probe changed an execution deadline and confirmed both sent flags remained populated.

**Impact:** A task reminded during execution may never receive its review or revision reminder. Extending a deadline can prevent notification when the new deadline arrives.

**Action:** Track reminders by task, stage/cycle, deadline version, recipient, and notification type. At minimum, reset the relevant flags whenever the active deadline or responsibility changes.

**Release test:** Reminder → extension → reminder, execution → review, and reopen → revision all notify once for the correct new deadline.

### F08 — Review deadline notifications go to the assignee instead of the reviewer

**Evidence:** [deadline reminders](<C:/xampp/htdocs/Task Management/task-management/app/Console/Commands/SendTaskDeadlineReminders.php:36>) and [overdue notifications](<C:/xampp/htdocs/Task Management/task-management/app/Console/Commands/SendOverdueTaskNotifications.php:34>) use the active deadline but always notify the assignee. A probe confirmed that a submitted task due for review tomorrow notified the assignee and not the reviewer.

**Impact:** The person responsible for approval can miss the deadline while the person waiting for review receives a misleading overdue warning.

**Action:** Resolve the responsible actor from the workflow stage. Distinguish review reminders from execution/revision reminders in both text and links.

**Release test:** Reviewer receives review-stage reminders; assignee receives execution/revision reminders.

## P2 findings: functionality, usability, and reliability

### F09 — Email verification middleware is ineffective

[User](<C:/xampp/htdocs/Task Management/task-management/app/Models/User.php:13>) does not implement `MustVerifyEmail`, although protected routes use `verified`. A probe confirmed an unverified manager can access the tasks page. Profile edits clear verification timestamps without enforcing verification.

Choose a coherent policy: verified ownership with invitation/reverification delivery, or explicitly admin-provisioned accounts without a verification claim. Simply enabling the interface can lock out newly created accounts unless provisioning and resend behavior are repaired.

### F10 — The profile page returns 200 while omitting its forms

[Profile view](<C:/xampp/htdocs/Task Management/task-management/resources/views/profile/edit.blade.php:1>) supplies a component slot, but [the app layout](<C:/xampp/htdocs/Task Management/task-management/resources/views/layouts/app.blade.php:50>) only renders `@yield('content')`. A rendered-response probe confirmed the profile information and password form content is missing. The existing test checks only HTTP 200.

Render the slot or migrate the page to the layout's section convention. Also restore the required Alpine/Vite initialization for the component-based forms; the authenticated layout has `@vite` commented out. Test actual form presence and keyboard interaction, not just response status.

### F11 — Notification preferences are saved but not honored or accurately displayed

[ProfileController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProfileController.php:68>) persists the preferences, but notification delivery does not consult them. The probe confirmed a disabled deadline preference still selects the database channel. [Settings inputs](<C:/xampp/htdocs/Task Management/task-management/resources/views/settings.blade.php:229>) are always checked, regardless of stored values. Save feedback is written into the hidden Profile tab, not the active Notifications tab.

Bind inputs to saved preferences, enforce preferences in a shared notification policy, and show success/errors beside the affected control. State explicitly if any mandatory operational notification cannot be disabled.

### F12 — Date-only deadlines become overdue at the start of the due date

[Task date casts and activeDeadline](<C:/xampp/htdocs/Task Management/task-management/app/Models/Task.php:83>) return midnight dates; consumers compare them with `isPast()`. The probe confirmed a task due today is overdue at noon today.

If the product means “due by the end of this day,” compare calendar dates in the company timezone or use an explicit end-of-day boundary. If midnight deadlines are intentional, show that time clearly. Include day-boundary and timezone tests.

### F13 — Dashboard completion percentages have the wrong denominator

[ManagerDashboardController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ManagerDashboardController.php:39>) divides today's completions by currently active tasks. [TeamDashboardController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/TeamDashboardController.php:58>) uses the same pattern.

Two completed tasks and one active task produce 200%; completing the last active task can instead produce 0%. Define the relevant cohort and divide completions by that cohort, or label a different metric explicitly. Test zero remaining work and multiple completions.

### F14 — Analytics date ranges and historical metrics are inconsistent

[AnalyticsController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/AnalyticsController.php:80>) mixes active tasks created within the range with completions occurring within the range. Cancelled count is not date-filtered. Charts always generate the most recent 30 days even when a historical date range is selected. The overdue trend projects current active tasks backwards rather than reconstructing actual historical overdue state.

[Member analytics](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/TeamManagementController.php:169>) still uses the execution `due_date` instead of the current review/revision deadline. Its “created” trend excludes tasks that are now completed, so completing a task changes its historical creation counts.

Define workload, throughput, cohort completion, cancellation, and overdue-snapshot metrics separately. Query task events/snapshots for historical state. Add fixtures where creation, completion, review, and selected ranges differ.

### F15 — Analytics inputs lack explicit validation

Date and assignee filters in [AnalyticsController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/AnalyticsController.php:80>) are read directly from the request. There is no date format/order/range bound or scalar-ID validation.

Malformed inputs can lead to misleading results or database/type errors; extremely broad ranges amplify already expensive queries. SQL parameter binding is present, so this finding is not a claim of SQL injection. Add a FormRequest and return controlled validation errors.

### F16 — Important pages and scheduled jobs have unbounded data loading

[AnalyticsController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/AnalyticsController.php:91>) loads full task sets, queries once per day, and makes additional queries per member. It also loads users' task relations before issuing more task queries. Member analytics performs roughly 72 daily/monthly count queries plus collection loads. Dashboards load the same active workload twice. [Projects](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProjectsController.php:23>) paginates projects but eagerly loads all tasks for each displayed project. Reminder commands load all pending tasks before filtering in PHP.

Use SQL aggregates, bounded detail lists, `withCount`, grouped date queries, and chunked scheduled processing. Add indexes based on real query plans. No production-scale latency was measured; these are confirmed inefficient query patterns, not measured capacity limits.

### F17 — Company/user timezone settings do not drive runtime behavior

[Setup](<C:/xampp/htdocs/Task Management/task-management/app/Services/CompanySetupService.php:30>) stores company timezone and application URL. Runtime scheduling and deadline calculations use environment-backed `config('app.timezone')`; no bridge from the saved company/user timezone was found. Settings sends an existing timezone without providing a visible selector.

A setup choice can therefore differ from actual scheduling and displayed dates. Establish one company scheduling timezone, convert timestamps for user display where supported, and make setup/configuration agree. Apply the same explicit policy to the stored company URL versus `APP_URL`.

### F18 — Project and membership mutations are not atomic

[ProjectsController](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProjectsController.php:43>) writes the project, membership, history, and notifications separately. Membership removal checks for active tasks before detaching without a shared locking protocol with task creation/assignment.

A failure can leave partially recorded changes; a concurrent assignment/removal can break membership invariants. Race behavior was inferred from the code and was not reproduced against MariaDB. Use transaction-backed services and a consistent project/membership locking order across both sides of the race. Deliver notifications after durable commit.

### F19 — Project-manager assignees have inconsistent execution permissions

The UI permits assigning project managers, but [ProjectPolicy](<C:/xampp/htdocs/Task Management/task-management/app/Policies/ProjectPolicy.php:19>) allows them to view only projects they manage, even if they are members of another project. [TaskPolicy](<C:/xampp/htdocs/Task Management/task-management/app/Policies/TaskPolicy.php:235>) relies on that project visibility for execution actions.

Additionally, [generic task updates](<C:/xampp/htdocs/Task Management/task-management/app/Services/TaskLifecycleService.php:316>) allow progress only for the team-member role. A project-manager assignee's own progress update is rejected even though the policy recognizes assignee work.

Define execution permissions by actual assignment and membership, separately from management permissions. Test project managers doing work in both owned and other projects.

### F20 — Project completion/archive does not enforce a consistent task lifecycle

[Project updates](<C:/xampp/htdocs/Task Management/task-management/app/Http/Controllers/ProjectsController.php:67>) accept completed/archived status without checking open tasks. Task execution eligibility does not check project status. New task creation into those projects is blocked, so creation and ongoing execution follow different rules.

A closed project can contain active work that continues changing. Decide whether closing is blocked, closes/cancels work through explicit actions, or merely categorizes the project; explain and test that behavior. The delete message also says completing tasks enables deletion, while `tasks()->exists()` still blocks completed canonical tasks.

### F21 — Critical interface behavior depends on unpinned CDN scripts

[Authenticated layout](<C:/xampp/htdocs/Task Management/task-management/resources/views/layouts/app.blade.php:24>) loads SweetAlert from a moving major-version URL. [Analytics](<C:/xampp/htdocs/Task Management/task-management/resources/views/analytics.blade.php:173>) loads Chart.js without a version. Neither has an integrity attribute. These are outside the lockfile/build verification.

A CDN failure or upstream change can break task dialogs or charts despite a passing local build. Bundle and pin these dependencies, exercise a cold browser cache without CDN access, and define a deployable content-security policy.

### F22 — Service worker deletes unrelated caches on the same origin

[Activation handler](<C:/xampp/htdocs/Task Management/task-management/public/service-worker.js:28>) deletes every cache except its current cache name. Cache Storage is shared across the origin; the name also lacks an application/base-path namespace.

Other apps or release copies served under the same origin can lose their caches. Restrict deletion to cache names owned by this application and include an app/scope identifier. This matters especially for the current multi-directory XAMPP layout.

### F23 — Push retries cannot recover existing failed/pending deliveries

[Push job](<C:/xampp/htdocs/Task Management/task-management/app/Jobs/SendBrowserPushNotification.php:45>) skips any delivery row that was not just created, including failed/pending rows. The job has one try. A transient send failure or crash after row creation cannot be repaired by replaying the same notification.

Push is documented as best-effort, so this is lower priority than preserving database notifications. Nevertheless, provide a recoverable claim/attempt state with bounded retries and timeouts. Keep unique delivery identity while allowing retry of non-successful attempts. Test worker death between claim and send.

### F24 — Production release gates are documented but not demonstrated or automated here

The current suite is red, database-specific concurrency tests skip under SQLite, and no repository CI workflow was found. [PRODUCTION_RUNBOOK](<C:/xampp/htdocs/Task Management/task-management/PRODUCTION_RUNBOOK.md>) describes deployments, backups, Sentry, and devices, but this audit has no deployed evidence for those controls.

Create an enforced release pipeline on the exact release commit. Use a disposable database matching production for migration/concurrency verification. Exercise browser workflows, queue failures, scheduler timing, backup restoration, and rollback. The standard `/up` route is liveness evidence; it does not establish working queues, off-site backups, or end-to-end task processing.

## Additional usability and maintenance observations

These are narrower improvements or remaining review areas, not claims of verified production incidents.

- **Export naming:** The PDF export endpoint returns HTML, not a PDF file. Label it “Print / Save as PDF” with a deliberate print workflow, or produce an actual PDF download. The export should show the selected reporting period and applied filters.
- **Accessible preferences:** Toggle labels contain an empty decorative span without an explicit association to the nearby heading. Associate each checkbox with its visible label; expose tab roles/selection and announce save/error status.
- **Account provisioning:** Managers enter users' passwords directly. An invitation/reset-based onboarding flow would avoid managers handling ongoing credentials and can support verified ownership.
- **Account-change audit:** Task and project histories exist, but equivalent durable attribution for account role, credential, and activation changes was not found in these controller paths.
- **Recovery UX:** Soft deletion exists, but a supported restore UI is absent and task restore policy returns false. The runbook's targeted recovery guidance needs a concrete, tested operator procedure.
- **Maintainability:** Workflow logic is centralized better than reporting and UI logic. Large Blade files mix markup, styling, fetch calls, dialogs, and state. Extract shared request/error handling, assignment selection, and metric definitions before further feature expansion.
- **State representation:** The task accessor exposes display labels while storage uses machine values. Some components correctly use `machineState()`; others compare literal labels. Do not blindly replace all existing labels, but standardize new business logic on the enum and keep presentation conversion at the boundary.
- **Deployment packaging:** Preserve the runbook's rule that only the Laravel `public` directory is web-accessible. Keep old release copies, prototypes, audit artifacts, environment files, vendor sources, and backups outside the public document root. Their presence under local `htdocs` is not proof they are publicly exposed.
- **Sessions and hardening:** Verify session invalidation after password resets/administrative changes, secure cookies, trusted proxy/host settings, HTTPS/HSTS, debug mode, rate limits, and least-privilege database credentials in the deployed environment. This review did not inspect or certify production secrets or web-server configuration.
- **Installer concurrency:** The first-user existence check occurs before the setup transaction. Add a singleton installation lock and simultaneous-setup test; do not rely solely on a prior empty-user check.
- **Real UI qualification:** Keyboard-only workflows, screen-reader names, 200% zoom, mobile overflow, long text, empty/error states, slow-network double submission, and actual browser console behavior remain unverified because the local server was unavailable.

## Remediation sequence

### Gate 1 — Security and trustworthy writes

Resolve F01–F06 and F09. Include push destination controls, account handoffs, last-manager protection, assignment scope, and durable notification dispatch. Exit only when the current suite is green and targeted regression tests cover these paths.

### Gate 2 — Complete the business workflow

Resolve reminders (F07–F08), deadline semantics (F12), actor/assignee inconsistencies (F19), and project closure rules (F20). Exercise one task from assignment through revision, approval, reopening, and cancellation, including manager/assignee replacement.

### Gate 3 — Make the interface and reports truthful

Repair profile rendering and preferences. Define and test dashboard/report metrics. Validate filters and timezones. Pin interface dependencies and correct export behavior.

### Gate 4 — Prove operational readiness

Run database-specific tests in a disposable production-like database, measure query and memory budgets on representative volume, test queue/scheduler recovery, restore an off-site backup to staging, and rehearse rollback. Verify desktop and mobile browsers plus supported real PWA devices.

## Minimum acceptance scenarios

1. First manager setup; second setup blocked, including concurrent requests.
2. Manager invites/adds a user; account verification policy behaves consistently.
3. Manager, project manager, and team member cannot access or mutate work outside their authorized scope.
4. Task creation uses a valid active assignee and independent reviewer; duplicate retries do not duplicate tasks.
5. Start → hold → resume → submit → review → revise → resubmit → approve succeeds with one ordered audit trail.
6. Reopen and cancellation retain identity/history and preserve accurate deadlines and actor responsibilities.
7. Project transfer, role change, deactivation, and account closure leave no stranded active tasks.
8. Last active manager cannot be deleted or otherwise removed without a replacement.
9. Reminders target the stage owner and rearm for deadline changes and revision cycles.
10. Notification failure never misreports a committed business operation as unsaved.
11. All preference controls reload correctly, change actual behavior, and show visible accessible feedback.
12. Profile/password forms render and function; password/session policy is verified.
13. Dashboard percentages remain meaningful at zero workload and after all work is completed.
14. Historical reporting does not change past creation counts when a task completes; filters and exports agree.
15. Malformed analytics inputs produce controlled validation errors.
16. Push endpoints reject prohibited destinations; duplicate jobs/retries and account switching preserve correct ownership.
17. Database concurrency and migration tests pass on the actual production database family.
18. Staging backup restoration, rollback, worker restart, scheduler, HTTPS, and monitoring checks have recorded results.

## Sign-off position

This is a substantial application with an established workflow architecture, not an empty prototype. It is also **not production-grade in its present verified state**. The first release should be held until the security and workflow blockers are fixed, the failing tests pass, and the missing operational/browser evidence is collected. An audit alone cannot supply those fixes or certify an environment that has not been tested.
