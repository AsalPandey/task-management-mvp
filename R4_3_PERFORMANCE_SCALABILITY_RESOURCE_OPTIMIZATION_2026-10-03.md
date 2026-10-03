# R4.3 — Performance, scalability and resource optimization

Date: 2026-10-03. Repository: `C:\xampp\htdocs\Task Management\task-management`.

## 1. Executive summary

**R4.3 PASSED — PERFORMANCE, SCALABILITY AND RESOURCE LAYER STABILIZED**

The four assigned findings are repaired and qualified: aggregate analytics, bounded dashboards/project cards, notification bulk reads, and authorized server-side Team search. Shared notification navigation and repeated consumed-generation scheduler scans were additional demonstrated bottlenecks and are repaired.

At 10,000 tasks the baseline aggregate service hydrated 20,000 Task objects and peaked at 138 MiB; the repaired service uses seven SQL queries, hydrates zero Task/Event models and peaks at 28 MiB. The manager dashboard drops from 893,572 to approximately 143,402 HTML bytes with bounded previews. Mark-all-read drops from 10,001 queries/10,000 updates to one owner-scoped update. All 22 final qualification processes run at `memory_limit=128M`; their maximum peak is 44 MiB.

Full suites: SQLite 569 passed; MariaDB 585 passed; isolated concurrency 32 passed; browser 15 clean-company cases plus two large-data cases passed. Existing R4.1/R4.2 regressions remain green. This classification applies to R4.3; R4.4/R4.5 acceptance remains outstanding.

## 2. Starting Git state

Branch `main`; verified starting HEAD `ed2902ae4cb365756da8c57d6ed83a35c7a0ef35`, matching the R4.2 final state. Tracked and staged changes were empty. Existing untracked audit reports, probes, helpers and output were preserved. No reset, amend or history replacement was used.

The last 20 commits, initial status, remotes and index inventory are recorded in `output/r43/initial-commits.txt`, `initial-status.txt`, `remotes.txt` and `indexes-before.txt`. Remotes remain `origin` (task-management-final) and `production` (task-management-mvp), both under AsalPandey on GitHub.

The R4 audit and R4.1/R4.2 reports were inspected. Their qualified workflow/security behavior was retained; performance work was limited to this phase.

## 3. R4 findings addressed

| Finding | Demonstrated problem | Qualified repair |
|---|---|---|
| R4-016 | Whole-cohort analytic/export hydration; 128M exhaustion | Canonical SQL aggregates, scalar trends and owner DTOs; streamed CSV retains protections |
| R4-017 | Duplicate active cohorts, unbounded dashboard lists, paginated projects loading all child tasks | Aggregate totals, bounded previews, conditional project counts and eager member roles |
| R4-018 | One update per unread notification | One unread/owner/morph-scoped UPDATE |
| R4-025 | Team search filtered only the currently loaded DOM page | Authorized GET search across name/email with 12-row pagination |

## 4. Performance methodology

The guarded fixture generator only accepts disposable `task_management_r43_*` MySQL/MariaDB schemas and refuses pre-existing users/tasks. Main fixtures contain 100, 1,000 and 10,000 tasks across 20 projects, 40 members, manager and PM. All eight canonical states are mixed evenly where divisible; execution/review/revision deadlines, overdue/today/future dates, priorities, progress, completed/cancelled event records and 30 creation dates are represented. PM ownership alternates by project. Each main fixture also contains the corresponding number of manager-owned notifications.

Additional fixtures cover zero tasks/notifications, one notification, one dense project at 100/1,000/10,000 tasks, 100 projects with 1,000 tasks, and an optional 25,000-task diagnostic. Fixed qualification time is 2026-10-03 noon in Asia/Kathmandu. The arithmetic oracle computes expected states, priorities, deadline eligibility, rates, role scopes, dates, events and per-member results independently of application queries.

Each measurement launches a fresh PHP process. Profiles record wall and SQL time, SQL statement counts, repeated normalized SQL shapes, retrieved Eloquent model counts, peak allocated process memory, allocation delta and response bytes. Wall time excludes initial framework bootstrap and selecting the profile actor; peak memory includes bootstrap. SQL time is the driver's reported query duration. Instrumentation keeps counters/hashes rather than an unbounded query log. Process memory is PHP's allocated peak, not operating-system RSS. These are single diagnostic samples, not latency percentiles or load-test SLAs; cache/filesystem/DB warm-up and local contention affect timing.

Environment: Windows; Intel64 Family 6 Model 165, 12 logical processors; PHP 8.4.20 ZTS 64-bit; Laravel 12.69.3; Composer 2.8.6; MariaDB 10.4.32 strict SQL/utf8mb4_unicode_ci; Node 22.14.0/npm 10.9.2. Baseline CLI memory limit was 512M, with explicit 128M failure probes. Every repaired resource profile uses 128M. Production/debug-off config, routes and views are cached with isolated paths; queue/cache/session use database drivers and mail uses the local log driver.

