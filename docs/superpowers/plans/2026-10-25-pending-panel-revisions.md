# Remaining Panel Revisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. Delegation is optional and requires an explicit choice at execution time.

**Goal:** Complete pending requests 1–9 and the database-backup request while preserving annual enrollment, scan history, report ownership, and existing access controls.

**Architecture:** Extend the existing PHP application in dependency order. Centralize delivery-day resolution and attendance classification so operational pages, finalizers, absence interventions, analytics, and exports use the same meaning. Implement each subsystem as an independently testable release; automatic intervention SMS is enabled only after its inputs and delivery reservations have been verified.

**Tech Stack:** Existing PHP >= 8.1, PDO, MySQL/MariaDB, Bootstrap, Chart.js, UniSMS integration, CLI workers, Hostinger cron / Windows Task Scheduler. A direct PDF renderer is a proposed new dependency, subject to the focused export feasibility check below.

**Spec:** [Existing agreed scope](../specs/2026-10-22-panel-revisions-roadmap.md). Retain [annual enrollment design](../specs/2026-10-22-school-years-enrollment-design.md) and [recovery requirements](../../SCHOOL_YEAR_ENROLLMENT.md).

**Status:** Planning draft, 2026-10-25, Asia/Manila. No application code, database, cron job, or SMS was changed. Existing agreed requirements are carried forward; choices labeled **Proposed** below remain reviewable. This is a staged master plan, not approval to deploy all subsystems at once. Each substantial subsystem still receives its focused design review under the existing roadmap before implementation.

**Execution update:** The user subsequently authorized implementation and chose this checkout. Development changes and verification are recorded in [the execution ledger](2026-10-25-pending-panel-revisions-progress.md) and [acceptance record](../../PANEL_REVISIONS_ACCEPTANCE.md). The draft checklist below is the original plan; actual file/test mappings and deployment prerequisites are recorded in those documents.

## Global Constraints

- Keep the existing `assets/img/school_logo.png`.
- Do not rebuild moving up or school-year selection (item 10); verify those as regression coverage.
- Use enrollment and dated section assignment ownership, never current profile placement, for historical attendance.
- Scope dates, deadlines, schedules, and backup folders to `Asia/Manila`.
- Preserve teacher section restrictions, administrator permissions, scanner isolation, CSRF checks, encrypted sensitive history, and append-only audit protection.
- Closed years remain read-only through ordinary attendance/calendar forms. Historical changes require the existing reviewed administrator correction process or a separately reviewed extension to it.
- Missing data, remarks, pending participation, and review-needed records must not silently become confirmed unexcused absences.
- Development and recovery tests use disposable synthetic databases and stubbed SMS. Never point tests at the live school database.
- Preserve unrelated working-tree changes. In particular, `includes/sidebar.php` and `security_audit.php` already have local changes.
- Roll out new attendance semantics only after annual enrollment cutover is verified. If the enrollment model is disabled, retain existing behavior and show the new feature as unavailable; do not infer annual ownership.

## Scope and Current Evidence

| Request | Current checkout | Planned deliverable |
| --- | --- | --- |
| 1: Security Audit → Logs | Sidebar and page title currently say **Security Logs**, reflecting local edits | Finish the requested user-facing name **Logs**, retaining audit functions and URL |
| 2: Proper analytics labels | Mixed yearly/monthly cards and generic attendance categories | Explicit period, units, legends, formulas, and consistent status names |
| 3: Monthly analytics / daily breakdown | `analytics/analytics.php` already has a basic month selector and daily series | Extend existing views with school-year/section scope, daily detail links, and complete classification |
| 4: Manual attendance / Attendance Sheet | Manual Entry loads a roster; daily Attendance starts from saved attendance; manual type is recomputed from timestamps | Full roster with no fabricated rows; explicit manual statuses, Excused reason, audited corrections |
| 5: Online / modular schedules | Calendar handles school days and closures; section schedules handle scan times | School-wide dated modes plus authorized section overrides and modular deadlines |
| 6–7: Warning / parent intervention | Existing individual absence and missing-checkout messaging exists | Separate 3-absence teacher warning and 5-absence intervention workflow |
| 8: DepEd policy verification | Prior roadmap correctly labels 3/5 as a proposed school policy | Source-backed policy note separating school intervention from official form indicators |
| 9: Print / Save | SF2/SF4 use `Print / Save PDF`; `export_pdf.php` redirects to SF2 HTML | Separate **Print** and actual **Download PDF** actions |
| Added: Database backups | Recovery instructions exist; no management feature | Daily/manual complete-database backups, private storage, retention, authenticated download |

