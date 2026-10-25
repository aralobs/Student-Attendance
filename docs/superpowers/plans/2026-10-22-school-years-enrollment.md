# School Years and Annual Enrollment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prepare and activate annual enrollments without duplicating student identities or changing the year/section ownership of historical attendance.

**Architecture:** Keep one student profile and add managed school years, annual enrollments, and effective-dated section assignments. Centralize enrollment resolution and authorization, then convert every attendance writer and historical reader before an atomic cutover. Moving up prepares future enrollments; a separate transaction activates the next year.

**Tech Stack:** Existing PHP >= 8.1, PDO MySQL/MariaDB with InnoDB, Bootstrap 5.3.0, existing audit/CSRF/session/encryption helpers, plain PHP test scripts. No additional production framework or package is required.

**Spec:** `docs/superpowers/specs/2026-10-22-school-years-enrollment-design.md` (user approved 2026-10-22).

## Global Constraints

- Keep `students.id`, the unique LRN, profile/contact fields, and QR identity stable.
- One annual enrollment per `(student_id, school_year_id)`, enforced in the DB.
- Exactly one school year can be active after initialization; dates are admin-verified, not inferred from a year label.
- Assignments cannot overlap for one enrollment; keep existing student/date attendance uniqueness.
- Teachers cannot perform moving up or move existing enrollments. Scanner operators gain no staff/report access.
- Backfill does not send parent SMS, create missing absences, invent scan times, or duplicate profiles.
- Closed years are read-only except the separately reviewed correction flow; no automatic deletion of school records.
- Preserve the existing `assets/img/school_logo.png`.
- No product implementation, live migration, real notification, or deployment is authorized by plan creation alone.
- Preserve the substantial pre-existing dirty working tree. At execution, follow using-git-worktrees using the current working state as the baseline, not an old HEAD-only checkout. Do not stash, discard, or sweep unrelated changes into task commits.
- Use the configured private connection/key mechanisms; never print credentials, student data, or raw database exports into tool output or commit them.
- Each task's commit stages only its new work. Where a file already has user edits, inspect/stage the relevant changes deliberately rather than adding whole directories.

## Review Focus

1. Prepared future enrollment must not change current scans, absence finalization, teacher scope, or QR identity (Tasks 6, 7).
2. Double submission and competing admins must not create duplicate annual enrollment or two active years (Tasks 6, 9).
3. A midmonth section change or archived/graduated profile must not erase old report membership or grant access to a new section's attendance (Tasks 3, 8).
4. Ambiguous legacy dates/placements must remain visible for mapping; preflight/backfill must never invent a historical section (Task 4).
5. An adviser change, closed year, or new scan between preview and confirmation must invalidate a stale mutation without partial writes (Tasks 5, 7, 10).

## Execution setup and test conventions

The plan is for the first subproject only. Online/modular scheduling, excused-status rules, three/five absence alerts, new analytics presentation, separate PDF downloads, and scheduled backups remain separate designs in the roadmap. This plan changes existing analytics/report queries only as necessary for correct annual ownership.

- Inspect attached worktrees and the execution skills at execution time. Preserve current tracked and relevant untracked code in the isolated baseline; do not assume commit `17e63a5` contains the working application changes.
- Use `php` (`C:/xampp/php/php.exe` in this workspace). Integration tests create only randomly named `enrollment_test_[0-9a-f]{16}` databases and drop only the exact database they created. Failure to obtain disposable test DB access is a reported prerequisite failure, not a passing test.
- Tests load synthetic profiles and a random test-only PII key, substitute notification callbacks, and assert zero external sends for migration/moving-up/correction operations. Never exercise real parent phone numbers.
- Task 1 provides `withEnrollmentFixture(callable $test): void`, `checkEnrollment(bool $condition, string $message): void`, and `expectEnrollmentError(string $class, callable $operation): void` in `tests/support/enrollment_fixture.php`. `withEnrollmentFixture` calls the test with `$f`, cleans up in `finally`, prints no PII, and exits nonzero on failure.
- Fixture `$f`: `db` is PDO; `admin` is `{id:1,role:'admin'}`; `teacher` is `{id:7,role:'teacher'}`; `otherTeacher` is `{id:8,role:'teacher'}`; `student` is ID 101 (Grade 3); `grade6Student` is ID 106; `currentYear` is ID 1 (`2026-2027`, 2026-06-01 through 2027-03-31); `nextYear` is ID 2 (`2027-2028`, 2027-06-01 through 2028-03-31); section IDs 10/11 are current Grade 3 sections (advisers 7/8), 20 is next Grade 4, 21 is next Grade 3, and 16 is current Grade 6. Enrollment IDs 1001/1006 belong to students 101/106. These dates are test fixtures, not proposed dates for the actual school.
- Every task follows red/green: add the named test with the assertions below, run it and observe the relevant failure, implement the smallest change, rerun and require exit 0. A fatal error caused by a broken harness is not acceptable red evidence. Commit after the focused checks pass; do not claim broader checks passed unless run.
- Plain PHP test scripts use explicit check functions rather than language `assert`, which can be disabled. Add any referenced fixture factories to the same support file; do not invent a second API with different row keys.

## File ownership and interface contracts