The customer's `.env`, normal database on port 3306 and pre-existing caches were not rewritten. Private qualification used loopback MariaDB 3350 and PHP 8050. Raw before/after JSON, failures and successful reruns remain under `output/r43`; browser evidence remains under `output/playwright`.

## 5. Before/after benchmark table

Values show **before → after** on identically sized fresh fixtures. MiB uses 1,048,576 bytes. Task counts are retrieval events, so repeated retrieval of a task is counted again. HTML includes existing assignment controls and bounded notification navigation.

| Surface | Tasks | Wall ms | Queries | Peak MiB | Task retrievals | Response bytes |
|---|---:|---:|---:|---:|---:|---:|
| Aggregate analytics | 100 | 58.2 → 16.69 | 6 → 7 | 28 → 28 | 200 → 0 | 0 → 0 |
| Aggregate analytics | 1000 | 436.2 → 41.41 | 6 → 7 | 38 → 28 | 2000 → 0 | 0 → 0 |
| Aggregate analytics | 10000 | 3747.32 → 246.12 | 6 → 7 | 138 → 28 | 20000 → 0 | 0 → 0 |
| Manager dashboard | 100 | 553.54 → 503.28 | 52 → 31 | 40 → 38 | 164 → 15 | 147274 → 143325 |
| Manager dashboard | 1000 | 649.24 → 489.15 | 52 → 31 | 54 → 38 | 1625 → 15 | 214886 → 143352 |
| Manager dashboard | 10000 | 2715.36 → 518.63 | 52 → 31 | 196 → 38 | 16250 → 15 | 893572 → 143402 |
| Projects | 100 | 605.76 → 429.34 | 538 → 22 | 38 → 36 | 96 → 0 | 368732 → 369334 |
| Projects | 1000 | 631.51 → 430.96 | 538 → 22 | 44 → 36 | 616 → 0 | 368752 → 369353 |
| Projects | 10000 | 1100.27 → 442.01 | 538 → 22 | 114 → 36 | 6032 → 0 | 368786 → 369386 |
| Analytics HTML | 100 | 428.93 → 225.73 | 47 → 24 | 34 → 32 | 201 → 0 | 108393 → 108649 |
| Analytics HTML | 1000 | 1799.35 → 232.41 | 47 → 24 | 46 → 32 | 2001 → 0 | 108634 → 108917 |
| Analytics HTML | 10000 | 16831.19 → 526.76 | 47 → 24 | 166 → 32 | 20001 → 0 | 108789 → 109099 |
| Member analytics | 100 | 335.28 → 182.77 | 75 → 27 | 32 → 32 | 72 → 8 | 45006 → 50071 |
| Member analytics | 1000 | 362.9 → 204.6 | 75 → 28 | 34 → 32 | 288 → 10 | 45088 → 50411 |
| Member analytics | 10000 | 820.23 → 237.71 | 171 → 27 | 60 → 32 | 2368 → 10 | 50234 → 50435 |
| CSV | 100 | 302.98 → 118.87 | 21 → 22 | 32 → 30 | 201 → 0 | 1429 → 1429 |
| CSV | 1000 | 1492.97 → 135.88 | 21 → 22 | 42 → 30 | 2001 → 0 | 1563 → 1563 |
| CSV | 10000 | 14344.53 → 334.89 | 21 → 22 | 140 → 30 | 20001 → 0 | 1693 → 1693 |
| Print | 100 | 232.74 → 113.98 | 19 → 20 | 32 → 32 | 201 → 0 | 9275 → 9275 |
| Print | 1000 | 1450.27 → 126.62 | 19 → 20 | 42 → 32 | 2001 → 0 | 9409 → 9409 |
| Print | 10000 | 15712.53 → 336.47 | 19 → 20 | 140 → 32 | 20001 → 0 | 9539 → 9539 |
| Tasks | 100 | 761.51 → 834.95 | 55 → 31 | 42 → 42 | 24 → 24 | 337011 → 337269 |
| Tasks | 1000 | 855.08 → 860.15 | 55 → 31 | 44 → 42 | 24 → 24 | 348892 → 349149 |
| Tasks | 10000 | 1593.44 → 943.49 | 55 → 31 | 78 → 42 | 24 → 24 | 349026 → 349282 |
| Team | 100 | 222.71 → 261.6 | 43 → 19 | 34 → 34 | 0 → 0 | 104078 → 104148 |
| Team | 1000 | 254.18 → 246.48 | 43 → 19 | 36 → 34 | 0 → 0 | 104086 → 104155 |
| Team | 10000 | 491.73 → 304.99 | 43 → 19 | 70 → 34 | 0 → 0 | 104104 → 104164 |
| Inbox | 100 | 261.92 → 184.99 | 240 → 36 | 32 → 30 | 0 → 0 | 94057 → 94317 |
| Inbox | 1000 | 301.31 → 176.14 | 240 → 36 | 34 → 30 | 0 → 0 | 100011 → 100270 |
| Inbox | 10000 | 608.71 → 234.83 | 240 → 37 | 68 → 30 | 0 → 0 | 100052 → 100305 |
| Mark all read | 100 | 157.1 → 14 | 101 → 1 | 28 → 28 | 0 → 0 | 0 → 0 |
| Mark all read | 1000 | 1395.17 → 39.14 | 1001 → 1 | 30 → 28 | 0 → 0 | 0 → 0 |
| Mark all read | 10000 | 14955.85 → 207.25 | 10001 → 1 | 58 → 28 | 0 → 0 | 0 → 0 |

