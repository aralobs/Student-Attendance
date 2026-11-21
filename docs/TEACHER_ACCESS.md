# Class adviser access

The `teacher` account role is for class advisers. Subject teachers do not use this system.

Advisers can view learners, attendance, analytics, reports, QR cards and intervention cases for their authorized sections. Year-specific sections and dated learner placements determine record scope. Historical authorization follows the adviser currently recorded on that year's section; the system does not reconstruct undocumented adviser tenure. Closed school years remain read-only.

Advisers can record attendance and participation, with an explanation required for Excused decisions and corrections. Original scans are preserved by the managed decision workflow. The default manual-entry window includes today and the preceding seven calendar days, using Manila dates. Administrators can change the window to 0–365 days in **Settings → Teacher Attendance Access**. A value of zero permits today only. **Finalize attendance through** blocks adviser manual writes on or before the chosen date. Scanning still follows current enrollment, calendar and delivery rules.

Only administrators can create learner identities, enroll returning learners, directly edit learner/guardian details, approve placement changes, manage accounts/roles/adviser assignments, activate school years, or manage school-wide settings, full Logs and backups. Existing administrator-only calendar, deletion and SMS configuration controls remain in force. Teacher Analytics and Reports links expose the existing scoped views.

## Learner change requests

From a learner profile, choose **Request Change**. The adviser selects learner/guardian details, enrollment/placement or attendance correction, and explains the proposed change. Attendance requests should include the learning date and intended status. **Change Requests** shows up to 200 accessible requests with their status and administrator notes.

Administrators verify the request, apply any appropriate change using the existing profile, enrollment correction or manual-attendance workflow, then mark the request resolved or rejected with a review note. Marking resolved does not automatically change a learner, enrollment or attendance record. A reviewed request cannot be reviewed again. Requests and review notes are encrypted, and mutations require their transactional audit record.

## Intervention follow-ups

The **Attendance Interventions** page allows authorized advisers to append guardian contact notes, scheduled meetings, monitoring, completed follow-up or referral outcomes in the active year. Notes retain their author/time and cannot overwrite previous notes. These outcomes record staff action separately from calculated attendance and absence streaks. Saving a request or follow-up sends no SMS. Relevant notification delivery status remains visible on attendance and intervention pages; unrestricted SMS logs/configuration remain administrator-only.

## Installation

Install the complete source release and run the additive migration using the configured migration account:

```text
php migrations/adviser_permissions.php
```

The combined `migrations/panel_revisions.php` migration and both fresh-install schema imports also include the two new tables. Migration reruns preserve records. Pages never create tables. Grant the runtime account SELECT/INSERT/UPDATE on `learner_change_requests` and SELECT/INSERT on `intervention_followups`; keep schema changes restricted to the migration operator. Existing private encryption keys and audit tables are required.

The editing window and finalization cutoff use existing `system_settings` storage, with no new settings migration. Default policy applies immediately; administrators can adjust it through Settings.
