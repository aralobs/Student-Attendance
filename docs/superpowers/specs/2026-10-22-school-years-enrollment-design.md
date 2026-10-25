# School years, annual enrollment, and moving up

Date: 2026-10-22 (Asia/Manila)
Status: Approved by the user on 2026-10-22; implementation planning authorized.
Scope: First architectural subproject of the panel revision roadmap.

## Intent and success criteria

An administrator can prepare the next school year's roster without duplicating
student identities, changing current-year attendance, or relabeling historical
reports. Teachers continue recording attendance for their assigned current
sections. Historical reports retain the year, grade, section, and attendance
that applied at the time.

The user approved admin-only moving up, multiple selection and Select All,
promotion/retention/graduation, separate next-year activation, and controlled
correction after attendance exists. This design makes the supporting data and
access rules explicit for written review.

## Current evidence

- `students/add.php` copies grade and school year from the selected section into
  the student row; the form does not separately select an enrollment year.
- `students/edit.php` changes that current section/year. Teachers are currently
  prohibited from changing sections, even between sections they advise.
- `students/bulk_assign_section.php` directly overwrites section, grade, and
  school year on selected student rows.
- `attendance_system.sql` stores one current section/year and a few previous
  fields on `students`. These are not a complete annual enrollment ledger.
- Attendance references student/date, and several readers/finalizers join to the
  student's current section. SF2 chooses its roster using the current section.
- `includes/student_access.php` scopes teacher student access through that
  current section. Historical roster permissions need enrollment-aware checks.
- General School Settings and section school-year strings coexist. A setting
  change alone does not establish dates or preserve enrollment history.

This is a code/schema review, not an inspection of live student records. Existing
uncommitted work is the starting point and must remain intact.

## Approaches considered

1. Add visible year selectors while continuing to overwrite student placement.
   Small UI change, but historical sections and rosters remain unreliable.
2. Create separate student rows each year. Simple yearly rosters, but duplicates
   identity/contact information and makes LRN, QR, and history handling fragile.
3. **Recommended: one student identity with annual enrollments and dated section
   assignments.** More coordinated query changes, but preserves identity and
   allows correct attendance, access checks, and historical reports.

## Data responsibilities

### Student identity

Keep `students.id`, the unique LRN, profile/contact fields, and QR identity stable.
Annual promotion does not insert another student profile or issue another LRN.
Keep legacy placement columns temporarily as a compatibility projection, not as
the source of historical truth. Profile editing never silently moves enrollment.

### School years

Add managed school-year records with a unique label, start/end dates, and lifecycle
state: planned, active, or closed. Exactly one can be active. Dates are verified
by the admin; they are not inferred solely from a label such as `2026-2027` or the
server's calendar year. Validate start <= end and disallow ambiguous overlaps.

School Settings displays/manages the active year through this source of truth.
Existing string settings are synchronized for compatibility. Changing a label
or a text setting cannot by itself activate a different year.

### Sections and enrollment

- Each section references one managed school year; its grade belongs to that
  section/year. Reusing a name next year creates a distinct section record.
- One annual enrollment per `(student_id, school_year_id)`, enforced in the DB.
- Enrollment stores the student, school year, enrollment start/end dates, and
  lifecycle state: prepared, active, completed, or cancelled.
- A moving-up decision links its source enrollment to its prepared destination
  when there is one, with outcome promoted, retained, or graduated and actor/time.
- Dated section assignments belong to the annual enrollment. They record grade,
  section, and effective dates. Assignments cannot overlap for one enrollment.
  This preserves earlier section membership after a midyear reassignment.
- A graduation decision applies to the source Grade 6 enrollment and does not
  create a future elementary enrollment. It does not end current attendance
  early while the current school year remains open.

### Attendance ownership

Existing attendance keeps its student and learning date and gains an enrollment
and section-assignment reference. The date must lie within the school year,
enrollment, and assignment. Enforce one daily record for a student/date, keeping
the existing protection against duplicate concurrent scans.

Operational code resolves the eligible active enrollment/assignment for the
learning date. Preparing a future enrollment does not change this resolution.
Reports select roster membership for the requested historical dates, not the
student profile's latest placement. A midmonth transfer includes the student
only on dates belonging to the relevant section.

Streaks and absence eligibility in later subprojects consume enrollment/date
boundaries from this module. Future students must not receive current-year
absence rows. Dates before entry or after exit are not eligible absence days.

## User flows

### Manage school years and sections

Admin creates a planned year with verified dates and creates its sections.
The next-year default increments the selected source year label, looks up the
managed year, and requires confirmation. If it does not exist, ask the admin to
create it; never silently insert an unverified year or choose another section.

### Add or enroll a student

Show School Year (active by default), then sections for that year and the user's
allowed scope. New profiles retain current admin/assigned-teacher creation
permissions. A returning student is selected using an existing profile by LRN;
the system reports existing enrollment instead of duplicating it. Teachers may
enroll only into sections they are authorized to manage. This does not authorize
teachers to move existing enrollments or perform moving up.

### Moving up

Admin selects the source year/section, checks students or Select All, chooses an
outcome and destination year, and selects a valid destination section when
required. Use separate batches for different outcomes/destinations. A preview
shows the actual selected students, source placement, outcome, and destination.
Final confirmation revalidates access, roster, destination year/section, and
duplicates inside a transaction. A conflicting student rejects the batch with
actionable feedback; no partial move or silent overwrite occurs.

Promotion requires the next grade; retention requires the same grade. Graduation
is available only for Grade 6 and requires no destination section. A grade skip
is outside this flow. Recording a moving-up outcome is an administrative action,
not an automated assessment of the learner's academic results.

