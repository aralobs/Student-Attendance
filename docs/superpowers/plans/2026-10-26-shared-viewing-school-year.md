# Shared Viewing School Year Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task by task.

**Goal:** Remember a staff member's viewing school year across year-aware pages with one consistent selector and a return-to-current action.

**Architecture:** A session-backed viewing-year resolver validates explicit choices against staff access. Reporting adapters and directory controllers consume it; operational writes keep their existing explicit year/date guards. The navbar renders the shared control, with a historical indicator beneath it. Legacy deep links still resolve explicit years without silently changing the saved preference.

**Tech Stack:** PHP, MySQL, Bootstrap, existing synthetic integration fixtures and Playwright.

**Spec:** User-approved shared selection proposal in this conversation, 2026-10-26.

## Global Constraints
- Default to active year for a new login; viewing preferences never update the active year.
- Hide selector on unrelated pages; preserve the preference there.
- Scanner stays current-year only and says so.
- Recheck teacher access on every resolution; stale preferences fall back safely.
- Preserve existing enrollment/write authorization and explicit report links.

## Review Focus
- Explicit report links must not overwrite the remembered choice.
- Removed or newly inaccessible years must not strand a teacher.
- Historical dashboard must not display or poll current scans.
- Form submissions and pagination must retain the displayed year.
- Mobile navbar and historical indicator must fit 320px.

### Task 1: Shared resolver and report integration
- [x] Add failing synthetic tests for session persistence, explicit precedence, reset, access revocation, malformed choices and unchanged active year.
- [x] Implement `viewingSchoolYear(PDO $db, array $actor): array`, `viewingSchoolYears(PDO $db, array $actor): array` and a shared renderer in `includes/viewing_school_year.php`.
- [x] Integrate report/directory adapters and Sections; reset preference at successful login.
- [x] Run `php tests/viewing_school_year_test.php` and existing enrollment report/access tests.

### Task 2: Page consistency and current-operation boundaries
- [x] Add integration checks for shared markup, student navigation, historical dashboard, attendance and operational notices.
- [x] Use shared navbar control on attendance reads and intervention monitoring; remove duplicate viewing selectors there. Keep enrollment destination choices separate.
- [x] Bound historical dashboard snapshot/trend to selected year; hide current live panels and polling in historical mode.
- [x] Add historical/current notices and responsive presentation.
- [x] Verify PHP syntax, relevant synthetic integration tests, desktop/mobile rendering and JavaScript errors.

### Task 3: Verification and documentation
- [x] Review final diff for scope, access rules, exports and writes.
- [x] Update `docs/SCHOOL_YEAR_ENROLLMENT.md` with viewing-year behavior and boundaries.
- [x] Report completed behavior and verification without committing unrelated workspace changes.

## Execution record

- Worked in the existing workspace because this change builds directly on its uncommitted page designs and annual-enrollment work; unrelated edits were retained.
- The shared selector alone changes the saved preference. Explicit `sy`, `school_year_id`, enrollment and section links keep their local context without changing the saved preference.
- Preserved the existing rejection of an unscoped multi-year date range when there is no saved or explicit viewing year.
- Review found and corrected dependent grade filters, active-year intervention links and section-only historical report date defaults. Focused re-review found no remaining critical or important issues.
- Synthetic tests cover shared navigation, access revocation, reset, malformed selection, historical dashboard, scoped report links and unchanged active year. Browser checks cover seven pages at desktop and 375/320px, one visible year control, historical notice and reset, no live historical scans, scanner current-only and no JavaScript errors.
- Existing report, attendance, authorization, privacy, generated section script and manual-notification checks passed using synthetic fixtures. No production data or active-year setting was changed.
