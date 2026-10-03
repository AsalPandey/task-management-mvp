# Continuous integration

GitHub Actions runs three release gates for pull requests and pushes to `main`,
`master`, the current development branch, and release branches.

- **SQLite, quality, and build** installs dependencies from `composer.lock` and
  `package-lock.json`, validates and audits them, checks PHP syntax and Pint,
  runs the maintained SQLite suite, builds production assets, and compiles Blade
  templates.
- **MariaDB migrations and concurrency** uses an ephemeral MariaDB 10.4.32 service.
  It creates only explicitly approved `task_management_phase28_*_ci` databases,
  runs a fresh migration and the maintained MariaDB suite, then reruns every
  database-name-gated process-concurrency suite against its required disposable
  database name. A skipped gated suite is therefore not treated as coverage.
- **Clean-company browser critical path** consumes the exact production asset
  build, creates a disposable production-like MariaDB installation and exercises
  UI onboarding, R4.1/R4.2/R4.3 regressions and the R4.4 client/PWA/accessibility
  scenarios. It installs Chromium, Firefox and WebKit; runs the subdirectory
  qualification router; records axe/reflow/freshness evidence; and then runs
  the separate large-data browser fixture. No native device installation is
  implied. The recorded Windows Firefox `spawn UNKNOWN` host limitation is
  skipped with diagnostics; other launch failures fail the test. Linux CI cannot
  take that Windows-only branch. See [R4.4 client contract](R44_CLIENT_PWA_ACCESSIBILITY_CONTRACT.md).

The qualification jobs have read-only repository permissions. They do not deploy,
push formatting changes, use a developer database, or retain databases and
application state after the runner is destroyed. A green result means the exact
revision installed from both lockfiles, passed the maintained SQLite and MariaDB
checks, passed the required genuine concurrency and browser suites, and produced
a clean frontend build. It is not evidence that deployment or production operations have
been exercised.

An optional dependent Hostinger deployment job runs only after all three gates pass
on a `main` push, and only when `HOSTINGER_DEPLOY_ENABLED=true`. It uses dedicated
Actions secrets and the exact source/asset revision. See [Hostinger deployment](HOSTINGER.md)
for provisioning, private-state preservation, cron, and recovery requirements.
When the gate is disabled or deployment is skipped, CI success is not deployment
success.
