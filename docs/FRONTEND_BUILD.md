# Frontend build and browser contract

R5.4 extracts task lifecycle/reviewer/deadline actions, completed-task reopening,
and shared timeline loading into `resources/js/tasks/`. Both task pages load the
`task-features.js` Vite entry. The modules use the existing AppClient-wrapped fetch,
rendered versions/URLs/selectors and Phase3UI timeline/focus handling. The shared
request helper only serializes this feature; it does not replace freshness,
offline, dirty-form or account safety. Chart.js remains analytics-only.

`npm run check:js` uses Node's maintained parser on browser/module source without
adding lint dependencies or style churn. It catches syntax failures; the browser
suite detects undefined DOM/API/closure behavior. No claim of semantic linting is
made. The R54 browser gate includes completed-history 409 recovery in Chromium and
WebKit, real reviewer reassignment, initial hold/resume, reopening/cancellation,
and profile save/modal error/keyboard recovery. The profile compatibility page
uses the existing modal focus handling without CSP-unsafe Alpine expressions.

The application uses Laravel/Blade with Vite 6 and Tailwind CSS 4.3.3 through the
official `@tailwindcss/vite` plugin. The committed npm lockfile is authoritative.
Install with `npm ci`, run the full `npm audit` and production-only audit, check
`npm ls --all`, then run `npm run build`. The full moderate-or-higher CI audit
remains a required gate, including development/build dependencies.

## Source and output boundaries

`resources/css/app.css` declares the source directories explicitly. Only the
application Blade templates and Laravel pagination templates provide utility
candidates. Generated `storage/framework/views`, test output, uploaded files and
the database are not scanner inputs. JavaScript theme/font/forms configuration
remains in `tailwind.config.js`, loaded through `@config`. Automatic whole-project
scanning is disabled so local cache contents cannot change the production CSS.

`resources/js/app.js` retains Alpine, SweetAlert and the existing bootstrap code.
R5.3 moves Chart.js into `resources/js/charts.js`, included only by company and
member analytics through the Vite manifest. Build both entries together; chart,
print, non-chart, root and mounted paths are qualified by the browser gates.
The compiler runs in Node during the build; production serves
the resulting hashed CSS/JS and manifest. Hostinger packaging excludes
`node_modules`, consumes the exact CI asset build, and runs PHP cache/migration
commands on the host. Qualification of an actual deployed host remains separate.

The R4.4A migration removes Tailwind v3, braces, micromatch, fast-glob, chokidar v3
and the obsolete PostCSS/autoprefixer integration. It uses no override, fork,
advisory suppression or manual lockfile editing. The standard v4 compiler replaces
the affected dependency path even though braces itself has no patched release at
the qualification date.

## Visual compatibility and browser support

The existing UI is retained. Templates use v4 names for shadows, rounded corners,
focus outlines and rings. Small compatibility rules preserve existing border,
placeholder, pointer, label and member-select defaults. The explicit shell cache
version is v4; releases that alter shell assets must continue to bump that version
so controlled clients can receive the existing draft-preserving update notice.

Tailwind v4 requires **Safari 16.4+, Chrome 111+, and Firefox 128+**. These are
upstream minimums, not a claim that each minimum browser/device was locally
tested. Locally qualified engines and host limitations are recorded in the phase
report. Older browsers are outside this build contract. See the
[official migration guide](https://tailwindcss.com/docs/upgrade-guide).

## Repeating a toolchain comparison

Use disposable local MariaDB databases and the existing clean-company installer
workflow. Create accounts, a project, memberships and representative tasks through
the UI; keep that company unchanged throughout the comparison. A second empty,
prepared database/server must remain on `/setup` for installation-screen images.
Never point qualification at a customer database.

Set `APP_URL` to the company server, `R44A_SETUP_URL` to the empty setup server,
and `R44A_VISUAL=before`. With the old compiler/build, run:

```text
npx playwright test --config playwright.toolchain.config.js --update-snapshots
```

Build the candidate, set `R44A_VISUAL=after`, and run without updating snapshots:

```text
npx playwright test --config playwright.toolchain.config.js
```

The opt-in visual suite compares 12 screens at six viewport sizes, retains full
screenshots and computed element styles/geometry, and rejects horizontal overflow.
Its 0.1% screenshot tolerance accommodates minute rasterization/color differences;
inspect the computed-style evidence and meaningful image differences as well.
References and evidence live under `output/playwright/r44a` and `output/r44a` and
are not source-controlled application assets. Recapture references only from a
known accepted build, never from a failing candidate to make the check pass.
Keep the fixture's calendar day stable across captures: analytics chart windows
depend on the current date. A disposable server clock may be offset to the baseline
day while advancing normally so rate-limit windows expire. Use session cookies
without an expiry date in that fixture so a past clock does not immediately expire
authentication. Ordinary workflow tests use real time.

Run the ordinary browser suite on a new company afterward. `R44_EVIDENCE_DIR`
selects the directory for R44 metrics/engine journals (default `output/r44`), and
`BROWSER_OUTPUT_DIR` selects browser artifacts. R4.4A uses separate directories to
preserve prior qualification evidence. The maintained suite includes rendered
reduced-motion and keyboard/forced-color focus checks. The 10k resource and
large-data browser gates use their separately guarded fixtures.

## Continuing security checks

Every pull request and configured branch push runs the existing full npm audit
and Composer audit. A future moderate/high/critical finding must fail that gate;
`--omit=dev` remains an additional check and never replaces it. Re-query advisories
before any release candidate even when the lockfile has not changed. No exception
or risk acceptance was needed for R4.4A, and no scheduled remote job was activated
locally.
