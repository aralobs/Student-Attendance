# Prepare sections for a planned school year

## Intent

Let an administrator prepare next year's sections in bulk before preparing student Moving Up batches. The user chose to keep each section's current grade.

## Selected approach

Copy selected sections into a planned school year as new records, preserving section name, grade, schedule type, and scan time windows. This follows the existing separation between annual sections and student enrollment.

Moving existing section records to the next year would change current placements and historical references. Raising section grades would conflict with the user's chosen behavior.

## Workflow and presentation

- Add **Prepare Next-Year Sections** links from Sections and Moving Up.
- Administrator selects an active or closed source year and a later planned target year, then loads active source sections.
- Display a Students-style list with individual selection and Select All. Use Monthly Analytics cards and centered list headings, matching Moving Up.
- Preview the selected names, unchanged grades, schedules, and whether each section will be created or skipped.
- Confirm to create missing target-year sections in one transaction, then link to the target year's Sections page and student Moving Up.
- Leave advisers unassigned in the new sections so staffing can be reviewed for the new year. This is an implementation recommendation, not a user-specified rule.

## Constraints

- Students, enrollments, attendance, source sections, and school-year activation remain unchanged.
- Copy only active source sections; create active destination sections.
- Skip matching name/grade sections already present in the target year, including archived matches. Explain that archived matches can be restored through Sections; never overwrite them.
- Reject ambiguous duplicate selections or invalid source/target years. Revalidate records and preview state at confirmation so stale previews cannot copy unexpected settings.
- Enforce administrator authorization, annual enrollment mode, POST/CSRF validation, audit logging, and the existing control/year/section lock order.
- Repeated confirmation must not create duplicates. No schema change is expected.
- Work in the existing checkout and preserve all unrelated uncommitted changes.

## Validation

Use isolated enrollment fixtures for authorization, target-year restrictions, preservation of names/grades/schedules, unassigned advisers, duplicates and repeat submission, stale previews, transactional rollback, and unchanged student records. Verify rendered preview, empty states, selection, centered headings, and desktop/mobile layout.

## Approval

Approved by the user on 2026-10-26.
