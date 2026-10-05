# R5.4 — Maintainability and final internal release qualification

Completion date: 2026-10-05 (Asia/Kathmandu). Evidence root: `output/r5-4-20261005/`; qualifying results: `output/r5-4-20261005/final-corrected/`. Historical reports and evidence were retained. Commands used process-level disposable configuration; the company `.env` was not edited.

## 1. Executive summary

**R5.4 PASSED — MAINTAINABILITY AND FINAL INTERNAL RELEASE QUALIFICATION COMPLETE**

R5-014, R5-015 and R5-019 received focused maintenance changes. Application PHP files decreased from 172 to 170; level-5 diagnostics decreased from 250 to 23 reviewed diagnostics in 20 narrow baseline entries. Fifty-four relationships across fourteen models now have truthful native/generic types. Two unbound kernels and three unreachable private helpers were removed. Lifecycle, reopen and timeline handlers use five small Vite modules; repeated feature POST serialization and first-page timeline loading are centralized while AppClient and Phase3UI retain their authority.

All results below belong to application/test candidate `1e1ec440171166ac289c557daefaa3e222c39752`. The completion commit adds documentation only, with unchanged candidate application/test/build-tool trees. Earlier internal gate claims are superseded by these results. No deployment, push, tag or external R4.5 operation occurred.

## 2. Final classification

**R5.4 PASSED — MAINTAINABILITY AND FINAL INTERNAL RELEASE QUALIFICATION COMPLETE**

**INTERNAL RELEASE CANDIDATE QUALIFIED — EXTERNAL PRODUCTION ACCEPTANCE REMAINS**

## 3. Starting Git state

Repository: `C:\xampp\htdocs\Task Management\task-management`; branch `main`; starting full HEAD `5d3e499688048a67167450e348c7350dcf7dd0a2`. Tracked and staged changes were empty. The previous 25 commits, status, remotes and tags are captured in `starting-*.txt`, `remotes.txt` and `tags.txt`. No tags existed.

Pre-existing untracked material included historical audit reports, `audit/`, `output/`, audit JSON/text, `scripts/hostinger-initialize.php` and `tests/Feature/DeepProductionAuditReconciliationTest.php`. It was preserved and not swept into commits. Existing origin/production remotes were not changed. Historical report hashes and evidence-directory inventory are retained and checked at completion.

Runtime: PHP 8.4.20, Laravel 12.69.3, Composer 2.8.6, MariaDB 10.4.32 (InnoDB, REPEATABLE READ, strict mode), Node 22.14.0, npm 10.9.2, Vite 6.4.3, Playwright 1.63.0. Runtime/version/migration/route captures are in the phase root. Private MariaDB listened on loopback port 3366 with unique phase schemas. Thirty-nine migrations and 84 routes were reviewed.

## 4. R5 findings entering phase

R5-001–013 and R5-016–018 were closed entering this phase. R5-014 (maintained analyzer baseline), R5-015 (proven obsolete code) and R5-019 (incremental frontend modularization) were owned here. Source-of-truth R5/R5.1/R5.1A/R5.2/R5.3 reports, CI scripts, readiness tests, browser README, R4.3 budgets and deployment documents were inspected before changes. Existing fixes were preserved; only observed failures were investigated.

## 5. Pre-repair static-analysis result

Current PHPStan 2.2.16 and Larastan 3.12.2, level 5, real Laravel bootstrap: **250 diagnostics, zero internal errors, exit 1**. This differs from the historical isolated audit's 241; the historical figure was not substituted for this run. `static-before-clean.json` records every diagnostic and file; `static-before-resources.txt` records a warmed verbose run at 94 MB peak and 2.31 seconds. Neither count represents hundreds of runtime bugs.

Largest distributions: TaskNotificationDispatcher 58, TaskLifecycleService 19, TasksController 16, ReopenApprovedTask 14, ManagerDashboardController and ProjectManagerReplacementService 10 each. The full distribution is retained in `maintainability-facts.json`.

## 6. Static diagnostic classification

