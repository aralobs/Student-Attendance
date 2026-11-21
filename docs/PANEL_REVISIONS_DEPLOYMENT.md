# Panel revisions: installation and operation

This release adds Logs naming, class delivery schedules, decision-based Manual Entry, full-roster Attendance, intervention monitoring, monthly analytics, direct PDF downloads, and database backups. Existing school-year moving up and `assets/img/school_logo.png` are retained.

## Before enabling the release

Follow [annual enrollment recovery/cutover requirements](SCHOOL_YEAR_ENROLLMENT.md). Verify annual enrollment is initialized on the target installation; preparing a year or adding schema alone is not initialization. Preserve a consistent database export, original encryption keys, and separate uploads, and verify recovery in an isolated database. Never run a test restore over school data.

Use maintenance mode and drain affected web requests and workers before installing the complete source/vendor release and additive migration. The new attendance screens use the annual enrollment model and installed decision tables. Do not deploy only selected entry points. Existing data is not converted into Excused status from free-text remarks.

Install the lockfile dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`; PHP must satisfy the application's PHP >= 8.1 requirement and Dompdf's DOM/mbstring requirements. Use a maintained PHP version. The lockfile selects Dompdf 3.1.6; rendering disables remote resources, embedded PHP, and JavaScript.

Run once with the migration operator's private database binding:

```text
php migrations/panel_revisions.php
```

The complete migration also installs adviser change requests and intervention follow-ups. For an installation that already has the panel tables, use `php migrations/adviser_permissions.php`. See [class adviser access](TEACHER_ACCESS.md) for the editing window, finalization cutoff, review workflow and runtime grants.

This creates delivery modes, attendance decisions/recompute work, separate daily-absence and intervention notification reservations, absence episodes, and backup metadata. It adds the `intervention` SMS log type. Reruns preserve records; migration is CLI-only. It neither initializes annual enrollment nor creates a backup nor sends messages. Individual migration scripts remain available for controlled phased installation. The two fresh-install schema imports include these additions too.

Do not grant schema changes to the runtime web user. Extend its existing SELECT/INSERT/UPDATE permissions to the new tables; the attendance recompute queue also needs DELETE to acknowledge completed work. Keep append-only audit permissions/triggers. Calendar editing retains its existing calendar DELETE privilege. Runtime needs no DELETE on learner records or backup metadata: expired backup metadata is retained.

## Class delivery and attendance

Open **School Calendar → Class Delivery Schedule**. Administrators may preview and confirm school-wide ranges or section overrides; teachers may schedule their assigned sections. A section override wins over the school-wide mode. Holidays/no-class dates remain ineligible. Modular schedules require a Manila-time deadline.

Dates with scan evidence or manual decisions cannot be changed through ordinary delivery scheduling. Ordinary holiday add/delete and Calendarific import also protect recorded dates and closed years. Existing imported calendar entries are preserved. A school administrator must review any needed historical calendar correction separately; this release does not provide an automatic historical calendar rewrite.

Attendance displays the eligible dated roster, including learners without scans. **Manual Entry** preserves date/year/section and records a separate decision. Present/Late/Partial do not require invented timestamps. Online/modular participation requires an accepted participation method. Excused requires a reason. Changing an existing decision or overriding scan-derived status requires a correction reason. Reasons and change history are protected with the original PII key. A stale revision or changed scan rejects the write and requires reload.

Online/modular nonparticipation becomes pending/review-needed, never automatically unexcused. Original scans remain unchanged; QR entry on online/modular dates directs staff to Manual Entry. Existing legacy Manual Entry remains available only when the new annual decision feature is not installed/enabled.

Marking a learner newly unexcused preserves the existing daily absence SMS, using the learning date and the absence template after the decision commits. A dated reservation prevents duplicate sends; repeated saves and Excused decisions do not send. A correction before dispatch cancels the pending notice. The attendance row shows its delivery outcome for follow-up. This daily notice is separate from intervention enablement below.

Reviewed enrollment assignment corrections include manual decision IDs in preview/confirmation, remap decisions along with scans, and queue streak recomputation. Original confirmation time, reason ciphertext, and notification history are retained. Closed years remain closed.

## Warning evaluation and parent SMS

Review [the intervention policy and verification limits](ATTENDANCE_INTERVENTION_POLICY.md). Three/five are school intervention thresholds. They do not automatically fail, drop, or promote a learner.

Schedule this command every five minutes, in addition to the existing attendance finalizer:

```text
php tools/process_absence_interventions.php
```

Test the installed worker first with `--dry-run`. Dry-run evaluates and persists monitoring state but does not dispatch SMS. Evaluation is enrollment-scoped; it reads annual evidence rather than loading a whole-school report per learner. Current enrollments and queued historical corrections are evaluated. Dashboard/Attendance warnings and **Attendance Interventions** show the resulting cases. No browser request sends intervention SMS.

Leave `ATTENDANCE_INTERVENTION_ENABLED_AT` empty until current cases, school policy, parent contacts, provider configuration, and the **School Attendance Intervention** template in Settings have been reviewed. Then set an explicit `YYYY-MM-DD HH:MM:SS` Manila timestamp in the existing private hosting/worker environment. The private hosting loader supports this key. Review access to this private file under the existing key/configuration process.

Automatic dispatch is restricted to a qualifying threshold on the worker's current learning date, at/after enablement. Backfilled or revised decisions that create historical crossings require direct school follow-up. Missing/invalid phone numbers are failed attempts. `sending`, `sent`, `failed`, and `unknown` reservations are not automatically retried. A worker abandoned while sending becomes unknown after 15 minutes, because the provider may already have accepted the request. At-most-one local reservation is not a guarantee of exactly-once provider delivery.

Evaluation and reservation share the structural/student/enrollment locks and one transaction; external I/O follows commit. A subsequent correction can supersede a message already claimed/sent; it cannot unsend a provider request. Recompute preserves notification ownership across corrected episode merges/splits. Check failed/unknown/superseded outcomes in Attendance Interventions; intervention sends also use the existing SMS log integration.

## Analytics and reports

Monthly analytics defaults to the current applicable month, provides school-year/month/section filters, and links daily rows to Attendance. Learner counts, learner-days, present/absent day equivalents, unresolved records, completion coverage, and delivery modes are explicitly labeled. Partial attendance contributes 0.5 day in the operational rate; required official-form formulas remain separately identified.

SF2/SF4 consume decision-aware report sources without rewriting raw scans. Excused decisions remain included in absence totals. Pending/review-needed decisions do not become absent report rows. **Print** opens the browser print dialog; **Download PDF** returns an attachment. The legacy `reports/export_pdf.php` URL now returns the authorized SF2 PDF directly. PDF layout uses supported table-based rendering with the same underlying form data; page breaks can differ from browser printing.

## Database backups

Follow [Database Backups](DATABASE_BACKUPS.md) for private paths, scheduling, rotation, and restore checks. The administrator page queues work; CLI workers create the files. No web restore action is added.

## Release checks and rollback

Use [the acceptance record](PANEL_REVISIONS_ACCEPTANCE.md). Rehearse migration on disposable legacy and fresh-install databases, check role boundaries, and inspect downloaded forms. Do not count a passing development test as evidence that live cron, provider delivery, private directory ACLs, or off-server copies are configured.

Before new decision writes, a maintenance rollback can restore prior code/config while retaining additive tables. Once decisions, ownership corrections, or notifications exist, reverting code alone hides new records from old readers. Use reviewed reconciliation or the verified recovery copy with an explicit outage/data-loss boundary. Preserve post-change data for reconciliation and original keys/uploads. Do not drop the added tables as an automatic rollback.