| Unit | Files | Responsibility |
| --- | --- | --- |
| Schema | `includes/enrollment_schema.php`, `migrations/school_year_enrollment.php`, both schema imports | Additive/idempotent DDL; no business mutations during an HTTP load. |
| Years | `includes/school_years.php` | Validated dates/labels, planned-year creation, active-year reads, explicit year activation. |
| Enrollment | `includes/enrollment_repository.php`, `includes/enrollment_access.php`, `includes/enrollment_commands.php` | Date-based context/rosters, authorization, annual enrollment and assignment lifecycle. |
| Migration | `includes/enrollment_migration.php`, `migrations/school_year_enrollment.php` | Read-only inventory, explicit mapping, readiness, controlled initial cutover. |
| Moving up | `includes/moving_up.php`, `students/moving_up.php`, `students/save_moving_up.php` | Preview and atomic future enrollment batches. |
| Corrections | `includes/enrollment_corrections.php`, `students/enrollment_corrections.php`, `students/save_enrollment_correction.php` | Preview/confirm history-preserving correction. |
| Reports | `includes/enrollment_reports.php`, existing report/analytics pages | Consistent year/assignment joins and historical membership. |
| UI and adapters | Existing student/section/settings/attendance/QR endpoints | Validate requests and call services; no duplicate enrollment SQL in templates. |

Service `$actor` arrays come from the authenticated session, never POSTed role/actor values. Mutating services require an admin or assigned-teacher actor as appropriate, set the audit context, and revalidate locked records before commit. Internal transaction helpers document caller ownership; a public command owns its transaction and never nests another command's `beginTransaction`.

Common context row: `{student_id,enrollment_id,assignment_id,school_year_id,school_year,year_state,enrollment_state,section_id,grade_level,starts_on,ends_on,version}`. IDs/versions normalize to PHP integers. Dates are inclusive ISO `Y-m-d`; SQL uses bound values and fixed, validated developer-supplied aliases. Mutation lock order is: structural commands first lock `school_year_control` row 1, then school years by ID, sections by ID, profiles by ID, enrollments by ID, assignments by ID, attendance by student/date, and command metadata. Attendance writers take shared year/section locks (`LOCK IN SHARE MODE`), then the same downstream order, and never acquire the control row. Resolve and recheck eligibility after locks; do not upgrade year/section shared locks after locking attendance. Adviser updates participate in section locking. This lets activation wait for scans without a circular lock order.

## Task 1: Add additive schema and isolated migration tests

**Files:** Create `includes/enrollment_schema.php`, `migrations/school_year_enrollment.php`, `tests/support/enrollment_fixture.php`, `tests/enrollment_schema_test.php`; modify `attendance_system.sql`, `config/database_schema_update.sql`.

**Interfaces:** Produce `ensureEnrollmentSchema(PDO $db): void`. Initial migration CLI supports `--schema` only in this task. Table/column names below are shared contracts.

- [x] **Step 1: Add failing schema tests.** Import the existing schema-only baseline into the disposable database, call the real migration twice, and check preserved students/scans, added nullable ownership columns, FK restrictions, duplicate annual enrollment rejection, and duplicate active-year rejection. Core assertions:

```php
withEnrollmentFixture(function (array $f): void {
    ensureEnrollmentSchema($f['db']);
    ensureEnrollmentSchema($f['db']);
    checkEnrollment((int)$f['db']->query('SELECT COUNT(*) FROM students WHERE id=101')->fetchColumn() === 1, 'Identity preserved');
    expectEnrollmentError(PDOException::class, fn() => $f['db']->exec("INSERT INTO student_enrollments (student_id,school_year_id,state,starts_on,ends_on) VALUES (101,1,'active','2026-06-01','2027-03-31')"));
});
```

- [x] **Step 2: Run** `php tests/enrollment_schema_test.php`; expect failure on the missing migration/schema, after the baseline fixture itself imports successfully.
- [x] **Step 3: Implement schema.** `school_year_control` has one fixed row (ID 1) and version for structural-command serialization, including creation of the first year. `school_years`: ID, unique label, starts_on, ends_on, state (`planned|active|closed`), version, generated nullable `active_slot` equal to 1 only when active, with a unique index. `student_enrollments`: ID, student/year FKs, state (`prepared|active|completed|cancelled`), bounded dates, version, unique student/year and `(id,student_id)`. `enrollment_sections`: ID, enrollment/section FKs, grade snapshot, bounded dates, version, unique `(enrollment_id,starts_on)` and `(id,enrollment_id)`; all assignment writers lock their enrollment before checking interval overlap. Add `sections.school_year_id`, `attendance.enrollment_id`, and `attendance.assignment_id` as initially nullable fields. Composite attendance FKs `(enrollment_id,student_id)` and `(assignment_id,enrollment_id)` prevent cross-student/cross-enrollment ownership; converted writers require both ownership fields and readiness flags half-mapped rows. Preserve student/date uniqueness and existing columns.
- [x] **Step 4: Add command metadata tables.** `moving_up_batches` has unique request_key, canonical payload_hash, source/target years, outcome, destination section nullable, actor, timestamps. `moving_up_decisions` has batch/source/destination enrollment links, outcome, state (`prepared|applied|cancelled`), version; enforce one noncancelled decision per source enrollment/target year using a nullable generated uniqueness slot. `enrollment_corrections` stores target enrollment, kind, encrypted proposed command including its reason, preview fingerprint, expected version, state (`pending|applied|cancelled`), actor/timestamps; do not expose raw reasons in general event logs. `enrollment_migration_issues` stores record IDs, reason codes, explicit resolution references; no copied profile/contact data.
- [x] **Step 5: Make DDL retry-safe.** Inspect information_schema before ALTER; MySQL DDL implicitly commits, so record progress and retry safely rather than promise rollback of DDL. Fresh schema imports include final ownership structures; existing-install migration remains additive. Do not copy the destructive fresh-schema import onto a populated installation.
- [x] **Step 6: Run** `php tests/enrollment_schema_test.php`; require success including actual MySQL/MariaDB generated-column/index support. Commit the named files as `feat: add annual enrollment schema`.