| Diagnostic identifier | Before |
| --- | --- |
| property.notFound | 108 |
| larastan.relationExistence | 86 |
| argument.type | 5 |
| method.notFound | 15 |
| nullsafe.neverNull | 8 |
| function.impossibleType | 2 |
| instanceof.alwaysFalse | 2 |
| booleanOr.rightAlwaysFalse | 1 |
| class.notFound | 7 |
| return.phpDocType | 1 |
| varTag.misplaced | 1 |
| method.unused | 3 |
| booleanOr.alwaysTrue | 1 |
| deadCode.unreachable | 1 |
| property.onlyWritten | 1 |
| nullCoalesce.offset | 2 |
| instanceof.alwaysTrue | 2 |
| method.impossibleType | 1 |
| function.alreadyNarrowedType | 1 |
| match.unhandled | 2 |

A — reachable runtime call/type defects: none confirmed after inspecting remaining calls and framework contracts. B — missing truthful model/relation/builder information: annotated. C/E — framework inference, defensive compatibility/null-safety and validated dynamic behavior: individually reviewed in `docs/STATIC_ANALYSIS.md`, narrowly baselined. D — seven missing classes in the unbound HTTP kernel and three unused private methods: removed only after reachability review. All remaining argument, nullability, impossible-comparison and match diagnostics have a location-specific rationale.

## 7. Model/relation typing improvements

54 relationships across 14 models now declare actual BelongsTo, HasMany, HasOne, BelongsToMany or MorphMany returns with supported generics; no invented relationship. The manifest is `model-relation-types.json`. Task annotations cover nullable unsaved/legacy UID, integer version, nullable canonical foreign keys, provenance and string-read/string-or-TaskState-write status. User's nullable array preferences are documented. Typed builders, transition request validation, session guard and generic model lookup contracts match actual callers. Existing casts/accessors and runtime relationship queries are unchanged.

## 8. Actual type defects repaired

Declaration mismatches were repaired: TasksController's operation context accepts TaskTransitionRequest, matching its validated caller; assignment candidates accept an Eloquent Project collection, matching modelKeys; NotificationAccess's class-string/model return is generic; read builders and enabled subscription scope are typed; the web guard is documented as SessionGuard. Misplaced User/LoginRequest PHPDoc was corrected. No proven reachable runtime defect was hidden in the baseline. Separately, browser work confirmed and repaired the existing completed-timeline error path and CSP-unsafe profile dialog expressions (section 18).

## 9. Reviewed analyzer baseline

**23 diagnostics in 20 exact message/path/count entries**, tracked in `phpstan-baseline.neon`. These are disclosed maintenance debt, not zero static-analysis errors. Anchored messages, file paths, identifiers and counts remain reviewable. Unmatched entries fail, enabling reduction after fixes. There are no broad ignores or directory suppressions and no unknown class/method/property/relation errors retained. `docs/STATIC_ANALYSIS.md` records every entry: verification event metadata, Carbon inference, redundant defensive checks/null-safety, filtered collection cardinality, guarded enum/date compatibility, two validated deadline matches and one harmless unread dependency.

## 10. Analyzer CI gate

`composer analyse` uses root application autoload/bootstrap, level 5 and the reviewed baseline. Analyzer dependencies are installed from separate `tools/static-analysis/composer.lock`; root production dependencies were not enlarged. CI installs, strictly validates and audits this tooling, then rejects new diagnostics/increased counts/internal failures. A 512 MiB tool-only allowance and one worker do not change the 128 MiB application limit. The framework tool pin must be reviewed alongside future Laravel upgrades.

## 11. Negative-control result

The final-revision disposable `app/R54StaticNegativeControl.php` returned stdClass from an int-declared method. Analysis reported `return.type` and exited **1**. The finally block removed the probe; analysis then exited **0**. `analyzer-negative-v2*.txt` and exit files preserve both outcomes. The artificial defect is not committed. An earlier incorrectly synchronized probe attempt is retained in the phase root and explicitly does not qualify the control.

## 12. Obsolete-code reachability review

