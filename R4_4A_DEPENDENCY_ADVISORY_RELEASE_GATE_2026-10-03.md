# R4.4A — Dependency advisory resolution and release-gate reconciliation

Started: 2026-10-03; final recheck: 2026-10-04, Asia/Kathmandu.
Local qualification only; the requested report filename retains the phase date.

**R4.4A PASSED — DEPENDENCY RELEASE BLOCKER RESOLVED**

**R4.4 PASSED — CLIENT, PWA, UI/UX AND ACCESSIBILITY LAYER STABILIZED**

The candidate removes the affected package chain using the official Tailwind v4
compiler/Vite integration. Full npm audits report zero vulnerabilities. No audit
threshold, advisory ignore, override, fork or release-policy exception is used.

## 1. Starting state

Repository: `C:\xampp\htdocs\Task Management\task-management`; branch `main`;
starting HEAD `12f9e03cae5bf4d446093ff17787fd2613dbc6dd`. Tracked/staged state was
clean. The 23 historical untracked entries were preserved. Baseline package files,
Git state, dependency tree and tool versions are retained under `output/r44a`.

Node 22.14.0; npm 10.9.2; Vite 6.4.3; Tailwind 3.4.17; PostCSS 8.5.23;
Playwright 1.63.0; axe integration 4.13.0. The initially installed unused
`@tailwindcss/vite` 4.1.11 also carried a separate Tailwind 4.1.11 copy. The actual
CSS compiler was Tailwind v3 through `postcss.config.js`.

The first sandboxed npm install could not access the normal npm cache. A permitted
network/cache retry installed the original lock successfully. Fresh network audits
reproduced five high entries and a zero-vulnerability production-only audit.
Sandbox network failures are retained separately and are not vulnerability results.

## 2. Dependency tree

The complete original tree is `output/r44a/dependency-tree-before.json`; the
readable selected tree is `versions-and-paths-before.txt`.

```text
application devDependencies
  tailwindcss 3.4.17 (actual PostCSS compiler)
    chokidar 3.6.0 -> braces 3.0.3
    micromatch 4.0.8 -> braces 3.0.3
    fast-glob 3.3.3 -> micromatch 4.0.8 -> braces 3.0.3
```

Tailwind is a direct development dependency. Each child is declared as a normal
dependency by its parent, but this whole installed chain is development/build-only
relative to the application manifest. The forms plugin uses the root Tailwind peer.
The separate unused v4 plugin was not the source of the advisory. Removing only
that unused plugin would not have repaired the active v3 chain.

## 3. Advisory verification

