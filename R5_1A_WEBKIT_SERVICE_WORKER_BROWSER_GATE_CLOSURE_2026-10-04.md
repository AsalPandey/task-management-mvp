# 1. Executive summary

R5.1A qualifies the existing R5.1 repairs and closes the available WebKit/PWA browser blocker. The application continued asynchronous PWA startup after navigation began, retrying a cancelled worker registration in a departing document. WebKit emitted an access-control error. Startup now respects document lifetime, cancels its own pending requests, and does not retry a failed registration automatically. Actual notification-display capability is checked independently of worker support.

The worker itself, cache boundaries, CORS, dependencies, database migrations and R5.2 domain code are unchanged. An adjacent mounted-path logout defect was repaired by redirecting to the named login route. Permanent regressions exercise real Chromium and WebKit, including offline/account safety and root/subdirectory operation.

# 2. Final classification

**R5.1A PASSED — WEBKIT/PWA BROWSER GATE CLOSED**

Available WebKit and Chromium gates pass without console-error suppression. Firefox remains an unavailable external qualification item.

# 3. Starting Git state

Repository: `C:\xampp\htdocs\Task Management\task-management`; branch `main`; starting HEAD `62763a13148cbff4afbcf74a69bf708b0602a0e0`. Tracked and staged changes were empty. Existing untracked audits, historical output, deployment scripts and the independent audit test were preserved. The starting status, staged diff and last 15 commits are in `output/r5-1a/starting-*.txt`. Completion date: 2026-10-04 (Asia/Katmandu).

Node v22.14.0, npm 10.9.2, Playwright 1.63.0; bundled Chromium 153.0.8010.12 revision 1243 and WebKit 26.6 revision 2359. Evidence: version text files and `browser-versions.json` in the new evidence directory.

# 4. R5.1 inherited status

R5-001, R5-002 and R5-007 were repaired in R5.1. Its report recorded 39 browser passes, three skips and one WebKit failure despite successful functional assertions. Its earlier server, concurrency, build, dependency and resource gates passed. The historical R5.1 report remains unchanged. This report supersedes only its browser-gate classification through new evidence; it does not erase the recorded historical evidence-preservation mistake.

# 5. Pre-repair WebKit reproduction

Before source changes, the exact maintained WebKit smoke passed seven bounded runs on the fresh environment: one instrumented, three repeated instrumented and three uninstrumented. This establishes timing sensitivity, not absence of a defect. The retained inherited trace independently shows the failed worker request cancelled at navigation.

Delaying only worker and push-status responses by 1,200 ms in the diagnostic harness reproduced the exact worker access-control error with unchanged application source and the unchanged maintained smoke. All workflow assertions completed; the strict error assertion failed. `red-delayed.txt`, `red-delayed-forensics/events.jsonl`, `delayed-failure-sequence.txt` and `output/playwright/r5-1a-red-delayed/` retain the RED evidence. The same delayed smoke passed after repair (`green-delayed.txt`, corresponding telemetry and unique Playwright directory).

# 6. Exact console errors

The inherited R5.1 failure reported:

```text
Fetch API cannot load http://127.0.0.1:8063/push/status due to access control checks.
/127.0.0.1:8063/service-worker.js due to access control checks.
```

The diagnostic RED emits the equivalent worker pageerror at port 8071. Playwright reports its message as `/127.0.0.1:8071/service-worker.js due to access control checks.` Registration first rejects with `TypeError: Script http://127.0.0.1:8071/service-worker.js load failed`; the immediate retry rejects with `SecurityError`. These are application-triggered operations in a document being navigated away from, not a benign message whitelist.

The push/status message is an optional background-fetch failure on the same startup chain. Its inherited stack identifies `pwa.js` API/refresh/DCL calls; the inherited network record and new worker retry trace support the document-lifetime diagnosis. The forced RED directly reproduced the worker variant, not a second independent reproduction of every inherited push message.

# 7. Request/network trace

Old `pwa.js` DCL startup line 372 calls `navigator.serviceWorker.register('/service-worker.js', {scope:'/', updateViaCache:'none'})`. Its same-origin GET has `Service-Worker: script`, `Sec-Fetch-Mode: same-origin`, `Sec-Fetch-Site: same-origin`, and ordinary script cache headers. The diagnostic server fetch returns 200; the held browser request is cancelled as login/navigation starts. `requestfailed` says `Load request cancelled`. Startup's old registration catch then continues to `refresh()` at line 403, which retries registration at line 132 in the departing document and immediately triggers the SecurityError/pageerror.

