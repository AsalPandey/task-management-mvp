# R6.1 boundary contracts

One company remains one Laravel application/database. No new runtime service or migration is required.

## Account authority and lock order

Global account create/update (including role/password), activate/deactivate and delete use
`AccountAdministrationWriter`. The Manager sentinel is locked first, followed by all current
Manager accounts, acting account and target in ascending ID order, then dependent projects/tasks.
Current active Manager role and the request account's security stamp must still match inside
the transaction. The actor lock is retained through commit. Self-service deletion uses the same
order and current active/stamp check, without requiring Manager role; it only deletes self.
Other project/task writers acquire sorted relevant accounts, then projects, then tasks; they
do not acquire the Manager sentinel afterward. Last-Manager checks reuse the held locks.
Self-service profile/preferences/password actions do not grant global authority or change
assignment eligibility; their session-stamp behavior remains covered by existing tests.
The one-time initial-company installer has no privileged acting account and retains its
separate empty-installation/bootstrap contract.

## Unfinished responsibility

All states except completed/cancelled retain assignee responsibility, including submitted and
in-review work that may return for revision. Role changes use the canonical execution rule:
active Manager/Team Member, or active PM owning the project. A selective locking EXISTS query
blocks changes that would invalidate an unfinished assignment. PM replacement blocks an outgoing
PM's unfinished assignment if proposed ownership removes execution eligibility. No automatic
assignment transfer or historical ownership rewrite occurs. Account/project locks serialize
assignment and transition writers; stale ownership routing returns a controlled refresh conflict.
Resolve by completing/cancelling work or supported reassignment before submission, then retry.
Account UI uses a named public dependency code and a fixed safe message; arbitrary error payloads
remain excluded from account-form errors.

## Required notice semantics

Existing intent transition, creation time and immutable task version identify the historical
event generation; no new schema/payload column is necessary. Same-version notices keep their
current action. Once any later task version exists, deliver a historical non-actionable notice,
retain its original time/version/type, show current state explicitly and link to current task.
Strip newer-generation review/feedback/private details from that historical payload. Benign
edits conservatively make the notice historical rather than silently dropping it. The same
formatter applies at authorized read time if a previously delivered notice becomes stale.

| Later change | Delayed intent behavior |
|---|---|
| Submit → cancel/approve/newer resubmit | Historical submission; no current review instruction |
| Revision request → resubmit/cancel | Historical revision request; no obsolete revision instruction |
| Reviewer replacement | Former responsibility fails current-recipient validation and is discarded; other eligible observers get historical notice |
| Deadline generation replaced | Historical deadline-change event, no old deadline/action details |
| Completion → reopen | Historical completion formatter, no current completion action; completion remains preference-controlled and is not converted into a required durable intent |

Current access/recipient responsibility checks precede serialization. Revocation discard/redaction,
required preferences, retry/backoff, transactional delivery and exactly-once in-app identity remain.
Optional completion/team-update notices retain their existing category/delivery contract.

## Historical reports

The authorized task creation cohort determines contributors. Inactive, role-changed, renamed and
retained soft-deleted users keep their live retained names and contribution, with Active/Inactive/
Removed labels. Current active selector/zero-work roster rows may remain but never filter out
historical contributors. HTML, print and CSV share these results. PM scope comes from the task
dataset, not unrestricted employee history.

## Current review generation

Resubmission clears current review start, recording old timestamp → null in history/events.
The next review records its actual before value and new time. Historical review events remain.

## Roster budgets

Projects initially loads five members per visible project with total member count. Member browsing
returns twenty members/page; candidates return twenty-five plus at most one authorized selected
identity. Assignment/reviewer/PM/filter selectors search on demand, with lean DTOs, escaped name
search and current role/project authorization. PM default staff lists are scoped; explicit staff
search is an authorized project-membership-management action, not an unrestricted roster dump.
Membership mutation responses also return bounded previews. New endpoints expose no passwords,
security stamps or emails. Current account middleware remains mandatory.

`scripts/r61-prepare-roster.php` prepares an empty guarded disposable company fixture.
`scripts/r61-check-roster.php` starts fresh 128M profiling processes and enforces 64 MiB peak,
512 KiB response, 800 User retrievals and forty queries per route. These are headroom gates for
the demonstrated twelve-project fixture, not a throughput SLA or universal project-count limit.
Run 250/500/1000 independently. Existing 100/1k/10k task and 25k administration gates remain.
Private output evidence never belongs in a deployment package.