Reviewed real container kernel resolution, bootstrap registration, route/scheduler/CLI entrypoints, policies, tests, reflection and dynamic dispatch, Blade/events/deployment references and direct calls. `reachability-search.txt`, runtime `bindings-before.json`, route/schedule/CLI captures and private reflection metadata record the evidence. Grep absence alone was not used as proof. Production resolves Illuminate Foundation kernels; TaskPolicy is mapped, but its public abilities do not call controlsLifecycle; no reflection/dispatcher calls the private helpers.

## 13. Kernel removal verification

Removed only unbound `app/Http/Kernel.php` and `app/Console/Kernel.php` (116 physical lines). Laravel 12's `bootstrap/app.php` remains authoritative for middleware and routes; `routes/console.php` retains all six schedules. Final kernels are `Illuminate\Foundation\Http\Kernel` and `Illuminate\Foundation\Console\Kernel`; global/security middleware, web account-session/Sentry middleware, aliases and policy mappings remain intact. All 84 route definitions are identical. CLI command lines are identical (capture trailing newline differs). Full security/middleware tests and real browser routes pass.

## 14. Private helper cleanup

Removed TaskPolicy::controlsLifecycle (21 lines), TaskLifecycleService::lifecycleEventValues and ::canonicalHistorySnapshot (31 lines together). Final reflection checks confirm their absence. No adjacent workflow refactor. The cleanup-focused domain gate passed 77 tests / 552 assertions, with ten explicit MariaDB-only skips; final full/race gates supersede that focused result.

## 15. Compatibility code explicitly retained

Retired revert 410 response; TaskStateCompatibility; legacy completion provenance; UID backfill; historical migration normalization; backup commands; push/Sentry tooling; Hostinger helpers; task transition classes; public policy abilities; scheduled commands. Starter inspire, unused components and optional scaffolding were retained because cosmetic deletion offered no meaningful gain. No historical migration was rewritten.

## 16. Frontend maintainability baseline

Starting physical lines: tasks Blade 1,635; completed tasks 213; manager dashboard 750; navigation 670; Team 465; projects 418; settings 447; public PWA JS 442. Tasks mixed form/edit/membership handling, execution/review/deadline prompts, POST serialization and timeline opening. Completed tasks repeated reopen POST and timeline loading. Dirty drafts, freshness, offline state and modal focus already had shared owners. `complexity-before-normalized.json` uses physical lines from the starting Git archive; the original nonblank-line PowerShell inventory remains preserved, avoiding an inconsistent before/after comparison.

## 17. Module extraction decisions

Extracted only well-tested task responsibilities: lifecycle/reviewer/deadline actions, reopen prompts, shared task POST serializer, shared first-page timeline loader, and one feature entry. Kept task form/editor and unrelated pages local. RequestTaskAction remains a thin feature serializer through existing window.fetch/AppClient, not a competing request client. Phase3UI still owns timeline rendering/older cursor pages, escaping and focus. Server-rendered expected_version is serialized without refreshing it before submission.

## 18. Frontend changes

`resources/js/task-features.js` exposes the existing-page initializers, loaded only by task/history Blade through Vite. Modules preserve routes, attributes, selectors, accessible names, dialogs, CSRF, errors, pending controls and conflict behavior. Completed-timeline 409 now reports the timeline error and re-enables the button; it no longer enters an unrelated reopen branch with undefined originalText. Chromium/WebKit tests inject only that response, restore the real shared fetch and load actual history.

Real profile navigation exposed CSP errors from starter Alpine expressions. The affected profile controls now use a small native handler and existing `.modal.active` Phase3UI focus/Escape/trap behavior; save feedback remains two seconds. The generic component/framework was not rewritten. Tests cover native profile save, keyboard open/Escape/return focus, wrong-password validation reopening and Cancel. CSP was not weakened. No SPA, state store, route redesign, visual redesign or backend API redesign.

## 19. Frontend complexity before/after

| Metric | Before | After |
| --- | --- | --- |
| App PHP files | 172 | 170 |
| Resource JS files | 3 | 8 |
| tasks.blade.php physical lines | 1635 | 1292 |
| completed-tasks.blade.php physical lines | 213 | 98 |
| Unbaselined level-5 diagnostics | 250 | 23 |
| Reviewed baseline diagnostics | none | 23 |
| Obsolete kernels/helpers | 2 / 3 | 0 / 0 |