The service and HTTP/export timings are separate: controller, middleware, cached Blade rendering, navigation and permission checks contribute to HTTP wall time. The detailed 10k instrumentation is below; all raw fields also remain in `before-<scale>-<operation>.json` and `after-<scale>-<operation>.json`.

| 10k surface | SQL ms before → after | Allocation delta MiB before → after | Repeated SQL shapes before → after | Task/Event/Notification retrievals before → after |
|---|---:|---:|---:|---|
| Aggregate analytics | 153.81 → 236.91 | 110 → 2 | 0 → 0 | 20000/1250/0 → 0/0/0 |
| Manager dashboard | 341.7 → 104.21 | 170 → 12 | 37 → 8 | 16250/0/20000 → 15/0/8 |
| Projects | 349.62 → 98.21 | 88 → 10 | 524 → 10 | 6032/0/20000 → 0/0/8 |
| CSV | 163.39 → 256.36 | 114 → 4 | 5 → 5 | 20001/1250/0 → 0/0/0 |
| Print | 182.55 → 260.51 | 114 → 6 | 4 → 4 | 20001/1250/0 → 0/0/0 |
| Analytics HTML | 291.08 → 327.12 | 140 → 6 | 32 → 8 | 20001/1250/20000 → 0/0/8 |
| Member analytics | 163.88 → 71.35 | 34 → 6 | 155 → 8 | 2368/64/12140 → 10/0/8 |
| Tasks | 151.04 → 96.63 | 52 → 16 | 33 → 9 | 24/0/20000 → 24/0/8 |
| Team | 126.31 → 50.67 | 44 → 8 | 32 → 8 | 0/0/20000 → 0/0/8 |
| Inbox | 211.01 → 86.15 | 42 → 4 | 232 → 28 | 0/0/20020 → 0/0/28 |
| Mark all read | 9294.17 → 199.2 | 32 → 2 | 9999 → 0 | 0/0/10000 → 0/0/0 |

## 6. Analytics optimization

Previously the service hydrated the current cohort and creation/event history, then filtered/grouped in PHP. It now calls the canonical `TaskReadService::visibleTo()` scope and runs seven distinct aggregate queries: state/progress/deadline counts, priority groups, event types, completion dates, creation dates, 30 scalar overdue buckets and grouped member DTOs. No Task/Event model retrieval is required. Queries return small state/date groups plus one row per owner.

`TaskDeadlineSql` mirrors canonical current-stage selection: final states have no active deadline; submitted/in-review use review due dates; current revisions use revision due dates; execution uses execution due date with the legacy fallback. Local calendar dates preserve the rule that due today is not overdue. No business-state cache was added.

The final independent oracle checks manager, PM and member all-time reports and an inclusive Oct 1–3 creation cohort, including every per-member total/rate/overdue value. Creation-cohort snapshots and event throughput remain separate. Dedicated tests cover soft deletion, authorization, stage selection, missing dates, dates after midnight, cancelled denominators and date boundaries. The chart labelled “30-Day Creation Trend” now receives creation data; the existing mismatched completion data was a discovered defect.

CSV remains streamed, scoped and formula/UTF-8 protected; print consumes aggregate DTOs. Empty/100/1k/10k CSV/print profiles pass at 128M. Data size still scales with the number of reported members; these are member-summary exports, not an all-task export. Existing export security/date tests pass.

## 7. Dashboard optimization

Manager/PM totals and charts use aggregate SQL over canonical readable scopes. Recent activity is three active plus two completed tasks; overdue preview is at most ten, ordered by current deadline/id, while the displayed total covers the full scope. Member dashboards use aggregate totals, five notification sources and at most ten overdue/upcoming/completed rows. Seven-day productivity uses one grouped query. Current-stage dates are displayed in previews. Existing “View all tasks”/history navigation provides access beyond previews.