The old API function at line 76 fetches same-origin `push/status` with same-origin credentials after registration resolves. It lacked a document-lifetime boundary. Selected safe headers, response status, page URL, origin, call stack and event order are recorded; CSRF, cookies and authorization headers are excluded from diagnostic telemetry. The read-only inherited trace extraction is `inherited-network.json`, with the original cancelled worker request at 2026-10-04T08:55:44.505Z. Minimal vanilla navigation/cancellation experiments are retained as supporting evidence; they did not reproduce the exact application error by themselves.

# 8. WebKit capability matrix

Actual observations from fresh contexts on secure loopback origins, at root and mounted path:

| Capability | WebKit 26.6 | Chromium 153 |
|---|---|---|
| Secure context | true | true |
| navigator.serviceWorker | present | present |
| ServiceWorkerRegistration | function | function |
| PushManager | function | function |
| Notification | function | function |
| navigator.permissions | object | object |
| registration pushManager property | present | present |
| prototype.showNotification | undefined | function |
| initial notification permission | default | denied in headless context |
| beforeinstallprompt API | absent | present |
| active worker/control | verified | verified |

API presence does not certify subscription or delivery. WebKit lacks notification display capability here, so push UI reports Unsupported and makes no status/subscription request. Worker/offline functionality remains available. Chromium uses the supported-capability path, reports configuration unavailable for intentionally empty qualification VAPID settings, and handles optional status failure without false success. No real external push is sent. See engine/path JSON under the PWA evidence directories.

# 9. Service-worker lifecycle

Fresh contexts start without prior registrations/caches. Actual registration, activated state, controller script and scope are verified. Worker install populates the static cache, activation claims clients and removes obsolete same-scope caches. Real update probes change only a temporary cache-version suffix, await control/cache results, retain unsaved draft input and restore the source in `finally`. The final worker source remains byte-identical to the starting tracked file. Instrumented WebKit registration records an installing worker followed by installed, activating and activated transitions; controller verification completes the lifecycle. Reused contexts test subsequent navigation, updates, logout and account switching; stale browser state is unnecessary for RED reproduction.

Playwright's worker-event/network inspection APIs have Chromium-specific limits; DOM registration/control observations are used for actual WebKit lifecycle qualification. See [Playwright service-worker guidance](https://playwright.dev/docs/service-workers).

# 10. Origin/secure-context analysis

Diagnostic roots are HTTP `127.0.0.1:8071` and mounted server `127.0.0.1:8072/qualification/task-management`; first complete gate uses 8077/8078, closure gate uses 8080/8081. The synthetic 10k server uses 8079. Temporary loopback proxy ports are recorded in JSON. All are observed secure contexts. The worker and manifest share each page's origin and correctly scoped base path; no failed request crosses origins. Worker/manifest responses are successful when not deliberately interrupted.

Root worker/scope are `/service-worker.js` and `/`; mounted equivalents are `/qualification/task-management/service-worker.js` and `/qualification/task-management/`. Manifest scope and start URL preserve the corresponding base. No evidence proves a CORS defect. No CORS headers, arbitrary proxy feature or SSRF protections were changed. Production HTTPS is an external qualification requirement, not an explanation used to dismiss this loopback defect.

# 11. Root cause

Primary classification: existing APPLICATION ERROR in PWA initialization. Cancelled registration promises continued into automatic registration retry and optional push fetch while their document was leaving. Chromium's cancellation timing/reporting did not trigger the maintained strict error assertion in the qualified runs; this does not make the continuation valid. WebKit exposes the unsafe continuation as SecurityError/access-control failure.

Secondary classification: CAPABILITY ERROR in the prior push guard. PushManager/Notification presence was insufficient on this actual WebKit build because `showNotification` is missing. This is separate from worker availability. Neither failure requires a worker rewrite, root-scope widening, permissive CORS, error filtering or a WebKit user-agent branch.

# 12. Was R5.1 causal?

The audited pre-R5.1 and starting R5.1 HEAD have the same PWA blob `a6018719a5bdaf5bed25c25f9f89852c80904151` (`inherited-pwa-blobs.json`). The defective startup path predates R5.1. The evidence does not establish that an indirect R5.1 timing change could never influence manifestation; it establishes that R5.1 did not introduce this source defect. The integrity repairs remain independently covered.

# 13. Repair or harness correction