## Task 2: Implement school-year validation and audit support

**Files:** Create `includes/school_years.php`, `tests/school_years_test.php`; modify `includes/security_audit.php`.

**Interfaces:** Produce `nextSchoolYearLabel(string $label): string`, `getActiveSchoolYear(PDO $db, bool $lock = false): ?array`, `savePlannedSchoolYear(PDO $db, array $actor, array $input): int`, `enrollmentModelEnabled(PDO $db): bool`. `input` keys: `id` nullable, `label`, `starts_on`, `ends_on`, `expected_version` nullable. Lifecycle activation is Task 9, not a side effect of save.

- [x] **Step 1: Add failing date/year tests**, including impossible dates, reversed ranges, overlaps, duplicate labels, leap day, invalid year labels, stale edits, and teacher/scanner denial. Exact policy example:

```php
checkEnrollment(nextSchoolYearLabel('2026-2027') === '2027-2028', 'Next year is based on source label');
expectEnrollmentError(InvalidArgumentException::class, fn() => nextSchoolYearLabel('2026-2028'));
expectEnrollmentError(DomainException::class, fn() => savePlannedSchoolYear($f['db'], $f['teacher'], ['label'=>'2028-2029','starts_on'=>'2028-06-01','ends_on'=>'2029-03-31']));
```

- [x] **Step 2: Run** `php tests/school_years_test.php`; observe the relevant failure.
- [x] **Step 3: Implement the contracts.** Labels must match consecutive `YYYY-YYYY`; admin-verified date ranges are unique and nonoverlapping, with no fabricated opening/closing dates. Lock competing year updates in consistent order; forbid editing active/closed boundaries through planned-year save. The migration-only bootstrap may initially have no active year; ordinary ready mode requires exactly one. Model version is a DB setting (`enrollment_model_version`, absent/`0` for legacy and `1` for converted mode), never inferred just because a table exists.
- [x] **Step 4: Extend audit snapshots** to include attendance ownership IDs and allow audit target types `school_year`, `enrollment`, and `enroll_correct` (within VARCHAR(16)); preserve all existing accepted types and append-only protections. No audit failure may commit the associated mutation.
- [x] **Step 5: Run** `php tests/school_years_test.php` and `php tests/security_audit_test.php`; require exit 0. Commit as `feat: manage validated school years`.

## Task 3: Add date-based enrollment context, rosters, and authorization

**Files:** Create `includes/enrollment_repository.php`, `includes/enrollment_access.php`, `tests/enrollment_context_test.php`, `tests/enrollment_access_test.php`; modify `includes/student_access.php`, `includes/functions.php` only for adapters.

**Interfaces:** Produce `getEnrollmentContext(PDO $db, int $studentId, string $date, string $purpose = 'read', bool $lock = false): ?array`; `listEnrollmentRoster(PDO $db, int $yearId, int $sectionId, string $from, string $to, array $actor, int $page = 1, int $perPage = 25): array`; `enrollmentSectionScope(string $alias, array $actor, bool $historical = false): array`; `requireEnrollmentWrite(PDO $db, array $actor, int $studentId, string $date): array`.

- [x] **Step 1: Add failing context/access tests.** The roster result is `{rows,total}`; one row per enrollment/student with an `assignments` list clipped to the requested interval. Validate boundaries and count once even if a student returns to the same section. Core assertions:

```php
$ctx = getEnrollmentContext($f['db'], 101, '2026-10-22', 'read');
checkEnrollment($ctx['school_year_id'] === 1 && $ctx['section_id'] === 10, 'Resolve date, not latest placement');
expectEnrollmentError(DomainException::class, fn() => requireEnrollmentWrite($f['db'], $f['otherTeacher'], 101, '2026-10-22'));
```

- [x] **Step 2: Run** `php tests/enrollment_context_test.php` and `php tests/enrollment_access_test.php`; observe relevant failures.
- [x] **Step 3: Implement resolution.** `read` includes completed enrollment/closed years and archived identities for historical reporting; `write` requires active-year active enrollment and an eligible live profile/section; `scan` adds current-operational eligibility (today is validated at the scanner boundary). Cancelled enrollments never resolve. Missing/unmapped data returns null with a separate visible migration-issue count, never falls back to latest placement in model version 1. Detect multiple matching assignments as an integrity error.
- [x] **Step 4: Implement permissions.** Historical access follows the selected section's current adviser assignment, not the student's newest section and not a former adviser's past employment. Reassignment revokes access. Teachers see only permitted assignment ranges in a student history. Admin can view preserved archived history; scanner cannot read staff historical reports. Operational adapters default to the active managed year; explicit historical requests retain their year.
- [x] **Step 5: Run** both new tests plus `php tests/student_access_test.php`; update old fixtures to include enrollment state rather than weaken access assertions. Commit as `feat: resolve annual enrollment and historical access`.