Two copies of first-page timeline loading become one 23-line module. Lifecycle/reopen feature POST serialization becomes one 15-line helper. The largest Blade drops 343 physical lines, while its remaining editor is intentionally incremental work. The completed view drops 115 lines. Five modules total 446 lines; the gain is named boundaries, shared serialization/loading, focused recovery tests and CI syntax checks, not merely moving text. The larger dashboard/navigation files remain outside scope.

A maintained dependency-free `npm run check:js` checks 30 JS files, with a separate worker check. No ESLint dependency was added: a parser gate plus actual behavioral regressions adds value without a style baseline/framework migration. Parser success does not claim semantic lint coverage.

## 20. Candidate SHA

**`1e1ec440171166ac289c557daefaa3e222c39752`** — final application/test revision. Each qualifying gate has a `*-sha.txt` journal. The earlier `f04505281c4e3756a785fe5b1d0e969a5640d73c` attempt had five source-location XSS assertions fail after extraction; the assertions now verify both Blade feature initializers and the module's safe renderer call while retaining all output/JSON/escaping checks. Focused XSS: 10 passed / 241 assertions. A subsequent recheck found the new browser reopen test consumed R41 Basic Task, which CI expects after cache rebuilding. The test now approves/reopens/cancels its own UI-created task and asserts the original remains completed. All mandatory gates were restarted at 1e1ec440171166ac289c557daefaa3e222c39752 in final-corrected; the corrected full browser gate and post-cache replay qualify this revision. Earlier evidence, including interrupted capacity, is preserved.

The final report/runbook commit is documentation-only. Its full release HEAD is stored in `final-head.txt` and the final response; a post-commit candidate-tree comparison proves application, test, dependency, CI and build-tool content unchanged. This avoids a self-referential report hash and does not mix test results from differing source trees.

## 21. SQLite final

**603 passed / 70 skipped / 0 failed, 5,372 assertions, 117.91 seconds**. `sqlite-final.txt/xml`, exit 0. Named real-MariaDB tests are intentionally skipped here; SQLite does not certify their concurrency.

## 22. MariaDB final

**602 passed / 71 skipped / 0 failed, 5,369 assertions, 159.50 seconds**. `mariadb-final.txt/xml`, exit 0, fresh private final suite schema. Skips remain separate; named race groups below are not added into this full-suite total. The pre-existing untracked audit reconciliation file was retained; the maintained phpunit.xml explicitly excludes it from these suite totals.

## 23. Named concurrency groups

| Group | Passed | Assertions | Seconds |
| --- | --- | --- | --- |
| Core event/execution/lifecycle/account writers | 17 | 311 | 62.88 |
| Account safety | 1 | 10 | 2.57 |
| Notification delivery | 2 | 14 | 3.31 |
| Push and notification mutation | 15 | 93 | 30.22 |
| R3A2 writers | 3 | 22 | 6.33 |
| R3A3 writers | 8 | 71 | 13.79 |
| R4.2 membership | 1 | 7 | 2.38 |
| R4.3 notification | 2 | 21 | 5.12 |
| R5.1 project writers | 9 | 97 | 18.06 |
| R5.2 eligibility / required notice crash recovery | 12 | 92 | 24.53 |

Each qualifying group has zero failures/skips on real MariaDB, with separate process/lock schedules and independent logs/XML. All ten latest groups pass in `final-corrected/`. An earlier candidate attempt had one harness worker fail before entering the operation: the actor lookup found an already-deleted actor after the first worker's fixed 1,200ms hold elapsed. No JSON operation result existed. That failed attempt remains retained; a separate complete fresh core run passed unchanged source, and the final-corrected full group independently passed again. This was not counted as a pass or hidden by automatic retries. The R5.1/R5.2 groups cover PM replacement writers/eligibility and required-notice crash recovery; R5.3 capacity rollback/integrity is additionally covered by both full suites. No new production concurrency implementation was needed.

## 24. Migration qualification