## Proposed Delivery Approach

Use incremental releases in the order below. A single large release would make changes to calendar, attendance, and notifications harder to isolate. A presentation-only release would leave the missing attendance and notification behavior unresolved. The recommended approach extends the current app and establishes shared rules before building dependent views.

| Phase | Tasks | Completion milestone |
| --- | --- | --- |
| 0 | 1 | Baseline, recovery prerequisites, and policy distinctions documented |
| 1 | 2 | Requested Logs label complete |
| 2 | 3–5 | Calendar modes, full roster, and reliable manual corrections working |
| 3 | 6–7 | Teacher warnings and controlled intervention SMS working |
| 4 | 8 | Monthly analytics and daily drilldown reconciled with Attendance |
| 5 | 9 | Independent Print and Download PDF working |
| 6 | 10–11 | Backup creation, retention, downloads, and recovery proof working |
| 7 | 12 | Integrated acceptance and staged deployment complete |

The backup subsystem can be developed independently after phase 0. A verified recovery copy is required before any production schema change, even if the backup management page ships later.

## Shared Rules and Proposed Defaults

### Delivery dates

- Resolve eligibility first: enrollment/assignment dates and school calendar closures.
- On an eligible day: section/date override > school-wide date mode > face-to-face default.
- Store delivery mode separately from `school_calendar.type`; online/modular must not imply no class.
- Range scheduling expands to unique dated records inside the selected school year. Existing modes are shown in a preview; replacing them requires explicit confirmation. Closure dates are skipped and listed.
- A section override may change delivery mode, not reopen a holiday/no-class day. Preserve existing school-day/weekend semantics; do not introduce new weekend rules accidentally.
- **Proposed:** reject direct changes to a mode/deadline after attendance decisions exist; require a reviewed correction preview that shows affected records and alerts. Preserve the resolved mode on the attendance decision so history cannot silently change when a default changes.

### Attendance decisions

- Keep existing scan timestamps as evidence. Store explicit teacher decisions separately from scan-derived attendance rather than requiring invented arrival/departure times.
- **Proposed schema:** `attendance_decisions`, unique by `(enrollment_id, learning_date)`, with `assignment_id`, `decision`, `delivery_mode`, optional participation method, encrypted reason, deciding actor, revision, and timestamps. Decisions: `present`, `late`, `partial`, `absent_unexcused`, `absent_excused`, `pending`, `review_needed`. Legacy `remarked` remains a separate unresolved display state.
- Decide whether an explicit decision exists before falling back to valid scan-derived attendance. Both may coexist; a correction must retain original evidence and encrypted before/after history.
- No record on an open face-to-face day displays **Not recorded**. Existing face-to-face finalization may confirm absence after its cutoff only if there is no protected decision or unresolved remark.
- Online/modular participation is confirmed using accepted interaction/submission/contact methods. Credit it to the learning date, retaining the actual confirmation time separately.
- Modular nonconfirmation stays pending until its section/date deadline, then becomes **Needs review**. Online nonparticipation becomes **Needs review** after the day cutoff. Neither automatically becomes a countable absence.
- Excused is an explicit absence classification with a required reason and actor. A generic remark does not imply Excused.
- **Proposed:** prevent stale edits using a revision check; a conflicting scan/decision requires reload rather than silently overwriting another staff member's work.

### Warning and intervention policy

- Retain the accepted school proposal: teacher warning at **3** counted unexcused absences; intervention and parent SMS at **5**, once per streak.
- Excused days neither increment nor reset the unexcused streak. Holidays/no-class dates are skipped. Confirmed attendance ends the streak. Streaks belong to an annual enrollment and may span months or section transfers within that enrollment.
- **Proposed:** partial attendance, confirmed late attendance, and confirmed participation end the full-day absence streak; partial attendance remains separately reportable. A missing checkout is not a full unexcused absence.
- **Proposed:** an unresolved eligible date blocks threshold evaluation beyond that date. Mark the streak **Needs review** rather than assuming continuity. Recompute chronologically when resolved.
- **Proposed:** recompute affected streaks after corrections. Withdraw unsent invalid reservations and mark already sent alerts as superseded without deleting history. Corrections must not produce a second SMS for the same already-notified episode; merge/split episode lineage retains notification ownership.
- **Proposed:** historical backfill builds a review list only. No automatic messages about pre-enable historical dates or correction-created historical crossings. Record an enable timestamp and require a reviewed follow-up action for those cases.
- Keep the accepted school unexcused streak separate from the official school-form five-consecutive-absence-day indicator, which may include excused absence. Never label either indicator as automatic failure or dropout.

