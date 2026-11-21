# Delivery schedule deletion implementation plan

> **For agentic workers:** Use superpowers:executing-plans inline, then a focused requesting-code-review review.

**Goal / accepted scope:** The user can delete a saved delivery entry from the month list and scheduled-date list.

**Design:** Delete one dated scope record at a time. Preview its identity and the resulting inherited schedule; require confirmation bound to current database state. Reuse staff scope, CSRF, year/section locking and protected-history rules. Whole-school deletion preserves section overrides. Removing an override restores the school schedule/default; closures remain authoritative. No migration or live data deletion during development.

**Files:** `includes/delivery_modes.php`, `calendar/calendar.php`, `includes/calendar_month_entries.php`, `calendar/delivery_modes.php`, new `assets/js/delivery_delete.js`, `tests/delivery_delete_test.php`, `tests/delivery_delete_ui_test.js`.

## Implementation

- [x] Reproduce the missing service/action and add authorization, preview/confirmation, inherited schedule, stale-state, closed-year, recorded history and inactive-date tests.
- [x] Add transaction-owned `deleteDeliveryMode(PDO, array actor, array command): array`; validate the real actor, lock structural rows, recheck history and record deletion in Logs.
- [x] Add scoped trash buttons and a guarded AJAX handler. Confirm before deleting, render errors as text, do not retry ambiguous requests.
- [x] Verify relevant regressions and the full PHP suite; obtain focused review and fix material findings. Update the audit with actual results.

Ruling: the command deletes a single date/scope, including a saved schedule currently suppressed by a closure. It does not delete the holiday/no-class event or clear an entire scheduled range.

Verification: deletion regression passes 39 checks; full PHP suite passes 78/78 files; six Node regressions pass. Real Chrome DOM check passes for the trash button, preview, inheritance confirmation and cancellation. Changed PHP syntax and scoped whitespace checks pass. Focused review found no material issues. Raw suite results: `.qa/2026-10-26-delivery-delete-tests.json`.