At 10k, manager Task retrieval falls from 16,250 to 15 for the generic-notice fixture; the final manager/PM/member read profiles peak at 38/34/30 MiB. Scoped dashboards and totals are independently checked. A separate fixture with real task notices verifies the additional fresh permission checks: manager dashboard 55 queries, 31 Task retrievals, 38 MiB, 143,719 bytes. This also satisfies the stable budget.

Task previews and query counts are bounded with task growth. Existing authorized project/member assignment choices still scale with roster/project size. The qualified envelope is explicitly 40 members/20 projects, with a separate 100-project test.

## 8. Project-list optimization

Twelve paginated projects load manager and members.role, plus SQL `active_tasks_count` and `completed_tasks_count`. No child Task relation is loaded. This removes both full child hydration and lazy role lookups. Stable newest/id ordering preserves pagination.

| Fixture / repaired Projects | Wall ms | Queries | Peak MiB | Task retrievals | HTML bytes |
|---|---:|---:|---:|---:|---:|
| dense100 | 483.24 | 14 | 32 | 0 | 67355 |
| dense1000 | 492.96 | 14 | 32 | 0 | 67357 |
| dense | 463.9 | 14 | 32 | 0 | 67359 |
| many | 616.67 | 14 | 36 | 0 | 361781 |
| Dense 10k baseline | 968.65 | 55 | 100 | 10000 | 67359 |
| 100-project baseline | 754.55 | 506 | 38 | 192 | 361363 |

The dense 10k baseline survived 128M but hydrated all 10k children and peaked at 100 MiB; it was structurally unbounded. The repaired page hydrates zero child tasks. A separate real-task-notification profile has 16 Task retrievals solely for permission-masked header previews, 46 queries and 36 MiB; the checker explicitly requires zero loaded project-child Task relations. SQL counts still inspect relevant DB rows; no claim of constant database CPU is made.

## 9. Team search/pagination repair

The search control submits GET to the existing authorized roster endpoint. Name/email matching covers the complete permitted roster, trims whitespace, treats `%`, `_` and `!` literally, and relies on the declared database collation for mixed ASCII case. No per-keystroke requests or external search dependency was introduced. Pagination retains the query; a newly submitted search starts at page one; clear returns ordinary pagination.

Feature/browser checks cover exact and partial names/email, mixed case, whitespace, Nepali text, no results, a target originally beyond page one, filtered page two without overlap, clear and page reset. The browser creates 14 accounts through the actual Team form. Manager actions remain available; PM search remains scoped/read-only; ordinary members remain forbidden. Malformed search values remain validation errors.

## 10. Notification bulk optimization

`unreadNotifications()->update(['read_at' => now()])` performs one relation-scoped UPDATE. Owner ID, morph type and unread predicate remain in SQL. Other owners and already-read timestamps are unchanged; repeat requests are safe. Concurrent inserts after the update remain unread. No row hydration is required.

| Unread notifications | Queries before → after | UPDATEs before → after | Wall ms before → after | Repaired peak MiB |
|---:|---:|---:|---:|---:|
| 0 | unmeasured → 1 | unmeasured → 1 | unmeasured → 8.65 | 28 |
| 1 | unmeasured → 1 | unmeasured → 1 | unmeasured → 13.09 | 28 |
| 100 | 101 → 1 | 100 → 1 | 157.1 → 14 | 28 |
| 1000 | 1001 → 1 | 1000 → 1 | 1395.17 → 39.14 | 28 |
| 10000 | 10001 → 1 | 10000 → 1 | 14955.85 → 207.25 | 28 |

The notification center already paginates 20 rows. Navigation now queries unread count in SQL and retrieves eight recent notices instead of loading all history/unread rows. The mobile badge uses SQL count. Permission masking is evaluated once per rendered notification row and stays fresh per request. At 10k generic notices, inbox hydration falls from 20,020 to 28 notices (20 center + eight header). Real task notices remain within the explicit fresh-authorization query budget.

## 11. Scheduler/background processing review

Reminder/overdue scanners select current-stage eligible scalar candidates in id-ordered chunks of 200. A consumed snapshot with an identical existing generation fingerprint is skipped. Unconsumed candidates still pass through the unchanged atomic delivery service, which reloads current state/recipient under lock, honors preferences, claims the generation and writes transactionally. A newly changed generation after a snapshot skip is picked up by the next scheduled run. No transaction spans the entire scan.

| 10k scheduler | Wall ms before → after | Queries before → after | Task retrievals before → after | Peak MiB before → after | Delivered after |
|---|---:|---:|---:|---:|---|
| reminders first | 18610.6 → 14175.28 | 20018 → 20018 | 6432 → 5360 | 44 → 42 | Sent 1072 deadline reminder notifications. |
| reminders repeat | 4571.79 → 161.06 | 7153 → 6 | 4288 → 0 | 40 → 32 | Sent 0 deadline reminder notifications. |
| overdue first | 30040.2 → 27334.32 | 39997 → 39997 | 12852 → 10710 | 44 → 44 | Sent 2142 overdue task notifications. |
| overdue repeat | 9410.46 → 209.24 | 14292 → 11 | 8568 → 0 | 40 → 32 | Sent 0 overdue task notifications. |