### Analytics definitions

- Default to current month in the active school year. If the selected year does not contain the current month, select its latest elapsed eligible month; if none has elapsed, select its first month and show that it is future/planned.
- Daily breakdown units are **Learners**; monthly aggregates use **Learner-days** or **Day equivalents**, explicitly labeled. Distinct learners are counted separately.
- Show Present, Late, Partial, Unexcused absence, Excused absence, Pending, Needs review, Manual remark, and Not recorded distinctly; exclude noneligible dates.
- **Proposed:** attendance rate = confirmed present day equivalents / eligible elapsed learner-days × 100. Use existing AM/PM/schedule day-equivalent rules for partial scan attendance; late confirmed attendance receives the corresponding present credit. Display unresolved learner-days and completion coverage alongside the rate; do not call unresolved days absences. Zero denominator displays **N/A**.
- Future dates are excluded from rates and shown as future in the daily table. Pending work is visible separately. Membership and denominators follow dated annual assignments.
- Keep official SF2/SF4 formulas and symbols distinct where required. Any differences between operational metrics and official form summaries must be explicitly labeled and tested.

## Review Focus

1. A transfer or archived profile must retain historical attendance in the original dated section; test in tasks 5 and 8.
2. Retroactive changes and unresolved intervening days must not create duplicate or unsupported parent messages; test in tasks 6 and 7.
3. Scan/finalizer/manual requests racing must preserve scans and produce one authoritative decision; test in tasks 4 and 5.
4. A guessed section, backup ID, or export URL must not bypass role/ownership checks; test in tasks 3, 7, 9, and 11.
5. A failed backup or ambiguous SMS timeout must remain visibly unsuccessful/unconfirmed and must not be treated as completed; test in tasks 7, 10, and 11.

---

### Task 1: Confirm baseline, recovery prerequisites, and policy note

**Files:** Read existing enrollment/recovery docs and `docs/ITEP413_Cybersecurity_Plan.md`; create `docs/ATTENDANCE_INTERVENTION_POLICY.md`. Record baseline results in the execution record rather than editing unrelated work.

**Interfaces:** Produces the policy reference used by tasks 6–8 and a list of pre-existing failures. This task does not export or migrate the live database.

- [ ] Record `git status --short`; confirm annual enrollment cutover/deployment status separately from code presence. Do not describe unverified production features as deployed.
- [ ] Run the relevant existing baseline: `php tests/enrollment_attendance_test.php`, `php tests/enrollment_reports_test.php`, `php tests/two_scan_attendance_test.php`, `php tests/attendance_edit_access_test.php`, and `php tests/pdf_export_access_test.php`. Record pass/fail and environment blockers; missing MySQL is not a passing test.
- [ ] Write the policy note using the sources below. Distinguish verified source statements, proposed school rules, and current applicability still requiring confirmation. Confirm school type and current applicable national/division issuances before claiming official compliance.
- [ ] Before production migrations, obtain a consistent private export and perform the isolated restore checks in `docs/SCHOOL_YEAR_ENROLLMENT.md`, including triggers, keys, encrypted history, and separate uploads.
- [ ] Review the proposed defaults above with the focused subsystem designs. Do not silently represent them as prior user decisions. Commit only the intended planning/policy documents when commits are requested.

### Task 2: Finish the Logs rename

**Files:** Modify `includes/sidebar.php`, `security_audit.php`, `README.md`, and user-facing wording in `docs/SECURITY_AUDIT.md` as needed; preserve existing local edits.

**Interfaces:** Existing `security_audit.php` route and permissions remain the entry point; visible menu/page title becomes exactly **Logs**. **SMS Logs** stays separately labeled.

- [ ] Inspect the current diff before editing. Replace visible Security Audit/Security Logs navigation and title wording with Logs; retain internal audit identifiers and historical documentation where appropriate.
- [ ] Run `php tests/security_audit_test.php` and `php tests/header_xss_test.php`; expect pass. Inspect sidebar, page heading, alert badge, and admin-only access in the running app.
- [ ] Review the diff for accidental changes to audit storage, encryption, or permissions. Deliver this as a small independent change.