`public/js/pwa.js` tracks page departure with beforeunload/pagehide, aborts only its own pending API requests, refuses registration/API continuation after departure, and restores refresh on persisted pageshow. Startup owns registration; refresh does not automatically retry failed registration. Live registration failure displays an offline-unavailable explanation. The redundant explicit startup update request is removed: registration already performs the standard update check ([Service Worker specification](https://w3c.github.io/ServiceWorker/#navigator-service-worker-register)). Push requires notification display capability in addition to the existing API and secure-context checks.

Logout now redirects via `route('login')`; root lands on `/login`, and the mounted application lands on its explicit scoped `/qualification/task-management/login`. The previous bare mounted-root redirect produced a local 404. An attempted trailing-slash diagnostic did not qualify the mounted root route; it was reverted. The maintained mounted router is unchanged. The PHP regression asserts scoped logout and unauthenticated state.

Permanent tests add nine actual-engine cases and documentation. The first complete run had 49 passes, three skips and one new Chromium lifecycle timeout during navigation after worker-version restoration (not the inherited WebKit console failure). Its trace is retained. The test initially used cache existence as a completion signal, which can precede restoration activation; an explicit installing/waiting/active-state barrier was added. All nine cases then passed on that same fully provisioned schema (`update-barrier.txt`, nine passes in 1.6 minutes). The fresh closure gate verifies the final test synchronization; no retry or timeout increase is used. Existing smoke assertions are unchanged. Three older specs now place success screenshots under `BROWSER_OUTPUT_DIR`; this corrects artifact routing only. Full qualification is rerun with a fresh schema/output after that correction.

# 14. Why the repair is correct

The repaired delayed smoke uses the same timings and strict assertions as RED. The barrier regression permits exactly the deliberately cancelled worker request and rejects every page/console error and departing-document retry. This is a causal regression, not a generic browser whitelist. Other tests deliberately remove PushManager or fail only push/status to verify honest degradation, visible explicit-retry failure and retained worker control.

The production code adds no empty catch, unhandled-rejection suppression, UA detection or global fetch override. Supported push remains enabled in capable browsers. Authenticated JSON/mutations/navigation are never added to offline caches. Domain transactions and normal application writes are unaffected by the PWA-owned request cancellation.

# 15. WebKit functional results

Final maintained WebKit smoke PASS (26.2 s) with zero recorded application console/page errors. The final full gate passes. The repaired delayed maintained smoke passed login, creation/start, keyboard/filter/core-page navigation and logout with unchanged strict error assertions. The nine-test matrix includes actual WebKit settings/PWA startup. Tests use real rendered pages and dummy disposable accounts.

# 16. WebKit service-worker results

The nine-test PWA matrix passes on final application source: activation/control, offline static page and stylesheet, authenticated network-only navigation, reconnect, actual update signaling, draft retention, stale cache cleanup, logout/account switch, missing PushManager, actual unsupported notification display, and navigation cancellation without retry. Root and mounted-path lifecycle cases both pass. `pwa-capability.txt`: nine passed, zero skipped/failed. After the restoration activation barrier, `update-barrier.txt` also has nine passes, zero skips/failures.

# 17. Chromium regression

Actual Chromium runs the same root/mounted lifecycle/offline/update/account matrix. It also verifies supported-capability optional status failure with a truthful stale state and a visible explicit-retry error. Missing PushManager is separately injected. Final maintained Chromium smoke PASS; the complete available-engine gate passes.

# 18. Root/subdirectory results

Both engines pass worker URL/scope, manifest scope/start URL, static caches, generic offline fallback, login, dashboard, settings, updates and scoped logout. The existing mounted authenticated-mutation test also passes. Qualification covers explicit application routes; it does not certify every possible bare mounted-root web-server rewrite or physical Hostinger deployment configuration.

# 19. Offline/reconnect

Actual network interruption uses a temporary loopback proxy with closed application connections, exercising the unchanged real worker. Cached CSS and the generic offline page load; reconnect returns to the real authenticated dashboard. WebKit `context.setOffline()` produced an internal navigation failure in a diagnostic attempt before the fallback, so it is not used to claim product failure or success. A rejected worker-controlled optional fetch is narrowly injected because route interception is not uniformly available in WebKit; [Playwright documents worker routing limitations](https://playwright.dev/docs/service-workers).

Those diagnostic harness failures remain retained; only passing final cases qualify behavior. Deliberate offline/cancellation request failures are enumerated by exact URL in those tests, while all console/page errors remain failures.

# 20. Account/cache safety

CacheStorage contains only the existing allowlisted static paths/manifest/offline shell. Private HTML, JSON, push responses and mutations are excluded by the unchanged worker policy. Offline after logout never exposes the former manager's content. Switching to a member shows the member route with no former-manager content. Existing multi-tab account invalidation and failed-mutation recovery checks pass. Cross-origin and out-of-scope requests bypass the worker; non-GET requests are not intercepted. Cache scope and push-target restrictions are unchanged.

# 21. R5-001 regression

Dedicated MariaDB `R51ProjectWriterMariaDbConcurrencyTest`: nine passed, 97 assertions (`r51.txt`, XML and exit 0). It observes real writer lock waits and checks stale PM ownership, replacement locks, actor deactivation and role revocation. Maintained browser PM replacement/access regression is included in the complete gate.

# 22. R5-002 regression

The maintained delayed approval holds a real stale prompt while another session revises/resubmits and restarts review. It verifies rejection without approval/task/version/event/history/notification mutation, understandable reload and successful fresh approval. The final closure gate also passes this maintained delayed approval case (52.6 s). Intent/precondition server regressions also pass in the full suites; no serializer or transition implementation was altered here.

# 23. R5-007 regression

The nine dedicated races cover create/delete and move/delete in both lock schedules, plus rollback safety. Browser integrity coverage verifies protected populated-project deletion and successful empty-project deletion after UI-created fixtures. Final complete gate PASS, including PM replacement/protected and empty-project deletion (30.9 s). Project deletion/create domain code is unchanged.

# 24. SQLite

Full maintained final suite: 578 passed, 58 skipped, 5,038 assertions, 56.97 s; exit 0. Evidence: `sqlite-final.txt` and `sqlite-final.xml`. Skips are engine-specific concurrency groups, not passes. Logout/backend changed, so full backend reruns were performed.

# 25. MariaDB

Full maintained final suite: 577 passed, 59 skipped, 5,035 assertions, 90.83 s; exit 0. Evidence: `mariadb-final.txt` and XML. Dedicated concurrency classes are intentionally skipped unless explicitly enabled; the SQLite-only UID backfill case also skips here. The nine R5.1 races run separately and pass; this report does not relabel the other groups' ordinary-suite skips as newly executed races. Prior R5.1 dedicated concurrency evidence remains historical.

Qualification uses production-like database session/cache/queue drivers, dummy application/setup secrets, and empty VAPID keys to prevent external transport. Push configuration retains its existing queue/TTL/host policy. An inherited diagnostic WEBPUSH_ENABLED override has no application configuration consumer and is not a product push-disable change.

All database work uses a new private loopback MariaDB instance on 3359 and clearly disposable schema names. No customer environment, customer database or installed service is used or changed.

# 26. Browser suite

**50 passed, 3 skipped, 0 failed**, exit 0, 15.0 minutes. Final evidence: `output/r5-1a/closure-browser.txt` and dedicated `output/playwright/r5-1a-closure/`, on `task_management_r41_browser_r51a_closure`. The new nine-test PWA matrix passes within this full run. The earlier 49-pass/one-failure run is retained and is not used as the final qualification. The maintained gate uses one worker, no retries, a fresh empty MariaDB schema prepared through the existing setup contract, UI onboarding/account/project/task workflow and the independent persistence oracle. Available Chromium and WebKit execute; Firefox remains BLOCKED / UNAVAILABLE IN CURRENT HOST. Its known Windows side-by-side/spawn UNKNOWN launch failure is recorded, not repaired or counted as pass. The two synthetic large-fixture tests skip in the clean-company gate and run separately.

# 27. Accessibility

Maintained core-page axe high-confidence WCAG A/AA checks, keyboard/action names, install-dialog/update focus safety, Unicode content and responsive reflow checks pass in the complete browser run. Confirmed again in the final closure gate, including axe and responsive checks. This is the maintained check scope, not a claim of universal accessibility conformance. R5-016 duplicate landmarks remains open and is not repaired here.

# 28. Resource regression

Fresh synthetic fixture: 10,000 tasks, 20 projects, 10,000 notifications. Existing R4.3 resource checker PASS, 22 fresh PHP processes, 128M limit and measured maximum 44 MiB peak (below its 96 MiB guard). Independent arithmetic, query/output bounds and repeat scheduler delivery checks pass. Evidence: `resource-fixture.json`, `resource-results.json`, exit 0. Separate large browser gate: two passed, one clean-company-only test skipped; exit 0, 25.0 s (`large-browser.txt`). Manager totals/list pagination/analytics/print/notifications and 390px member/PM search checks pass. Screenshots use `output/playwright/r5-1a-large/`. No R5-010/R5-017 capacity work was started.

# 29. Dependency/build gates

Composer validate --strict, Composer audit, npm ci, full npm audit, npm audit --omit=dev and npm run build all exit 0. Composer advisories/abandoned packages and npm vulnerabilities are empty. No dependency upgrade. `.env`, `composer.lock` and `package-lock.json` match protected baseline hashes (`protected-files.json`). Tracked PHP Pint PASS; Blade compilation PASS; 419 PHP/generated Blade files and 35 JS files syntax-check with zero failures. All committed JS, including screenshot routing and the activation barrier, pass the final repeated syntax check (`quality-closure.txt`). git diff --check passes.

# 30. Files changed

- `public/js/pwa.js`: lifetime-safe initialization and precise push capability detection.
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`: named scoped login redirect after logout.
- `tests/Feature/Auth/AuthenticationTest.php`: logout and mounted-path regression.
- `tests/Browser/r51a-pwa.spec.js`: nine focused actual-engine regressions.
- `tests/Browser/README.md`: qualification, capability and evidence contract.
- `tests/Browser/core-workflow.spec.js`, `r42-correctness.spec.js`, `r43-performance.spec.js`: success screenshot destination follows isolated output directory.
- This final report.

No migration, worker source, lockfile, production environment or domain notification/business contract changes. New diagnostics/evidence live under `output/r5-1a/` and unique `output/playwright/r5-1a-*` directories, except the historical standalone screenshot issue disclosed below.

# 31. Commits

1. `a0c3509f64c1dec3c5c877c33f5648de3e580b17` — fix: stabilize webkit pwa capability handling.
2. `5ec7a81988be22ef86faa86f2bf73c8bb7c30b6a` — test: qualify webkit service worker behavior.
3. `0331907e721753b7fbe4e462e604522464312cc7` — test: isolate browser qualification screenshots.
4. `5d7333edcb643872a550e6cbc5dac07b20655559` — test: await restored worker activation before navigation.
5. Final documentation commit: `docs: record R5.1A browser gate closure`; resolve its exact SHA from HEAD after commit (a report cannot contain its own final content hash).

# 32. Remaining limitations

Firefox is unavailable on this host. No physical Safari/iOS, installed PWA, permission-grant/push subscription delivery, live VAPID transport, customer SMTP, production HTTPS or external Hostinger acceptance is claimed. Diagnostic APIs and artificial optional failures are distinguished from real network/lifecycle checks.

Evidence preservation: unique Playwright output prevented clearing old R5/R5.1 directories. However, inherited success-screenshot literals in three older specs wrote shared standalone `output/playwright/r41-*`, `r42-*`, and `r43-team-search.png` during the first full run. Earlier contents, if present, were overwritten and cannot be reconstructed. The defect was identified, corrected, committed, and the full gate rerun on a fresh schema and unique output. No historical R5/R5.1 directory or report was deleted/cleared. This disclosed limitation is separate from R5.1's already recorded transient-artifact loss; evidence completeness is not retroactively claimed.

# 33. Remaining R5 findings

Still open: R5-003, R5-004, R5-005, R5-006, R5-008, R5-009, R5-010, R5-011, R5-012, R5-013, R5-014, R5-015, R5-016, R5-017, R5-018 and R5-019. R5.2 is next, then R5.3/R5.4 and outstanding external R4.5 acceptance against the exact final repaired SHA. None of that work starts in this phase.

# 34. Final Git state

All application and test changes are committed; final documentation is committed after this report is saved. Post-commit tracked/staged clean state and exact documentation HEAD are verified in the final Git records. Qualified runtime code SHA: `5d7333edcb643872a550e6cbc5dac07b20655559`; the final documentation commit also updates the README engine-install instruction. Only the named phase files are committed. Existing untracked work and local evidence remain. Tracked/staged clean status and full final HEAD are saved in `output/r5-1a/final-git-*.txt`. All temporary worker edits are restored. Phase-owned loopback servers/database are stopped after identity verification; generated configuration/route/event caches under the owned phase directory are removed, with logs and evidence retained (`cleanup-result.json`, verified process identities, and removed-cache inventory). Cleanup stopped the eight phase PHP servers across diagnostic/final runs and the private MariaDB instance; no unrelated process was stopped.

# 35. Deployment state

No push, deployment, release tag, customer environment edit, production database action or server/service installation. Only local source/test/report commits and disposable qualification environments. External acceptance remains required.

# 36. R5.1 reclassification

**R5.1 PASSED — AUTHORIZATION, INTENT AND TRANSACTIONAL INTEGRITY REPAIRED**

R5-001, R5-002 and R5-007 remain green, and the available-engine browser blocker is closed. The historical R5.1 report is unchanged; this phase supplies new browser/integrity evidence only.