First runs deliver exactly 1,072 reminders and 2,142 overdue notices; repeats send zero. First-delivery queries remain linear because per-task atomicity is retained. Repeats use six/eleven scalar chunk queries and retrieve no Task models, instead of thousands of per-task rechecks. Query ceilings for initial work reflect this necessary cost. Cumulative retrieved model counts during delivery are not simultaneously retained collections.

Browser push remains the existing per-user enabled-subscription flow with atomic claims; task count does not expand that per-user set. Device fan-out is a future capacity dimension. Backup remains the package/external database dumper rather than PHP Task materialization; production backup/restore size and storage qualification belongs to R4.5. No push/backup redesign or delivery claim weakening was performed.

## 12. Index changes

No indexes or migrations were added. Existing project-leading/assignee/status/date/event and membership indexes were reviewed. `explain-existing-indexes.json`, `explain-after.json` and `indexes-before.txt` retain actual SQL/EXPLAIN evidence. Project conditional counts use the existing project-leading composite index; scoped predicates can use their existing indexes. Unscoped aggregate/current-deadline CASE queries still scan relevant rows in the database. A speculative expression/catch-all index would add write/storage cost without evidence sufficient to justify it.

## 13. 128 MB qualification

The baseline 10k analytics, manager dashboard and CSV probes failed at 134,217,728 bytes; logs are retained as `before-large-128-analytics.txt`, `before-large-128-dashboard.txt` and `before-large-128-csv.txt`. Projects and deadline processing did survive the old limit; their defects were hydration/query behavior, not a claimed fatal failure.

`qualification-10000-final.json` reports PASS for 22 fresh PHP processes, each explicitly at 128M, with a stricter measured 96 MiB peak guard. Maximum actual peak is 44 MiB. No runtime/customer memory limit was raised.

| Final 128M process | Role | Queries | Peak MiB | Task retrievals | Response bytes |
|---|---|---:|---:|---:|---:|
| analytics | manager | 7 | 28 | 0 | 0 |
| analytics-cohort | manager | 7 | 28 | 0 | 0 |
| dashboard | manager | 31 | 38 | 15 | 143401 |
| analytics | pm | 7 | 28 | 0 | 0 |
| analytics-cohort | pm | 7 | 28 | 0 | 0 |
| dashboard | pm | 23 | 34 | 15 | 101337 |
| analytics | member | 7 | 28 | 0 | 0 |
| analytics-cohort | member | 7 | 28 | 0 | 0 |
| dashboard | member | 20 | 30 | 35 | 63928 |
| projects | manager | 22 | 36 | 0 | 369385 |
| tasks | manager | 31 | 42 | 24 | 349281 |
| team | manager | 19 | 34 | 0 | 104163 |
| notifications | manager | 36 | 30 | 0 | 100307 |
| analytics-page | manager | 24 | 32 | 0 | 109098 |
| member-analytics | manager | 27 | 32 | 10 | 50434 |
| csv | manager | 22 | 30 | 0 | 1693 |
| print | manager | 20 | 32 | 0 | 9539 |
| mark-all | manager | 1 | 28 | 0 | 0 |
| reminders | manager | 20018 | 42 | 5360 | 0 |
| reminders | manager | 6 | 32 | 0 | 0 |
| overdue | manager | 39997 | 44 | 10710 | 0 |
| overdue | manager | 11 | 32 | 0 | 0 |

## 14. Large dataset results

The 10k oracle expects 7,500 active, 1,250 completed, 1,250 cancelled, 1,250 in progress, 2,142 overdue and a 14.3% completion rate. Each state has 1,250 tasks; cancellation is excluded from the completion denominator. Event totals are 1,250 completion and 1,250 cancellation events. PM scope contains 5,000 tasks/3,750 active; member001 has 256 tasks/192 active. All aggregate and displayed totals match independent arithmetic.

Small/medium resource gates also pass (`qualification-small.json`, `qualification-medium.json`). The final strengthened 10k gate adds all three role date-cohort profiles to those earlier 19-process gates and validates per-member DTOs.

| Optional 25k diagnostic | Wall ms | Queries | Peak MiB | Task retrievals | Response bytes |
|---|---:|---:|---:|---:|---:|
| Aggregate analytics | 648.3 | 7 | 28 | 0 | 0 |
| Manager dashboard | 555.15 | 23 | 38 | 15 | 130528 |
| Projects | 457.85 | 14 | 36 | 0 | 356541 |
| CSV | 697.39 | 22 | 30 | 0 | 1735 |

