# Prepare Sections Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task by task.

**Goal:** Copy selected sections into a later planned year with unchanged names, grades, and schedules.

**Architecture:** A transactional service previews and confirms copies using a fingerprint of locked source and matching destination records. A dedicated administrator page uses the existing Students/Monthly Analytics styles; Sections and Moving Up link to it.

**Tech Stack:** PHP, PDO/MySQL, Bootstrap, existing JavaScript and enrollment fixtures.

**Spec:** `docs/superpowers/specs/2026-10-26-prepare-sections-design.md`

## Global Constraints

Preserve student records, source sections, history and school-year activation. Advisers start unassigned. Skip existing name/grade matches including archived records. Use administrator authorization, annual mode, CSRF, audit logging, and control/year/section lock order. No schema change. Preserve unrelated working changes; no automatic commit.

## Review Focus

Forged section IDs, stale previews, duplicate names under database collation, archived destination matches, and failure midway through a copy must reject safely or skip as specified without partial writes.

## Task 1: Section preparation service

Files: create `includes/section_preparation.php` and `tests/section_preparation_test.php`.

Interfaces: `sectionPreparationCommand(array): array`, `previewSectionPreparation(PDO,array,array): array`, `prepareSections(PDO,array,array,string): array` returning rows/fingerprint or created/skipped counts.

- [x] Write fixture tests for permission and mode guards, invalid selections/years, copied values, unchanged students/sources, duplicate and archived skips, replay, stale previews, audit rollback, and collating names.
- [x] Run `php tests/section_preparation_test.php` and observe failure before implementation.
- [x] Implement current locked reads and fingerprint revalidation, atomic inserts and audit changes.
- [x] Run service tests to green.

## Task 2: Page and navigation

Files: create `sections/prepare.php`, `assets/js/section_preparation.js`, `tests/section_preparation_page_test.php`; modify `sections/sections.php` and `students/moving_up.php`.

- [x] Add real-page fixture cases for GET/preview/confirm, permission/CSRF, empty source/target, repeat submission and stale preview.
- [x] Observe missing-page failure; implement setup, selection, preview, confirmation, outcome links and page-local checkbox behavior. Keep all selections visible (no list pagination).
- [x] Run PHP syntax and page checks; rerun Moving Up checks.
- [x] Verify desktop/mobile cards, centered list headers, Select All and empty states in Chrome.

## Execution notes

Native execution in this checkout follows the user's approved implementation request. No further approval checkpoint; the design is approved. No live school data will be copied during verification.

## Validation ledger

- Service: 63 checks pass, including case-insensitive matching, archived sections, failed audit rollback, second-insert rollback, and revoked administrator access.
- Page: 32 fixture checks pass, including authorization, CSRF, stale and repeated confirmation, and successful redirect.
- Existing Moving Up: 92 checks pass.
- Chrome: source list, preview, existing copies, empty source, and no-target states checked at 1440px, 375px, and 320px; centered headers, no document overflow, no script errors.
- Focused independent review found no important correctness or security issues. Review improvement implemented: changing source selection hides the old confirmation until a fresh preview. Browser assertion observed failing before the fix.
- Corrected a test baseline after its synthetic stale-preview edit changed the source timestamp; product copy logic did not change source records.

## Follow-up: back navigation and corrections modal

User requested an origin-aware back button and Enrollment Corrections as a modal from Moving Up.

- [x] Pass an allowlisted local return destination from Sections and Moving Up; preserve original filters across source loading, preview, confirmation and redirect. Direct access defaults to Sections.
- [x] Open the existing correction workflow in a lazy-loaded modal with no duplicate navigation. Preserve modal context in all GET/POST forms, confirmation redirects and retry links. Standalone corrections remain available to existing links.
- [x] `php tests/workflow_navigation_test.php`: 43 checks pass, including local-only return links, embedded preview/confirmation, administrator access and CSRF.
- [x] Existing correction suite: 188 checks pass; Moving Up: 92; section preparation page: 32.
- [x] Chrome at 1440px and 375px: open, load enrollment, preview, confirm, close, Escape and reopen preserve the parent Moving Up page; back links preserve origin filters; no script errors. Browser rendering uses synthetic route responses, while PHP fixtures execute the real correction preview and save.
