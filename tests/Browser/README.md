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
