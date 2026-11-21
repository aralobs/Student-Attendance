# Unified calendar entry implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline. Final focused review uses the requesting-code-review skill.

**Goal:** Put school events and class delivery schedules in the School Calendar Add Entry modal, as accepted by the user.

**Architecture / accepted design:** One modal selects School event or Class delivery. Show the corresponding fields; delivery retains date range, authorized scope, mode, modular deadline, preview and confirmation. Reuse the existing protected event writer and delivery service; keep their storage separate. Teachers get only assigned-section delivery controls. The old delivery URL remains a schedule list with a link to the unified modal.

**Tech stack:** Existing PHP/PDO, Bootstrap modal, plain JavaScript. No new dependencies.

**Spec:** The accepted design in this document reflects the preceding proposal and the user's “yes we should do that.”

## Constraints and review focus

- Preserve existing in-place edits and data; no migration, live test messages, commits or deployment.
- Admin-only events and school-wide schedules; teacher overrides only for assigned active sections.
- Validate CSRF for both paths. Changing any delivery input invalidates preview confirmation.
- Preview must not write; save must recheck the token and current state. Closures and recorded attendance remain protected by existing services.
- Show asynchronous errors as text. An event lookup arriving after reopening/changing the modal must not overwrite the current form.

## Task 1 — Combined modal and guarded submission

Files: `calendar/calendar.php`, `includes/calendar_entry_modal.php`, `assets/js/calendar_entry.js`, `calendar/delivery_modes.php`, `tests/calendar_entry_modal_test.php`, `tests/calendar_entry_ui_test.js`.

- [x] Write and run failing copied-page tests for both modal choices, teacher options, preview without writes, confirmed saves, CSRF, closure skips, forged scope, stale preview and event protection.
- [x] Implement the shared modal, delivery AJAX action and input-dependent client workflow; reuse existing services.
- [x] Replace the old separate form with a link to the same modal, retaining the schedule list and guarded legacy POST compatibility.
- [x] Run PHP/Node regressions and a browser exercise of the actual modal. Record results here and in the audit.
- [x] Run the full PHP suite, lint changed PHP/JS and obtain a focused review. Fix material findings and deliver in-place changes.

## Execution record

Implemented inline in the existing checkout. No commit or branch operation is requested.

- Server regression initially failed because Add Entry had no class delivery choice. It now passes 33 checks using the actual copied calendar page and isolated database.
- The actual JavaScript passes Node tests for switching, CSRF selection, preview/confirmation, input invalidation, safe message rendering and stale event lookup results. A mutation removing input invalidation is caught.
- Review caught an additional race around partial event loading. The final solution locks event editing and saving until title, type and description have all loaded. Failed lookups remain locked until reopen. Reproducing tests failed before the fix; current tests verify original type/description preservation, loading/error guards and stale-response protection. The final focused review found no remaining material issues.
- A real Chrome/Bootstrap/DOM exercise passed for modular deadline validation, switching categories, preview and clearing stale confirmation. Its HTTP requests were intercepted; no live schedules were modified.
- Delivery display, delivery modes, calendar protection and staff authorization regressions passed. The full PHP suite passed 75/75 files; the expanded modal regression separately passed 33 checks. Five Node regressions passed, changed PHP/JavaScript passed syntax checks, and the scoped diff passed whitespace checks. Raw full-suite results: `.qa/2026-10-26-calendar-modal-tests.json`.

Ruling: retain guarded legacy POST handling at the old delivery URL while removing its duplicate form — existing integrations can retain the endpoint, while all visible creation/editing links open the unified modal.
