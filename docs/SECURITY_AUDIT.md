# Security audit and dependency integrity (F12)

For an existing deployment, run with the deployment/migration database identity:

```text
php migrations/security_audit.php
```

This idempotent migration creates two InnoDB tables and four triggers, and ensures
the existing PII encryption key is available. It does not reconstruct historical
edits or alter existing school records. Both fresh schema imports include these
tables and triggers. The migration was applied to local XAMPP on 10 October 2026;
each separate production database needs its own migration.

## What is recorded

General events contain a server-generated event/request ID, verified actor ID (or
anonymous/worker), a SHA-256 fingerprint of the session token, fixed action and
outcome codes, route name, target ID where applicable, timestamp and alert flag.
The fingerprint groups one authenticated session; it is not a permanent hardware
identity. Tokens, passwords/hashes, submitted usernames, raw IPs, query strings,
student names and contacts are not included in general events. Application PDO
connections explicitly use Asia/Manila (+08:00), matching PHP and the audit UI.
The HTTP X-Request-ID header correlates a request with its events.

Successful/failed logins, denials, rate limits, student/photo reads, report reads
and management requests are captured by the common request shutdown hook. Empty
anonymous page loads and successful heartbeat polls are omitted. These are
request events: a successful HTTP response is not proof that a requested edit
was performed. Explicit change events provide that evidence. Redirects from
shared role guards and unavailable student scopes are recorded as denials.
Scanner failure alerts focus on invalid/unknown tokens and HTTP errors; cooldown,
completed-day, holiday and scheduled-window responses do not create failure alerts.

Daily attendance override, manual entry, successful scans, end-of-day remark
edits, scheduled absence finalization and completion reconciliation save encrypted
before/after history in the same transaction as the write. History failure rolls
back the mutation before notifications can be sent. Account creation, administrative
edits, password resets, status/archive/restore/deletion have transactional events
and non-secret role/status history. Self-service profile/password events are also
transactional and never store credentials. Student edits retain only changed
fields in encrypted history; contact changes are flagged for review.
Existing server SF2 remarks retain their separate encrypted version history.

Before/after ciphertext uses AES-256-GCM and authenticates its event ID. Preserve
the existing PII_ENCRYPTION_KEY/PII_KEY_FILE on persistent private storage, restrict
OS access and back it up separately from the database. Losing the key makes old
encrypted history unreadable. Do not rotate by simply deleting the key file.

## Access and alerts

Administrators can open **Security Audit** in the sidebar. Teachers, scanner
operators and guests cannot read this page or its decrypted change history.
History views are themselves logged. The page escapes history values as text.
Its Alerts filter shows flagged account changes, contact changes, report/QR bulk
access, denials, request errors and failed authentication/scanner requests.
The sidebar badge counts flagged events in the previous 24 hours; it is not an
unread counter. The page additionally highlights at least 10 unsuccessful login
or scanner requests in the previous 15 minutes. These signals require human
review and may include legitimate mistakes; they do not assert a compromise.
Alerts appear within the application on the next page load. No email/Slack/SMS
alert transport has been configured or invoked.

Read/request telemetry is best effort: database outages write a generic
`SECURITY_ALERT audit_storage_unavailable request_id=...` to the private PHP error
log. Monitor that signal and database availability in production. Critical
mutations described above fail closed if their history cannot be written.
Early web-server denials/static resource requests and failures occurring before
the PHP shared helper loads still require protected edge/server logging.

UPDATE/DELETE triggers reject ordinary modification of either audit table.
Application code exposes no history-update/delete endpoint. Grant the runtime DB
identity only **SELECT and INSERT** on these tables; use explicit per-table grants
for the remaining application tables. A database-wide ALL/UPDATE/DELETE grant
cannot be removed just by adding narrower table grants. Do not give the runtime
identity CREATE/DROP/ALTER/TRIGGER/TRUNCATE or migration-owner privileges.
Triggers do not protect against a privileged database owner dropping tables or
triggers. Replicate/archive logs to separately administered, access-controlled
storage if stronger tamper resistance is required.

Back up audit data with encryption and access restrictions. Establish a school
retention policy before purging; no automatic destructive retention job was
added. Controlled maintenance/archival requires the separate migration-owner
identity because ordinary deletes are deliberately blocked. Track that maintenance
outside the mutable application database.

## Browser and dependency integrity

Bootstrap 5.3.0, Chart.js 4.4.0 and html5-qrcode 2.3.8 are served from
`assets/vendor/`. Their exact sources/versions/SHA-384 hashes are in
`assets/vendor/manifest.json`; upstream licenses are alongside them. The login,
shared footer and scanner script tags use matching SRI attributes. External
JavaScript execution is blocked by the shared HTML/login CSP. Legacy inline
scripts/handlers remain allowed; this is not a complete XSS CSP. Any future
external executable script must undergo a deliberate policy/dependency review.

Update browser libraries deliberately: update package.json/package-lock.json,
download the matching distribution over verified HTTPS, review release/advisory
information, update manifest and template SRI hashes, and test scanner/charts/UI.
Do not regenerate hashes merely to accept an unexpected file change. npm's
inventory audit and SRI are complementary; neither proves a library has no bugs
or reviews every bundled source line.

`.github/workflows/security.yml` runs on pushes, pull requests, daily schedules
and manual dispatch. Its third-party actions are pinned to verified commit IDs.
It checks Composer lock consistency/platform requirements/advisories, npm
advisories, local JS hashes/inventory consistency and reviewed PHP support dates
on PHP 8.3/8.4. A nonzero audit/check result fails the job. GitHub Actions must be
enabled and the check made required in branch protection for merge enforcement;
the workflow file alone does not configure repository settings.

Local checks on 10 October 2026: `composer audit --locked --format=plain` reported
no security vulnerability advisories; `npm audit --omit=dev --audit-level=moderate`
reported zero vulnerabilities. This reflects the queried advisory databases at
that time, not a guarantee of future security. Package versions were not changed.
CI should continue checking and administrators should install current PHP patch
releases. The local PHP branch is 8.2; its reviewed security-support window ends
31 December 2026. Runtime-support checks deliberately expire and must be reviewed
when upgrading.

Validation:

```text
php tests/security_audit_test.php
php tests/vendor_integrity_test.php
php tests/runtime_support_test.php
```

The audit test uses only a disposable synthetic database, temporary HTTP server
and sessions. It verifies actual mutation routes, encrypted history, administrator
access, HTML escaping, trigger rejection, actor spoofing rejection, failed login
logging and rollback when history storage is missing. Existing attendance
concurrency, end-of-day, profile, revocation, authorization, session and CSRF
regressions also passed. No live accounts, attendance records or notifications
were changed by these checks. The GitHub-hosted workflow itself was not executed
from this workspace.

References: [Composer audit options](https://getcomposer.org/doc/03-cli.md#audit),
[MDN Subresource Integrity](https://developer.mozilla.org/en-US/docs/Web/Security/Defenses/Subresource_Integrity),
[PHP supported versions](https://www.php.net/supported-versions.php).