39 fresh migrations; seeded disposable reset successfully reversed all migrations (remaining count 0), then remigrated. Retained historical upgrade helper starts from pre-2026-07-19 schema, normalizes work states, preserves legacy identity/provenance and supports restricted reopened follow-up. Account security-stamp helper passes fresh/additive upgrade/rollback/reapplication with retained user data. Focused project identity/workflow-intent/status/reminder/version/push regression: **17 passed / 166 assertions / 0 failed**, 31.90 seconds. Local setup/bootstrap rerun oracles preserve the first manager/password and prove production database session/cache/queue structure.

Production rollback is not certified by disposable reset: review data/schema/code compatibility, quiesce writers and rehearse restoration before external deployment. Never reset a company database. Project identity conflicts require explicit reconciliation; the migration does not silently rename retained projects.

## 25. 10k resource qualification

| Tasks | Fresh processes | Peak MiB | Result |
| --- | --- | --- | --- |
| 100 | 22 | 42 | PASS |
| 1000 | 22 | 42 | PASS |
| 10000 | 22 | 44 | PASS |

Each size uses 22 fresh child PHP processes at **128M**, the existing **96 MiB peak** guard, unchanged query/hydration/output budgets and independent fixture arithmetic. Manager/PM/member analytics and dashboards, pages, notifications, CSV/print, bulk read and repeat reminders/overdue commands pass. Read-page work is bounded; scheduler mutation/delivery totals scale with recipients and are not presented as constant query counts. No budget or runtime limit was raised.

## 26. 25k administration qualification

| Tasks | Affected active tasks | Peak MiB | Full replacement/delivery minutes | Result |
| --- | --- | --- | --- | --- |
| 10000 | 7500 | 36 | 11.42 | PASS |
| 25000 | 18750 | 36 | 17.6 | PASS |

At each size, dependent role change returns 409 without task hydration; actual PM replacement updates all affected active reviewers with version/history/event/intent oracles, then completes required notices with zero pending intents; owning-project dependency validation succeeds afterward. Dense fixtures include all eight states and final-state evidence. **128M** child processes and existing **64 MiB peak** budget. No OOM. Full-suite R53 rollback tests verify failure after multiple chunks rolls back the complete mutation; R5.1/R5.2 real race gates retain writer serialization and eligibility behavior.

Full synchronous replacement/delivery is expensive. The measured durations are local stress evidence, not a Hostinger SLA or interactive-request guarantee.

## 27. Required-notification regression

R52RequiredWorkflowNotificationTest and R52EligibilityMariaDbConcurrencyTest reconfirm transactional intent insertion, rollback, delivery failure recovery, process crash after commit, competing consumer deduplication, authorization/revoked-recipient discard, restricted withdrawal content, scheduler retry and bounded finished-intent cleanup. Final full suites and focused migration/intents tests are green; dense gates expect and verify zero pending required intents after complete delivery. R5-009/R5-011 remain closed. The runtime recovery command is present and succeeds on the disposable setup schema; the operator runbook now explains exit-status and pending-intent semantics.

## 28. Timeline regression

R53CapacityNavigationTest reconfirms bounded 100-entry first page, authorized stable cursors, complete traversal, no duplicates/omissions and private-note redaction. Actual browser traversal starts with 206 events, appends a new event above the anchor, traverses the original 206 without shifting, retries a deliberate 503, then checks member traversal/redaction. Synthetic older history is explicitly an isolated timeline fixture; core work transitions are performed through real UI. New Chromium/WebKit completed-history conflict/recovery tests cover the extracted first-page loader.

## 29. Browser final

**60 passed / 3 skipped / 0 failed (20.1m). Chromium PASS; WebKit PASS; Firefox unavailable.** Corrected fresh-company evidence: final-revision/browser-corrected.txt and browser-corrected-sha.txt; output/playwright/r5-4-20261005-fixture-corrected. Post-cache clear/rebuild logout/login/history replay: **1 passed / 0 failed**, separately preserved in final-corrected/post-cache-browser-final.txt.

