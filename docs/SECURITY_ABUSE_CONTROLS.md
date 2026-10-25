# Login and scanner abuse controls (F08)

Install the table before deploying the new PHP files:

```console
php migrations/security_abuse_controls.php
```

The local installation has already run this migration. Run it once on each
deployed database and daily afterward to remove buckets expired for over a day.
The command is idempotent and does not change users, students or attendance.
Both SQL imports include the table for new installations.

Limits are shared across PHP workers/instances using the same InnoDB database.
They do not depend on browser cookies. A missing table or failed quota query
returns HTTP 503; quota exhaustion returns HTTP 429 with Retry-After. Rejected
attempts do not extend the window. Counters store hashed identities, never QR
tokens, passwords, usernames or phone numbers as plaintext.

| Action | Identity | Limit | Window |
| --- | --- | --- | --- |
| Login | IP | 100 requests | 15 minutes |
| Login | Normalized username and, when found, actual account ID | 10 attempts each | 15 minutes |
| Scanner | User and current authenticated session/device | 120 requests each | 1 minute |
| Scanner | IP | 600 requests | 1 minute |
| Failed/malformed/out-of-scope QR probes | User | 20 probes | 1 minute |
| Failed/malformed/out-of-scope QR probes | IP | 100 probes | 1 minute |

Login counts successful and unsuccessful attempts; success does not erase a
quota an attacker might otherwise reset. Fixed windows may allow a burst across
a reset boundary. Scanner quotas permit normal arrivals; its existing
per-student cooldown and attendance row locks continue to prevent duplicate
events/notifications. Failed-probe quotas are checked before student lookup
after exhaustion. Responses do not reveal whether a teacher's out-of-scope QR
belongs to another class or is unknown.

Use one named scanner_operator account per kiosk. Its user ID has its own quota,
and its authenticated session supplies the device bucket. Existing single-session
login enforcement replaces the previous session when another browser logs in.
Administrators can revoke the kiosk by deactivating its account. This is an
account/session identity, not hardware attestation; do not share kiosk passwords.
Admins and scanner operators scan school-wide; teachers can scan only students
in their currently assigned active sections. Unknown roles are denied.

Only REMOTE_ADDR is trusted. Behind a reverse proxy, configure it to supply the
real client address using its trusted-proxy configuration, or all clients share
the proxy's IP quota. Never trust arbitrary incoming X-Forwarded-For values.
Tune the constants in includes/abuse_security.php against observed kiosk counts
and shared-school-network traffic, and apply edge request-body limits as well.

New student QR tokens use `STU-<LRN>-<random suffix>`, with a 256-bit suffix from
random_bytes. The LRN remains part of the token as required. Changing a section
or year does not change a token. Existing printed cards remain accepted
until deliberately rotated. To replace all old token formats:

```console
php migrations/rotate_student_qr_tokens.php --legacy --confirm
```

To revoke a single lost/shared card:

```console
php migrations/rotate_student_qr_tokens.php --student=123 --confirm
```

Rotation is CLI-only and transactional; it clears the old saved QR image
reference. Old cards immediately stop scanning. Reprint/download affected cards
through the authorized QR pages. No production student tokens were rotated
during this fix. These commands print counts only, not tokens or student PII.

New and changed passwords must contain 8–16 characters, without NUL. Unicode
characters are counted as characters rather than bytes. Valid UTF-8 at this
length also stays within bcrypt's byte bound. No forced symbol/case composition rule is used.
Existing hashes remain usable, and login no longer trims password whitespace.

Remaining operational controls within F08: enable MFA for privileged accounts
through a vetted authentication/identity-provider integration before treating
password compromise as mitigated. MFA was not introduced by this change.
Static reusable QR cards identify a student but do not prove physical presence;
use supervised kiosks when presence is required. A short-lived challenge would
require changing the printed-card workflow.

Checks (synthetic data and disposable databases only):

```console
php tests/abuse_limits_test.php
php tests/abuse_endpoints_test.php
php tests/csrf_actions_test.php
```

Guidance: [OWASP Authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
and [OWASP REST Security](https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html).
