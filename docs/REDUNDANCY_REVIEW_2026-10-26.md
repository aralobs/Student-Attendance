# Redundancy review — 2026-10-26

Static review of the current working tree, including uncommitted application changes. Scanned 297 PHP, JavaScript, CSS and SQL files, of which 192 were outside tests. Third-party code, generated deployment packages, private storage and other worktrees were excluded. Reference searches also covered tests and documentation where relevant.

No application code or database data was changed. These are cleanup findings, not a claim that every unused runtime path has been proven unreachable. No browser sessions, production database inspection or runtime coverage collection was performed. No byte-identical source files were found; the duplication is primarily blocks, responsibilities and old implementations.

## Highest-confidence cleanup findings

1. **Obsolete schedule implementation with conflicting defaults.** `sections/functions.php:5` defines `getScheduleForGrade`, which is also defined in `config/schedule_rules.php:8`. The old file has no reference found in the repository and does not begin with a PHP opening tag. Its Kinder rule says full day and its morning arrival begins at 07:00; the maintained rule says morning only and starts at 08:00. `includes/functions.php:24` loads the maintained configuration. Remove the obsolete file after checking any external tooling. Loading both definitions as executable PHP would also be unsafe.

2. **Unreferenced monthly analytics view.** `includes/monthly_analytics_page.php:1` contains a separate filter form, metrics, table and chart. No application include or other repository reference to its filename was found. The active analytics route is `analytics/analytics.php`; attendance also has `includes/attendance_overview.php`. Archive/remove the unreferenced view if no external loader uses it. Its underlying analytics helper is used elsewhere and should remain.

3. **Duplicate student soft-delete endpoints.** `students/archive.php:17` and `students/delete.php:17` perform substantially the same lookup, enrollment visibility update or legacy `is_active = 0` update. Differences are mainly messages/comments. The student list posts to `archive.php` at `students/students.php:298`; no production caller of the student delete endpoint was found. Keep a single implementation, with a compatibility wrapper if the old URL must remain supported.

4. **Duplicate section soft-delete endpoints.** `sections/archive.php:23` and `sections/delete.php:18` repeat the same enrollment visibility operation and legacy active-student check. The section list posts to `archive.php` at `sections/sections.php:173`. The delete endpoint also redirects to `index.php`, which does not exist in that directory. Consolidate behind the archive operation; preserve any required old URL with a wrapper.

5. **Two clock timers update the same scanner element.** `assets/js/main.js:85` installs a one-second timer for `liveClock`. `attendance/scanner.php:460` installs another one-second timer for that same element. One renders time only, the other renders date and time. `includes/footer.php:34` loads the shared script and line 38 emits the page script, so both run on the scanner. Choose one owner with the scanner's intended format.

6. **Unused report filter-building block.** `reports/date_range.php:26` builds `$where`, `$params` and `$whereSQL`, but the actual student-summary query uses a separate `$stuWhere`/`$stuParams` block beginning at line 62. `$whereSQL` is never consumed. Remove the first filter-building block while retaining `$orderSQL`, which is used.

7. **Unused values and an unnecessary lookup.** `analytics/analytics.php:56` assigns `$totalPresent` without using it; lines 164–165 assign unused `$monthlyAmLate` and `$monthlyPmLate`. `sections/sections.php:124`–125 assign unused `$usesAM`/`$usesPM`. `attendance/get_today_log.php:27` assigns unused `$now`. `students/archived.php:25`–26 assign unused `$sections` and `$schoolYears`; the latter calls `enrollmentReportYearLabels` without consuming its result. Remove these after checking include scope; page variables such as `$pageTitle`, `$extraJS` and `$sf2PrintSummaryRows` are consumed by included templates and are not dead merely because they appear once locally.

## Unused-helper candidates

8. **PHP helpers with no production callers found.** In `includes/functions.php`: `isLoggedIn` (116), `requireScannerOperator` (178), `requireScannerAccess` (187), `isKioskMode` (209), and `isSchoolDay` (329). Also `generateQRCodeBase64` in `includes/qr_helper.php:95`. Repository searches found their definitions but no other references. Check external entry points before removal. The active guards and QR rendering functions must remain.

9. **JavaScript utilities without production callers.** `assets/js/main.js:205` defines `ajaxPost`; line 222 defines `formatTime`; line 231 defines `formatDate`. No application calls were found. `ajaxPost` is used by `tests/csrf_forms_test.js`, so removing it also requires deciding whether that test should target the actual production POST flows. A test reference alone does not establish application use.

