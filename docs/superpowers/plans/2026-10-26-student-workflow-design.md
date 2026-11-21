# Student workflow design alignment

**Goal:** Apply the existing Monthly Analytics presentation to the selected student and enrollment workflows and Manual Entry.

**Scope:** students/add.php, edit.php, view.php, enroll.php, enrollment_corrections.php, moving_up.php, attendance/manual.php and its managed template includes/attendance_sheet_page.php (manual branch only).

**Design:** Reuse students.css for the same 14px analytics cards, gradient headers and Students table spacing. Add a scoped workflow stylesheet for compact headings, form controls, metric tiles and centered list titles and column headings. No decorative date badge; preserve required attendance, birth and enrollment dates. Preserve all request fields, validation, permissions, preview/confirmation and save behavior.

**Approach:** Explicit page-local wrappers and styles follow the existing Students rollout. Global CSS changes would exceed the selected scope; copying independent styles into every page would make future alignment harder.

## Implementation

- [x] Add the scoped workflow stylesheet and apply Students cards/table classes to the selected templates.
- [x] Align enrollment form headings and student metrics, fix the enrollment correction content wrapper, and cover both Manual Entry branches.
- [x] Run PHP syntax checks and relevant existing workflow checks; inspect desktop/mobile rendering with synthetic data or an existing authenticated browser if available.
- [x] Review the diff for presentation-only scope and record validation.

**Constraints:** Work in the existing checkout to retain the user's extensive current uncommitted designs. Do not commit unrelated changes. No backend or schema edits; no new pagination behavior for roster selection.

## Validation

- All eight PHP templates pass syntax checks.
- Real PHP pages rendered against the isolated synthetic enrollment database: seven selected pages, managed manual empty state, and legacy manual. No PHP diagnostics.
- Chrome layout checks passed at 1440px, 375px and 320px: scoped wrappers, analytics gradients and radii, centered table headings, no page overflow or JavaScript errors, search/empty results, roster selection and bulk AM status. Screenshots in `.qa/workflow-*-desktop.png` and `-mobile.png`.
- Existing student authorization checks: 47 cases plus four managed scopes; new-entry name checks: 19; list pagination checks passed. Existing list browser checks passed in Chrome (Edge could not launch inside the sandbox).
- Scope review preserves pre-existing unrelated changes and request handling.