The optional 25k diagnostic is after-only and is not a release gate or a before/after claim. Returned task previews, aggregate query count and PHP memory stay bounded. Database aggregate time still grows with cohort size. Tasks retain 24-row pagination, completed history 15 rows, Team/Projects 12 rows, inbox 20 rows, and task timeline a SQL limit capped at 100 with canonical ordering and eager actor. Existing limited history semantics are unchanged.

## 15. Browser acceptance

Real Chromium tested production/debug-off cached Laravel pages, DB sessions/cache/queue and a 128M PHP server. The clean-company run exercised existing setup, forms and canonical task lifecycle plus UI-created off-page Team search. The synthetic 10k run exercised manager totals/previews, Projects/Tasks nonoverlapping pagination, global analytics/chart/print, inbox limits, mobile member dashboard, logout/login and PM search/read-only behavior. No browser page errors were observed in the large manager scenario. Screenshots were inspected for manager and 390×844 member layouts.

Evidence: `browser-final.txt`, `browser-cache-persistence.txt`, `browser-large-resumed.txt`; screenshots `output/playwright/r43-team-search.png`, `r43-large-dashboard.png`, `r43-large-analytics.png`, `r43-large-member-mobile.png`. The mobile check is limited to performance-change sanity, not accessibility, physical-device or PWA acceptance.

## 16. Authorization/scoping recheck

Aggregate reports and previews reuse `TaskReadService` canonical visibility. PM project/roster restrictions, member ownership, soft deletion, final-state exclusion, reviewer/current-revision deadlines and fresh notification masking remain enforced. Search adds predicates within existing roster authorization; it does not broaden scope. Owner bulk updates retain morph/owner/unread constraints. SQL parameters are bound; literal search wildcards are escaped. No cross-request permissions/business cache was introduced. R4.2 notification revocation, export injection and policy tests remain green.

## 17. Concurrency recheck

All 30 existing isolated MariaDB concurrency cases were rerun, plus two new R43 cases, for **32 passed / 238 assertions**. Separate guarded schemas run account/identity races, task edit and transition contention, push/delivery claims, reminder generations and membership idempotency. New tests run two real bulk-read workers and two simultaneous deadline command scans; existing-read timestamps/other users/post-update inserts and unique generation effects are verified.

Breakdown: R2a 1/10 assertions; R2b5 2/14; R2b5q 15/93; R3a2 3/22; R3a3 8/71; R42 membership 1/7; R43 2/21. Logs are `output/r43/*-tests.txt`. No gated case is counted as passed merely because the ordinary suite skipped it.

## 18. R4.1 regression results

Full SQLite/MariaDB and existing browser scenarios preserve task creation, Team editing, project atomicity, reviewer/deadline ownership, validation, transport recovery, duplicate-submit guards, identity/text contracts, lifecycle timelines, stale edits and logout persistence. Two fresh CLI company installations each passed installer and repeat-installer checks with DB queue/cache/session drivers. R2a additive upgrade/rollback/reapply qualification passed. Existing 14 browser cases all passed on a fresh empty company.

## 19. R4.2 regression results

Canonical state/cohort/event oracles, field/ID boundaries, notification authorization, CSV formula/Unicode safety, membership idempotency, throttle isolation and database exception redaction pass in the full suites. Historical upgrade/provenance, migration reset/remigrate and zero remaining migration rows pass. Production-cached timezone checks pass in Asia/Kathmandu and America/New_York, including local midnight, due-today, reminder and report boundaries. Relevant evidence is `r42-legacy-upgrade.txt`, `r42-reset-count.txt`, `r2a-migration-check.txt`, `timezone-*-check.json` and `fresh-*-check.txt`.

## 20. Full SQLite results

**569 passed, 49 skipped, 4,300 assertions, 72.87 seconds.** Evidence: `full-sqlite-final.txt`, `sqlite-final-junit.xml`. This is 31 additional ordinary cases relative to R4.2. Skips are 32 separately gated concurrency cases, 16 MariaDB-specific cases and one retired nullable-UID rollback case. MariaDB behavior is not inferred from SQLite.

## 21. Full MariaDB results

**585 passed, 33 skipped, 4,608 assertions, 178.92 seconds.** Evidence: `full-mariadb-final.txt`, `mariadb-final-junit.xml`. Skips are 32 separately qualified concurrency cases and one retired nullable-UID rollback case. The 16 engine-specific checks skipped on SQLite execute here. This is 31 additional ordinary cases relative to R4.2, plus the two new isolated concurrency cases.

An initial nonqualifying local suite accidentally saw a pre-existing compiled route cache, causing a throttle-test failure. Qualification was rerun with isolated testing config/route/event paths. The application limiter was not changed to accommodate stale local cache data.