One worker, zero automatic retries, fresh disposable company, production caches/database drivers and 128M servers. Artifacts: `output/playwright/r5-4-20261005-fixture-corrected/`; engine/PWA/axe journals: `final-revision/browser-corrected-metrics/`. Skips are two deliberately separate large-fixture tests and Windows Firefox launch unavailability (`spawn UNKNOWN`), not engine passes. Chromium and actual Playwright WebKit pass; no console/page errors are filtered to force their gates green. Earlier preflight transport injection and profile/CSP failures are preserved; the shared loader and profile repair are validated by the final gate.

## 30. Large-browser final

**2 passed / 1 fixture-only skip / 0 failed** on the corrected final candidate. Separate settled telemetry: eight navigation scenarios, **zero page errors and zero request failures**, including 4x CPU / 200ms latency probes.

Separate 10,000-task/20-project/10,000-notification fixture. Manager totals/bounded dashboard and pages, pagination, notification lists, analytics and print, mobile member dashboard and PM scope/search are checked under 128M. Telemetry waits for settled navigation, observes actual response status, page errors and request failures, and captures timing/DOM/chart metrics. Synthetic throttling is loopback Chromium evidence, not physical mobile or production timing.

## 31. Accessibility final

Final maintained browser gate passes axe high-confidence WCAG A/AA checks, one main landmark on all roles, contextual action names, keyboard task/analytics operation, modal trapping/Escape/return focus, contrast, reduced-motion/forced-color focus and responsive/long Unicode content checks. Profile recovery adds explicit wrong-password/open/Cancel focus behavior. This is measured internal coverage, not a universal accessibility certification or physical assistive-technology acceptance.

## 32. PWA final

Chromium and WebKit root/mounted worker registration, activation/control, offline fallback via real loopback network interruption, reconnect, cache-version update/draft preservation, obsolete-cache cleanup, logout/account-switch safety and capability/error recovery pass. Authenticated/mutation requests are never dynamically cached; worker push/click destinations and application cache isolation remain tested. Worker update probes restore the original source in finally; completion hash matches the starting worker. Playwright WebKit's absent showNotification remains an honest Unsupported state. No physical install or real push-delivery claim.

## 33. Asset/build result

Locked `npm ci` and both final builds exit 0; manifest has four valid entries and every referenced JS/CSS asset exists. Main JS **174.85 kB raw / 55.20 kB gzip**, charts **205.59 / 70.65**, task feature entry **9.79 / 2.57**; CSS **30.44 / 5.13** and **30.72 / 6.46**. Main/chart separation remains intact. Real non-chart routes do not load Chart.js; company/member analytics and print initialize charts at root and mounted paths. No public/hot or dev-server asset references. Asset hashes/validation are retained in `asset-manifest-final.json`.

## 34. Dependency/security audits

Root and isolated-tool Composer strict validation exit 0; current locked advisory audits report zero advisories/abandoned packages. Locked npm install exits 0; full npm audit and production-only audit report zero vulnerabilities at every severity. Root Composer/npm lock hashes are unchanged; no dependency upgrade or accepted advisory exception. Security results and their final SHA journal are retained. Shell deployment/cron/package syntax passes; packaging content-boundary smoke passes under login Git Bash. Link smoke is unavailable on this Windows filesystem (directory symlink creation fails), retained honestly and assigned to clean Linux CI; no deployment package was sent anywhere.

## 35. Static-analysis final

Baseline gate exits **0**, no new diagnostics/internal errors. Verbose final run: **258 MB peak / 31.10 seconds**, within tool-only 512 MiB. Reviewed debt remains **23**, not zero. Negative control fails then restores pass. Pint checks all **414 tracked PHP files**, PHP syntax passes for those files; Blade compile passes and **59 generated PHP views** pass syntax; **30 JS files plus service worker** pass Node syntax; production build, strict validations and diff whitespace check pass. Inline page behavior is additionally exercised by the real browser gate. No production memory limit was changed.

## 36. Routes/bindings/schedule/CLI

84 identical routes; Foundation kernels; unchanged global/web middleware, aliases and policies; no private obsolete helpers. Six schedules retain required notice retry every minute, deadline reminders at 08:00, overdue hourly and backup run/clean/monitor times. CLI includes app:setup-company, app:reset-installer, UID backfill, all notification commands, backup commands and optional Sentry maintenance. Local setup/rerun, UID no-op, bounded required-intent command and production boot/DB health succeed on owned schemas. CLI listing and route source checks qualify presence; no external cron, backup transport or customer deployment was executed.

