# R4.3 performance contracts

These budgets apply to a single-company installation. They emphasize bounded queries, returned rows and memory; milliseconds are diagnostics, not hardware-independent SLAs.

## Read-path map

| Path | Before measurement / shape | Repair or retained boundary |
|---|---|---|
| Aggregate analytics | Whole cohort plus all 30-day creation/event rows hydrated; 20k Task objects for a 10k fixture | Seven scoped SQL aggregates; eight state groups, priority groups, 30 scalar date buckets and per-owner DTOs; no Task/Event hydration |
| Manager / PM dashboard | Active cohort fetched twice; all today's completions and overdue rows | SQL state/priority totals; three active plus two completed recent rows; ten overdue rows; total kept separately; canonical viewer scope |
| Member dashboard | Active cohort fetched twice; all overdue/upcoming/recent completed rows; seven daily count queries | SQL totals and one grouped seven-day productivity query; five notification sources, ten each overdue/upcoming/completed previews |
| Projects | Twelve projects with every child Task; one lazy role query per member | Two conditional withCount subqueries; members.role eager loaded; twelve cards; no child Task relation loaded |
| Team / search | Twelve roster cards; browser filters only those cards | GET submit to existing authorized roster scope; literal name/email search; 12-row pagination, preserved search and fresh page on submit |
| Member analytics | Full cohort and six unused monthly reports | One aggregate report plus ten recent models; canonical assignee/project scope |
| CSV / print | Precomputed full Task report | Aggregate DTO report; CSV stream retained, UTF-8/formula escaping unchanged; print is member-summary rows |
| Tasks | Already 24-row paginator | Retained; bounded relationships and full-data server search; no task-cohort hydration |
| Completed history | Already 15-row paginator | Retained, newest-first and canonical completion reads |
| Timeline | SQL limit clamped to 100, eager actor, in-memory sort of limited events | Retained; management-note policy and event sequence unchanged |
| Inbox / navigation | Inbox 20 rows, but shared navigation materialized all history and all unread rows; 20k notification models at 10k | SQL unread count, eight latest previews; inbox remains 20 rows; permission masking evaluated once per rendered row without cross-request caching |
| Mark all read | One SELECT plus one UPDATE for every unread row | One owner/morph-scoped UPDATE with read_at IS NULL |
| Reminder / overdue | 200-model chunks; retained noncurrent deadlines included candidates; repeat runs rechecked every consumed generation | 200 scalar candidates using current-stage deadline; identical fingerprint helper skips consumed snapshots; unchanged per-task atomic fresh delivery for eligible candidates |
| Browser push | Enabled subscriptions of one user, optional single subscription, then per-device claim | Retained; task count does not increase this per-user device set. Device fan-out remains an explicit future bound, not a whole task-table read |
| Backup | Package database dumper / external process | Retained; no PHP task materialization. Large backup/restore and hosting/storage capacity are R4.5 operational qualification |

There is no separate full Project-detail task page; existing membership/history endpoints and task filters retain their policies. Assignment dialogs still load the authorized active project/roster choices. Therefore output can grow with project/member counts, while task previews stay bounded. The 20-project/40-member fixture and 100-project pagination test are explicit qualification dimensions, not unlimited-company promises.

## Permanent qualification budgets

Every child process runs with memory_limit=128M. The measured process peak includes bootstrap and must stay at or below 96 MiB. No memory setting is changed in customer configuration.

| Surface | SQL ceiling | Task hydration ceiling | Output ceiling / returned rows |
|---|---:|---:|---|
| Aggregate service, each role | 10 | 0 | 30 dates, 8 states, per-owner DTO rows |
| Dashboard, each role | 80 | 40 including bounded notification chrome | 200,000 bytes; <=10 in each task preview |
| Projects | 64 | 16 allowed only for eight authorized notification previews | 400,000 bytes; <=12 projects; zero loaded child Task relations |
| Tasks | 80 | 45 including chrome | 400,000 bytes; <=24 task cards |
| Team | 64 | 16 including chrome | 160,000 bytes; <=12 accounts |
| Inbox | 150 | 60 including masked task references | 180,000 bytes; <=20 notices plus eight header previews |
| Global analytics page | 64 | 16 only for chrome | 180,000 bytes |
| Member analytics | 64 | 26 including ten recent rows and chrome | 120,000 bytes; <=10 recent models |
| CSV / print | 30 | 0 | 50,000 / 40,000 bytes for the 40-member fixture |
| Mark all read | 1 | 0 | Exactly one owner-scoped UPDATE regardless of unread count |
| Initial deadline commands | 60,000 at 10k | Per delivered candidate, bounded live memory | Chunk size 200; one task transaction at a time |
| Consumed-generation repeat | 50 at 10k | 0 | Zero new delivery; only scalar chunk reads |

HTML/export byte ceilings are for the declared SME fixture, not a guarantee for arbitrarily large staff rosters or input text. Individual task lists and DB history pages remain bounded. Notification authorization intentionally still queries fresh task/owner state; the ceiling allows those bounded checks rather than caching revoked permissions.

## How to qualify

1. Use an isolated MariaDB database matching `task_management_r43_[a-z0-9_]+`, with production/debug-off config, deployment timezone and database queue/cache/session drivers. Never point at a customer database.
2. Migrate/seed a fresh schema. The generator refuses existing users/tasks: `php scripts/r43-prepare-performance.php 10000 20 10000`.
3. Build config/routes/views with `php artisan optimize` using isolated cache paths.
4. Run `php -d memory_limit=128M scripts/r43-check-performance.php`. It checks independently calculated status, deadline, rate, priority, creation/event trends, date cohorts, per-member results and viewer scope; then executes HTTP and scheduler paths in 22 fresh child processes.
5. `php scripts/r43-explain.php` records read-only EXPLAIN plans. Existing project-leading, assignee, date/status and event indexes were sufficient for this qualification. No speculative index was added.

The existing MariaDB CI job runs this separate 10k resource gate and two R43 concurrency cases. Ordinary SQLite/MariaDB suites use small stable budget fixtures. R5.3 adds separate dense 10k/25k administration gates with a 64 MiB peak budget under the unchanged 128 MiB runtime limit; see [R5.3 contracts](R53_CAPACITY_AND_NAVIGATION.md). No 50k gate is required. Browser CI separately checks off-page UI-created accounts, then the large synthetic fixture. Timings and fresh-process profiles are retained as diagnostics; no exact millisecond assertion is used.
