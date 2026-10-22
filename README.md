# Student-Attendance

Hostinger ZIP deployment: see [docs/HOSTINGER_DEPLOYMENT.md](docs/HOSTINGER_DEPLOYMENT.md).
Run `powershell -ExecutionPolicy Bypass -File tools/hostinger/build.ps1` to prepare
the upload ZIP and separate development/database/private-transfer folders.

PII storage: deploy the protections and supporting tables described in
[docs/PII_STORAGE.md](docs/PII_STORAGE.md). SQL imports are schema-only;
they no longer include accounts, students or integration settings.

Deployment and attendance finalization: follow
[docs/DEPLOYMENT_SECURITY.md](docs/DEPLOYMENT_SECURITY.md), including production
environment/TLS settings and scheduling the CLI finalizer every minute. Read
requests no longer finalize attendance.

Daily attendance requires two scans for every student. Suggested grade defaults:
- Kinder, Grade 1 and Grade 2: morning Time-In and Time-Out by noon.
- Grades 3 through 6: morning Time-In and afternoon Time-Out.

Admins can choose AM Half-Day, PM Half-Day, or Full Day for any section; teachers can choose the schedule for their assigned active sections. The saved section schedule overrides the grade suggestion.

PM Half-Day uses afternoon Time-In and afternoon Time-Out, with no morning scan.

Section settings provide one arrival window, one departure window and a morning late threshold. A scan outside its configured window is rejected. Two valid timestamps complete the day; a missing departure remains partial. Full-day morning arrival covers AM and PM presence without a second arrival scan. Historical AM/PM columns remain in the database for compatibility.

For an existing installation, run `php migrations/two_scan_grade_policy.php` once after deploying. This idempotent command fills unsupported section schedule types from grade defaults and preserves valid saved choices while preserving custom time windows and attendance history. Review the section time windows in Sections if school dismissal times have changed.

Regression checks: `php tests/two_scan_attendance_test.php`, `php tests/attendance_edit_access_test.php`, `php tests/section_schedule_access_test.php`, and `php tests/scan_concurrency_test.php`. The concurrency check requires MySQL/MariaDB and uses a temporary test database with stubbed notifications.

End-of-day review: run `php migrations/end_of_day_alerts.php` after deploying to an existing database. Both schema imports include the new structure; the migration also normalizes existing remark records when importing historical data. This idempotent migration adds the parent-notification reservation table, the `missing_checkout` SMS log type, and the `remarked` attendance type. Existing no-scan records with remarks become “Manual remark” and are excluded from automatic absence totals; the text explains the teacher's decision without implying the child was present.

Teachers see an End of Day Alert on any open system page as their assigned sections' Time-Out windows close. The window's last second is inclusive; the alert checks at the next second and refreshes every 30 seconds. Background or offline tabs check again when opened. This is an in-app alert, not a background email/SMS to teachers. End of Day Review also supports earlier dates. Holidays, no-class days, ordinary weekends, completed scans, and records with any existing remark are excluded.

End of Day Review and its live alerts are restricted to teachers reviewing their assigned active sections. Admins and scanner operators cannot open the review or status endpoints. Scanner operators cannot open staff attendance, reports, analytics, or calendar pages directly.

Teachers can add remarks and explicitly select missing-checkout students for parent SMS. The server validates section access, the form token, and current eligibility before saving. Parent SMS uses the existing UniSMS configuration and is attempted once per student/date, outside the attendance transaction. Failed/unconfirmed notifications are reported and must be followed up directly; automatic retries are disabled to prevent duplicate messages. No-scans absence rows do not offer a parent notification checkbox.

Checks: `php tests/end_of_day_alerts_test.php` and `php tests/end_of_day_integration_test.php`. The integration test creates a randomly named temporary database and substitutes an SMS callback; no real parent notifications are sent.

Security event and encrypted change history: run `php migrations/security_audit.php` on each existing deployment, then use the administrator **Security Audit** menu. See [audit/dependency deployment notes](docs/SECURITY_AUDIT.md) for keys, append-only DB permissions, in-app alerts and automated advisory/integrity checks.
