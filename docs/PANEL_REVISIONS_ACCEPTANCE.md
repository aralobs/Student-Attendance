# Panel revisions acceptance record

Date: 25 October 2026 (Asia/Manila). This record describes code and development verification in the checkout, not a completed live deployment.

Follow-up audit, 26 October 2026: the saved delivery schedule is now displayed in School Calendar's Today panel and date cells, including mobile labels. The [current request-to-evidence audit](PANEL_REVISIONS_AUDIT_2026-10-26.md) records fresh checks, repaired test fixtures, and the remaining operational verification items.

Local follow-up, 26 October 2026: the requested XAMPP migration, worker schedules, private backup storage, and intervention activation were configured and verified. See [the separate local runtime record](LOCAL_RUNTIME_SETUP.md) for actual results and operational limits. The development record below remains dated to its original verification; it does not establish remote deployment or provider delivery.

User revision, 26 October 2026: restored the original Analytics dashboard and removed the switch to the added monthly/daily breakdown view. The existing dashboard's charts and school-year access rules are retained. The original analytics implementation entry below records the earlier version and is superseded by this request.

Further Analytics revision, 26 October 2026: removed the duplicate Today's Log and its polling; top cards now use the selected calendar year. Existing charts remain. The monthly Absent tooltip retains count/percentage and appends one top recorded reason category, with `Not recorded` when none exists. Reasons are grouped into Sickness, Family reasons, Transportation, Weather, Personal reasons, or Other recorded reason; raw private notes are not sent to the chart. Categories use the same authorized absence rows and selected month/year. Known generated notes and revised unexcused correction notes are excluded. Synthetic tests cover encrypted reasons, teacher scope, month isolation, Online/Modular present/absent decisions, pending participation, and the actual emitted tooltip callbacks.

| Request | Implemented behavior | Main evidence / remaining operational check |
| --- | --- | --- |
| 1 | Logs navigation/page title; audit functionality retained | Existing security-audit/header checks |
| 2–3 | Monthly overview, explicit units/statuses/rates, school-year/section filters, daily drilldown, delivery modes | `attendance_analytics_test.php`, `panel_workflow_test.php` |
| 4 | Full dated roster; separate Manual Entry; scan-preserving decisions; encrypted Excused/correction reasons; stale-edit checks; reviewed assignment corrections include decisions; existing daily absence SMS preserved with dated duplicate protection | `attendance_decisions_test.php`, `panel_corrections_test.php`, `manual_absence_notification_test.php`, existing enrollment/access checks |
| 5 | School-wide delivery ranges, teacher/admin section overrides, modular deadlines, precedence and no-class protection | `delivery_modes_test.php`, `delivery_finalization_test.php`, `calendar_protection_test.php` |
| 6 | Warning at three counted unexcused absences; annual streaks, unresolved barriers, dashboard/Attendance warning and intervention list | `absence_streaks_test.php`, scheduled worker required on target host |
| 7 | Separate intervention template; one durable reservation per corrected episode lineage; failed/unknown delivery follow-up | `absence_intervention_sms_test.php`, `intervention_claim_race_test.php`, `absence_intervention_concurrency_test.php`; enable time/provider review still required |
| 8 | School policy is distinguished from official five-consecutive-absence-day form indicator | `ATTENDANCE_INTERVENTION_POLICY.md`; current national/division applicability remains unverified because the newer official source was inaccessible |
| 9 | Independent Print and real PDF attachments for SF2/SF4; legacy export URL returns PDF | `report_pdf_download_test.php`, `pdf_export_access_test.php`, copied-app workflow and rendered synthetic form review |
| Added | Daily/manual complete-database export, Manila folders, rotation, private metadata/downloads, explicit retention expiry | `database_backups_test.php`, `backup_access_test.php`, `backup_restore_test.php`; target private ACLs, cron, grants and off-server copy still require operator verification |
| Existing 10/11 | Moving up/year selection retained; existing school logo retained | Existing moving-up tests and form visual review |

## Verification record

The initial broad PHP run passed all 62 test files then present. The final broad run passed **71/71 PHP test files**, including real backup/restore, copied-page workflow, daily absence notices, reviewed-decision correction, historical-calendar protection, end-of-day decision race, PDF layout, and SMS race/concurrency tests. Three JavaScript checks passed. The Python PDF pagination verifier also passed: long names/remarks, all 24 learner rows across four physical pages, correct footers, and one-page empty forms. Test databases are synthetic and isolated on loopback port 3307; test senders never contact parents.

A fresh read-only reviewer identified four Important issues: stale SMS evaluation/claim, decisions omitted from assignment correction, unprotected historical calendar changes, and the legacy PDF redirect. Each received a reproducing failing test and a focused fix, followed by passing relevant verification. The reviewer did not validate deployment, live delivery, current DepEd applicability, or final visual fidelity; those are tracked separately here. No second review is implied by the author running regression checks after the fixes.

## Review decisions

- Work was done inline in the existing checkout at the user's explicit request, preserving pre-existing edits. No commit, push, school-data migration, production cron change, or real SMS was performed.
- The consolidated implementation plan's “proceed” authorization was used for its proposed defaults. Subsystem implementation uses the existing PHP app rather than introducing a new application/platform.
- Delivery preview/confirmation lives in `calendar/delivery_modes.php`; Manual Entry/Attendance share one view. Tests consolidate access/domain cases where this produces meaningful coverage instead of creating every originally proposed filename.
- Explicit decisions are separate evidence, and official report adapters project them without rewriting original scan rows. Reviewed assignment correction moves their ownership and retains their confirmation/reason history.
- Automatic intervention sending is deliberately conservative: only current-day qualifying crossings are dispatched; late historical confirmations or revised evidence require follow-up. Claims are transactional and abandoned/unknown attempts are not retried.
- Unsupported historical calendar rewrites are rejected rather than modifying recorded/closed-year eligibility. A separately reviewed operator correction is needed for such a change.
- The PDF renderer uses supported tables and shared form data. It preserves the school logo and required data structure; exact browser and PDF page breaks may differ.
- Runtime export uses the host dump client, not a custom partial SQL serializer. Host capability and private ACL verification remain deployment prerequisites. The last completed copy is protected from rotation until a replacement exists.

See [installation and operational steps](PANEL_REVISIONS_DEPLOYMENT.md). Do not mark live installation/notifications/backups enabled until those checks are complete.
