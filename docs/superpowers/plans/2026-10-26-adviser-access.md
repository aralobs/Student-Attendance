# Class Adviser Access Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task by task.

**Goal:** Implement the adviser permission policy approved in chat on 26 October 2026.

**Architecture:** Retain existing section/year/dates authorization. Make learner creation, enrollment and direct profile changes administrator-only. Add an audited, encrypted change-request queue and intervention follow-up records. Apply an administrator-configurable attendance editing window at every manual attendance write entry point.

**Tech Stack:** PHP 8.1+, PDO/MySQL, Bootstrap, existing isolated PHP fixtures.

**Spec:** Approved chat permission table; teachers are class advisers only.

## Constraints and rulings

- Preserve the current dirty checkout; do not commit or move existing revisions.
- Default teacher editing window: seven calendar days before today, inclusive. Administrators can change it to 0–365 days and lock attendance through a date. Administrators retain correction authority within existing enrollment guards.
- Requests describe proposed changes; administrators review and apply them using existing forms, then resolve or reject the request. Approval never automatically edits a learner or attendance record.
- Retain historical authorization by the adviser of each year-specific section and the learner's dated placement. No retrospective reconstruction of unknown adviser assignment dates.
- New schema is additive; migration only, never page-triggered DDL. Tests use disposable databases and stub providers.

## Review focus

- Direct POSTs and forged learner/episode IDs must not bypass section scope.
- A revoked assignment must be rechecked inside the write transaction.
- Closed years remain read-only, including follow-up forms.
- Blank/oversized notes, stale review status and missing CSRF must fail without writes.
- Audit failure must roll back the mutation; requests/follow-ups must send no messages.

## Tasks

- [x] Add failing adviser policy and copied-endpoint regressions; prove current enrollment/profile/correction gaps.
- [x] Restrict learner and enrollment writes; align navigation and add scoped change requests with administrator review.
- [x] Add configurable attendance window and finalization guard to managed and legacy manual writes; align forms.
- [x] Add scoped, encrypted, audited intervention follow-up history and outcomes.
- [x] Run focused checks, full PHP suite and relevant JS checks; review changes and document deployment.

## Execution record

Implementation authorized by the user's “okay implement this”; no additional design approval required.

Independent review identified a legacy manual-entry ownership race. A failing copied-page test reproduced a revoked adviser still writing. Transactional section/adviser and learner-roster locking now reject it; legacy override checks also use the current locked ownership fields. Existing synthetic fixtures were updated for the new administrator-only enrollment/profile policy, required correction reasons and copied dependencies. Initial broad PHP run: 85/90; all five failures investigated (two missing fixture dependencies, analytics setup outside the new teacher window, legacy adapter fixtures, and audit fixtures expecting direct teacher contact edits). Focused repairs pass; final suite follows.

Final verification: 90/90 PHP test files pass; adviser workflow regression passes 59 checks and legacy attendance access passes 28 cases. Nine Node regressions pass. Real headless Chrome passes CSRF forms (23 checks) and scanner XSS (75 checks). Request/review screenshots were visually checked with the application's existing Bootstrap stylesheet cached for QA; the 504px mobile rendering has viewport and document scroll width both 504px. Changed PHP syntax and scoped whitespace checks pass. Independent follow-up review found no remaining material issues. Raw full-suite results: `.qa/adviser-suite-results.json`.

The local additive migration completed and an idempotent rerun also completed. It only creates request/follow-up tables; no learner, enrollment, attendance or notification data was changed by migration. Defaults apply through existing settings storage. Source changes remain uncommitted alongside the user's existing revisions.