### Task 3: Add dated delivery modes and section overrides

**Files:** Create `includes/delivery_modes.php`, `calendar/save_delivery_mode.php`, `migrations/delivery_modes.php`, `tests/delivery_modes_test.php`, `tests/delivery_modes_access_test.php`; modify `calendar/calendar.php`, `attendance_system.sql`, and `config/database_schema_update.sql`.

**Interfaces:** `resolveDeliveryDay(PDO $db, int $yearId, int $sectionId, string $date): array` returns `{eligible: bool, mode: ?string, source: string, deadline_at: ?string}`. Modes are `face_to_face`, `online`, `modular`. `saveDeliveryModes(PDO $db, array $actor, array $command): array` validates and saves a reviewed date/range command atomically.

- [ ] Write failing tests for default mode, override precedence, closures, year boundaries, teacher-forged section access, range conflict preview, atomic rollback, and modular deadlines. Assert a no-class date remains ineligible even with an override.
- [ ] Run `php tests/delivery_modes_test.php` and `php tests/delivery_modes_access_test.php`; expect failure before implementation.
- [ ] Add school-wide and section-specific dated tables with unique `(school_year_id,date)` and `(section_id,date)` keys. Validate section/year, deadline >= learning date, permissions, and active-year write rules. Protect metadata changes with audit history. Implement the resolver and calendar forms without weakening existing admin-only holiday/API-key actions.
- [ ] Run both tests plus `php tests/calendarific_test.php` and `php tests/section_schedule_access_test.php`; expect pass. Inspect a teacher override and an administrator range preview.

### Task 4: Introduce shared attendance decisions and protect background workers

**Files:** Create `includes/attendance_decisions.php`, `migrations/attendance_decisions.php`, `tests/attendance_decisions_test.php`, `tests/delivery_finalization_test.php`; modify `includes/attendance_scan.php`, `includes/attendance_finalizer.php`, `includes/end_of_day_alerts.php`, `includes/functions.php`, and both schema import files.

**Interfaces:** `classifyAttendanceDay(array $context, ?array $scan, ?array $decision, DateTimeImmutable $now): array` returns `{status: string, present_units: float, absent_units: float, countable_unexcused: bool, unresolved: bool}`. Context includes eligibility, mode, deadline, and schedule. `saveAttendanceDecision(PDO $db, array $actor, array $command): array` returns the committed decision and revision.

- [ ] Write failing cases: manual present without timestamps, Excused without reason rejected, legacy remarked unresolved, pending modular after deadline becomes review-needed, and online/modular no-scan days never auto-finalize as absent. Assert scan timestamps remain byte-for-byte unchanged after decision corrections.
- [ ] Add races for a scan, manual correction, and finalizer targeting the same learner/date. Reuse existing enrollment locking order and add deterministic decision locks/revision checks; assert no lost scan or silent stale update.
- [ ] Run `php tests/attendance_decisions_test.php` and `php tests/delivery_finalization_test.php`; expect failure before implementing schema, classification, and transaction/audit handling.
- [ ] Integrate the resolver with scanner, finalizer, and end-of-day logic. **Proposed:** reject QR scans on online/modular dates with guidance to Manual Entry; teacher decisions record participation. Keep old remarked data unresolved; do not infer Excused from text.
- [ ] Run the new tests plus `php tests/two_scan_attendance_test.php`, `php tests/scan_concurrency_test.php`, `php tests/finalizer_worker_test.php`, and `php tests/end_of_day_integration_test.php`; expect pass with stubbed notifications.

### Task 5: Build full-roster Attendance and improve Manual Entry/corrections

**Files:** Modify `attendance/attendance.php`, `attendance/manual.php`, `attendance/override.php`, `attendance/get_today_log.php`, `includes/enrollment_operations.php`, `includes/security_audit.php`; create `tests/attendance_roster_test.php`, `tests/manual_attendance_decisions_test.php`.

**Interfaces:** `loadAttendanceSheet(PDO $db, array $actor, int $yearId, int $sectionId, string $date): array` in `includes/attendance_decisions.php` combines authorized dated roster, scan evidence, decision, and shared classification. Writes use task 4's command; no duplicate attendance calculation in endpoints.

