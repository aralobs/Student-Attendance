# Execution ledger — plan: 2026-10-25-pending-panel-revisions.md

## Baseline and workspace

- User requested implementation: “proceed”. Execution is inline; no implementation agents have been dispatched.
- Checkout is an ordinary repository on `main`. Existing modified/untracked files were inventoried and remain preserved. User explicitly selected working in this checkout. Changes remain uncommitted; there is no new feature branch to merge or remove.
- Initial enrollment tests could not reach their default disposable test service at `127.0.0.1:3307`.
- Initialized a separate synthetic MariaDB data directory under ignored `.qa/panel-mariadb`, bound to loopback port 3307. Added an Apache deny file. This is not the existing XAMPP school-data directory or server. No live school database was exported or migrated.
- Baseline after test-service setup: `php tests/enrollment_attendance_test.php` PASS (111 checks); `php tests/enrollment_reports_test.php` PASS (135 checks); `php tests/two_scan_attendance_test.php` PASS; `php tests/attendance_edit_access_test.php` PASS (22 cases); `php tests/pdf_export_access_test.php` PASS (10 cases).
- Added `docs/ATTENDANCE_INTERVENTION_POLICY.md`, retaining the known source-verification limitation. School production cutover and current official policy applicability remain unverified.

## Pre-flight interfaces

| Producer → consumer | Contract / finding |
| --- | --- |
| Task 3 → 4–8 | Delivery resolver must evaluate closures before mode precedence; never let a mode reopen a holiday. |
| Task 4 → 5–8 | Decision classification is shared; preserve original scans and unresolved legacy remarks. |
| Task 5 → 6–8 | Dated annual ownership must be shared by sheet, evaluation, and aggregates. |
| Task 6 → 7 | Episode lineage and durable reservation ownership must survive correction merge/split. |
| Task 8 → 9 | Operational rates must not silently replace required official-form formulas. |
| Task 10 → 11 | Only completed private artifacts are downloadable; worker handles queued creation. |

## Task status

- Task 1: development baseline and policy note prepared. Production recovery/cutover verification remains a deployment prerequisite.
- Task 2: implemented. Logs label, existing audit behavior, security-audit (42) and header-XSS checks passed.
- Tasks 3–5: implemented. Dated delivery preview/confirmation, shared classification, full roster/manual decisions, encrypted reasons, scan/finalizer protection and reviewed ownership corrections. Domain, access, rendered-page, historical-calendar, correction, and end-of-day race checks passed.
- Tasks 6–7: implemented, with live sending disabled by default. School 3/5 streak evaluation, staff warnings, lineage-preserving reservations, concurrent dispatch and unknown-delivery follow-up are verified with synthetic senders. Evaluation/claim race regression reproduced the defect before the fix and passes afterward.
- Task 8: implemented. Shared monthly/daily metrics, filters, labels, mode breakdown, source projections and report reconciliation tested. Existing representative enrollment performance test passed.
- Task 9: implemented. SF2/SF4 direct downloads and authorized legacy export now return PDF bytes. Form layouts, long names/remarks, empty rosters and physical-page numbering were rendered/verified. Pagination assertion failed on automatic overflow before the fix and passes on four physical pages afterward.
- Tasks 10–11: implemented. Private queued/daily exports, metadata, rotation/retention, guarded downloads, admin UI and CLI workers. Real synthetic export/restore verified counts, indexes, a trigger, and encryption-key recovery. Positive/tampered/unauthorized download and expiry-after-replacement checks passed.
- Task 12: development acceptance and handoff prepared. Live installation/migration, private ACLs, scheduled jobs, provider activation, current official policy applicability and off-server copies remain explicit operator prerequisites. None is claimed as performed.

## Fresh review and fix evidence

A single fresh read-only reviewer found four Important issues and no Critical issue:

1. Evaluation committed before SMS claim: reproduced by `intervention_claim_race_test.php`; fixed by evaluating and reserving under one ordered structural/student/enrollment transaction. Concurrent workers make one synthetic attempt; unknown results are not retried.
2. Enrollment corrections omitted new decisions: reproduced by `panel_corrections_test.php`; fixed with decision locks, preview fingerprints/IDs, ownership remapping, revision/audit history and recomputation work.
3. Calendar writers changed recorded/closed-year eligibility: reproduced by `calendar_protection_test.php`; fixed with shared protected add/delete/import paths and year locks. Unsupported historical calendar rewrites require separate review.
4. Legacy PDF URL redirected to HTML: reproduced in `panel_workflow_test.php`; fixed to call the actual authorized download renderer. The existing PDF fixture was updated for the intended response and supplied its missing renderer/asset dependencies.

The reviewer set aside production deployment, actual provider delivery, current official policy applicability and final PDF visual fidelity. These are tracked in the acceptance record; synthetic rendering was subsequently reviewed by the implementer. No independent post-fix re-review is claimed.

Additional author verification caught and fixed a stale end-of-day review racing an explicit decision, and physical-page footers on PDF overflow. Both had observed failing tests before their fixes.

The final compatibility pass also preserved the existing daily manual-absence SMS independently of five-absence intervention enablement. Tests cover one dated dispatch, repeated saves, duplicate workers, Excused decisions, cancellation after correction, and the copied attendance-page workflow with a synthetic sender.

## Verification

- Final broad run: **71/71 PHP test files passed**, recorded under ignored `.qa/panel-verification.json`, including daily manual-absence notification and PDF layout coverage.
- `report_pdf_pagination_test.py` also passed: all 24 long-name entries survive automatic overflow, all physical page numbers are correct, and empty SF2/SF4 remain one page.
- JavaScript checks passed: `privacy_storage_test.js`, `scanner_response_test.js`, `sf2_remarks_ui_test.js`.
- Changed/new PHP lint and `git diff --check` passed.
- Synthetic Hostinger ZIP build passed: backup page and both approved CLI workers included, source-clone Git metadata excluded. Real school keys/data were not used in that packaging test.
- `backup_restore_test.php` used the real XAMPP dump/import binaries against isolated synthetic databases; no live database restore was performed.

## Implementation adjustments

- Delivery preview/confirmation is a single guarded calendar page rather than a separate save endpoint; Attendance/Manual Entry share a view. Access/domain cases are consolidated in the meaningful tests listed above rather than creating every proposed test filename.
- Intervention evaluation reads one enrollment's dated evidence and relevant modes, avoiding repeated whole-school report loads. Structural lock order matches attendance writers.
- Existing browser print layouts remain; the direct PDF renderer uses supported tables, shared form data and actual physical page numbering. Long remarks can create additional pages without dropping learner rows.
- The user chose in-place work, so automatic branch integration/commit/push steps do not apply. Existing edits and all new source/doc/test files remain reviewable in the checkout.

See `docs/PANEL_REVISIONS_ACCEPTANCE.md`, `docs/PANEL_REVISIONS_DEPLOYMENT.md`, and `docs/DATABASE_BACKUPS.md` for the development/deployment boundary and remaining operational checks.