## 22. Browser suite results

Fresh clean-company gate: **15 passed, two intentionally skipped large-fixture cases, 4.3 minutes**. Separate large gate: **two passed, one intentionally skipped clean-company case, 41.7 seconds**. All 17 applicable scenarios passed across their required fixture types. Cache-clear/rebuild persistence rerun: **one passed, 10.0 seconds**.

Initial harness failures are retained: selecting a hidden mobile pagination link on desktop, expecting logout `/login` when the application's root displays login, and a resumed run while private servers had stopped. Selectors/assertions and server lifetime were corrected; final runs pass. None was hidden with retries or described as an application fix. Browser scaffolding still has zero automatic retries.

## 23. Build/style/dependency results

| Gate | Result |
|---|---|
| Composer validate --strict | Passed |
| Composer audit | No vulnerability advisories; no abandoned packages reported |
| npm audit / npm audit --omit=dev | Zero vulnerabilities in both |
| Pint, maintained source/test/script directories | Passed (`pint-final.txt`) |
| PHP syntax | 351 maintained non-Blade PHP files; zero errors (`php-syntax-final.json`) |
| Blade compile/syntax | 59 views; zero syntax errors (`blade-syntax.json`) |
| Browser JS syntax / CI Bash syntax | Passed |
| Vite build | Passed, 3.82s; JS 380.92kB / 125.93kB gzip |
| git diff --check | Passed |
| Config/routes/views cached production mode | Qualified on optimized screens, timezone and browser runs |

No PHPStan/Larastan or separate JS lint configuration exists, so none is claimed. No dependency/lockfile change or installation of infrastructure was necessary. Network-denied audit attempts were rerun with authorized read-only access; successful audit logs end in `-qualified.txt`.

The default whole-checkout Pint invocation encounters historical untracked audit/generated runtime files. Those evidence files were preserved; the explicit maintained-source scope passed. An additional Linux packaging symlink fixture could not complete under Windows MSYS (`Too many levels of symbolic links`); its normal archive case passed, no packaging code changed, and the existing Linux CI packaging gate remains. This auxiliary platform limitation is not represented as a passed R4.3 gate. Remote CI/deployment was not run.

## 24. Files changed

- `.github/workflows/ci.yml`
- `app/Console/Commands/SendOverdueTaskNotifications.php`
- `app/Console/Commands/SendTaskDeadlineReminders.php`
- `app/Http/Controllers/AnalyticsController.php`
- `app/Http/Controllers/ManagerDashboardController.php`
- `app/Http/Controllers/NotificationController.php`
- `app/Http/Controllers/ProjectsController.php`
- `app/Http/Controllers/TeamDashboardController.php`
- `app/Http/Controllers/TeamManagementController.php`
- `app/Services/TaskAnalyticsService.php`
- `app/Services/TaskDeadlineCandidates.php`
- `app/Support/ReadLimits.php`
- `app/Support/TaskDeadlineSql.php`
- `docs/R43_PERFORMANCE_BUDGETS.md`
- `resources/views/analytics-export.blade.php`
- `resources/views/analytics.blade.php`
- `resources/views/manager-dashboard.blade.php`
- `resources/views/notifications/all.blade.php`
- `resources/views/partials/mobile-bottom-navigation.blade.php`
- `resources/views/partials/navigation.blade.php`
- `resources/views/projects.blade.php`
- `resources/views/team-dashboard.blade.php`
- `resources/views/team-management.blade.php`
- `scripts/ci-mariadb-tests.sh`
- `scripts/r43-check-performance.php`
- `scripts/r43-explain.php`
- `scripts/r43-prepare-performance.php`
- `scripts/r43-profile.php`
- `tests/Browser/README.md`
- `tests/Browser/r43-performance.spec.js`
- `tests/Feature/CanonicalTaskReadPathTest.php`
- `tests/Feature/PerformanceReadCorrectnessTest.php`
- `tests/Feature/R43NotificationMariaDbConcurrencyTest.php`
- `tests/Feature/ScalabilityBudgetTest.php`
- `tests/Feature/TeamManagementTest.php`
- `tests/Support/r43_fixture.php`
- `tests/Support/r43_notification_worker.php`
- `R4_3_PERFORMANCE_SCALABILITY_RESOURCE_OPTIMIZATION_2026-10-03.md` (this report)

Source/helpers/tests/docs are committed. Raw benchmarks, traces and disposable qualification scripts remain local under `output/`; historical untracked evidence is preserved. No migration, dependency lockfile, `.env`, PWA, hosting or push-transport file was changed.

## 25. Commits

Logical commits were created locally on `main` only after qualification:

