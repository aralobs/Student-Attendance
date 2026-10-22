# Application security review

Date: 10 October 2026 (Asia/Manila). Reference: [OWASP Top 10:2025](https://top10.owasp.org/).

## Scope and assurance

Reviewed the current working tree, including uncommitted changes: login and registration, shared session/authorization helpers, student CRUD and QR delivery, attendance scanner/editing/finalization, reports, settings encryption, notification transport, SQL schema/dump, and deployment files. This is a source review with selected isolated regression checks, not a production penetration test or compliance certification. Severity assumes routes are reachable by the relevant attacker; deployed route restrictions, TLS termination, PHP settings, database encryption and proxy rate limits were not inspected. No real accounts, attendance records, or parent notifications were created or changed. No student values or credentials are reproduced here.

## Priority summary

| ID | Severity | Finding | OWASP 2025 |
| --- | --- | --- | --- |
| F01 | Critical | Anonymous registration can create active administrators | A01, A07 |
| F02 | High | Anonymous student QR downloads expose attendance tokens and identifiers | A01, A04 |
| F03 | High | Student read/write authorization gaps undermine attendance/remark boundaries | A01 |
| F04 | High | Live log broadens access for teachers without assigned sections | A01 |
| F05 | High | Stored XSS through scanner results and student-derived page titles | A05 |
| F06 | High | State-changing routes lack CSRF validation; several mutate on GET | A01 |
| F07 | High | SMS transport disables TLS certificate verification | A04 |
| F08 | High | Login/scanner lack application rate limits; QR tokens are weak and reusable | A06, A07 |
| F09 | Medium | Session validation fails open; expiration and revocation are incomplete | A07, A10 |
| F10 | High if publicly served; otherwise Medium | PII-bearing SQL dump, public photos, browser remarks and third-party name disclosure | A01, A02, A04 |
| F11 | Medium | Unsafe deployment defaults, diagnostic disclosure and unscoped finalization | A01, A02, A10 |
| F12 | Medium | Insufficient security audit trail and dependency integrity assurance | A03, A08, A09 |

## Findings and remediation

### F01 — Public administrator creation

**Evidence:** `register.php:7` loads only database configuration. The POST role is accepted from the caller, `register.php:37` explicitly permits `admin`, and `register.php:65` inserts that role with `is_active = 1`. There is no authentication, invitation, bootstrap-only restriction, or administrator guard.

**Impact:** Anyone who can reach this route can acquire full administrative access, exposing student/parent PII and enabling attendance modification, account management and integration-setting changes. This is a direct privilege escalation without an existing account.

**Fix:** Remove public registration from production or require administrator authentication and CSRF validation before any processing. Perform initial administrator provisioning through a CLI or tightly controlled, expiring setup process. Never allow public input to choose privileged roles. Review accounts created through this route and revoke untrusted sessions; rotate affected credentials if exposure is confirmed.

### F02 — Anonymous QR download IDOR

**Evidence:** `students/qr_download.php:5` includes helpers but never invokes `requireLogin()`. It queries an active student solely by caller-supplied numeric ID (`:12`) and returns the QR token in PNG/SVG (`:24`, `:36`). Download filenames expose LRN and, in the PNG path, surname. `qr_image.php` also has no login guard, accepts a token in the URL, and `includes/qr_helper.php` sends `Cache-Control: public, max-age=86400`.

**Impact:** Sequential ID enumeration can disclose students' reusable attendance tokens and identifiers. A valid authenticated scanner session is still required to submit scans, but leaked tokens make attendance impersonation possible through such a session. URL tokens can also enter access logs and shared image caches.

**Fix:** Authenticate and authorize the student before generating any QR response; allow only designated staff within their authorized scope. Apply the same checks to individual and bulk QR cards. Use `Cache-Control: no-store`, avoid tokens in query strings, and rotate exposed tokens. Use random temporary filenames rather than PII-derived shared names.

**Important distinction:** `students/generate_qr.php` and `students/bulk_qr.php` eventually invoke login through the shared HTML header. They are not confirmed anonymous downloads, but lack section authorization and perform database work before that guard.

### F03 — Student IDOR and authorization-boundary bypass

**Evidence:** `students/view.php:8` requires only login, then loads arbitrary student IDs, PII and attendance history. Parent name, phone, email, address and birth date are rendered at `:125–151`. `students/edit.php:7` also requires only login; its update (`:87`) accepts arbitrary student IDs, parent contact details and any active destination section. Neither checks the current student's section nor explicitly rejects scanner operators. `students/students.php` and `students/master_list.php` also lack adviser filtering.

**Impact:** Teachers and scanner operators can view or alter records outside their duties. Changing parent contacts can redirect future attendance notifications. A teacher can move another section's student into a section they advise, then pass existing attendance/remark authorization checks. Current section assignment also determines access to historical attendance, so transfer permissions are security-sensitive.

**Fix:** Define explicit read, edit, transfer and contact-change permissions. Enforce both role and object scope on every route and SQL query. Require authorization over source and destination sections; reserve transfers for authorized administrators or a controlled workflow. Restrict scanner accounts to scanner functions and minimal identity data. Consider historical section membership when authorizing old records.

**Existing protection:** `attendance/override.php:48` checks the record's student section; `attendance/manual.php:30` checks the section and validates every submitted student against its active roster before writes. End-of-day review independently checks scope, CSRF and current eligibility. A straightforward attendance-ID substitution or foreign-student batch is blocked there. These guards do not fix unrestricted student reassignment.

### F04 — Live scanner log has a fail-open scope fallback

**Evidence:** `attendance/get_today_log.php:26` starts with no adviser filter. Lines `:31–37` add it only if the teacher already has at least one active assigned section. A teacher with zero assignments receives the full log (up to 200 rows). Scanner operators also receive the broad log. Returned fields include names, LRNs, student IDs and attendance times.

**Impact:** Unassigned teachers can read other sections' attendance. A compromised kiosk can expose broad student identity and daily movement information. Scanner-wide access may be operationally intended, but the exposed dataset needs an explicit policy.

**Fix:** Always scope teachers by adviser identity; zero assignments must yield zero records. Define scanner permissions separately, minimize fields and limit the feed to the necessary kiosk workflow. Use an explicit role allowlist rather than allowing every authenticated role by default.

### F05 — Stored XSS

**Evidence:** Student editing stores raw names. `attendance/scan_process.php` returns raw student and section names; `attendance/scanner.php:334–343` interpolates them into `innerHTML`. `showResult()` at `:521` also uses HTML interpolation for messages. Separately, `includes/header.php:28` renders `$pageTitle` without escaping, while student pages derive that title from stored names.

**Impact:** A malicious stored name can execute script when a staff member views the student or scans their QR. The script can act with the victim's session, read PII and change records; HttpOnly cookies alone do not stop authenticated actions by injected scripts.

**Fix:** Build scanner UI with DOM APIs and `textContent`. Treat server messages as text. Escape titles and every dynamic HTML output with context-appropriate encoding; validate input length without treating validation as an XSS defense. Add a restrictive CSP as defense in depth after removing or nonce-protecting inline scripts.

### F06 — CSRF on attendance and management actions

**Evidence:** `attendance/override.php`, `attendance/manual.php`, `attendance/scan_process.php`, `students/edit.php`, `users/add.php`, `users/edit.php` and `sections/save.php` lack CSRF verification. `users/toggle.php:9`, user archive/delete routes, and student/section archive routes use GET IDs to mutate state. Settings, calendar and end-of-day review already have token checks.

**Impact:** A victim's authenticated browser may be induced to alter attendance/remarks, contact information, roles or account availability. Exploitability depends on cookie SameSite behavior and request context; GET mutations remain particularly problematic.

**Fix:** Require POST (or another appropriate mutation method), validate a session-bound CSRF token before all changes, and return 405 for disallowed methods. Apply the existing token approach consistently. Set SameSite cookies and validate Origin as supplementary controls. See [OWASP CSRF guidance](https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html).

### F07 — SMS certificate validation disabled

**Evidence:** `includes/UniSms.php:53` sets `CURLOPT_SSL_VERIFYPEER => false` while sending Basic-auth integration credentials, parent phone numbers and messages identifying students and attendance events.

**Impact:** A network attacker capable of intercepting the connection can present an untrusted certificate, steal the SMS API credential and sensitive message content, or manipulate delivery responses. HTTPS without peer verification does not authenticate the provider.

**Fix:** Enable peer verification and hostname verification (`CURLOPT_SSL_VERIFYHOST => 2`), install a trusted CA bundle, and fail closed on certificate failures. Never carry development bypasses into production. Add connect/overall timeouts and return generic errors instead of echoing cURL diagnostics. See [OWASP TLS guidance](https://cheatsheetseries.owasp.org/cheatsheets/Transport_Layer_Security_Cheat_Sheet.html).

### F08 — Authentication and scanner abuse controls

**Evidence:** `index.php` performs password verification without attempt counters, backoff or lockout controls. `attendance/scan_process.php:16` requires login, but has no explicit scanner-role/object guard, CSRF token, request quota, or token-length bound. `includes/attendance_scan.php` has a five-minute per-student successful-scan cooldown and concurrency locks; these do not rate-limit unknown tokens or scans across students. `includes/functions.php:539` creates QR tokens as `STU-<LRN>-<six hex characters>` using `md5(uniqid(...))`: only 24 bits of suffix space and a non-cryptographic source.

**Impact:** Password guessing, expensive token probes, database/worker exhaustion and excessive notification activity are possible. Static tokens disclose LRN, are enumerable with a known LRN, and do not prove physical presence. Any authenticated user can submit a token for any active student. School-wide kiosk scanning may be intended, but teacher scope should be deliberate.

**Fix:** Use shared, server-side per-account/IP login limits and per-user/device/IP scanner limits, bounded requests and 429 responses with Retry-After. Tune scanner quotas to real kiosk throughput and separate failed probes from legitimate bursts. Require explicit scanner permissions, enforce teacher scope where applicable, and bind kiosks to individually revocable device identities. Replace QR tokens with at least 128 bits from `random_bytes`, omit PII, and provide rotation/revocation. If attendance must prove presence, require supervised scanning or short-lived challenges. Use MFA for privileged accounts and stronger consistent password policy; `users/edit.php` currently accepts six characters. See [OWASP REST security guidance](https://cheatsheetseries.owasp.org/cheatsheets/REST_Security_Cheat_Sheet.html).

### F09 — Session lifecycle gaps

**Evidence:** `includes/functions.php:112` returns `null` on a session-validation database exception, which means valid to callers. This can preserve stale role/token decisions if validation fails while subsequent queries succeed. Complete database-connection failure instead exits through `getDB()`. Idle expiration defaults to disabled (`:20`); scanner operators are exempt even when enabled. No application cookie hardening or absolute session lifetime was found. Password updates in `users/edit.php:90` do not clear session tokens.

**Impact:** Revoked or downgraded sessions may retain access during validation errors. Unattended or stolen sessions persist, including after password changes. Actual Secure, HttpOnly, SameSite and strict-session settings depend on deployment configuration.

**Fix:** Fail closed with a generic 503 when session validity cannot be verified. Centralize session startup before any `session_start()`, require Secure/HttpOnly/SameSite cookies and strict mode, and enforce idle plus absolute lifetimes. Revoke sessions on password changes and sensitive role changes. Give kiosks revocable identities and bounded lifetimes appropriate to operation.

### F10 — PII storage and distribution risks

**Evidence:** Tracked root file `attendance_system.sql` contains populated student, user, SMS-log and settings inserts. It is located under the application document root; no repository web-server deny rule was found. Student fields and SMS content are stored directly, with no application encryption. Whether records are synthetic, database disks/backups are encrypted, or the deployed server blocks SQL downloads is unverified. Photos are linked directly under `uploads/students/`. Student-name fallback requests to `ui-avatars.com` appear in student and attendance pages. `reports/sf2.php:1125–1163` stores remarks in persistent localStorage keyed by section/month/year, without user identity. Logout does not clear these remarks.

**Impact:** A served SQL dump could expose PII, password hashes and attendance tokens; a public repository or shared archive introduces another exposure path. Public photo paths bypass application authorization. External avatar requests disclose student names to another service. Remarks can survive logout and cross account boundaries on shared browsers. A database or backup compromise exposes unencrypted PII and SMS message content.

**Fix:** Remove populated dumps from web deployments and source history where appropriate; use synthetic fixtures and block `.sql`, `.git`, backup, storage and internal configuration paths. If sensitive material was exposed, investigate and rotate affected secrets/tokens. Store photos outside the document root and serve them through authorized handlers. Generate avatars locally. Save remarks on the server with authorization/auditing; avoid persistent sensitive browser storage. Use encrypted storage and backups, retention/deletion policies and least-privilege database access; consider authenticated field encryption for sensitive contacts/remarks with keys separated from data. Existing AES-256-GCM settings encryption protects selected integration secrets, not student PII, and legacy plaintext settings remain readable until rewritten.

**Remediation applied (10 October 2026):** Both tracked SQL imports are now schema-only; local recovery copies were moved outside the document root. Apache deny rules block dumps, keys, internal directories and legacy photo URLs (verified locally). Student photos use an authenticated, section-scoped handler and private storage; external avatar requests were replaced with local images. SF2 remarks now use authorized server-side storage, AES-256-GCM encryption, version checks and encrypted change history. As requested, legacy SF2 browser entries are discarded rather than imported. The supporting-table migration was run locally; no existing school records or QR tokens were changed. Validation passed: 162 isolated PII checks, browser-storage cleanup and autosave checks, student authorization and QR-card regressions, and both empty-schema imports. See [PII storage deployment notes](docs/PII_STORAGE.md). Production volume/backup encryption, retention policy, web-server equivalents and historical repository exposure still require deployment verification; existing contact fields and daily attendance remarks were not field-encrypted by this patch.

### F11 — Deployment and finalization risks

**Evidence:** `config/database.php:17–23` falls back to root/empty database credentials and an HTTP base URL; its PDO setup has no explicit TLS configuration. Lines `:45–51` return host, database user/name and raw PDO errors to clients. `reports/export_pdf.php` has login but no section access check; its query references an obsolete attendance `status` column, so successful leakage is schema-dependent. `attendance/get_today_log.php:19` finalizes attendance school-wide before applying read scope; dashboard/report reconciliation also runs without a section argument. Photo uploads in `students/edit.php` rely on extension and size checks, without content decoding.

**Impact:** Missing environment settings can result in excessive database privileges or insecure transport. Diagnostics reveal deployment internals. Ordinary read requests trigger writes beyond the caller's section and additional database load. Legacy report fixes may accidentally activate an existing authorization gap. Invalid image content can reach publicly served storage; remote code execution was not demonstrated.

**Fix:** Fail startup in production when required settings are absent, use a dedicated minimally privileged DB user, enforce HTTPS at the trusted edge and verified database TLS when crossing a network. Return generic failures with correlation IDs. Put finalization in a trusted scheduled worker or explicitly authorized scoped service; keep reads free of mutations. Authorize legacy reports before repairing their schema. Validate and re-encode uploads, generate random names and disable script execution in upload storage.

**Remediation applied (10 October 2026):** Production configuration now requires explicit non-root database credentials and an HTTPS base URL. Remote database connections require a readable CA bundle, certificate verification and an encrypted session before use. Configuration/connection and session-validation failures return generic responses with correlation IDs. Attendance read routes no longer finalize or reconcile records; mutation helpers are CLI-only, with bounded catch-up and overlap protection in the existing worker. The legacy PDF URL checks active-section authorization and redirects to the maintained SF2 print view, removing the obsolete status-column query. New photo uploads are decoded, bounded and re-encoded into fresh JPEG/PNG files in private storage; required extensions are declared. Validation passed: 49 deployment/proxy/error checks, 23 image checks, 10 PDF-access cases, 12 worker checks, plus live-log, session, profile, scanner-abuse and attendance concurrency regressions. See [deployment setup](docs/DEPLOYMENT_SECURITY.md). Production DB grants/certificates, trusted-edge configuration and scheduling the finalizer every minute still require operator verification; no worker was started against school attendance records during testing.

### F12 — Audit and supply-chain assurance gaps

**Evidence:** Attendance editing overwrites records and `recorded_by`; there is no append-only before/after history. Existing SMS logs and error logs do not provide a comprehensive trail of login failures, authorization denials, PII access, account/role changes or record edits. `includes/footer.php` and `attendance/scanner.php` load third-party scripts without SRI. Composer dependencies are locked, but no advisory audit was performed and no vulnerable dependency is asserted.

**Impact:** Unauthorized access and changes can be hard to investigate or detect. A compromised third-party script can act inside the application origin. Dependency vulnerability status remains unknown.

**Fix:** Add access-controlled, append-only security events with actor/device, action, target, outcome, time and request ID. Record protected change history without copying secrets or unnecessary PII into general logs. Alert on admin creation, role changes, mass exports, abnormal scanner failures and contact changes. Self-host reviewed scripts or use pinned SRI plus CSP; automate Composer advisory auditing and supported-runtime checks in CI.

**Remediation applied (10 October 2026):** Added request security events and an administrator-only audit/history viewer with in-app alert flags/counts. Daily attendance writes (including scans, manual/override edits, end-of-day remarks and scheduled finalization) and administrative account changes now save encrypted before/after history transactionally; self-service account changes have transactional non-secret events and student edits retain encrypted field deltas, flagging contact changes. Audit UPDATE/DELETE triggers enforce append-only DML; local migration applied. General events omit passwords, hashes, QR/session tokens and contact values. Locally served browser scripts now have pinned SHA-384 SRI, version/hash inventory and preserved licenses; CSP blocks external executable scripts while retaining legacy inline code. Added pinned GitHub Actions checks for Composer/npm advisories, platform/runtime support and vendor integrity. Live Composer/npm audits reported no advisories/vulnerabilities for their locked inventories. Isolated HTTP/SQL history tests and related regressions passed; no real records or notifications changed. Production migration, key persistence, runtime patch updates, restricted DB grants, monitoring/retention and required CI checks remain operator configuration. Historical changes before this deployment cannot be reconstructed. See `docs/SECURITY_AUDIT.md`.

## OWASP coverage

| Category | Assessment |
| --- | --- |
| A01 Broken Access Control | Confirmed F01–F04/F06; direct attendance editing has useful guards but student reassignment undermines them. |
| A02 Security Misconfiguration | Confirmed diagnostic/default risks; static file access and actual server policy require deployment verification. |
| A03 Software Supply Chain Failures | Lockfile present; CDN integrity and dependency audit process require improvement. No known CVE claimed. |
| A04 Cryptographic Failures | Confirmed SMS peer-verification bypass and PII distribution risks; deployed encryption/TLS requires verification. Passwords are hashed and selected settings use authenticated encryption. |
| A05 Injection | Stored XSS confirmed by source data flow. Reviewed attendance/student queries generally use prepared statements; no SQL injection confirmed in this review. |
| A06 Insecure Design | Reusable weak QR identifiers and missing request-abuse controls; static QR does not establish physical presence. |
| A07 Authentication Failures | Public privileged enrollment, missing login throttling and session lifecycle gaps. |
| A08 Software or Data Integrity Failures | Third-party script integrity and auditability gaps. Scan row locking, unique student/date key and end-of-day notification reservations are positive controls. |
| A09 Security Logging and Alerting Failures | No comprehensive security-event/change audit trail observed. |
| A10 Mishandling of Exceptional Conditions | Fail-open session validation and detailed database error responses; notification calls need bounded timeouts. |

## Validation and next steps

Executed isolated tests: `php tests/attendance_edit_access_test.php` (22 cases passed), `php tests/section_schedule_access_test.php` (12 cases passed), `php tests/settings_security_test.php` (passed), and settings POST-guard checks for missing token and GET (passed). These use fixtures/stubs or temporary session storage; they do not demonstrate production-wide authorization. No live abuse tests or provider calls were made.

First close public admin registration and QR downloads, then enforce student transfer/read/write permissions, fix XSS/CSRF and SMS TLS, and add throttling plus token rotation. Follow with deployment/data-retention controls and auditing. In a disposable test environment, add negative tests for guest QR access, scanner CRUD denial, cross-section student reads/edits, transfer-based attendance/remark bypass, zero-assignment teachers, CSRF failures, invalid-certificate rejection, XSS rendering, request limits and session-validation outages. Verify deployed static-file rules, cookie flags, transport encryption and edge limits separately.

Authorization recommendations follow the [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html): deny by default, check every request and protect static resources as well as database records.
