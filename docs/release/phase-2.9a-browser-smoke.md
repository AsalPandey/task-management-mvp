# Phase 2.9A disposable browser smoke

This procedure creates a dedicated SQLite database and never uses the configured
`task_management` database. `BrowserSmokeSeeder` refuses production and refuses
any database whose filename or database name does not begin with
`task_management_phase29_smoke_`.

## Prepare the disposable database

Open a new PowerShell session in the application root. Do not reuse a terminal
that is serving the configured application database.

```powershell
$smokeName = 'task_management_phase29_smoke_' + (Get-Date -Format 'yyyyMMddHHmmss')
$smokePath = Join-Path $env:TEMP ($smokeName + '.sqlite')
New-Item -ItemType File -Path $smokePath -ErrorAction Stop | Out-Null

$env:APP_ENV = 'local'
$env:APP_DEBUG = 'false'
$env:APP_URL = 'http://127.0.0.1:8092'
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = $smokePath
$smokePassword = Read-Host 'Temporary password (16+ chars, mixed case, number, symbol)' -AsSecureString
$env:BROWSER_SMOKE_PASSWORD = [System.Net.NetworkCredential]::new('', $smokePassword).Password

php artisan config:clear
php artisan migrate:fresh --seed --force
php artisan db:seed --class='Database\Seeders\BrowserSmokeSeeder' --force
php artisan serve --host=127.0.0.1 --port=8092
```

The seeder prints three deterministic `.example.test` login IDs. All three use
the temporary password entered in this PowerShell session. The password is not
stored in the repository and must not be reused anywhere.

## Three-role checklist

Use a private browser profile and test desktop and mobile widths.

1. Manager: log in, verify Dashboard, Tasks, Completed, Analytics, Timeline,
   notifications, CSV download, and the HTML print-export route. Verify the
   browser print-to-PDF preview and filename manually.
2. Manager: create a disposable task assigned to the seeded Team Member with
   the seeded Project Manager as reviewer.
3. Team Member: Start, Hold (enter a reason), Resume, Submit, and log out.
4. Project Manager: Start Review, Request Revision with formal feedback and a
   revision deadline, and log out.
5. Team Member: Begin Revision, Resubmit, and log out.
6. Project Manager: Start Review and Approve. Verify Completed and Timeline,
   then log out.
7. Manager: Reopen the approved task with a revision deadline, then cancel it.
   Verify prior approval remains in Timeline, the task leaves active/completed
   counts, notifications render as text, filters work for all machine states,
   and CSV/HTML print exports remain project-scoped.
8. Repeat export checks as Project Manager. Confirm unrelated projects cannot
   be selected or exposed by crafted query parameters.
9. Team Member: confirm Analytics CSV and print exports return 403 and no
   management-only cancellation/override data appears.
10. At mobile width, open/close navigation, use each visible workflow control,
    verify modals do not sit behind navigation, and log out.

Do not record this checklist as visually passed unless every step was actually
performed in the browser.

## Remove the disposable resources

Stop the server with `Ctrl+C`, then run in the same PowerShell session:

```powershell
$resolvedSmokePath = (Resolve-Path -LiteralPath $smokePath).Path
$expectedLeaf = [System.IO.Path]::GetFileNameWithoutExtension($resolvedSmokePath)
if ($expectedLeaf -notlike 'task_management_phase29_smoke_*') {
    throw 'Refusing to remove an unverified path.'
}
Remove-Item -LiteralPath $resolvedSmokePath -Force

Remove-Item Env:APP_ENV -ErrorAction SilentlyContinue
Remove-Item Env:APP_DEBUG -ErrorAction SilentlyContinue
Remove-Item Env:APP_URL -ErrorAction SilentlyContinue
Remove-Item Env:DB_CONNECTION -ErrorAction SilentlyContinue
Remove-Item Env:DB_DATABASE -ErrorAction SilentlyContinue
Remove-Item Env:BROWSER_SMOKE_PASSWORD -ErrorAction SilentlyContinue
$smokePassword = $null
```

For MariaDB, use an equally unique database name with the required prefix,
apply the same migrations/seeders, and drop only that exact positively
identified database after testing.