## Task 4: Implement read-only migration inventory and explicit backfill

**Files:** Create `includes/enrollment_migration.php`, `tests/enrollment_migration_test.php`; extend `migrations/school_year_enrollment.php`.

**Interfaces:** Produce `inventoryEnrollmentHistory(PDO $db): array`, `applyEnrollmentMappings(PDO $db, array $actor, array $mapping): array`, `enrollmentMigrationReadiness(PDO $db, int $yearId): array`. Inventory returns `{counts,issues,fingerprint}` using IDs/reason codes. Readiness returns `{ready,blocking_issues,historical_issues,fingerprint}`.

- [x] **Step 1: Add failing migration tests.** Test blank year labels, student/section disagreement, attendance predating current placement, previous-year fields without effective dates, missing section/profile, out-of-year dates, rerun, and new legacy writes after inventory. Assertions:

```php
$inventory = inventoryEnrollmentHistory($f['db']);
checkEnrollment($f['db']->query('SELECT COUNT(*) FROM student_enrollments')->fetchColumn() === $beforeCount, 'Inventory performs no writes');
checkEnrollment(in_array('ambiguous_assignment', array_column($inventory['issues'], 'code'), true), 'Ambiguity remains visible');
```

- [x] **Step 2: Run** `php tests/enrollment_migration_test.php`; observe failure in the new behavior, not DB setup.
- [x] **Step 3: Implement CLI modes** `--inventory`, `--apply-map=<private-json-path> --actor-id=<admin-id>`, and `--readiness=<year-id>`; CLI-only, no HTTP mutation. The privileged CLI operator supplies an attribution ID; query and verify that this user is an active admin rather than trusting a role from JSON. Mapping JSON has `inventory_fingerprint`, verified `school_years`, `sections` year links, and `enrollments` with explicit start/end dates and assignment intervals. Each mapping enrollment/assignment has a stable local `key`; attendance mappings contain `attendance_id` and `assignment_key`, resolved to real IDs inside the transaction. Do not require the operator to guess auto-increment IDs. Validate the complete input before DML. Require migration credentials through existing configuration, use ID-only console summaries, and reject stale inventory. Large inventories are streamed to a private destination rather than dumped to chat.
- [x] **Step 4: Implement mapping application.** Upsert by stable unique keys; never duplicate profiles or rewrite timestamps/scans. Automatic suggestions are allowed, but current section/created_at/previous-year fields alone cannot confirm old ownership or enrollment start. Record unresolved IDs/reasons. Any missing mapping affecting current operations blocks readiness; historical exceptions stay available to admins and visibly qualify affected reports.
- [x] **Step 5: Run** `php tests/enrollment_migration_test.php`; require preserved raw attendance, profile/LRN counts, zero notification calls, and idempotent rerun. Commit as `feat: add enrollment migration inventory and mappings`.

## Task 5: Add visible annual enrollment and year-scoped section management

**Files:** Create `includes/enrollment_commands.php`, `settings/school_years.php`, `settings/save_school_year.php`, `students/enroll.php`, `students/save_enrollment.php`, `tests/enrollment_creation_test.php`; modify `students/add.php`, `students/edit.php`, `students/students.php`, `sections/sections.php`, `sections/save.php`, `settings/settings.php`, `includes/sidebar.php`.

**Interfaces:** Produce `createAnnualEnrollment(PDO $db, array $actor, int $studentId, int $yearId, int $sectionId, string $startsOn, string $endsOn): int` and `updateCurrentEnrollmentProjection(PDO $db, int $studentId): void` (transaction-internal). Current projection uses only the active managed year; no prepared future placement appears on `students`.

- [x] **Step 1: Add failing enrollment/endpoint tests.** New profile plus first enrollment is atomic; a second year reuses the profile; an existing LRN prompts the returning-student flow without leaking an inaccessible student's contact data. Assert teachers cannot POST another section, move an existing enrollment, or retain access after adviser reassignment. Example:

```php
$future = createAnnualEnrollment($f['db'], $f['admin'], 101, 2, 20, '2027-06-01', '2028-03-31');
checkEnrollment($future > 0, 'Future enrollment created');
checkEnrollment((int)$f['db']->query('SELECT section_id FROM students WHERE id=101')->fetchColumn() === 10, 'Current projection untouched');
```

- [x] **Step 2: Run** `php tests/enrollment_creation_test.php`; observe the relevant failure.
- [x] **Step 3: Implement forms and commands.** Year selector defaults to active year; sections filter by that year and actor's scope. Dates default from the selected verified year and remain visible/correctable within its bounds; late entry requires its actual start date. Keep one student profile transaction, photo/QR behavior, and unique-LRN constraints. A returning enrollment is an explicit action, not silent profile duplication. Teacher enrollment creation requires an assigned live section and no existing annual enrollment; moving up remains admin-only.
- [x] **Step 4: Close settings/section bypasses.** Replace free-text setting mutation of `school_year` with managed-year display/link; the general POST allowlist cannot activate a year. Section forms use year IDs and synchronize legacy labels; remove hardcoded `2026-2027` defaults. Forbid changing year/grade on sections referenced by enrollment history; create a distinct section instead. Year creation/activation controls are admin-only; teacher schedule edits retain existing boundaries.
- [x] **Step 5: Run** new test, `php tests/student_access_test.php`, `php tests/settings_post_guard_test.php`, and `php tests/section_schedule_access_test.php`; require exit 0. Commit as `feat: enroll students into managed school years`.