## 37. Clean-company acceptance

| Real UI coverage | Qualifying test family |
| --- | --- |
| Setup, Manager/PM/member/project/membership/task creation | core-workflow |
| Start, hold/resume, submit/review/revision/hold/resume/resubmit/approve | core-workflow and z-r54-feature-modules |
| Reopen, cancellation, reviewer reassignment, private/visible reasons | z-r54-feature-modules on its own approved task |
| Deadline changes and stale two-tab/delayed approval conflicts | core-workflow |
| PM replacement and old/new authorization | z-r51-project-integrity |
| Member removal and private withdrawal notice | z-r52-domain-contracts |
| Older timeline traversal, retry and redaction | z-r53-timeline |
| Analytics, print, notifications, settings/profile | r42/r44/z-r53/z-r54 families |
| Logout/login and post-cache persistence | core-workflow plus post-cache replay |
| Offline/reconnect/account switch/worker update/mounted paths | r44-quality and r51a-pwa |

Real forms, routes, CSRF, serializers, authorization and persistence were used for core workflow; the PHP snapshot is read-only. Bulk performance and >100-history fixtures are separate disclosed synthetic fixtures. No direct DB task state substituted for core UI actions. Root and mounted behavior use the same fresh company on separate 128M servers.

## 38. Capacity envelope

Internally measured 100/1k/10k resource gates at 128 MiB, 96 MiB peak budget; dense 10k/25k administration at 128 MiB, 64 MiB peak budget. Latest 25k full administration peak: 36 MiB; complete replacement/delivery: 17.6 minutes. Synchronous full delivery can be very slow locally. Actual shared-host PHP/request/process/DB/cron constraints and timing remain unqualified. No stress-survival result is a production SLA. See `docs/R53_CAPACITY_AND_NAVIGATION.md` and `docs/R54_INTERNAL_RELEASE_HANDOFF.md`.

## 39. Files changed

```text
M	.github/workflows/ci.yml
D	app/Console/Kernel.php
M	app/Http/Controllers/TasksController.php
D	app/Http/Kernel.php
M	app/Http/Middleware/EnsureCurrentAccountSession.php
M	app/Http/Requests/Auth/LoginRequest.php
M	app/Models/BrowserPushDelivery.php
M	app/Models/BrowserPushSubscription.php
M	app/Models/Permission.php
M	app/Models/Project.php
M	app/Models/ProjectHistory.php
M	app/Models/Role.php
M	app/Models/Task.php
M	app/Models/TaskApproval.php
M	app/Models/TaskEvent.php
M	app/Models/TaskHistory.php
M	app/Models/TaskNotificationDelivery.php
M	app/Models/TaskRevisionCycle.php
M	app/Models/TaskSubmission.php
M	app/Models/User.php
M	app/Policies/TaskPolicy.php
M	app/Services/NotificationAccess.php
M	app/Services/TaskAssignmentCandidateService.php
M	app/Services/TaskLifecycleService.php
M	app/Services/TaskReadService.php
M	composer.json
M	docs/CI.md
M	docs/FRONTEND_BUILD.md
A	docs/STATIC_ANALYSIS.md
M	package.json
A	phpstan-baseline.neon
A	phpstan.neon
A	pint.json
A	resources/js/task-features.js
A	resources/js/tasks/action-request.js
A	resources/js/tasks/lifecycle-actions.js
A	resources/js/tasks/reopen-actions.js
A	resources/js/tasks/timeline-actions.js
M	resources/views/completed-tasks.blade.php
M	resources/views/profile/edit.blade.php
M	resources/views/profile/partials/delete-user-form.blade.php
M	resources/views/profile/partials/update-password-form.blade.php
M	resources/views/profile/partials/update-profile-information-form.blade.php
M	resources/views/tasks.blade.php
A	scripts/check-js-syntax.mjs
A	tests/Browser/z-r54-feature-modules.spec.js
M	tests/Feature/WorkflowFreeTextXssTest.php
A	tools/static-analysis/.gitignore
A	tools/static-analysis/analysis.neon
A	tools/static-analysis/bootstrap.php
A	tools/static-analysis/composer.json
A	tools/static-analysis/composer.lock
M	vite.config.js
M	docs/HOSTINGER.md
M	docs/OPERATIONS_RUNBOOK.md
A	docs/R54_INTERNAL_RELEASE_HANDOFF.md
A	R5_4_MAINTAINABILITY_FINAL_INTERNAL_RELEASE_QUALIFICATION_2026-10-05.md
```