- [ ] Write failing tests: roster includes students with no attendance rows; pagination/counts include them; transferred/archived learners remain visible on eligible historical dates; unrelated sections are denied; reading Attendance creates no database rows.
- [ ] Write manual workflow tests for present/late/partial/unexcused/excused without fake scan times, required Excused/correction reason, correction audit atomicity, stale revision conflict, and preservation of actual scans. Unchanged rows in a bulk form must not become new absent decisions.
- [ ] Run `php tests/attendance_roster_test.php` and `php tests/manual_attendance_decisions_test.php`; expect failure before implementing the shared sheet loader and forms.
- [ ] Show selected date/year/section, mode, status, scan evidence, and correction history. Preserve section/date when linking to separate Manual Entry. Keep SF2 as the monthly report. Reject closed-year ordinary edits.
- [ ] Keep existing individual-absence messaging separate: a committed transition into confirmed unexcused absence may use its existing policy; unchanged saves, Excused, pending, and review-needed must not send. Test this transition explicitly and route sends through a post-commit reservation to prevent resubmission duplicates.
- [ ] Run new tests plus `php tests/attendance_edit_access_test.php`, `php tests/today_log_access_test.php`, `php tests/enrollment_attendance_test.php`, and `php tests/security_audit_test.php`; expect pass. Verify a full classroom roster with no scans, mixed modes, a correction, and a transfer.

### Task 6: Calculate absence streaks and display teacher warnings

**Files:** Create `includes/absence_interventions.php`, `migrations/absence_interventions.php`, `tests/absence_streaks_test.php`; modify `dashboard.php`, `attendance/attendance.php`, and both schema import files.

**Interfaces:** `evaluateAbsenceStreak(array $orderedDays): array` returns `{count: int, state: string, starts_on: ?string, threshold_date: ?string, blocked_on: ?string}`. States are `normal`, `warning`, `intervention_required`, `needs_review`. `recomputeEnrollmentInterventions(PDO $db, int $enrollmentId, string $fromDate): array` persists episode state/lineage, with no external messaging.

- [ ] Test the exact sequence Unexcused, Excused, Unexcused, Holiday, Unexcused => count 3 / warning. Add five-unexcused crossing, confirmed-present reset, partial/late reset, annual boundary, same-year transfer, unresolved-day barrier, corrected episode merge/split, and historical backfill with no send.
- [ ] Run `php tests/absence_streaks_test.php`; expect failure, implement deterministic chronological evaluation and stable episode lineage, then rerun for pass.
- [ ] Show teacher warning at 3 and intervention required at 5 with qualifying dates, unresolved blockers, and permitted section access. Explain that this is school intervention policy. Test that another teacher cannot view the learner through forged filters.
- [ ] Recompute after committed attendance, eligibility, and approved correction changes using a durable work item; a worker restart must not lose reevaluation. Verify a month boundary and a backdated correction manually.

### Task 7: Add reserved parent intervention SMS and follow-up

**Files:** Modify `includes/absence_interventions.php`, `includes/notification_templates.php`, `includes/functions.php`, `includes/sms_status.php`, `settings/settings.php`, `sms/logs.php`; create `tools/process_absence_interventions.php`, `tests/absence_intervention_sms_test.php`, `tests/absence_intervention_concurrency_test.php`. Extend task 6's schema before release if shipped together; otherwise add a versioned migration.

**Interfaces:** `processAbsenceInterventions(PDO $db, DateTimeImmutable $now, callable $sender): array` accepts an injected sender returning `sent|failed|unknown`. Durable reservation states include `pending`, `sending`, `sent`, `failed`, `unknown`, `cancelled`; unique logical ownership is `(episode notification owner, intervention type)`. The production adapter calls existing `sendSMS` and retains its normalized delivery outcome.