## Task 6: Implement admin-only moving-up preview and prepared batches

**Files:** Create `includes/moving_up.php`, `students/moving_up.php`, `students/save_moving_up.php`, `tests/moving_up_test.php`, `tests/moving_up_concurrency_test.php`; modify `students/students.php`, `students/master_list.php`, `students/bulk_assign_section.php`, `includes/sidebar.php`.

**Interfaces:** Produce `previewMovingUp(PDO $db, array $actor, array $command): array`, `prepareMovingUp(PDO $db, array $actor, array $command, string $requestKey, string $expectedFingerprint): array`. Command keys: `source_year_id`, `source_section_id`, sorted unique `student_ids`, `target_year_id`, `outcome` (`promoted|retained|graduated`), `destination_section_id` nullable. Preview returns `{rows,fingerprint}`; prepare returns `{batch_id,decision_ids,destination_enrollment_ids,replayed}`.

- [x] **Step 1: Add failing batch tests.** Assert promotion Grade 3 -> Grade 4, retention Grade 3 -> Grade 3, Grade 6 graduation -> no destination enrollment; reject graduation below Grade 6, skipped grade, same/earlier target year, wrong-year section, foreign source student, empty/invalid IDs, stale preview, duplicate enrollment, and unauthorized direct POST. Same request key/payload replays; changed payload with the same key conflicts. Example:

```php
$cmd = ['source_year_id'=>1,'source_section_id'=>10,'student_ids'=>[101],'target_year_id'=>2,'outcome'=>'promoted','destination_section_id'=>20];
$preview = previewMovingUp($f['db'], $f['admin'], $cmd);
$saved = prepareMovingUp($f['db'], $f['admin'], $cmd, str_repeat('a', 32), $preview['fingerprint']);
checkEnrollment(count($saved['destination_enrollment_ids']) === 1, 'One prepared enrollment');
checkEnrollment(getEnrollmentContext($f['db'], 101, '2026-10-22')['section_id'] === 10, 'Current attendance remains Grade 3');
```

- [x] **Step 2: Run** `php tests/moving_up_test.php` and `php tests/moving_up_concurrency_test.php`; observe relevant failures. Concurrency harness uses two PDO/process workers and a barrier rather than relying on sleep timing.
- [x] **Step 3: Implement preview and preparation.** Follow the common structural lock order, including source/destination sections before profiles/enrollments; recheck roster/outcomes, versions, session authority, and destination uniqueness. Record a payload-hashed request and decisions atomically; rollback the whole batch on any conflict. Prepared enrollment dates inherit the verified target-year range. Do not modify current section projection, source outcome/state, scans, LRN, or QR. Graduation is a prepared source decision applied only at closure/activation.
- [x] **Step 4: Implement UI and remove bypass.** Source year/section -> checkbox roster -> outcome/target year -> destination -> preview -> CSRF-protected confirmation. Select All explicitly names the filtered roster count and includes the complete source-section selection, not hidden unrelated results. Separate batches handle differing destinations/outcomes. Missing next year shows a create-year action; no invented year. Replace legacy cross-year bulk overwrite with this flow. Same-year placement changes go through Task 10's correction route; reject bypass POSTs meanwhile.
- [x] **Step 5: Run** both tests; assert concurrent requests create one destination and zero external notifications. Commit as `feat: prepare audited moving-up batches`.

## Task 7: Convert attendance writers and current operational access

**Files:** Modify `attendance/scan_process.php`, `includes/attendance_scan.php`, `includes/attendance_finalizer.php`, `attendance/finalize_absences.php`, `attendance/get_today_log.php`, `attendance/manual.php`, `attendance/override.php`, `includes/end_of_day_alerts.php`, `attendance/end_of_day.php`, `attendance/end_of_day_status.php`, `includes/qr_access.php`, `qr_image.php`, `student_photo.php`, `students/generate_qr.php`, `students/bulk_qr.php`, `students/qr_download.php`, `students/archive.php`, `students/delete.php`, `students/restore.php`, `sections/archive.php`, `sections/delete.php`, `sections/restore.php`; create `tests/enrollment_attendance_test.php` and update affected existing fixtures.

**Interfaces:** Consume Tasks 2/3. Preserve `saveAttendanceScan(PDO $db, int $studentId, string $date, array $section, int $recordedBy, ?DateTimeImmutable $scanTime = null): array` for callers, but resolve/lock authoritative context inside it and verify the supplied section matches; callers cannot forge ownership. Existing finalizer public signatures stay intact.

- [x] **Step 1: Add failing behavioral tests.** Prepared next-year sections never scan or receive absences today; dates before entry/after exit are ineligible; ordinary writes to closed years fail; backdated records use their own eligible assignment; newly archived/reassigned students revoke in-flight writes; duplicate scans remain serialized. Example:

```php
$before = $f['db']->query('SELECT am_in FROM attendance WHERE student_id=101 AND date="2026-10-22"')->fetchColumn();
expectEnrollmentError(DomainException::class, fn() => requireEnrollmentWrite($f['db'], $f['otherTeacher'], 101, '2026-10-22'));
checkEnrollment($f['db']->query('SELECT am_in FROM attendance WHERE student_id=101 AND date="2026-10-22"')->fetchColumn() === $before, 'Denied write preserves scan');
```