Two additional functions have test callers but no production references found: `sendAbsenceEmail` (`includes/mail_helper.php:173`) and `revisePreparedEnrollment` (`includes/enrollment_corrections.php:192`). Treat these as an integration/API decision, not immediate deletion candidates: their tests document intended notification and correction behavior.

## Consolidation opportunities

10. **Sidebar styling and active-link detection have two owners.** `assets/css/style.css:34` defines sidebar layout/nav rules while `includes/sidebar.php:20` embeds a second set with more specific selectors. The latter changes padding, sizing and appearance. Active state is set by PHP in the sidebar and again by the fallback in `assets/js/main.js:55`. Move maintained styling to one stylesheet and prefer one deliberate active-state strategy. The existing rules differ, so this needs visual verification rather than blind deletion.

11. **Reports repeat filters, access-scoped SQL setup and rendering.** Examples include `reports/am_pm.php:19`, `reports/by_student.php:21`, and repeated filter/header markup around `reports/am_pm.php:101`, `reports/by_section.php:77`, `reports/by_student.php:115` and `reports/partial.php:73`. `reports/by_grade.php:169` and `reports/by_section.php:181` also repeat totals rendering. Extract small shared filter/context components while retaining each report's distinct result and permissions. Similar purpose does not make the reports themselves redundant.

12. **School-day loops repeat calendar lookups.** `reports/am_pm.php:27`, `reports/by_grade.php:26`, `reports/by_section.php:27`, `reports/date_range.php:47` and `reports/sf4.php:48` implement very similar day counting. Each eligible weekday calls `isHolidayOrNoClass`, which calls `getCalendarEntry` and issues a query (`includes/functions.php:322`–338). Share a period-calendar calculation and preload calendar entries for the range. Preserve enrollment dates and delivery-mode rules; the official forms can have different formulas.

13. **Schema definitions are maintained in multiple places.** Large enrollment-table blocks occur in `attendance_system.sql:337`, `config/database_schema_update.sql:273` and `includes/enrollment_schema.php:77`. Related security/notification definitions also recur in migration SQL. These serve fresh-install and upgrade paths, so deleting one copy can break deployment. Prefer a canonical schema source with generated install SQL or a consistency check to reduce drift.

14. **Unused Composer namespace mapping.** `composer.json:14` maps `App\\` to `src/`, but there is no `src` directory or application class tree using that namespace in the current checkout. Remove the mapping if that architecture is no longer planned, then regenerate Composer autoload metadata. Keep the Composer dependencies: the application uses them.

15. **Potentially redundant attendance index.** `attendance_system.sql:158` declares `idx_attendance_student(student_id)` alongside the unique index `unique_attendance(student_id,date)` at line 155. The composite index has the same leading column and is a candidate to cover student-only lookups. Confirm the installed schema, foreign-key requirements and query plans before dropping anything. This review did not inspect the live database.

## Items that should not be removed as generic redundancy

- Legacy versus managed enrollment branches remain selected by `enrollmentModelEnabled` and table availability, for example `attendance/attendance.php:16`. Their retirement requires a confirmed deployment cutover and compatibility decision.
- Historical AM/PM columns support compatibility and reporting; README explicitly documents their retention.
- `reports/export_pdf.php` is an intentional compatibility route that delegates to maintained SF2 PDF rendering.
- `register.php` deliberately denies public registration. It is a security behavior, not an unfinished page.
- `users/archive.php` and `users/delete.php` are different operations: reversible archiving versus permanent deletion of an already archived account.
- Vendored browser libraries, package manifests and lockfiles serve deployment and dependency auditing. Their coexistence is intentional.
- Tests, migration scripts, backups and historical plans are not disposable merely because normal browser navigation does not reference them.

## Suggested cleanup order

1. Remove confirmed unused values/filter setup and the obsolete schedule file; resolve the unreferenced analytics view.
2. Consolidate student/section archive handlers and the scanner clock; verify access checks, CSRF protection, redirects and scanner display.
3. Review helper/API candidates with their tests before removal.
4. Consolidate sidebar styling, report filters and calendar calculations with focused regression checks.
5. Handle schema generation, legacy retirement and index changes separately with database evidence.
