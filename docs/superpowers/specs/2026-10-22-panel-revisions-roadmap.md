# Panel revisions: agreed scope and implementation order

Date: 2026-10-22 (Asia/Manila)
Status: User decisions consolidated; product implementation has not started.

## Purpose

Address the panel's revisions while preserving student identity, historical
attendance, teacher access boundaries, and dependable parent notifications.
This document records the agreed scope. Each architectural subproject gets its
own reviewed design and implementation plan before its code is changed.

## Implementation order

| Order | Subproject | Panel items | Reason |
| --- | --- | --- | --- |
| 0 | Verify a recoverable pre-change database export and preserve existing work | Supporting prerequisite | Protect the populated installation before migrations. Do not run migrations or send SMS during design. |
| 1 | School years, annual enrollment, and moving up | 10 | Attendance must remain attached to the correct year and section. First focused design: `2026-10-22-school-years-enrollment-design.md`. |
| 2 | School calendar delivery modes | 5 | Establish when face-to-face, online, or modular attendance applies. |
| 3 | Full-roster attendance visibility and manual entry | 4 | Support participation confirmation and explicit excused absence without requiring scans. |
| 4 | Absence warnings and intervention SMS | 6, 7, 8 | Depend on year boundaries, eligible school days, and confirmed absence classifications. |
| 5 | Monthly/daily analytics and clear labels | 2, 3 | Use the same enrollment, calendar, and attendance meanings as operational screens. |
| 6 | Separate Print and Download PDF | 9 | Provide separate actions in reports currently combining them. |
| 7 | Scheduled database backups and admin downloads | Added request | Automate protection and retention; the pre-change export in step 0 is still required. |
| Small accompanying revision | Rename Security Audit to Logs | 1 | Change user-facing labels while retaining audit functionality. |
| Existing asset retained | School logo | 11 | User selected the existing `assets/img/school_logo.png`; no replacement image is required. |

The order follows data dependencies. The complete scheduled-backup feature does
not need to block all earlier work, but a verified recovery copy is a migration
prerequisite. Each subproject remains independently reviewable.

## Agreed school years and moving up

- Maintain one permanent student profile, student ID, and unique LRN.
- Store separate enrollment records for each school year, with their grade and
  section. Prevent duplicate enrollment for the same student and year.
- Admin manages school-year dates and the active school year.
- Add Student visibly selects a year, defaulting to the active year, and then
  selects a section belonging to that year. Returning students reuse a profile.
- Admin alone performs moving up. Teachers may review assigned rosters.
- Select multiple students from a source section, including Select All.
- Require review of the destination school year, defaulted to the next year.
- Support Promoted, Retained, and Graduated. Promotion/retention require a
  destination section; Grade 6 graduation creates no next elementary enrollment.
- Prepare next-year enrollments while current-year attendance continues. A
  separate admin action activates the next school year.
- Preserve previous enrollment and attendance; closed years are read-only.
- Admin can change/cancel a prepared enrollment before attendance references it.
  Once attendance exists, use a reviewed correction preserving history.

## Agreed class delivery and participation

- The admin schedules the school-wide delivery mode for dates or date ranges
  through the calendar: face-to-face, online, or modular.
- Admin can override any section/date; teachers can override assigned sections
  and dates. An override takes precedence over the school-wide mode.
- Default normal school days to face-to-face. Delivery modes remain distinct
  from holidays and no-class days; scheduling online/modular is not a no-class
  declaration.
- Online participation can be confirmed through a live class, submitted work,
  or another accepted interaction.
- Modular participation can be confirmed through module submission, parent
  contact, or another accepted activity.
- Teachers set modular deadlines for their own sections. Credit participation
  to the assigned learning date rather than the later submission date.
- Unconfirmed modular participation remains pending until its deadline, then
  requires teacher review. Online nonparticipation also requires teacher review.
- Pending/review-needed online and modular records cannot automatically become
  countable absences or trigger absence/intervention SMS.

## Agreed Attendance and Manual Entry improvements

- Attendance is the daily operational page; SF2 is the monthly report. Keep the
  separate Manual Entry page and link it with the selected section/date intact.
- Attendance displays the eligible full roster, including students without scans.
  Distinguish missing records, pending participation, and confirmed absences.
- Manual Entry must permit changes without fabricated arrival/departure scans.
- Add explicit Excused absence with a required reason and recorded actor.
- Preserve scan history and record corrections in Logs.
- Current code already left-joins the full active section roster in Manual
  Entry. Investigate the reported missing-student behavior instead of assuming
  this query alone explains it. Current Attendance lists existing records only.

## Accepted absence policy for this implementation

The user accepted the following proposed school policy for implementation.
Do not describe these settings as a verified universal DepEd critical-status
rule, and do not automatically fail, drop, or graduate a learner based on them.

- At three counted unexcused absences in the current streak: teacher warning.
- At five: intervention required and automatic parent SMS through existing SMS
  integration, once per streak.
- Excused absences remain absences in reports/totals, but do not increment the
  warning/SMS counter and do not reset it. Call this an **unexcused absence
  streak**, since excused days may intervene.
- Holidays and no-class days do not count. Pending online/modular participation
  does not count as confirmed absence.
- A confirmed attendance day ends an absence streak. Scope streaks to the
  relevant annual enrollment; do not carry them across school years.
- Log sending outcomes and surface failed/unconfirmed delivery for follow-up.
  Reserving one notification per streak must precede the external SMS call;
  claiming exactly-once external delivery after a timeout would be incorrect.
- The absence subproject must define treatment of unresolved intervening dates,
  partial days, retrospective corrections, and deployment-time historical
  notifications in its own reviewed design. No additional SMS behavior is
  implicitly approved by this roadmap.

## Agreed analytics and reports

- Improve the existing monthly overview, defaulting to the current month, with
  daily breakdowns inside the selected month.
- Provide school-year, month, and section filters with role-appropriate scope.
- Clarify titles, axes, legends, counts versus percentages, and status wording.
- Make excused absences and pending participation visible separately.
- Identify face-to-face, online, and modular delivery modes.
- Agree metric formulas in the focused analytics design so totals do not
  confuse unique students with student-days or pending participation with absence.
- Print opens a print dialog. Download PDF downloads a report file; it is not a
  database Save Changes action. Required school-form formatting is preserved.

## Agreed database backups and retention

- Automatic daily backups plus admin-created backups and authenticated downloads.
- Each backup contains the complete database across all years, not only the
  date's records. Group by backup creation year/month/day in Asia/Manila time.
- Retain the latest 30 days of daily backups and 12 monthly snapshots. A monthly
  snapshot is protected from ordinary daily rotation until its monthly expiry.
- Manual copies follow normal rotation unless explicitly retained with expiry.
- Database backups do not include uploaded images or external encryption keys.
  Recovery instructions must require the original keys through the established
  private key-management process and preserve uploads separately.
- Store backups privately and make downloads admin-only. Keep a protected copy
  outside the hosting server as part of operations.
- Run automatic backups outside class hours. Exact scheduling and restore
  verification belong in the focused backup design.
- Backup expiration never deletes live student/attendance records. Automatic
  deletion of school records remains disabled until an applicable school
  retention policy is approved. Closed-year read-only access is not deletion.

## Review boundaries

No product code, database data, deployed configuration, backup job, or parent SMS
has been changed by these documents. Existing working-tree changes predate this
planning step and must not be discarded or included in a planning-only commit.

The next review is the focused school-year/enrollment design. After its written
review, create its implementation plan. Later subprojects retain their own design
and plan reviews; approval of this roadmap does not skip those reviews.