- [x] **Step 2: Run** `php tests/enrollment_attendance_test.php` and the relevant existing scan/manual/finalizer tests; capture the failures caused by current-placement assumptions.
- [x] **Step 3: Implement ownership writes.** Resolve under enrollment locks before attendance upsert; every created attendance row records enrollment/assignment IDs in the same transaction and audit snapshot. Finalization resolves eligibility separately for each learning date and excludes future/cancelled/closed or unmapped operational enrollment. Preserve two-scan grade schedules, holiday logic, duplicate-scan behavior, existing absence/manual-remark semantics, missing-checkout reservations, and post-commit notification behavior; new excused/modality/streak rules are outside this task.
- [x] **Step 4: Implement access/archive adapters.** Current QR/card issue uses active enrollment scope while retaining the stable QR token. Historical report photos use an explicit authorized historical enrollment context, not an arbitrary student ID. Soft archive blocks new operations but does not delete enrollments or old report membership; restore does not invent or reopen an enrollment. Section archive checks active enrollments rather than a profile cache. Lock/revalidate permissions in the same transaction as writes and use consistent lock ordering for attendance/finalizer/activation.
- [x] **Step 5: Run** `php tests/enrollment_attendance_test.php`, `php tests/two_scan_attendance_test.php`, `php tests/scan_concurrency_test.php`, `php tests/attendance_edit_access_test.php`, `php tests/finalizer_worker_test.php`, `php tests/end_of_day_alerts_test.php`, `php tests/end_of_day_integration_test.php`, `php tests/today_log_access_test.php`, and `php tests/qr_access_test.php`; require exit 0 with stub notifications. Commit as `feat: bind attendance operations to annual enrollments`.

## Task 8: Preserve year/section ownership in historical reports and profiles

**Files:** Create `includes/enrollment_reports.php`, `tests/enrollment_reports_test.php`; modify `dashboard.php`, `analytics/analytics.php`, `students/view.php`, `students/students.php`, `students/master_list.php`, `students/section_advisers.php`, `students/archived.php`, `reports/daily.php`, `reports/by_student.php`, `reports/by_section.php`, `reports/by_grade.php`, `reports/date_range.php`, `reports/am_pm.php`, `reports/partial.php`, `reports/absence_summary.php`, `reports/export_excel.php`, `reports/export_pdf.php`, `reports/sf2.php`, `reports/sf4.php`, `reports/sf4_print.php`, `includes/sf2_remarks.php`, `reports/save_sf2_remark.php`.

**Interfaces:** Produce `attendanceOwnershipScope(string $attendanceAlias, array $actor, int $yearId, ?int $sectionId = null): array` returning `{sql,params}` with fixed safe joins to enrollment/assignment/section; `loadEnrollmentReport(PDO $db, array $actor, int $yearId, ?int $sectionId, string $from, string $to): array` returning `{roster,attendance,unresolved_count}`. Task 3's roster remains the source of date eligibility, not a second competing roster query.

- [x] **Step 1: Add failing report tests.** Capture synthetic October Grade 3 totals/SF2 before future preparation, after activation, and after midmonth transfer. Expect identical old attendance ownership, correct date-clipped membership, no duplicate student-days, retained/graduated/archived history, cross-year range rejection unless an explicit separate-year selection is made, teacher-scope isolation, and visible admin unresolved counts. Test the real report data loaders and representative rendered SF2/SF4/export routes, not only source-string matching.

```php
$old = loadEnrollmentReport($f['db'], $f['admin'], 1, 10, '2026-10-01', '2026-10-31');
checkEnrollment(count($old['roster']) === 1, 'Historical source roster includes the learner');
checkEnrollment($old['attendance'][0]['assignment_id'] === $expectedSourceAssignment, 'Original assignment owns old scan');
```

- [x] **Step 2: Run** `php tests/enrollment_reports_test.php`; observe current-placement leakage before conversion.
- [x] **Step 3: Convert loaders and filters.** Join attendance through its ownership references, use annual enrollment/dated assignments for roster denominators, default operational lists/dashboard to active year, and show selected historical year labels rather than global settings. Archived/current-profile flags cannot hide lawful historical report rows. Unknown ownership is visibly qualified, never imputed into a selected section. Preserve existing formulas and form layout; revised analytics metrics and direct PDF generation are later scope.
- [x] **Step 4: Preserve report remarks/access.** Read SF2 remarks using historical section/month membership. Old section remarks remain attached to their original report; no automatic move/re-encryption of remarks to another section. Ordinary closed-year remark saves fail; authorized reviewed corrections are Task 10. Profile summary/recent attendance are filtered to the actor's permitted enrollments, so viewing an old profile cannot expose its new teacher's data.
- [x] **Step 5: Run** `php tests/enrollment_reports_test.php`, `php tests/student_access_test.php`, `php tests/pdf_export_access_test.php`, `php tests/pii_storage_test.php`, `php tests/staff_workflow_access_test.php`, and `node --test tests/sf2_remarks_ui_test.js`; require exit 0. Commit as `fix: preserve historical attendance ownership in reports`.

## Task 9: Implement atomic year activation and readiness UI

**Files:** Extend `includes/school_years.php`, `settings/school_years.php`; consume existing `includes/enrollment_migration.php` readiness (modify only if required); create `settings/activate_school_year.php`, `tests/school_year_activation_test.php`, `tests/school_year_activation_concurrency_test.php`.

