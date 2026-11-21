# Panel revisions audit — 26 October 2026

This audit compares the pending panel plan, its execution ledger, the acceptance record, subsequent Analytics revisions, and the current code. The original plan's unchecked boxes are not a reliable list of unfinished features: later implementation and changed requirements are recorded separately.

## Calendar defect fixed

The local database was checked read-only. Whole-school delivery for **26–29 October 2026 is Modular**, with a deadline of **30 October 2026, 13:00 Manila time**. The School Calendar previously checked only holidays/closures and then displayed “Regular school day.” It never read the delivery schedules.

The Today panel and month cells now read the saved delivery schedules. They display the mode and scope; Today shows modular deadlines, and each month label includes its deadline in the tooltip. Section overrides are labeled explicitly and take priority over the whole-school schedule. Teachers see only their authorized section overrides. Holidays/no-class days suppress delivery labels, and unscheduled eligible days default to face-to-face when the delivery feature is available. Browsing another month does not change Today's status. Older installations without the optional delivery table still render.

An independent review found that the existing mobile CSS would hide these labels. A delivery-specific mobile rule fixes that. Headless Chrome reproduced `display:none` before the fix and verified visible labels afterward at a viewport width of 504px, within the existing mobile breakpoint.

## Request-to-evidence record

Month Entries follow-up, 26 October: the selected-month list now combines school events with all authorized saved delivery schedules, ordered by learning date, with a total count, scope and modular deadline. Saved schedules on holidays/no-class dates or unmarked weekends remain listed as **Not applied**; they do not reappear as active delivery in the grid or Today panel. Existing event deletion remains protected. The rendered calendar regression passes **47 checks**, including delivery-only months, teacher scope, suppressed schedules and month isolation. Modal, delivery-mode, calendar-protection, current-read and JavaScript editor checks also pass; the focused review found no material issues.

Subsequent accepted change, 26 October: **Add Entry now combines School event and Class delivery in one modal.** Fields switch with the category; delivery includes scope, date range, mode, modular deadline and preview/confirmation. Admins manage events and school-wide schedules; teachers can schedule only their assigned sections. The old delivery page retains its scheduled-date list and opens the shared modal for changes. No storage migration is required. See the [implementation record](superpowers/plans/2026-10-26-unified-calendar-entry.md).

The combined-modal server regression passes 33 checks. JavaScript tests cover preview invalidation and delayed event lookups, and a real Chrome/Bootstrap DOM exercise passed. The full follow-up PHP suite passed **75/75 files**. Existing event editing/saving remains locked until the original type and description load, preventing partial overwrites; the final focused review found no remaining material issues.

Scanner follow-up, 26 October: **Start, manual/barcode input and Record now disable unless the user has an available face-to-face section.** Managed Online/Modular dates and closures are rejected; the page displays the reason. Section overrides remain authoritative: teachers use their assigned sections, while administrators and scanner operators can scan available face-to-face sections school-wide. Every submitted learner is checked independently on the server, so a face-to-face exception does not admit another section's Online/Modular learners. Expected delivery-policy rejections return a clear forbidden response instead of a generic server error. Disabled JavaScript paths request neither camera access nor a scan submission.

`scanner_delivery_access_test.php` passes 93 checks, including real copied-page controls, direct endpoint rejection without attendance changes/notifications, a successful face-to-face save, mixed scopes, and legacy compatibility. `delivery_current_read_test.php` first reproduced a stale transaction snapshot and now passes four checks across two isolated connections: a newly committed Modular schedule or closure is honored after structural locks are acquired. Transactional delivery/calendar reads now use current locking reads. Legacy installations retain their previous unspecified-date face-to-face defaults and explicit holiday/no-class restrictions. Node checks and a real Chrome DOM exercise passed for disabled controls and suppressed camera/scan requests. Final focused review found no remaining material issues. The final full PHP run passed **77/77 test files**, and all five Node regressions passed. Changed PHP files passed syntax checks and the scoped diff passed whitespace checks. Raw results: `.qa/2026-10-26-scanner-delivery-final.json`.

“Working” below means inspected code and passing synthetic checks, rather than a claim of real provider delivery or remote deployment.