- [ ] Write failing tests: no SMS at 3/4, one reservation/send at 5, none on worker rerun or concurrent run, none for Excused/pending/unresolved or pre-enable historical crossing, and no duplicate when correction merges/splits a notified episode.
- [ ] Test invalid/missing parent phone, provider rejection, timeout after request acceptance, crash before send, crash after send, and correction before dispatch. Ambiguous/in-flight crash becomes unknown and requires follow-up; never promise exactly-once provider delivery or auto-retry unknown sends.
- [ ] Run both new PHP tests; expect failure before implementing transactionally reserved sends. Reserve before calling UniSMS, revalidate eligibility before dispatch, and call the provider outside the attendance transaction. Document the dispatch/correction race; flag a late-invalidated message as superseded.
- [ ] Use a separate intervention template describing school follow-up, without a dropout/failure claim or sensitive Excused reason. Display failed/unknown delivery and teacher follow-up; retain original outcomes when attendance is corrected.
- [ ] Run both new tests plus `php tests/sms_status_test.php`, `php tests/notification_templates_test.php`, and `php tests/end_of_day_integration_test.php`; expect pass. Configure cron only at controlled deployment, initially in dry-run mode. Enable live intervention messaging after previewing eligible cases and the template.

### Task 8: Correct analytics labels, monthly overview, and daily breakdown

**Files:** Create `includes/attendance_analytics.php`, `tests/attendance_analytics_test.php`; modify `analytics/analytics.php`, `includes/enrollment_reports.php`, relevant report summaries in `reports/absence_summary.php`, `reports/sf2.php`, `reports/sf4.php`, and `dashboard.php` where status totals would otherwise conflict.

**Interfaces:** `loadMonthlyAttendanceAnalytics(PDO $db, array $actor, int $yearId, int $calendarYear, int $month, ?int $sectionId, DateTimeImmutable $now): array` returns `summary`, `daily`, `by_section`, `by_mode`, `coverage`, and `period`; consumes tasks 3–5. Counts and rates are derived once and reused for cards, charts, and tables.

- [ ] Write failing tests for a small hand-calculated roster: 2 learners × 2 eligible elapsed days = 4 learner-days; present 1, partial 0.5, Excused 1, unresolved 1 yields 37.5% attendance, 1.5 absent day equivalents, and 75% resolved coverage. Include a future date and a closure; neither enlarges the denominator. Use this sample only where partial credit is exactly one half under the selected schedule.
- [ ] Add opening/closing month, enrollment midmonth, transfer, archived profile, year crossing, zero denominator (**N/A**), teacher authorization, leap February, and conflicting URL parameters. Reject months outside the selected year rather than mixing years.
- [ ] Run `php tests/attendance_analytics_test.php`; expect failure before implementing the service and replacing duplicated queries. Query by bounded dates and dated ownership, not `YEAR()` alone or mutable profile placement.
- [ ] Use **Monthly Attendance Overview — October 2026**, **Daily Attendance Breakdown**, x-axis **Date**, count y-axis **Learners**, rate y-axis **Attendance rate (%)**. Legends use the shared status names; monthly cards state **Learner-days** / **Day equivalents**. Add delivery mode breakdown and click-through daily table links preserving all filters.
- [ ] Run the new test plus `php tests/enrollment_reports_test.php` and `php tests/enrollment_performance_test.php`; expect pass. Reconcile selected-day counts with Attendance and selected-month totals with shared source records; document official-form formula differences instead of changing required forms silently.

### Task 9: Separate Print and direct Download PDF

**Files:** Modify `reports/sf2.php`, `reports/sf4.php`, `reports/sf4_print.php`, `reports/export_pdf.php`, `reports/index_reports.php`; create `includes/report_pdf.php`, `reports/sf4_pdf.php`, `tests/report_pdf_download_test.php`. Extend other existing printable report pages consistently after inventory. Modify `composer.json` / lockfile only if the renderer is selected.

**Interfaces:** **Print** calls the current authorized report's print view/dialog; **Download PDF** returns `application/pdf` with attachment disposition and a safe filename including form, section, year, and month. Both use the same authorized report data and required form template, with no attendance writes.

- [ ] First run a synthetic SF2/SF4 renderer feasibility check on the target PHP/Hostinger environment. **Proposed:** use a maintained PHP PDF library compatible with PHP >= 8.1; do not depend on a production Chrome/Node binary. Existing SF2 uses complex print CSS, so allow a dedicated supported PDF layout sharing its data model if required.
- [ ] Verify the candidate's current official documentation, runtime requirements, licensing, and security advisories before installation. Record the selected version/layout in the focused export design; the feature is incomplete until a real PDF is returned.
- [ ] Write failing response tests: `%PDF-` signature, MIME and attachment headers, safe filename, exact year/section/month, unauthorized direct URL denied, and no redirect to HTML. Run `php tests/report_pdf_download_test.php` before implementation; expect failure.
- [ ] Add distinct buttons and shared data loading. Use local assets, retain `school_logo.png`, disable remote resource loading, bound report size, and never accept arbitrary file/URL input. Keep legacy `export_pdf.php` authorized while making its download behavior real.
- [ ] Run the new test and `php tests/pdf_export_access_test.php`; expect pass. Render and visually inspect one-page/multipage SF2 and SF4, long names, empty rosters, and school-year headers. Check grid alignment, symbols, totals, logo, signatures, and page breaks. Browser print and downloaded PDF must show the same records and totals.