**Interfaces:** Produce `schoolYearActivationPreview(PDO $db, array $actor, int $targetYearId): array` returning `{ready,blocking_issues,follow_up,counts,fingerprint}`; `activateSchoolYear(PDO $db, array $actor, int $targetYearId, string $expectedFingerprint): void`. It consumes readiness from Task 4 and the projection updater from Task 5.

- [x] **Step 1: Add failing activation tests.** Assert source year closes and next year activates atomically; only planned next-year prepared enrollments activate, graduating students get no new enrollment, unspecified outcomes are preserved for admin follow-up, future operational dates remain ineligible, and a failure halfway rolls everything back. Two competing activations leave one active year; stale preview and blocking migration issues reject the operation.

```php
$preview = schoolYearActivationPreview($f['db'], $f['admin'], 2);
activateSchoolYear($f['db'], $f['admin'], 2, $preview['fingerprint']);
checkEnrollment(getActiveSchoolYear($f['db'])['id'] === 2, 'One active target year');
checkEnrollment(getEnrollmentContext($f['db'], 101, '2026-10-22', 'read')['school_year_id'] === 1, 'Old attendance context preserved');
```

- [x] **Step 2: Run** both new activation test scripts; observe relevant failures.
- [x] **Step 3: Implement lifecycle transaction.** Lock year rows and affected enrollment/profile rows consistently with scan/finalizer locks, recompute readiness/fingerprint, close outgoing year and its noncancelled enrollments, apply source decisions, activate target prepared enrollments, synchronize current projections and legacy setting, and audit all changes. Source records without destinations remain historical and get explicit follow-up counts. Do not auto-enroll, drop, or send SMS. Administrative activation may precede the first school day; operational eligibility still requires an in-range date.
- [x] **Step 4: Implement admin readiness/confirmation page.** Display date bounds, counts for each outcome, unresolved mappings, unassigned students, and affected active year. Require CSRF and a fresh preview fingerprint; return conflict on stale state, not partial success.
- [x] **Step 5: Run** both activation tests and `php tests/enrollment_attendance_test.php`; require transactional rollback, concurrency uniqueness, and zero lifecycle notifications. Commit as `feat: activate prepared school-year enrollments atomically`.

## Task 10: Add reviewed, history-preserving enrollment correction

**Files:** Create `includes/enrollment_corrections.php`, `students/enrollment_corrections.php`, `students/save_enrollment_correction.php`, `tests/enrollment_corrections_test.php`; modify `students/edit.php`, `students/bulk_assign_section.php`, `students/moving_up.php` and `includes/sidebar.php` for entry links.

**Interfaces:** Produce `revisePreparedEnrollment(PDO $db, array $actor, int $enrollmentId, array $change, int $expectedVersion): void`; `previewEnrollmentCorrection(PDO $db, array $actor, int $enrollmentId, array $change): array` returning `{correction_id,affected_attendance_ids,fingerprint}`; `applyEnrollmentCorrection(PDO $db, array $actor, int $correctionId, string $expectedFingerprint): void`. Change keys: `kind` (`reassign|cancel|historical_assignment|sf2_remark`), `reason`, bounded assignment details or remark report coordinates as applicable.

- [x] **Step 1: Add failing correction tests.** Prepared change/cancel succeeds before attendance; a record added concurrently blocks the direct path. Teacher/scanner POST is denied. A preview followed by scan/adviser/assignment/version change conflicts at confirmation. A midyear future reassign preserves prior ownership; explicit historical correction changes only reviewed ownership IDs, never scan times. Closed-year ordinary edits fail while the reviewed exception is audited. Corrected SF2 remarks retain encrypted before/after and optimistic versions.

```php
expectEnrollmentError(DomainException::class, fn() => revisePreparedEnrollment($f['db'], $f['admin'], 1001, ['kind'=>'cancel','reason'=>'Wrong selection'], 1));
$preview = previewEnrollmentCorrection($f['db'], $f['admin'], 1001, $reviewedChange);
checkEnrollment($preview['affected_attendance_ids'] === $explicitAffectedIds, 'Review names exact affected records');
```

- [x] **Step 2: Run** `php tests/enrollment_corrections_test.php`; observe the missing correction behavior.
- [x] **Step 3: Implement two correction paths.** Fast path accepts prepared enrollments with no attendance and updates linked moving-up decisions/projection safely; cancellation keeps the annual row so re-enrollment reuses it. Reviewed path persists an encrypted command/reason, then locks/revalidates all affected records, dates, grade/year constraints, ownership and versions on confirmation. Split effective assignment intervals without overlap. Preserve original before/after in encrypted audit history; do not change student identity, scan timestamps, or parent-notification history, and never resend retrospective SMS. No implicit reopening of a closed year.
- [x] **Step 4: Implement admin preview/confirm UI** showing effective dates and exact impacts, requiring a reason and a second explicit action by the same admin; a second administrator is not required. All legacy placement controls route here. Profile Edit remains for profile fields; it cannot directly POST a placement change.
- [x] **Step 5: Run** `php tests/enrollment_corrections_test.php`, `php tests/enrollment_reports_test.php`, and `php tests/security_audit_test.php`; require exit 0, zero notification calls, intact scan values, and audit records for ownership/remark changes. Commit as `feat: review and audit enrollment corrections`.

