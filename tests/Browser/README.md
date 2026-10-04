# Required browser qualification

Run `npm ci`, `npx playwright install chromium`, and `npm run test:browser` against an **empty disposable MariaDB schema**. The CI `browser` job is a required release dependency alongside `quality` and `mariadb`.

The suite performs setup, login, account provisioning, project creation/membership, task creation, work/review/revision/approval, deadline changes, account edit/role confirmation, validation/transport recovery, duplicate submit protection, stale edit recovery, logout/login and timeline inspection through Chromium and the real rendered pages. No task or final workflow state is inserted by setup code. A separate read-only PHP oracle checks persistence and event sequences.

Environment requirements:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=http://127.0.0.1:8046
APP_TIMEZONE=Asia/Kathmandu
APP_SETUP_TOKEN=r41-disposable-installer-token
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=<private MariaDB port>
DB_DATABASE=task_management_r41_browser_<unique disposable suffix>
DB_USERNAME=<private test database account>
DB_PASSWORD=<private test database password>
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
```

Use an ephemeral application key and process-level overrides; do not edit a customer `.env`. Local runs sharing a checkout must also isolate `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE`, `APP_EVENTS_CACHE` and `VIEW_COMPILED_PATH` under a session-specific output directory. Laravel cache paths must resolve from the repository root.

1. Create a new empty schema with the required disposable prefix.
2. Run `php scripts/r41-browser-prepare.php`. It refuses a nonempty schema or an incompatible engine/configuration, then migrates, seeds canonical roles and builds production caches.
3. Start Laravel's PHP router **from `public/`**: `php -S 127.0.0.1:8046 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.
4. From the repository root run `npm run test:browser` with the same environment.
5. Run `php artisan optimize:clear`, then `php artisan optimize`, then `npx playwright test --grep 'logout and login retain'` to check persistence after cache rebuilding.
6. Stop the private app/database processes and remove only generated caches containing configuration secrets. Preserve test results and evidence.

The complete suite runs with one worker against one fresh company. Individual diagnostic tests can be selected only after their UI prerequisites have run. A failed onboarding test makes downstream failures non-qualifying; rerun the full gate on a new empty schema. No automatic retries hide failures.

Artifacts go to `output/playwright/r41` (override with `BROWSER_OUTPUT_DIR`). Failures retain screenshots and traces; successful history and revision timeline screenshots are also retained. Authentication credentials in this suite are dummy qualification accounts only.

Transport simulations intercept only Team requests to exercise error UI. The weak-password test alters that form's outgoing password solely to exercise server validation. Task creation and all qualified transitions use the real serializer, CSRF, selected UI identities, authorization and database persistence.

MariaDB PHPUnit/failure-injection and concurrency gates remain separate. SQLite and HTTP tests do not substitute for this browser gate. This gate does not qualify installed PWAs, physical devices, real SMTP/push transport, scalability or external hosting.

## R4.3 scalability qualification

The normal browser run also creates 14 accounts through the real Team UI, locates the oldest account through server search, verifies Unicode/email search, clears it, and checks pagination and page reset. This runs after the existing workflow prerequisites.

A second run uses an explicitly disposable `task_management_r43_*` MariaDB schema prepared by `scripts/r43-prepare-performance.php 10000 20 10000`. Set `R43_LARGE_BROWSER=1` and run only `tests/Browser/r43-performance.spec.js`. This synthetic performance fixture is separate from the clean-company workflow gate. It checks manager totals, bounded dashboard lists, Projects/Tasks pagination, analytics/print, notification pagination, a 390px member dashboard, and PM read-only search. Run the PHP server with `-d memory_limit=128M`. CI runs both gates.

Stable PHPUnit budgets are in `ScalabilityBudgetTest` and `PerformanceReadCorrectnessTest`. The separate MariaDB qualification command `php -d memory_limit=128M scripts/r43-check-performance.php` starts fresh 128M PHP processes, checks independent fixture arithmetic, query/row/output limits, a 96 MiB measured peak guard, and repeat scheduler delivery. It is run on a fresh 10k fixture in the existing MariaDB CI job; no 25k/50k fixture is required in ordinary CI.

## R5.1 authorization, intent and project integrity

Dedicated lifecycle POSTs require `expected_version` from the rendered task. The maintained core workflow includes a reviewer holding an approval prompt on version 4 while another session revises/resubmits and begins review on version 8. It asserts 409, unchanged task/version/events/history/notifications, no approval, understandable reload UX, and successful fresh approval. It uses the real serializer and does not refresh the version before submitting.

`z-r51-project-integrity.spec.js` runs after clean-company provisioning. It creates a replacement PM and project through UI, checks former/new PM access, adds membership and a task, verifies protected deletion, and deletes an empty project. Its unique fixture names permit focused diagnostic reruns after prerequisites.

The independent MariaDB gate is `R51ProjectWriterMariaDbConcurrencyTest`, on a fresh `task_management_r51_concurrency_*` schema. Migrate that disposable schema before running the file. It uses separate HTTP-kernel processes and query-boundary barriers, observes actual InnoDB lock waits, and checks both create/move versus deletion schedules, PM replacement, account deactivation/role revocation, and injected deletion rollback. CI runs this gate separately; SQLite skips it. Polling a barrier does not substitute for observing the writer lock.

## R5.1A worker and push qualification

`r51a-pwa.spec.js` is part of the maintained gate. It launches actual Chromium and WebKit in fresh contexts and checks root and representative subdirectory registration, activation/control, static offline fallback, reconnect, update notices without losing drafts, obsolete cache cleanup, logout and account switching. Provide `R44_SUBPATH_URL` for a second PHP server running `tests/Support/r44_subdirectory_router.php` against the same disposable schema. Set a unique `BROWSER_OUTPUT_DIR` and `R44_EVIDENCE_DIR` for every run; never reuse historical evidence directories.

The lifecycle tests use temporary loopback proxies to interrupt actual application network access. WebKit's `context.setOffline()` can fail navigation internally before the worker fallback is used. The proxy exercises the real worker without changing its fetch policy. An update probe temporarily changes the worker cache version and restores the file in `finally`; run this gate serially and do not run another browser qualification against the same checkout concurrently.

Push requires the actual notification display capability as well as PushManager and Notification. The qualified Playwright WebKit build exposes PushManager but lacks `ServiceWorkerRegistration.prototype.showNotification`; it must display Unsupported, hide push actions, and retain the active worker. Missing PushManager and optional status transport failure are narrowly injected in separate tests. No console/page errors are filtered. The cancellation regression deliberately delays one worker response across navigation and permits only that exact request's cancellation; it rejects any console/page error or retry from the departing document.

The existing available-engine smoke keeps its strict console assertion. Firefox remains an explicit unavailable-host limitation when its Windows launch fails. These tests do not certify physical Safari, installed PWAs, production HTTPS, or live push delivery.