### Task 10: Build private backup creation and scheduled worker

**Files:** Create `includes/database_backups.php`, `migrations/database_backups.php`, `tools/backup_database.php`, `tests/database_backups_test.php`, `docs/DATABASE_BACKUPS.md`; modify `config/hosting_environment.php`, `tools/hostinger/hosting.example.php`, both schema import files, and deployment packaging rules if needed.

**Interfaces:** `createDatabaseBackup(PDO $db, array $options, callable $dumpRunner): array` returns backup metadata. `runBackupRetention(PDO $db, DateTimeImmutable $now): array` returns deleted/skipped IDs. CLI supports scheduled execution and a dry-run inventory without exposing credentials. Backup metadata includes opaque ID, creator/kind, creation time, relative private path, bytes, SHA-256, status, and retention expiry.

- [ ] Add failing tests for full-database scope, Manila date grouping across a UTC boundary, simultaneous jobs, failed/truncated dump, checksum, completion publication, and path confinement.
- [ ] Run `php tests/database_backups_test.php`; expect failure before implementing a private configured backup root, temp-file creation, consistent export, metadata, and locking. Publish as completed only after the dump succeeds and validation/checksum complete.
- [ ] **Proposed:** use the host's `mysqldump`/`mariadb-dump` with a private credentials file and an argument-safe process invocation. Include all tables/data, indexes, foreign keys, audit triggers, and routines/events if used; do not broaden web-account grants. Verify binary availability, process execution support, grants, and storage before choosing the export adapter. If unsupported, document the hosting blocker and select a verified host export mechanism; do not substitute an untested partial PDO exporter.
- [ ] Store complete dumps for all school years under `YYYY/MM/DD/<opaque-id>.sql.gz`, based on backup creation time. Compress, restrict filesystem access, and keep files outside document root and deployment ZIPs. Grouping by year is not a school-year filter.
- [ ] **Proposed:** daily run at **02:00 Asia/Manila**; convert correctly if host cron uses another timezone. Use Windows Task Scheduler locally and Hostinger cron in hosting. Backups run without a browser session; HTTP access to the CLI script is denied.
- [ ] Run tests for the latest 30 daily dates, 12 monthly snapshots, retained manual copies with expiry, and protected-copy collisions. **Proposed:** first successful daily backup of each calendar month is protected until its monthly expiry; other daily/manual copies expire after 30 days unless explicitly retained. Never expire the last verified recovery copy solely because newer backups failed.
- [ ] Restore a synthetic completed dump into an isolated database and compare tables, counts, foreign keys/indexes, triggers, and encrypted-history readability. Rerun backup tests for pass. Document separate preservation of uploads and original encryption keys, plus the private off-server copy process.

### Task 11: Add the administrator backup page and authenticated downloads

**Files:** Create `backups/index.php`, `backups/create.php`, `backups/download.php`, `tests/backup_access_test.php`; modify `includes/sidebar.php`, `includes/database_backups.php`, `docs/DATABASE_BACKUPS.md`.

**Interfaces:** Administrator POST creates a durable manual backup job; the CLI worker from task 10 claims it. Downloads take an opaque backup ID and resolve its stored path on the server. Never accept a filesystem path supplied by the browser.

- [ ] Write failing tests for unauthenticated/teacher/scanner denial; CSRF on manual creation/retention changes; guessed ID, path traversal/symlink escape, pending/failed/missing file, and expired session denial. Test that a successful backup download returns the intended completed file with attachment/no-store headers.
- [ ] Run `php tests/backup_access_test.php`; expect failure before implementing the admin page, queued jobs, access checks, canonical path containment, and audit events. Do not expose credentials, private paths, or download URLs publicly.
- [ ] Display folders by year/month/date, daily/manual/monthly labels, checksum, size, status, retention expiry, last successful run, and failures. Add **Create Backup** and **Download**. Long-running dumps execute in the worker rather than an HTTP request.
- [ ] Test manual retention with an explicit expiry. Keep rotation limited to known backup files inside the configured root; remove no live school records. Do not add a web restore button in this scope.
- [ ] Rerun access and backup tests for pass; inspect admin creation → queued → completed → download, plus failed worker follow-up. Verify public URL requests cannot fetch the private dump.

