# Maintained application static analysis

The level-5 gate analyzes `app/` through the real Laravel 12 `bootstrap/app.php`.
PHPStan 2.2.16, Larastan 3.12.2 and their dependencies are locked separately in
`tools/static-analysis/composer.lock`. Install the application's locked dependencies
first, then install this development-only tool project:

```sh
composer install --working-dir=tools/static-analysis --no-interaction --prefer-dist
composer analyse
```

Use an isolated environment/cache/database when running beside a local application.
CI supplies SQLite `:memory:` and array cache/session drivers; analysis does not
require company data. The tool bootstrap loads the application autoloader and
resolves the actual console kernel. Tool Laravel is pinned to the application lock;
review that pin when upgrading the framework. Production `composer install --no-dev`
does not install this separate tool tree. Its vendor/cache directories are ignored.

The **512 MiB analyzer allowance is tooling only**. Application qualification and
shared-host assumptions remain **128 MiB**. One analysis worker avoids unnecessary
local contention. The initial phase run found 250 diagnostics and no internal
errors. Truthful types now cover 54 relationships across 14 models, canonical
Task attributes/status accessors, User's array cast, typed read builders, the
session guard, model lookups and validated transition requests.

The reviewed baseline contains **23 diagnostics in 20 exact message/path/count
entries**, not zero analysis debt. New diagnostics, increased occurrence counts,
internal analyzer errors and obsolete baseline entries fail the gate. Do not
regenerate the baseline to silence new failures. Analyze without the baseline with:

```sh
php -d memory_limit=512M tools/static-analysis/vendor/bin/phpstan analyse \
  -c tools/static-analysis/analysis.neon --memory-limit=512M
```

After fixing an entry, reduce its count/remove it and rerun `composer analyse`.
The [PHPStan baseline guide](https://phpstan.org/user-guide/baseline) explains this
incremental workflow; `reportUnmatchedIgnoredErrors` stays enabled.

Every retained diagnostic was inspected against its runtime callers and framework
implementation. The review below records each location; identical messages are
grouped only where their counts remain explicit in `phpstan-baseline.neon`.

| File / diagnostic | Count | Review and retained reason |
|---|---:|---|
| Auth/VerifyEmailController: Verified argument | 1 | Authenticated verification route guarantees a User. User inherits Laravel's verification methods; the optional metadata feature deliberately does not implement the enforcement interface. Verified's PHPDoc expects MustVerifyEmail, but its constructor parameter is untyped. Existing signed verification tests exercise this route; no runtime call defect. |
| ManagerDashboardController: assignee nullsafe names | 2 | Nullable FK and soft-deleted/absent assignees are valid. `?->name ??` safely handles them. PHPStan recommends equivalent `->name ??`; retained defensive spelling. |
| TasksController: priority enum check | 1 | Canonical persisted priority is string. Enum compatibility branch is harmless serializer normalization; no invalid method is invoked without instanceof. |
| TeamManagementController: redundant self check | 1 | `$allowed` already includes self before `abort_unless`. The extra OR does not grant additional access. Scope tests cover self and managed membership. |
| Task: deadline Carbon argument | 1 | Larastan infers parent Carbon\Carbon from date casts. Default Laravel DateFactory creates Illuminate\Support\Carbon, matching TaskDeadlineGeneration's native constructor. R52 model/SQL deadline parity and full lifecycle tests execute construction; no cast or date semantics changed. |
| TaskReviewWorkflowNotification: project name nullsafe | 1 | Deleted/missing parent is safely described through nullsafe/coalescing. Equivalent syntax warning; delivery still reauthorizes fresh resources. |
| ProjectManagerReplacementService: reviewers property | 1 | Unread constructor dependency remains harmless maintenance debt. Eligibility now lives in selective SQL. This phase removes only the separately proven kernels/helpers, without changing replacement construction or locking. |
| ProjectManagerReplacementService: old PM name nullsafe | 1 | Missing old PM is supported. Coalescing supplies None. Equivalent spelling warning, not dereferencing an absent model. |
| TaskLifecycleService: assignee coalescing | 2 | Validator requires the field before mutation. Defensive fallback is redundant for the canonical path and safe for legacy/internal input. |
| TaskLifecycleService: DateTimeInterface checks | 2 | Date casts satisfy the interface. The guarded serializer still supports string-like retained input without affecting canonical values. |
| TaskLifecycleService: priority enum check | 1 | Same explicit guarded compatibility normalization as the controller. Canonical strings flow through unchanged. |
| TaskLifecycleService: context nullsafe | 1 | The method fills absent context before the transaction. Nullsafe access is redundant and harmless. |
| TaskNotificationDispatcher: filtered collection isEmpty | 1 | Higher-order collection inference loses cardinality after predicate authorization/filter/unique/values. Real revoked/no-recipient tests demonstrate the empty path. The guard is required and retained. |
| TaskTimelineService: actor name nullsafe | 1 | Actor is nullable and uses withTrashed. Safe fallback remains for absent retained accounts; equivalent spelling warning. |
| TaskTimelineService: occurrence date nullsafe | 1 | Current schema requires occurrence time; retained/in-memory histories can omit it. Safe serializer fallback retained. |
| TaskViewData: latest submission date nullsafe | 1 | Current submission schema requires its timestamp; safe serialization retains an absent-value fallback for retained/in-memory data. Payload remains unchanged. |
| WebPushDestinationValidator: resolver is_array | 1 | Injected resolver's documented array return narrows analysis. Runtime validation is useful at this optional integration boundary; malformed returns cannot flow into array operations. |
| TaskDeadlineRules: reasonRequired match | 1 | Callers supply the three literal supported kinds from view generation, or ChangeTaskDeadline only after supports/allows validation. Invalid external kinds are rejected first. No reachable unhandled supported value. |
| ChangeTaskDeadline: apply match | 1 | Executor always validates the command before apply. validate rejects unknown kind before mutation. Existing malformed-kind/invariant tests exercise this rejection. |
| ReopenApprovedTask: retained assignee id nullsafe | 1 | Nullable retained assignee is checked by eligibility before apply. Equivalent coalescing syntax safely represents absent context. |

No class-not-found, unknown method, missing relation, undefined property or reachable
call defect remains in the baseline. R5.4's deliberate untracked return-type defect
must fail with `return.type`; its removal must restore the passing gate. Results
are retained in the phase evidence and final qualification report.
