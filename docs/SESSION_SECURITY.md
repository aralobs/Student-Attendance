# Session lifecycle controls (F09)

Session initialization is centralized in `includes/session_security.php` and
used by login, authenticated routes, heartbeat and logout. PHP sessions use
strict mode, cookies only, HttpOnly, SameSite=Lax, browser-session lifetime,
and a host-only cookie. Secure is required except for direct HTTP loopback
development with a loopback BASE_URL, hostname and client address. Setting
APP_ENV=production always requires Secure. Set BASE_URL to the deployed HTTPS
URL; do not spoof forwarded headers to relax these settings.

Database connection, validation-query or activity-update errors stop protected
requests with HTTP 503, a generic message and Retry-After: 30. They do not preserve
authorization through a successful fallback. Normal token revocation, role
changes, deactivation and expiry reject the session. Login uses the same
validation rules before redirecting an already authenticated browser.

| Role | Idle timeout | Absolute lifetime |
| --- | --- | --- |
| Administrator / Teacher | 30 minutes | 8 hours |
| Scanner Operator | 4 hours | 12 hours |

Creation and activity timestamps are server-side session values established at
login. Absolute lifetime never slides. Heartbeat, live-log refresh and end-of-day
polling validate access without refreshing idle time. Page navigation and
authenticated actions refresh idle time; actual successful/attempted scans count
as kiosk activity. Merely leaving a tab open does not keep a session alive.
Scanner operators must log in again at least once per school day.

Positive environment settings can tune these defaults (seconds, at most 86400):
SESSION_IDLE_TIMEOUT, SESSION_ABSOLUTE_TIMEOUT, SCANNER_SESSION_IDLE_TIMEOUT,
SCANNER_SESSION_ABSOLUTE_TIMEOUT. Zero/invalid environment values use the default;
they do not disable expiration. Keep PHP session storage persistent and private,
and configure a dedicated session save path so another application's garbage
collection cannot shorten the kiosk lifetime.

Password changes atomically clear session tokens and metadata. Role/status edits
atomically revoke tokens if the actual database values change. Deactivation,
archive, activation and restore also clear old tokens, so reactivating an account
does not restore an old authenticated browser. Logout only revokes the current
browser's token and cannot revoke a replacement session owned by another browser.

This deployment requires one fresh login for preexisting sessions that lack
reliable creation/activity timestamps. No schema migration is required, and
student QR tokens, attendance records and password-length policy are unchanged.

Teachers and administrators can open **My Profile** in the sidebar (`profile.php`)
to update their own full name/email or change their password. Username, role and
account status cannot be changed there. Both save actions use one shared current
password field and a session-bound CSRF token. New passwords must contain 8–16 characters and
match their confirmation. Password changes revoke the database token and destroy
the local session before redirecting to sign in. Profile saves keep the session
and refresh its displayed name. Saves are limited to 10 per account and 100 per
client IP per 15 minutes, using the existing shared rate-limit table.

Checks: `php tests/profile_management_test.php`, `php tests/session_security_test.php` and
`php tests/session_revocation_test.php`. The first uses temporary session files;
the second uses synthetic accounts in a disposable MySQL database. Neither
changes live accounts or sends notifications.

Reference: [OWASP Session Management Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
