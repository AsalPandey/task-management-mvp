# Phase 2.9A clean release packaging

Do not build a release from the current dirty development checkout. After the
Phase 2.9A remediation is reviewed and committed, use a fresh worktree at the
approved commit:

```bash
git worktree add --detach ../task-management-phase29a-release <approved-commit>
cd ../task-management-phase29a-release
git status --short
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm audit
npm audit --omit=dev
npm run build
php artisan test
vendor/bin/pint --test
```

`git status --short` must be empty before dependency installation. Build output
is intentionally ignored and is produced by the deployment process, not
committed.

Before deployment, confirm the clean worktree contains none of:

- `.env` or credential files
- database, SQLite, QA, or browser-smoke files
- the 19 unrelated development modifications
- `node_modules`, `vendor`, logs, cache files, or local IDE metadata
- `public/build` as a tracked artifact
- `resources/prototypes` in any `git archive` package

Apply only the reviewed Phase 2.9A remediation commit to the approved Phase 2
checkpoint. Never copy the current working directory to production. Remove the
temporary worktree only after its commit, audits, tests, build, and manifest
have been recorded in release evidence.
