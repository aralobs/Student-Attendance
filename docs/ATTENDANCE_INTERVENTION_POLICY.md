# Attendance intervention policy

Implementation reference for the remaining panel revisions, prepared 25 October 2026 (Asia/Manila).

## School intervention thresholds

The accepted school proposal warns the assigned teacher after three counted unexcused absences in an annual enrollment's streak. At five, it requires intervention and reserves one parent SMS per episode. This is a school intervention policy, not an automatic academic outcome or a claim that five absences universally means critical status under DepEd rules.

Excused absences require a reason and actor, remain absences in reporting, and neither increment nor reset the unexcused streak. Confirmed attendance ends the streak. Holidays and no-class days do not count. Pending or review-needed online/modular participation is not confirmed absence. Streaks do not cross school years.

The implementation plan proposes treating confirmed late/partial attendance as attendance for the full-day streak and blocking evaluation beyond unresolved eligible dates. Historical backfill must not send messages automatically. Corrections preserve episode notification ownership and sent-message history. These implementation defaults belong to the reviewed plan; they were not separately established as national policy.

## Official indicators and limits of verification

The [DepEd school-form data descriptions](https://catanduanes.deped.gov.ph/files/School-Forms-Data-Field-Descriptions.pdf), SF2 item 18 on page 4, identify five consecutive absence days as an indicator supporting home visitation. That indicator must be calculated separately from an unexcused streak that allows excused dates to intervene. The document does not establish the proposed automatic SMS behavior.

[DepEd Order 11, s. 2011](https://www.deped.gov.ph/2011/02/04/do-11-s-2011-amendments-to-the-2010-revised-manual-of-regulations-for-private-schools-in-basic-education/) describes a percentage-based attendance provision for private schools. It is not proof that the school's five-unexcused-absence intervention is a universal DepEd threshold.

A potentially relevant [2026 order on classroom assessment](https://www.deped.gov.ph/2026/06/04/june-4-2026-do-015-s-2026-revised-guidelines-on-classroom-assessment-grading-system-and-awards-and-recognition-for-the-k-to-12-basic-education-program/) was identified in planning, but the official page/PDF returned HTTP 403. Its contents, effective provisions, and relationship to earlier orders remain unverified. Do not rely solely on older orders or secondary summaries to claim current compliance.

Before making an official-compliance claim, the school must establish its school type, applicable current national/division issuances, and authorized local policy. Until then the application must label 3/5 as **School intervention policy**. Attendance indicators never automatically fail, drop, promote, retain, or graduate a learner.

## Notification and deployment boundaries

Intervention messages are distinct from existing arrival/departure, individual absence, and missing-checkout messages. Sending requires a committed eligible record, a durable reservation, a valid parent contact, and enabled production configuration. An ambiguous provider timeout remains unconfirmed and must not be automatically retried as though nothing was sent.

Tests use synthetic records and injected senders. Production activation requires case/template review. No live messages or school-record migrations are authorized by this policy document alone.
