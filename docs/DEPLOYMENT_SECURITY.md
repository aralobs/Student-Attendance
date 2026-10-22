# Deployment and finalization controls (F11)

Production requires `APP_ENV=production`, explicit database host/name/user/password
and a canonical HTTPS `BASE_URL`. Railway environment metadata also selects
production automatically. Without APP_ENV, development defaults apply only to
CLI or direct loopback HTTP with a loopback hostname and no forwarded headers.
Remote/proxied web requests otherwise use production validation. Explicit
development/local/test/testing modes retain local XAMPP behavior; never use those
modes on a production deployment. Production rejects root and empty passwords.
Use a dedicated database identity with only necessary table operations, no global
grants or schema privileges. Run schema migrations using a separate identity.

Supported variables:

| Setting | Requirement |
| --- | --- |
| `APP_ENV` | `production` on web and worker deployments |
| `MYSQLHOST` / `DB_HOST` | Explicit database hostname |
| `MYSQLPORT` / `DB_PORT` | Valid port; defaults to 3306 |
| `MYSQLDATABASE` / `DB_NAME` | Explicit application database |
| `MYSQLUSER` / `DB_USER` | Dedicated non-root runtime account |
| `MYSQLPASSWORD` / `DB_PASS` | Nonempty production credential from secret storage |
| `BASE_URL` | Canonical HTTPS URL, including the application's path |
| `DB_TLS_CA` | Absolute readable trusted CA bundle for every non-loopback DB host |
| `TRUSTED_PROXY_IPS` | Comma-separated exact IP addresses of controlled TLS proxies only |

MySQL/Railway names take precedence when present. Configure these consistently for
the web server and scheduled worker; CLI processes do not automatically inherit
Apache's per-directory environment. Secret values are never printed in setup
examples or browser errors.

PDO receives the CA and `MYSQL_ATTR_SSL_VERIFY_SERVER_CERT=true` during connection
creation. TLS-configured connections must also report a nonempty `Ssl_cipher`
before use. Never use an IP alias/name that does not match the database server's
certificate. Set the remote DB account to REQUIRE SSL at the database server and
verify the deployed server/client combination rejects untrusted certificates,
wrong hostnames and plaintext. Local loopback/same-host databases can omit TLS.
No production DB certificate was inspected or contacted during these checks.

Terminate HTTPS at the controlled edge. The application accepts forwarded HTTPS
only from the explicitly listed proxy IPs; arbitrary client-supplied forwarding
headers do not bypass it. HTTP GET/HEAD redirects to the canonical HTTPS base;
HTTP mutations are rejected. Disable plain HTTP access to application workers
and backend listeners except from the controlled edge, which must overwrite
forwarding headers. Configure valid certificates and HSTS at that edge after
confirming HTTPS operation. Do not trust every proxy or add wildcard addresses.

Configuration/connection failures produce generic 503 responses and random
request IDs. Private logs carry the matching ID and a non-sensitive reason;
browser responses no longer include database addresses, usernames or PDO errors.
Session validation outages also include correlation IDs. Protect the error-log
destination from HTTP and limit its filesystem access to operators.

Dashboard, attendance pages, live logs and report reads no longer reconcile or
finalize attendance. Mutation helpers are CLI-only. Scanning/manual edits still
save attendance through their authenticated write workflows. The trusted worker
is:

```text
C:\xampp\php\php.exe C:\xampp\htdocs\Attendance_System\attendance\finalize_absences.php
```

Schedule this command **every minute** using Windows Task Scheduler under a
designated worker account, with the required environment and persistent access
to `storage/private`. Set its working directory to the application directory and
capture output/errors outside the document root. For Linux, use cron or an
equivalent managed scheduler (adjust paths to the deployment):

```cron
* * * * * cd /app && /usr/bin/php attendance/finalize_absences.php >> /var/log/attendance-finalizer.log 2>&1
```

Scheduling is a deployment step, not installed by this source patch. Without it,
automatic absence persistence and stale-label reconciliation do not run when a
page is opened. Monitor job success/exit status and the cursor setting
`attendance_absence_last_date`; do not expose the worker as a web URL or call it
from page JavaScript. It sends no parent notifications. A nonblocking lock avoids
overlapping runs on one shared filesystem; use one designated worker instance in
a multi-instance deployment. Each run catches up at most 31 calendar days and
advances the cursor only after successful finalization. The cursor's day is
revisited to handle later closing windows. Existing rows/remarks and no-class
rules retain their existing finalizer protections.

The legacy PDF URL authorizes an active section before redirecting to the
maintained SF2 page. Use that page's Print / Save PDF action. The obsolete query
against an attendance `status` column has been removed rather than re-enabled.
Invalid report parameters are rejected before report queries; guest/scanner and
unrelated-teacher requests cannot reach the report. The target page independently
authorizes the request again.

Photo uploads now verify an actual PHP upload and its real byte size, decode
approved JPEG/PNG/GIF/WebP content, bound dimensions/pixel count, and re-encode as
JPEG or PNG under a cryptographically random filename. This discards metadata,
appended payloads and animation (GIF avatars become a still image; unsupported
animated WebP content is rejected). Unsupported,
truncated or oversized images fail closed. PHP GD with the required decoders must
be installed; Composer now declares GD, mbstring and OpenSSL platform requirements
so the Nixpacks provider can select them. Validate the deployed build with
`composer check-platform-reqs --no-dev`; the local platform check passes.
Private storage is outside the document root, and direct legacy upload paths are
blocked. The authorized handler serves only an allowed image MIME with nosniff.
Never add a PHP handler for the private storage directory.

Validation uses synthetic local HTTP and database fixtures, not production records:
`php tests/deployment_security_test.php`, `php tests/photo_upload_security_test.php`,
`php tests/pdf_export_access_test.php`, `php tests/today_log_access_test.php` and
`php tests/finalizer_worker_test.php`, plus the existing session/attendance
regressions. Production grants, CA chain, proxy
configuration and worker scheduling require operator verification.

References: [OWASP Database Security](https://cheatsheetseries.owasp.org/cheatsheets/Database_Security_Cheat_Sheet.html),
[OWASP File Upload](https://cheatsheetseries.owasp.org/cheatsheets/File_Upload_Cheat_Sheet.html),
[PHP PDO MySQL TLS options](https://www.php.net/manual/en/ref.pdo-mysql.php).
Build reference: [Nixpacks PHP extension selection](https://nixpacks.com/docs/providers/php).
