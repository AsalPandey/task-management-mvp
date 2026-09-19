# Continuous integration

GitHub Actions runs two release gates for pull requests and pushes to `master`,
the current development branch, and release branches.

- **SQLite, quality, and build** installs dependencies from `composer.lock` and
  `package-lock.json`, validates and audits them, checks PHP syntax and Pint,
  runs the maintained SQLite suite, builds production assets, and compiles Blade
  templates.
- **MariaDB migrations and concurrency** uses an ephemeral MariaDB 10.4.32 service.
  It creates only explicitly approved `task_management_phase28_*_ci` databases,
  runs a fresh migration and the maintained MariaDB suite, then reruns every
  database-name-gated process-concurrency suite against its required disposable
  database name. A skipped gated suite is therefore not treated as coverage.

The workflow has read-only repository permissions. It does not deploy, publish,
push formatting changes, use a developer database, or retain databases and
application state after the runner is destroyed. A green result means the exact
revision installed from both lockfiles, passed the maintained SQLite and MariaDB
checks, passed the required genuine concurrency suites, and produced a clean
frontend build. It is not evidence that deployment or production operations have
been exercised.
