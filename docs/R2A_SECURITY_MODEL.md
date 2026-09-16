# R2A account and notification security

Accounts are provisioned by managers in an internal application. Public registration
is disabled; first-manager setup requires the existing installation controls.
An email address is an account identifier and password-recovery destination, not
proof of an access entitlement. Managers must check identities and deliver initial
credentials securely. Addresses need not be deliverable for ordinary access.
The optional signed email-verification routes record ownership metadata only;
verification never grants a role and is not required to use a provisioned account.
Email delivery must be configured if verification or password recovery is used.

The configured session driver is `database`. The revocation mechanism also works
with file/Redis sessions: a session stores an HMAC of the account security stamp,
credential hash, email, role, active state and current permission IDs. Login binds
the fingerprint. Every authenticated session request checks fresh account state.
Password, email, role and active-state Eloquent updates rotate a random stamp and
the remember token in the same SQL UPDATE; existing R1 transaction locks remain
in place. Direct bulk account SQL must not be used for security changes because
it bypasses model events. A password hash change is additionally detected directly.
Administrative changes and password reset invalidate all existing sessions.
Successful self-service password/profile changes regenerate the current session
and CSRF token, clear password-confirmation status and bind only that session to
the new fingerprint. Other sessions must authenticate again. Pre-R2A sessions
without a fingerprint must also authenticate again after rollout.

The security-stamp migration is additive and nullable; no user backfill is needed.
Apply it before serving R2A code. Roll back code before dropping the column.
No account lifecycle lock order, task state transition or assignment policy changes.

Task/project notifications use current resource policies at dispatch, stored-data
serialization and queued push delivery. Historical rows, timestamps and read state
remain intact. Content and deep links become a generic unavailable message when
access is lost. Management-only excerpts are removed when that permission is lost.
Notifications already delivered to a device cannot be recalled. Task deep links
still require server-side authorization. Mark-read requests remain owner-scoped.

User JSON is allow-listed: rosters expose id/name/active and role id/name;
manager account mutation responses additionally expose email/role_id; self-profile
responses additionally expose the owner's timezone/preferences. Task payloads
already use TaskViewData's id/name projections.

Application responses add nosniff, SAMEORIGIN framing, strict-origin-when-cross-origin
referrer policy and disabled camera/microphone/geolocation permissions. Authenticated
responses are private/no-store. Existing login throttling remains; password reset,
confirmation/change, JSON profile updates, setup, exports and push registration have
bounded request rates. Deploy a shared cache for cross-worker rate limiting.

CSP is deferred to R3: authenticated views use inline scripts/styles and handlers,
SweetAlert from jsDelivr, unpinned Chart.js, and guest fonts from Bunny. A strict CSP
requires that asset cleanup. HTTPS/HSTS, proxy trust and secure-cookie deployment
configuration remain environment gates; no production configuration was changed.