| Request | Current status | Evidence |
| --- | --- | --- |
| 1. Rename Security Audit to Logs | Working; existing audit protections retained | `includes/sidebar.php`, `security_audit.php`; security audit and header-XSS checks |
| 2–3. Analytics labels/monthly overview | Working in the subsequently requested original dashboard layout; duplicate live log removed; selected-year cards and aggregate absence-reason tooltip retained | `analytics/analytics.php`; `analytics_absence_reasons_test.php`, `analytics_tooltip_test.js`, `panel_workflow_test.php`. The earlier replacement daily-breakdown dashboard was intentionally superseded, not forgotten. |
| 4. Full-roster Attendance and Manual Entry | Working; no-scan decisions, Excused reasons, scan history, corrections and dated ownership retained | Attendance decisions, enrollment attendance, panel corrections, manual absence notification and copied-page workflow checks |
| 5. Online/Modular delivery | Scheduling/resolution working; missing School Calendar display fixed in this audit | `delivery_modes_test.php`, `delivery_finalization_test.php`, `calendar_protection_test.php`, new `delivery_calendar_test.php` |
| 6. Three-unexcused-absence warning | Working with unresolved evidence kept out of confirmed streaks | `absence_streaks_test.php`, attendance/intervention workflow |
| 7. Five-unexcused-absence intervention SMS | Reservation, duplicate protection, correction and outcome handling working with synthetic senders; worker registered and last run successful | Intervention SMS, concurrency and claim-race checks. Actual account/sender approval and delivery to a handset still require controlled verification. |
| 8. Policy distinction | School 3/5 policy documented; current official national/division applicability still needs verification | `ATTENDANCE_INTERVENTION_POLICY.md`. No new official-compliance claim is made. |
| 9. Separate Print and PDF download | Working; real PDF bytes, authorization and original form content verified | PDF download/layout/access and copied-page checks; Python pagination and SF2 format checks |
| Added. Database backups | Creation, retention, private downloads and synthetic restore working; queue/daily tasks registered and last run successful | Backup creation/access/restore checks; `LOCAL_RUNTIME_SETUP.md`. Off-machine copies and a recovery exercise for the actual installation remain operational work. |
| Existing 10/11. School years, moving up and logo | Retained and regression-tested | Annual enrollment, schema, cutover, migration, moving-up, report and PDF checks |

## Verification and repairs

Delivery deletion follow-up, 26 October: **Saved delivery entries now have trash buttons in Month Entries and View scheduled dates.** Deletion targets one learning date and scope. Confirmation explains the inherited whole-school schedule or face-to-face default; deleting a whole-school entry preserves section overrides. Holiday/no-class entries remain unchanged. The server rechecks staff permissions, active-year status, schedule changes and affected recorded attendance/decisions before deletion, and records the action in Logs. Teachers can delete only entries for their active assigned sections. This required no migration and development deleted no live school data.

The new deletion regression passes **39 checks**, covering authorization, CSRF, stale confirmations, inheritance, protected history, inactive dates and both rendered interfaces. The full PHP suite passes **78/78 files**; six Node regressions pass, including cancellation, safe errors and no automatic retry after an ambiguous deletion response. A real Chrome DOM check exercised the calendar trash button, preview, inheritance message and cancellation without deletion. Changed PHP syntax and scoped whitespace checks pass. Focused review found no material issues. Raw suite results: `.qa/2026-10-26-delivery-delete-tests.json`. See the [implementation record](superpowers/plans/2026-10-26-delivery-schedule-deletion.md).

The first full PHP run passed **69/74 files**. All five failures were investigated:

- Three enrollment CLI tests passed only the synthetic database name, leaving the CLI to select the application's default port 3306 while their fixture ran on 3307. The shared test helper now passes the fixture host, port, user and password explicitly, with a synthetic database-name guard. Production connection behavior was not changed.
- The copied enrollment report fixture omitted the newer SF2 stylesheet. It now copies the actual stylesheet.
- The staff access fixture omitted newer Analytics/calendar dependencies. It now copies the required source files, preserving its original authorization assertions.

Each repaired test passed on recheck. The new calendar regression first failed on the actual rendered Today panel's incorrect regular-class status and now passes **36 checks**, including closure priority, teacher scope, section overrides, deadline display, defaults, legacy compatibility and no provider calls.

The final full PHP run passed **74/74 test files** after those repairs. All nine changed/new PHP files passed syntax checks, and the relevant diff passed whitespace checks. Raw run results are local ignored QA artifacts under `.qa/2026-10-26-panel-audit-*.json`.

Four Node checks passed: privacy storage cleanup, scanner response handling, SF2 remark saving, and the actual emitted Analytics tooltip callbacks. Python checks passed for PDF pagination/footers, preserved SF2 content, and six local HTTPS SMS transport cases. PDF checks needed read access to the existing local PyMuPDF dependency outside the sandbox's accessible files; no replacement package was installed.

## Local operational check

Read-only Windows Task Scheduler inspection on 26 October confirmed all four tasks present, Ready, with last result **0**: `AttendanceSystem-FinalizeAbsences`, `ASAM-AbsenceInterventions`, `ASAM-BackupQueue`, and `ASAM-DailyBackup`. The daily backup's next run was 27 October at 02:00 Manila time. Task configuration was not changed by this audit.

## What remains

The remaining items are verification/operations, not requests to invent additional features:

1. Confirm a controlled real SMS delivery, including sender/account readiness, when authorized by the school. This audit used synthetic senders and sent no parent messages.
2. Confirm applicable current school/division/national attendance guidance before presenting the school 3/5 rule as official policy.
3. Maintain an off-machine recovery copy with original encryption keys/uploads and perform an isolated recovery exercise for the actual installation. Synthetic database restore tests already pass.

See the [original acceptance record](PANEL_REVISIONS_ACCEPTANCE.md), [local setup record](LOCAL_RUNTIME_SETUP.md), and [backup runbook](DATABASE_BACKUPS.md) for their existing boundaries and operating instructions.
