# Post-deployment security audit — 27 October 2026

Target: springgreen-cobra-784324.hostingersite.com. Read-only live inspection, local source review and isolated automated tests. No production records, schedules, files, credentials or cron jobs changed during this audit.

## Subsequent authorized corrections

After the audit, the user authorized small, reversible corrections. The following changes are deployed and read back against tested local files:

- `.htaccess` now blocks all requests to the legacy qrcodes directory. All five existing images returned HTTP 403 in anonymous follow-up requests. Files were retained, and the Hostinger server/CDN cache purge was accepted before verification.
- `index.php` now renders and validates the existing session-bound CSRF token before credential processing. Live missing, incorrect, array and different-session tokens return 403. A matching-session form with empty credentials reaches normal validation and returns 200. No real account credentials were submitted.
- HTTPS login responses now send host-only HSTS with a conservative `max-age=300`, and no longer disclose X-Powered-By. No includeSubDomains or preload was requested. Headers on CDN-served assets may differ.
- Anonymous login page and stylesheet return 200; dashboard and authenticated QR image endpoint still redirect to login; configuration remains blocked. No business records, key material, schedules or cron definitions were changed by the correction.

Local regression proof: login_csrf_test.php passed 31 checks after failing on a tokenless submission before the patch. Existing CSRF endpoint (189), deployment security (49), abuse endpoint (123) and session security (213) checks passed. The abuse test fixture now starts a login session before seeding its synthetic CSRF token, to model an actual browser session.

Rollback copies of both prior live files and the patched files are stored in `deployment/security-fix-20261027/`. Restoring `.htaccess` would re-expose legacy QR images; use a targeted rollback if only the login route needs reverting. Browsers may retain the short HSTS policy for up to five minutes. Existing open login forms need a refresh to obtain the new token. A successful real-account login and post-login QR rendering still require operator verification.

Remaining: tighten the inline-script policy after compatibility work, configure school-approved section cutoff times, and determine whether previously exposed legacy QR values remain active. The original findings below describe the pre-correction audit state.

## Findings

### 1. Legacy QR images bypass the authenticated QR endpoint — priority

The Hostinger file inventory contains five PNG files in public_html/qrcodes. An anonymous request for one existing image returned HTTP 200 with Content-Type image/png. Directory browsing returns 403, but this does not prevent direct file downloads. No QR content was displayed or decoded; filenames are omitted from this report.

The current qr_image.php endpoint authenticates staff and checks student access before generating images. The legacy static files bypass those controls. Whether their encoded values still match active student tokens is unverified; if they do, exposure could help someone copy an attendance identifier. Scanner authentication still applies; this is not proof of anonymous attendance writes.

Recommended first correction: deny HTTP access to the entire legacy qrcodes directory, preserve the files privately if needed, and verify the authenticated image endpoint continues working. Review token validity and decide whether affected tokens need replacement; do not rotate all tokens blindly. If images were cached publicly, invalidate their cached copies as part of the correction.

### 2. Login POST lacks CSRF validation — medium, source-confirmed

The deployed index.php matches local source and contains no CSRF token generation, form field, or validation. Its POST handler accepts credentials without an Origin/Referer check. Authenticated mutation endpoints have separate tested CSRF guards. This finding concerns login CSRF, not bypassing password verification. No live login or cross-site exploitation was attempted.

Recommended correction: bind login submissions to a token in the pre-authentication session, validate before credential processing, and renew the session/token at authentication.

Reference: [OWASP CSRF Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).

### 3. Browser security hardening — lower priority

- Tested HTTPS responses lack Strict-Transport-Security. HTTP does redirect to HTTPS with 301 and the TLS certificate validates. Add a site-specific HSTS policy after confirming HTTPS readiness; do not set a parent-domain policy or preload on assumptions.
- The login Content-Security-Policy allows script-src 'unsafe-inline'. This limits the policy's protection against injected inline JavaScript; no exploitable XSS was established. Move toward external scripts/nonces and test existing templates before tightening enforcement.
- PHP responses expose X-Powered-By: PHP/8.3.36. Suppressing the version is minor hardening, not a replacement for patching.

Reference: [OWASP HTTP Headers Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/HTTP_Headers_Cheat_Sheet.html).

### 4. Schedule configuration follow-up — functional

The earlier deployment investigation identified full-day sections with unset 00:00 afternoon cutoffs. The deployed finalizer matches the tested source that skips midnight placeholders. Actual school-approved end times still need configuration. This audit did not read the live database to recount affected sections.

## Verified controls and runtime health

- Login returns HTTP 200 without sampled PHP warnings, fatal errors or SQL traces. Sampled requests completed promptly, approximately 0.12–0.20 seconds; this is not a load test.
- Anonymous requests to dashboard, students, users, reports, audit, photos, QR endpoint and backup UI redirect to login.
- Tested configuration, includes, migrations, tools, storage, .git and .env paths return 403. SQL dump request returns 403; private hosting configuration and phpinfo test paths return 404.
- Directory listings are blocked on sampled directories. The nested public_html directory is empty according to the connector.
- Session cookies observed on live responses have Secure, HttpOnly and SameSite=Lax. Login and authenticated redirects use no-store cache controls.
- Login sends CSP frame-ancestors 'self', object-src 'none', base-uri 'self', form-action 'self', X-Content-Type-Options nosniff and Referrer-Policy same-origin. Lack of X-Frame-Options alone is not treated as a separate finding because the login has CSP framing protection.
- Cron schedules remain every minute for absence finalization and every five minutes for maintenance. Latest captured outputs: finalizer finalized 0; interventions exit_code 0; backups exit_code 0. This does not independently prove a backup restore succeeds on the hosted server.
- Six key deployed PHP files match the tested local source after newline normalization: index.php, includes/functions.php, includes/csrf.php, includes/student_access.php, includes/browser_security.php, includes/attendance_finalizer.php.
- Live Composer package names/versions match local installed metadata. composer audit --locked --no-dev --format=json returned no known advisories and no abandoned dependencies at audit time. This is not a guarantee against unknown vulnerabilities or a hash verification of every vendor file.

## Automated checks completed

All of the following passed locally using synthetic images, isolated sessions, mocked endpoints or disposable databases; none were run against the production database:

- deployment_security_test.php — 49 checks
- session_security_test.php — 213 checks
- settings_security_test.php
- photo_upload_security_test.php — 23 checks
- header_xss_test.php
- csrf_actions_test.php — 189 cases
- student_access_test.php — 47 cases plus four managed scopes
- pdf_export_access_test.php — 10 cases
- session_revocation_test.php — 50 checks
- abuse_endpoints_test.php — 123 checks
- backup_access_test.php
- attendance_edit_access_test.php — 28 cases
- finalizer_midnight_cutoff_test.php

## Limits

No authenticated browser session was available. Full admin/teacher/scanner workflows, production record ownership, account/password inventory, hosted file permissions, notification delivery, database privileges, and complete production error/access logs were not audited. The connector does not expose PHP error/access logs. Anonymous probes and sampled source comparisons cannot establish that the entire system is vulnerability-free. No brute force, load test, record mutation, notification send or credential attack was performed.