### Task 12: Integrated acceptance, migration rehearsal, and staged rollout

**Files:** Create `docs/PANEL_REVISIONS_ACCEPTANCE.md`; update `README.md`, `docs/HOSTINGER_DEPLOYMENT.md`, `docs/DEPLOYMENT_SECURITY.md`, `docs/DATABASE_BACKUPS.md` with final behavior and commands only after implementation.

**Interfaces:** Produces a checked request-to-evidence matrix, migration order, worker schedule/runbook, and rollback boundaries for each release.

- [ ] Rehearse all additive migrations on both supported fresh-install schema imports and a populated synthetic legacy fixture. Test reruns; do not use automatic schema changes during ordinary page loads.
- [ ] Run new task-owned tests and impacted existing enrollment, access, SMS, audit, finalizer, and PDF tests. Lint each changed PHP file with `php -l <file>`. Record commands and results; distinguish unit/integration verification from live deployment evidence.
- [ ] Exercise the end-to-end scenario: school-wide online date → authorized section override → pending modular learner → submission credited to learning date → Excused absence → third-unexcused warning → fifth-unexcused stubbed intervention → correction → monthly daily drilldown → Print/PDF → private backup/restore. Check teacher/admin/scanner views separately.
- [ ] Verify the complete release before migration; preserve a recovery copy, drain affected writes/workers, apply schema, deploy readers/writers together, and restart converted workers. Keep intervention sending disabled during backfill and dry-run review.
- [ ] Document rollback per subsystem: before new-format writes, restore prior code/config with additive schema retained; after new-format writes, use a reviewed reconciliation or verified restore with an explicit data-loss boundary. Never claim a feature flag alone reverses committed decisions or sent SMS.
- [ ] Mark requests 1–9 and Added complete only when their acceptance evidence exists. Keep item 10 and the logo regression checks. Record deployment-specific prerequisites honestly, especially SMS activation, current policy applicability, PDF layout, and off-server recovery copies.

## Policy Sources and Verification Limits

Checked on 2026-10-25:

- [DepEd school-form data descriptions, SF2 item 18](https://catanduanes.deped.gov.ph/files/School-Forms-Data-Field-Descriptions.pdf), pages 3–4: five consecutive absence days are an indicator supporting home visitation. This supports an intervention indicator, not a universal automatic “critical” classification or an SMS requirement.
- [DepEd Order 11, s. 2011](https://www.deped.gov.ph/2011/02/04/do-11-s-2011-amendments-to-the-2010-revised-manual-of-regulations-for-private-schools-in-basic-education/) discusses a percentage-based attendance rule for private schools. Do not generalize its scope to this school's proposed five-unexcused-day threshold.
- A potentially relevant newer [DepEd Order 015, s. 2026 official page](https://www.deped.gov.ph/2026/06/04/june-4-2026-do-015-s-2026-revised-guidelines-on-classroom-assessment-grading-system-and-awards-and-recognition-for-the-k-to-12-basic-education-program/) was identified during research, but the official page/PDF returned HTTP 403. Its attendance provisions and effect on earlier orders were **not verified**. Do not rely solely on DO 8, s. 2015 or secondary summaries as a statement of current rules.

**Conclusion for implementation:** retain 3/5 as the previously accepted school intervention proposal, clearly labeled. The research supports distinguishing it from the official five-consecutive-day form indicator; it does not establish a universal DepEd five-absence critical-status rule. Current applicability must be confirmed from accessible official issuances and the school's authorized policy before adding any compliance claim. No automatic failure, dropout, or graduation decisions are part of this plan.

## Definition of Done

All pending requests have working role-appropriate flows, meaningful passing checks, and acceptance evidence. Calendar, manual attendance, streaks, analytics, and reports reconcile against the same dated enrollment and classification rules. Print and Download PDF are separate functioning actions. Backups are complete, private, restorable, rotated correctly, and available only to administrators. School policy and verified official guidance are clearly distinguished.