Prepared enrollments are visible as upcoming and do not count in current daily
attendance or analytics. No attendance SMS is sent by moving-up actions.

### Activate the next school year

Admin reviews a readiness summary covering year dates, section assignments,
prepared enrollments, duplicate/conflicting records, and migration exceptions.
Activation explicitly closes the outgoing year, completes applicable source
enrollments, activates prepared destination enrollments, and updates the current
year/compatibility projections in one transaction. Other planned years remain
planned. A failed activation leaves the old year fully active.

Current operational scans remain restricted to an active enrollment valid today;
activation cannot generate attendance before a year/enrollment start date. Source
records that have no prepared destination are preserved and shown for admin
follow-up, never silently promoted or dropped.

### Correct a mistake

Before attendance references a prepared destination enrollment, admin can change
or cancel it with a reason. Cancellation preserves its audit trail and permits
reinstatement/reuse of the same annual enrollment rather than a duplicate row.

After attendance exists, direct reassignment is blocked. A separate correction
request records the reason and proposed effective dates, previews the affected
records, and requires a second explicit admin confirmation. "Reviewed" means
this review/confirmation step; it does not require a second administrator.
Changing a future effective assignment preserves earlier assignments. A genuine
historical correction explicitly identifies affected attendance, records its
before/after ownership, and keeps the original history. It cannot silently
rewrite scans, remove attendance, or send retrospective SMS. Closed-year
corrections use this exceptional flow; ordinary closed-year editing is blocked.

## Access and integration boundaries

- Server-side admin checks protect year lifecycle, moving up, enrollment moves,
  corrections, and section creation/assignment administration.
- Teachers see and manage records for the sections they are authorized to advise;
  historical access checks use the selected enrollment/assignment rather than
  the student's latest section. Teacher reassignment must revoke unauthorized
  access consistently. Scanner operators gain no staff/report access.
- Centralize year lifecycle and enrollment resolution in focused services used
  by scans, finalization, manual entry, rosters, and reports.
- All changes use existing CSRF protection, transaction conventions, encrypted
  history where applicable, and security audit facilities. User-facing labels
  can say Logs without renaming the audit storage subsystem.
- Cover direct entry points: Add/Edit Student, bulk assignment, archive/restore,
  scans, scheduled finalization, end-of-day review, QR access, dashboard, analytics,
  and report/export endpoints. Removing one UI action is not an access control.
- Index annual enrollment uniqueness, year/section/date roster queries, and
  attendance ownership/date queries. Paginate operational student lists.

## Existing-data transition and rollback

1. Verify a complete database export, including audit triggers, can be restored
   to an isolated database with the original private encryption keys. Preserve
   uploads separately. No live destructive migration starts without recovery.
2. Run a read-only migration preflight that inventories existing school-year
   labels, section/student disagreements, dates, and unassigned attendance.
3. Add schema and idempotent migration tooling. Admin supplies/verifies year
   boundaries. Backfill only associations supported by existing records.
4. Do not assume current placement describes all old attendance. A previous-year
   field alone does not prove historical effective dates. Ambiguous rows remain
   preserved in an admin exception list until an explicit mapping is reviewed.
5. Keep legacy behavior active until enrollment mapping and consumer conversion
   are ready. Switch all affected writers/readers together in a maintenance
   window; do not dual-run an old finalizer against new enrollment rules.
6. Activation/cutover is blocked by unresolved mappings affecting operational
   attendance. Historical reports disclose unresolved historical assignments
   instead of inventing a section or silently omitting those records.
7. Retain legacy columns during this subproject. Before cutover, additive schema
   rollback is possible without dropping historical data. After new writes,
   rollback requires reconciling them or restoring the verified snapshot during
   maintenance; do not claim an old build can understand new annual history.

Backfill does not send parent SMS, create missing absences, invent scan times,
duplicate profiles, or modify unrelated user work.

## Verification and acceptance

- Same student ID/LRN across two annual enrollments; duplicate same-year creation
  is rejected, including concurrent/repeated moving-up submissions.
- Future enrollment leaves current scans, roster, absence finalization, teacher
  scope, and current-year reports unchanged.
- Promotion, retention, and Grade 6 graduation produce the expected records;
  graduation preserves source-year access and attendance until closure.
- Activation is atomic; invalid dates, destinations, and enrollment conflicts
  cannot leave two active years or partially moved students.
- Historical SF2, daily/monthly reports, and exports retain original year/section
  after promotion and effective-dated midyear reassignment.
- Teacher/scanner/direct-POST authorization checks cover enrollment and historical
  paths, including access revoked while a form is open.
- Prepared corrections work without attendance; existing-attendance and closed
  years require the reviewed correction flow and preserve audit/scan history.
- Migration is repeatable, leaves ambiguous history visible for mapping, and
  restores successfully from the verified pre-change copy.
- Run focused existing student-access, staff-access, attendance-edit, scan
  concurrency, finalizer, end-of-day, and report-access checks after integration.
  Test notifications with stubs; no real parent messages during verification.

## Scope boundaries and next step

This subproject supplies enrollment and year ownership. Delivery-mode calendars,
explicit excused/pending classifications, absence-streak policy, automatic SMS,
new analytics presentation, direct PDF download, and scheduled-backup UI are
separate subprojects in the roadmap. The existing school logo stays in use.

The user approved this written design on 2026-10-22. Its implementation plan is
`../plans/2026-10-22-school-years-enrollment.md`. Product implementation starts
only after that plan is reviewed and an execution method is selected.