## Task 11: Finish cutover tooling, recovery instructions, and workflow proof

**Files:** Extend `includes/enrollment_migration.php`, `migrations/school_year_enrollment.php`; create `tests/enrollment_cutover_test.php`, `tests/enrollment_workflow_test.php`, `docs/SCHOOL_YEAR_ENROLLMENT.md`; update `README.md`, `docs/HOSTINGER_DEPLOYMENT.md`, and `docs/DEPLOYMENT_SECURITY.md`.

**Interfaces:** Produce `initializeEnrollmentModel(PDO $db, array $actor, int $activeYearId, string $expectedFingerprint): void`. CLI adds `--initialize=<year-id> --fingerprint=<hash> --actor-id=<admin-id>` with Task 4's verified CLI attribution for the initial managed-year cutover only. Later year changes always use Task 9, never initialization.

- [x] **Step 1: Add failing end-to-end/cutover tests.** Synthetic legacy import -> inventory -> explicit map -> readiness -> initial model version 1 -> prepare promotion/retention/graduation -> next-year activation -> historical report check -> correction. Reject unresolved current mappings, stale inventory, repeat initialization to another year, and unauthorized initialization. Verify the converted worker cannot select its legacy branch after the model switch; stopping/draining an already-running old build is an operator precondition, not something new code can retroactively enforce. Verify historical ambiguity remains visibly qualified instead of silently lost.
- [x] **Step 2: Run** `php tests/enrollment_cutover_test.php` and `php tests/enrollment_workflow_test.php`; observe relevant failures.
- [x] **Step 3: Implement bootstrap transaction.** Initialization requires no existing enabled model, locks managed-year/enrollment readiness state, sets exactly one active year with its explicit verified dates, synchronizes projections/settings, and flips model version only after all writers/readers are installed and ready. Rerun for the identical initialized model is harmless; attempting another initial year conflicts. An installed but disabled partial build cannot enable annual workflows in production accidentally.
- [x] **Step 4: Document operator sequence.** Preserve/checksum a complete pre-change DB export including triggers; restore it to an isolated database with original keys and verify representative encrypted history/remarks. Preserve photos/uploads separately. Record recovery verification without secrets. Enter maintenance, stop the existing finalizer, install the complete code, apply additive schema, rerun inventory against the latest DB, apply verified mappings, review unresolved exceptions, initialize, verify scans/reports with designated synthetic data, then restart only the converted worker and exit maintenance. Do not run a real school's export or migration merely to complete development tests.
- [x] **Step 5: Document rollback boundaries and grants.** Before new-model writes, revert the code/model switch in maintenance while retaining additive data. After new writes, reconcile them or restore the verified snapshot with an explicit outage/data-loss assessment; never silently drop them. Document SELECT/INSERT/UPDATE privileges for new state tables, no changes to existing append-only history grants/triggers, and no HTTP access to migrations. No purge of school records is introduced.
- [x] **Step 6: Run** both new tests and all task-owned enrollment/year/moving-up test scripts. Re-run the existing focused student/attendance/access/scan/finalizer/end-of-day/report/QR/security checks listed in Tasks 2, 5, 7, and 8 after integration. Every required script must exit 0; report unavailable DB/browser prerequisites honestly. Lint each changed PHP file with `php -l <file>` using an explicit changed-file list. No live provider calls.
- [x] **Step 7: Verify the real UI** in a synthetic local test installation: admin adds/chooses year, creates same-name next-year sections, enrolls an existing student, selects all source students, previews each outcome, sees upcoming roster without changing today's attendance, activates atomically, opens old SF2/SF4, and corrects a mistake. Repeat denied teacher/scanner paths. Use the available browser tooling skill at execution; record meaningful screenshots/results without real student PII. Check 25-row pagination and query plans for year/section/date indexes on representative fixture volume rather than claiming performance from schema alone.
- [ ] **Step 8: Commit only the integration/docs changes** as `feat: complete annual enrollment cutover and recovery guide`. Review the whole branch against the approved spec, report test results and remaining deployment prerequisites, and do not deploy or modify the live school database as part of a code-completion claim.

## Coverage and handoff

| Approved requirement | Owning tasks |
| --- | --- |
| One identity/LRN/QR; yearly enrollment uniqueness | 1, 3, 5, 6 |
| Verified year dates, section year, visible enrollment year | 2, 5 |
| Admin-only multiselect moving up; three outcomes | 6 |
| Upcoming preparation and separate atomic activation | 6, 7, 9 |
| Historical sections, reports, teacher scope | 3, 7, 8 |
| Prepared cancellation and reviewed correction | 10 |
| Preserved audit/CSRF/session controls | 2, 5, 6, 7, 9, 10 |
| Idempotent mapping, ambiguity visibility, recovery | 1, 4, 11 |
| Closed-year read-only, no automatic record deletion | 3, 7, 8, 10, 11 |

Execution record: Tasks 1–11 are implemented and have passed their independent task reviews. The complete required regression inventory, representative-volume proof, browser workflow, recovery proof and changed-PHP syntax checks passed. The final whole-branch review remains the release gate; no live school migration or deployment has been performed.

**Chosen execution:** User-approved subagent-driven implementation with an independent implementer/reviewer per task, followed by one independent whole-branch review. The original working checkout is preserved; implementation is on `codex/school-year-enrollment` in `.worktrees/school-year-enrollment`.