No root lock, historical migration, public worker, customer environment or historical report was changed. Analyzer lock is a separate development-only tool manifest. Evidence stays in unique output directories and is not staged as production source.

## 40. Commits

- `a2a206152191e27b1066a72d0b02a9f1329eb3a7` — remove proven obsolete application scaffolding.
- `5f3af580f18bb20915171cfa715136a5615c87aa` — establish reviewed static analysis baseline.
- `f04505281c4e3756a785fe5b1d0e969a5640d73c` — modularize high-value task browser handlers.
- `4529f84a848ae773f0f6af2bc98fc5ca1cf01a38` — follow extracted timeline boundary in XSS regression.
- `1e1ec440171166ac289c557daefaa3e222c39752` — isolate reopen qualification from retained browser fixtures.
- Documentation/report completion commit: full SHA in `final-head.txt`, final commit journal and final response. Application/test/CI/build/dependency tree matches the qualified candidate.

All commits are local; history was not rewritten.

## 41. R5 finding closure ledger

Closed earlier and reconfirmed:

- R5-001
- R5-002
- R5-003
- R5-004
- R5-005
- R5-006
- R5-007
- R5-008
- R5-009
- R5-010
- R5-011
- R5-012
- R5-013
- R5-016
- R5-017
- R5-018

Closed in R5.4:

- R5-014
- R5-015
- R5-019

Remaining R5 findings: **NONE**.

## 42. Remaining internal issues

No unresolved mandatory internal release gate or open R5 finding.

Reviewed analyzer debt remains as disclosed. Firefox/physical devices/live transport/Linux CI/shared hosting require external environment evidence. Local link packaging smoke remains unavailable and is not disguised as passing. Initial diagnostic failures, the source-location test repair, interrupted initial capacity and core startup race are retained with their qualifying replacements clearly distinguished.

## 43. External R4.5 handoff checklist

Prepared in `docs/R54_INTERNAL_RELEASE_HANDOFF.md`: exact final full SHA; clean Linux quality/MariaDB/all race/resource/browser/packaging CI; Hostinger PHP 8.4/extensions/MariaDB/runtime limits; immutable package/manifest/checksum; symlinks/rewrite/mounted paths; permissions/private storage; HTTPS/cookies/sessions; real cron/database queue/notice recovery; SMTP/Sentry/real push; installed PWA/physical Android/iOS/Safari; Firefox on supported host; encrypted offsite backup/APP_KEY escrow and actual restore; restart/recovery, staging soak/timing and compatible rollback. Each needs actual external evidence and separate authorization. No external operation started.

## 44. Final Git state

Tracked and staged state is clean after the documentation/report commit, captured in `final-tracked-status.txt` and `final-staged-status.txt`. Full final HEAD is captured in `final-head.txt`; application/test/CI/build/dependency source comparison with candidate is empty. Pre-existing untracked audit material and unique new evidence remain intentionally preserved. Remotes and tags match the starting state; protected `.env`, root lockfiles and service-worker hashes match; historical report hashes/directories remain preserved. Private phase process/cache cleanup is documented without affecting unrelated processes or customer data.

## 45. Deployment state

**No push. No deployment. No release tag. No remote merge. No customer environment/database change. No external Hostinger operation. No external R4.5 acceptance.** One company remains one isolated Laravel application/database. **INTERNAL RELEASE CANDIDATE QUALIFIED — EXTERNAL PRODUCTION ACCEPTANCE REMAINS**