[GitHub GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)
identifies **CVE-2026-93687**, affected versions `braces <=3.0.3`, no patched
release, published `2026-09-18T18:31:41Z`, updated `2026-10-02T22:36:34Z`.
The authoritative API response is saved as `github-advisory.json`. Fresh npm
registry metadata still reports latest braces **3.0.3**. The upstream issue is
[micromatch/braces #70](https://github.com/micromatch/braces/issues/70).

The advisory describes uncontrolled recursion in AST walking that can exhaust
the Node stack when a caller supplies a deeply nested brace pattern. Availability
is affected; this issue does not establish data theft or code execution. GitHub
shows CVSS v4 8.7, while npm's response includes CVSS v3.1 7.5; both classify high.
All five original npm entries derive from this one issue.

## 4. Technical exploit path

Installed `braces/lib/compile.js` recursively visits child AST nodes, and
`lib/expand.js` does the same during expansion. A character-count bound is not a
nesting-depth bound. A local child-process reproduction used 4,500 opening braces,
one character and 4,500 closing braces: 9,001 characters. Both compile and expand
threw `RangeError: Maximum call stack size exceeded`. The harness caught the error
to retain evidence and constrained the child to 64 MB and a five-second timeout;
there was no external target. See `bounded-parser-reproduction.json`.

The installed fast-glob pattern utility calls `micromatch.braces(..., {expand:true})`;
micromatch delegates expansion/parsing to braces. Chokidar 3 invokes braces.expand
for paths containing a brace. Tailwind's content resolver passes configured file
patterns into fast-glob. Its CLI watcher additionally uses micromatch/chokidar.

## 5. Application reachability

The original Tailwind content patterns were fixed repository configuration:
Laravel pagination templates, compiled view PHP, and application Blade templates.
The application's HTTP/controllers/routes/client code did not call braces,
micromatch or fast-glob, and no custom build script accepted customer glob input.
The only application-tree process invocation found in the targeted search was a
qualification script launching PHP children, not a web endpoint invoking Node.

An instrumented Vite build loaded braces/micromatch/fast-glob but recorded **zero
braces calls** for those brace-free patterns. This measures the normal build, not
every possible developer configuration. Template contents supply CSS class
candidates; they are not the configured path patterns passed into this parser.
HTTP form/customer data is stored in the database and cannot alter this build
configuration through the inspected application paths. Repository writers or
untrusted pull-request code can change configuration or execute Node in CI.

After remediation, the same instrumented build loads none of the four removed
glob/watch packages. See `build-reachability-before.json` and `-after.json`.

## 6. Runtime, build and CI exposure

| Surface | Original exposure demonstrated | Final state |
|---|---|---|
| Local Node build | Installed/loaded affected chain; developer-controlled patterns | Chain absent |
| Tailwind v3 CLI/watch | Vulnerable calls present in installed parent source; not the production build command | v3 toolchain absent |
| Browser | Vite emitted-module inventory contained no vulnerable package | Still absent; runtime JS SHA-256 is unchanged |
| PHP HTTP runtime | No Node/glob invocation found in application routes/controllers | No new runtime dependency |
| CI | `pull_request` builds repository code on Ubuntu runners, read-only repository permissions, 30-minute quality timeout | Existing full audit remains mandatory and is green locally |
| Hostinger artifact | Packaging explicitly excludes node_modules and consumes CI-built CSS/JS | Same artifact contract |
| Hostinger execution | Inspected deployment script invokes PHP checks/caches/migrations; no npm build on host | Same contract; actual deployed host was not inspected |

The original advisory was a build-process availability risk if hostile patterns
reached the parser. It was not demonstrated as a customer-triggered web exploit.
Untrusted CI repository code is still untrusted code generally; removing this
package does not claim to solve every CI threat. Existing audit-before-build,
read-only permissions, job timeouts and source/asset packaging boundaries were
observed in checked-in workflow/scripts. No unverified branch protection,
contributor restriction or production-host mitigation is claimed.

## 7. Remediation options evaluated

| Option | Security/compatibility result | Decision |
|---|---|---|
| Patched braces release | No release beyond affected 3.0.3 | Unavailable |
| Compatible Tailwind v3 parent update | 3.4.19 retains all affected paths; five high entries | Rejected after actual update/audit |
| Chokidar major-only replacement | New major removes glob support/chain, but other Tailwind paths still need braces; incompatible override | Rejected |
| Latest micromatch/fast-glob | Registry latest 4.0.8/3.3.3 still use braces | No repair |
| Remove Tailwind utilities altogether | Would require replacing working utility/style behavior manually | Higher maintenance and visual risk |
| Official Tailwind 4.3.3/Vite plugin | Removes chain, supported upstream integration, clean locked install, qualified visuals | Selected |
| Audit waiver/ignore or fork | Does not provide the preferred clean release gate | Not used |

Security and reproducible installation favored the official replacement. Browser
minimums and visual/default changes were explicit costs tested and documented below.

## 8. Parent upgrades attempted

`npm update tailwindcss --ignore-scripts` was the first actual upgrade attempt. It
installed compatible **3.4.19**, and a subsequent full audit still reported five
high entries. Its lockfile and audit are preserved as `package-lock-compatible-spike.json`
and `compatible-parent-audit.json`. The saved original lock was restored through
a clean install before capturing baseline images; no Git history or unrelated
work was reset. Current registry metadata for every relevant parent is retained
as `registry-current-*.json`.

## 9. Override evaluation

No genuinely patched braces release exists, so no override was installed. A major
chokidar override would not remove micromatch/fast-glob exposure and would change
the API expected by Tailwind v3. No vulnerable-version substitution, Git commit,
fork, integrity edit or npm audit suppression was used.

## 10. Tailwind migration spike and final implementation

Targeted npm install selected Tailwind and its official Vite plugin **4.3.3**.
The plugin is now active in `vite.config.js`; the obsolete PostCSS configuration
and direct autoprefixer/PostCSS dependencies are removed. Vite still uses its own
PostCSS dependency internally. The existing forms plugin remains **0.5.10**.

CSS uses the v4 import and explicit `@config`/`@source` directives. Automatic
whole-workspace scanning is disabled. Application and vendor pagination sources
are included, while generated view caches and test/output directories are excluded.
The existing font/theme/forms configuration remains supported JavaScript.

The first unadjusted spike cleared the advisory but failed visual comparison.
Repairs used documented v4 utility names (shadow, radius, outline and ring), kept
the prior border/placeholder/pointer defaults, and restored the measured label and
project-member-select spacing affected by cascade layers. No product workflow or
Laravel authorization change was introduced. Worker cache version v4 announces
the shell change; update regression now derives the current version instead of
hardcoding v3 and separately verifies old v3 cache removal.

[Tailwind's migration guide](https://tailwindcss.com/docs/upgrade-guide) specifies
Safari **16.4+**, Chrome **111+**, Firefox **128+**, with newer CSS primitives and
changed defaults. Those minimums are now explicit in `docs/FRONTEND_BUILD.md`.
The local latest-engine tests do not claim to test every minimum version or a
physical device. CI's Node 20 is within the new compiler's supported runtime;
local qualification used Node 22.14.0.

## 11. Visual regression results

**72/72 screen/viewport comparisons passed** against the saved accepted v3 build:
setup, login, manager dashboard, member dashboard, Team, Projects, Tasks, task
modal, analytics, notifications, settings and member analytics, each at
320x568, 390x844, 768x1024, 844x390, 1280x720 and 1440x900.

References were captured before migration from UI-created accounts/project/tasks,
with a separate empty setup database. They were not updated to accept the candidate.
The predeclared screenshot tolerance is 0.1%; computed styles and bounding boxes
are retained too. All 72 cases had no horizontal overflow. Remaining differences
are small v4 palette/border representations, extra transparent shadow terms and
three short Team detail-span widths changing by at most 0.04 pixels in the final
recheck (the prior same-day run differed by at most 2.43 pixels). All other
measured element geometry, display, padding, font sizes and line heights matched.
Desktop dashboard, narrow project page and task dialog images were visually
reviewed, alongside the baseline/candidate login images during repair.

Final evidence: `visual-qualified.txt`, `visual-qualified.json`,
`style-diff-qualified.json`, and `output/playwright/r44a/{reference,before,qualified}`.
The final 72-case run passed in 3.5 minutes. Failed initial spike images
and metrics remain under their own phase names. A retry after user continuation
restored terminated private servers; its connection-refused attempt is a host
lifecycle event, not a product regression.

The October 4 recheck initially differed only in two analytics chart images
because the rolling date window crossed midnight. The disposable visual server
was then offset to the baseline calendar day, with time advancing normally and
session-only cookies. Initial clock-fixture attempts exposed expired cookies, a
static-router forwarding error and non-expiring rate-limit windows under a frozen
clock; these fixture errors were corrected without changing application code,
reference images, comparison tolerance or the ordinary real-time browser suite.
Their logs remain under `visual-recheck*`; the final `visual-qualified.txt` is green.

## 12. Accessibility results

Eight axe-scanned pages have **zero violations**. The rendered keyboard, names,
modal focus, reduced-motion and forced-color checks passed. Team secondary-text contrast
was 7.69:1. The responsive matrix covers 81 cases and long/Unicode content covers
nine additional cases, with no horizontal overflow.

The maintained suite checks actual keyboard activation, contextual names, modal
entry/wrap/return, contrast and focus. The new R44A regression also checks rendered
reduced-motion durations, keyboard traversal and visible forced-color focus.
This is browser/automated qualification, not screen-reader or WCAG certification.

## 13. PWA and synchronization results

The fresh-company recheck passed cross-user polling and the complete lifecycle,
offline navigation/failed-save preservation, worker installation/update and
unsaved-input preservation. Creation was detected after **58,687 ms** against
the 60,000 ms polling interval. The initial browser run had one test-readiness
failure: after reload, HTML was visible before DOMContentLoaded attached task
actions. The trace records the early click. The helper now waits for page
initialization and asserts the confirmation appears; it changes no product
behavior, assertion or timeout budget. Both its focused rerun and full-suite
rerun passed that lifecycle. The earlier failing log remains `browser-final.txt`;
the final complete result is `browser-recheck.txt`.

The shell-version change uses the existing draft-preserving update notice.
Static-only caching, authenticated HTML/JSON network boundaries, offline failed
save recovery, worker update/cache cleanup, local invalidation/storage fallback,
multi-tab 409 safety, different-user lifecycle freshness and subdirectory scope
remain mandatory. No offline mutation queue or new realtime infrastructure exists.

## 14. Performance results

**PASS: 22/22 fresh processes with memory_limit=128M**, 10,000 tasks, 20 projects,
40 members and 10,000 notifications. Peak process memory was **44 MiB**, below the
96 MiB guard. Existing R43 query/hydration/response budgets were preserved.
`resource-10000.json` contains every result. The separate large-data browser run
passed both manager and mobile member/PM cases; its clean-company-only test was
deliberately skipped and is covered in the ordinary suite.

The primary generated CSS shrank from **40,239 to 30,745 bytes**; the separate
SweetAlert CSS changed from 30,697 to 30,443 bytes through the new optimizer.
Runtime JavaScript remained **380,916 bytes with identical SHA-256**. Vite chunk
names changed with the build graph, so the manifest remains authoritative.
Clean reinstall/rebuild produced identical source-scoped assets before the final
dropdown utility correction (`ring-black/5`). The final build includes that
correction and was visually rechecked. See `build-artifacts.json`,
`build-artifacts-recheck.json` and retained build logs.

## 15. SQLite

Final maintained suite: **574 passed, 49 skipped, 4,341 assertions**
(`sqlite-final-qualified.txt`). Sixteen skips require real MariaDB; 32 require
specialized named concurrency schemas and are run separately; one nullable-UID
rollback fixture is explicitly retired. An initial fixture environment inherited
the browser-only setup token and returned a registration redirect; removing that
token from backend-test configuration restored the required 404 and the full pass.
No registration behavior or assertion was weakened.

## 16. MariaDB

Final maintained suite: **590 passed, 33 skipped, 4,649 assertions**
(`mariadb-qualified.txt`), on private MariaDB 10.4.32 at 127.0.0.1:3354. The 32
schema-gated tests run separately; the retired fixture is the remaining skip.
The customer's database/configuration was not used or changed.

## 17. Concurrency

Seven dedicated disposable schemas passed **32 tests, 238 assertions**:
R2A 1/10; R2B.5 2/14; R2B.5Q 15/93; R3A.2 3/22; R3A.3 8/71;
R4.2 membership 1/7; R43 notifications 2/21 (tests/assertions).
The main MariaDB suite additionally executes its 16 MariaDB-only concurrency cases.
Logs are `output/r44a/task*-tests.txt`.

## 18. Browser and cross-browser

Final fresh-company run: **39 passed, 3 skipped, zero failures (12.7 minutes)**,
including Chromium and WebKit smoke flows. Firefox could not launch on this
Windows host (incorrect side-by-side configuration / `spawn UNKNOWN`) and is
explicitly skipped, as allowed when the current host does not permit it. The
other two skips are the large-data cases passed separately against the 10k
fixture. See `browser-recheck.txt`, `output/r44a/recheck` for metrics and engine
journals, and `output/playwright/r44a-recheck-suite` for artifacts.

The ordinary run uses a new empty disposable company, normal UI installation,
and UI-created business data. Large synthetic data remains in a separate guarded
fixture. No physical Safari/Android/iOS, installed-device PWA, real push or remote
CI is claimed. The host limitation is recorded distinctly from application results.

## 19. Final npm audit and quality gates

`npm ci`, **full npm audit: zero vulnerabilities**, `npm audit --omit=dev: zero`,
`npm ls --all`, and `npm run build` passed. The final install added 108 installed
packages; the lock also records platform-optional packages. Composer strict
validation and audit passed with zero PHP advisories. All 354 maintained
standalone PHP files passed syntax and scoped Pint checks; 59 freshly compiled
Blade templates passed PHP syntax. Maintained/new JavaScript syntax and Git diff
checks passed. No JS linter was invented where none is configured.

The production-only npm audit is additional evidence, not the release criterion:
browser bundle dependencies are declared as development dependencies too.
The full original moderate-or-higher audit gate remains unchanged.
The final October 4 recheck again returned zero vulnerabilities in both npm
audits and Composer audit; GitHub still lists no patched braces version.
Fresh results are `npm-audit-recheck.json`,
`npm-audit-production-recheck.json`, `composer-audit-recheck.txt` and
`composer-validate-recheck.txt`. The dropdown follow-up also passed a fresh
59-view Blade compilation/syntax check (`blade-recheck.json`).

## 20. Final package tree

Direct locked versions: Tailwind and official Vite plugin 4.3.3; forms 0.5.10;
Vite 6.4.3; Laravel Vite plugin 1.3.0; Playwright 1.63.0; axe 4.13.0;
Alpine 3.14.9; Axios 1.20.0; Chart.js 4.4.9; SweetAlert 11.22.5;
concurrently 9.2.0. `npm-ls-final.txt` and `dependency-tree-final.json` retain the
complete result. There are **no braces, micromatch, fast-glob or chokidar package
nodes** in the final lock/tree. The build trace contains none of them either.

## 21. Lockfile diff

The lock was generated by npm operations and verified by a fresh `npm ci`.
Compared with the starting lock: **75 package paths removed, 2 added, 34 changed
versions**. Most changed entries are the compiler/oxide/Lightning CSS platform
variants and their resolver/source-map dependencies. Unrelated direct browser
libraries retain their locked versions. The legacy vulnerable chain, duplicate
Tailwind copy and obsolete PostCSS integration dependencies are removed.
`lockfile-diff.json` records exact paths and before/after versions. No checksum or
advisory data was manually rewritten.

## 22. Commits

- `d02710d` — `build: migrate frontend toolchain off vulnerable glob chain`
- `3bc605c` — `test: qualify dependency remediation against R4 gates`
- Final documentation commit — `docs: reconcile R4.4 dependency release gate`

Only qualified local changes are committed. The final report commit's own SHA is
recorded afterward in `output/r44a/final-head.txt` and `final-commits.txt`, avoiding
a self-referential hash in its contents. No earlier phase history is amended.

## 23. Residual risk

The specific installed dependency blocker is removed; no exception is required.
Zero advisories is a dated registry result, not proof that software has no defects.
Tailwind's newer browser floor is explicit. Physical-device/minimum-version and
actual hosting qualification remain R4.5 responsibilities. The separate current
Firefox host-launch limitation is disclosed, not silently treated as a pass.

## 24. Recommended release policy

Retain the **strict full-toolchain audit policy**. The selected migration satisfies
it technically once all reported local gates pass, so there is no reason to request
temporary risk acceptance. If this remediation were rejected or a future advisory
cannot be safely removed, strict policy blocks release. An alternative temporary
build-risk acceptance would require the owner's explicit documented decision,
proven reachability boundaries, expiry/recheck criteria and continued monitoring.
No such acceptance was selected or granted here. R4.4 reclassification is based
on technical remediation, not a waiver; production release remains a later decision.

## 25. Monitoring and recheck plan

Retain existing full npm/Composer audits on every pull request and configured
branch push. Re-query before each release candidate even without dependency
changes. A dependency change must pass clean installation, full audit, build and
relevant browser/visual gates. New high/moderate findings fail CI as before.
The removed braces chain needs no temporary allowlist, expiry or special exception
monitor. No remote schedule or Codex automation was activated; remote CI is R4.5.
The repeatable build/visual workflow is documented in `docs/FRONTEND_BUILD.md`.

## 26. Final Git state

Branch remains `main`. Implementation and regression changes are committed locally;
the report and historical R4.4 reconciliation form the final documentation commit.
The final tracked/staged state is verified clean after that commit, with exact
HEAD, commit history and status retained in `output/r44a/final-*.txt`.

Historical untracked evidence is preserved. New R4.4A logs/metrics/screenshots use
separate output paths. All six owned qualification services were stopped; private
database files, generated configuration, compiled views and the disposable key
were removed (`output/r44a/cleanup.txt`). The customer's
`.env` and ordinary database configuration remain unchanged.

## 27. Deployment state

Nothing was pushed, deployed or tagged, and no remote infrastructure was modified.
R4.5 has not begun. The local result does not qualify hosted HTTPS, Hostinger,
real scheduler/queue/mail/push, physical mobile/Safari, installed PWA, backup/restore,
restart/recovery, operational soak, remote CI or the final production release.
