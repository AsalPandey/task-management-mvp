# R5.3 qualification contracts

Use only disposable databases. Never run fixture writers against a company database.

`scripts/ci-mariadb-tests.sh` now includes fresh dense 10,000 and 25,000-task gates in addition to the existing R4.3 resource and concurrency gates. The whitelisted schemas are `task_management_r43_r53_ci_dense10000` and `task_management_r43_r53_ci_dense25000`. Each fixture has representative users, membership, eight workflow states and final-state evidence. A second manager permits dependency validation without triggering the separate last-manager guard.

The gate rejects dependent role demotion without hydrating tasks, makes every active reviewer require replacement, commits real changes and required notification delivery, and checks versions, task histories, task events, intent counts and absence of pending intents. It then accepts reviewer dependency validation for the newly owned projects. Each measured operation runs in a fresh PHP process with `memory_limit=128M` and a **64 MiB** peak-memory budget. Qualification measured 36 MiB at 25k, leaving 28 MiB of budget tolerance and 92 MiB below the runtime limit. Delivery remains synchronous and expensive; this is a memory capacity gate, not a shared-host request-time guarantee.

For a manually prepared **fresh** dense fixture matching the `task_management_r43_r53_*` guard, run:

```sh
php -d memory_limit=128M scripts/r53-check-administration.php
```

Never reuse its consumed fixture for baseline comparisons. `r53-profile-administration.php` provides instrumented single-operation output; its optional `rollback` argument is suitable for a non-delivery diagnostic, not full mutation acceptance.

Task timeline GET responses contain `entries`, `has_more` and `next_cursor`. The first page contains at most 100 display events. Pass a positive integer `before` sequence to load older entries, exclusively below that sequence. Entries are returned in ascending order within each page; clients prepend older pages. New events above the anchored cursor do not shift older pages. Existing adjacent approval/completion display collapse happens before pagination. Raw stored events are preserved. Every page independently authorizes the current user and applies private-note redaction. The UI offers keyboard-accessible loading, retry and end states.

Notification presentation memoizes owners and resources only in the current safe HTTP request. It is not used by delivery authorization or mutation checks. A subsequent HTTP request rereads membership, account and resource state. Candidate datasets were inspected and retain their existing minimal DTOs; no speculative payload refactor was introduced.

Chart.js has a separate Vite entry loaded by company and member analytics. Build both entries with `npm run build`. Root and mounted analytics, print, non-chart routes, Chromium and WebKit are covered by the maintained browser suite. No new dependency or migration is required.

Regression coverage: `R53CapacityNavigationTest`, `z-r53-timeline.spec.js`, `z-r53-charts.spec.js`, the full SQLite/MariaDB suites, all named MariaDB race groups and the R4.3 large resource/browser gates. Qualification artifacts are retained under `output/r5-3-20261005` and unique `output/playwright/r5-3-20261005-*` paths. Initial failing experiments remain available alongside final results.