- `5d5990a904579cc8e485378e757dd22e141dcfad perf: aggregate task reports and bound dashboard reads`
- `40465a3ced589ad95cdc32ae3b9b336593fefe38 perf: bound project and notification reads and bulk updates`
- `f197af61dd94900519668e7f9cef035384d70766 perf: scan deadline generations in bounded scalar chunks`
- `91974133fe5d840b9a166261640d968fa114fec8 test: enforce R4.3 scalability and resource qualification gates`

The final report is committed separately as `docs: record qualified R4.3 performance stabilization`. Its SHA and the complete final sequence are recorded outside this self-referential document in `output/r43/final-commits.txt`. No existing R4.1/R4.2 commit was amended.

## 26. New defects discovered

The notification center's shared navigation hydrated 20,000 notices in the 10k fixture despite center pagination; the unread collection added another materialization. These now use a count and eight-row preview. Repeated notification accessor reads multiplied fresh permission checks within each rendered row; each row now evaluates its authorized payload once.

The creation chart used completion trend data despite its label; it now uses the correct creation series. Current-stage deadline projection and bounded preview formatting are covered by independent stage tests. Replacing an Eloquent last-updated value exposed an intermediate missing Carbon cast; that implementation error was corrected before qualification and its failed profile retained. Consumed-generation scheduler scans were demonstrated to repeat thousands of model/query operations; the bounded scalar fingerprint skip addresses them without changing atomic delivery.

No newly discovered R4.3 acceptance defect remains open. Browser harness/environment issues and the Windows-only auxiliary packaging limitation are documented in sections 22–23.

## 27. Remaining R4 findings

R4.4 retains R4-012 stale open surfaces, R4-013 keyboard card behavior, R4-020 Unicode avatar initials, R4-021 raw technical role labels, R4-022 missing accessible control names and R4-024 contrast, together with PWA/freshness/synchronization/presentation and cross-browser/device work. R4-025 Team search is closed in this phase, including real browser qualification.

R4.5 retains HTTPS staging/Hostinger, production cron/queue, large backup and actual restore, real SMTP/push, Android/iOS/Safari/installed PWA, synchronization, operational soak and final release acceptance/tagging. Roster/project/device fan-out and production capacity remain explicit operational dimensions. No remaining-phase implementation or release tag was started here.

## 28. Performance budgets established

[`docs/R43_PERFORMANCE_BUDGETS.md`](docs/R43_PERFORMANCE_BUDGETS.md) documents the path map, scope and numeric contracts. Every 10k child runs at 128M with an actual allocated peak <=96 MiB. Stable ceilings cover SQL count, Task retrieval, loaded relationships, output bytes and page/preview sizes; no fragile millisecond assertion is used.

Core ceilings: aggregate <=10 queries/zero Task models; each dashboard <=80 queries/40 Task retrievals/200k bytes; Projects <=64 queries/zero loaded Task children/12 cards/400k bytes; Tasks <=80 queries/24 cards/400k bytes; Team <=64 queries/12 cards/160k bytes; inbox <=150 queries/20 rows plus eight header previews/180k bytes; global analytics <=64 queries/180k bytes; member report <=64 queries/ten recent rows/120k bytes; CSV/print <=30 queries/zero Task retrievals/50k and 40k bytes for the 40-member fixture. Mark-all is exactly one UPDATE. Initial deadline commands <=60k queries at 10k; consumed repeats <=50 queries/zero Task retrievals/zero new notices.

Normal CI contains small deterministic correctness/query/pagination tests. The existing MariaDB CI architecture adds two gated concurrency cases and a separate 10k/22-process resource gate. Browser CI runs the clean workflow/search gate and a separate synthetic 10k gate on a 128M server. Existing required quality/MariaDB/browser dependencies remain. These gates are configured and qualified locally; no remote CI run is claimed.

## 29. Final Git state

Branch remains `main`. Qualified source/test/documentation HEAD before the report commit is `9197413` (full SHA in section 25). The only final additional tracked change is this report commit. The final branch, exact HEAD, status, changed-file inventory and commit sequence are recorded in `output/r43/final-head.txt`, `final-status.txt`, `final-files.txt` and `final-commits.txt` after committing the report. Tracked and staged changes are clean; historical and new raw evidence remains intentionally untracked. No unrelated untracked helper/report was staged.

Private PHP/MariaDB qualification processes were stopped and generated secret-bearing cache files/disposable DB data were removed. Benchmark JSON, test logs, oracle results and screenshots were retained. Customer `.env`, normal DB/service, original tracked files outside the repair and historical evidence remain preserved.

## 30. Deployment state

**Nothing was pushed or deployed.** No remote branch, production database, deployment switch, release tag, PWA installation, real mail/push provider or hosting environment was modified. The single-company isolated application/database contract remains. The final classification is **R4.3 PASSED — PERFORMANCE, SCALABILITY AND RESOURCE LAYER STABILIZED**; whole-product production acceptance awaits R4.4/R4.5.
