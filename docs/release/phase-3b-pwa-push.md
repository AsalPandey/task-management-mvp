# Phase 3B PWA and Browser Push

## Delivery architecture

Task workflow transitions keep their existing recipient selection and
after-commit notification flow. The existing Laravel notification writes the
detailed database notification and additionally invokes the browser-push
channel. That channel queues one logical job after commit. The job fans out to
the user's enabled device subscriptions and records one delivery per
notification/subscription pair.

The push transport uses standards-based Web Push with VAPID. No Firebase,
native application, paid provider, offline task mutation, or background sync is
introduced.

Push-enabled notification classes:

- task assigned and task updated;
- execution and review workflow transitions, including start, hold, resume,
  submit, review start, revision, resubmit, approval/completion, reopen,
  cancellation, reviewer reassignment, and deadline change;
- deadline reminders and overdue notifications.

Account status and project-membership notifications remain database-only.

## Data and privacy

Lock-screen payloads contain only a short plain-text title/body, notification
type and identifier, task ID/UID, current state, relevant deadline, local icon
paths, and an application-relative target. They exclude descriptions,
feedback, cancellation or override reasons, protected event metadata, personal
information, and subscription secrets.

Subscription endpoints and encryption material are hidden by the model and are
never returned by status, registration, disable, or test responses. Endpoints
are selected by a SHA-256 hash under authenticated user scope. A unique endpoint
cannot be claimed by a second account. Deactivation/deletion disables active
subscriptions.

Logout tries to disable and unsubscribe the current browser before ending the
session. Account-switch detection unsubscribes a browser subscription associated
with another locally recorded user and never registers it automatically for the
new account.

## Browser behavior

Notification permission is requested only by the explicit Enable Notifications
button. The interface reports unsupported, unavailable, blocked, disabled,
enabled, and stale states. Disabling affects only the current browser/device.

The service worker caches only public static assets. It does not intercept or
cache documents or application JSON. Push targets are constrained to same-origin
paths; invalid targets fall back to the notification center. Opening a
notification still passes through normal authentication, route middleware, and
task authorization/read scoping.

PWA install prompting is optional, dismissible, and locally remembered. Browsers
without `beforeinstallprompt` retain their native installation behavior. iPhone
and iPad users may need to install from Safari's Share menu before Web Push is
available.

## Operational limitations

Web Push requires HTTPS outside local development, valid VAPID configuration,
and a running queue worker. It is best effort rather than guaranteed real-time
delivery. Operating-system settings, browser policy, focus/do-not-disturb modes,
network availability, and battery optimization can suppress or delay delivery.
Physical-device validation remains a release gate.

XAMPP on Windows may require `OPENSSL_CONF` to point to
`C:\xampp\apache\conf\openssl.cnf` while generating the EC VAPID key pair.
