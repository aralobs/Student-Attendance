# Student Form Redesign Implementation Plan

**Goal:** Apply the approved enrollment mockup to Add Student and match Edit Student to it.

**Design:** A clear page heading, new/returning enrollment links, grouped enrollment details, student information and guardian cards in the main column, a smaller photo/QR sidebar, and sticky save actions. Larger labels and controls, restrained blue accents, and a single-column mobile layout. Keep all existing fields and required/optional rules, including optional birth date and section where currently allowed.

**Architecture:** Preserve each PHP page's existing request handling and authorization. Reorganize its template and add one stylesheet scoped to these two forms. Keep existing photo previews, title casing, year selection, date boundaries, CSRF fields, and edit placement restrictions.

**Tech Stack:** PHP, Bootstrap 5, scoped CSS, existing JavaScript.

## Constraints

- Work in the existing checkout to retain the current uncommitted application design, as documented in the existing student workflow plan.
- No backend/schema changes, commits, or unrelated cleanup.
- The user approved the visual sample and requested implementation on both pages; proceed inline.
- This is a reversible presentation change: use existing behavioral checks and direct browser verification rather than adding tests that mirror markup.

## Tasks

- [x] Reorganize both templates around matching enrollment/profile, guardian, and photo sections. Preserve POST inputs and access restrictions.
- [x] Add shared scoped responsive styling, accessible label associations, keyboard-accessible photo upload, and sticky actions.
- [x] Run PHP syntax checks, student authorization and name normalization checks, and synthetic rendering. Inspect desktop/mobile layouts, form values, placement restrictions, photo preview and action visibility.

## Review focus

Existing managed and legacy enrollment, retained values after validation errors, teacher placement restrictions, long names/options, and narrow mobile screens must continue to work.

## Verification

- PHP syntax checks passed for both pages and the sidebar; diff whitespace checks passed.
- Original PHP request handling matched the pre-change files, except the Add Student page title; every named field was preserved.
- Existing authorization checks passed (47 cases and four managed scopes), name formatting checks passed (19 PHP and eight JavaScript checks), enrollment creation passed (65 checks), CSRF passed (189 cases), and enrollment workflow passed (121 checks).
- Real PHP rendering against a disposable synthetic database passed for both forms in managed, legacy, and invalid-POST states.
- Chrome checks passed for all six rendered states at 1440, 1024, 768, 375, and 320 pixels: no horizontal overflow; visible sticky save controls; labels; year picker/save form ownership; retained invalid-POST values; disabled managed placement; keyboard upload focus and photo preview; no JavaScript errors.
- Desktop and mobile screenshots visually inspected: `.qa/student-form-add-1440.png` and `.qa/student-form-edit-375.png`. Additional screenshots and verification scripts are in the ignored `.qa` directory.
- The sidebar omits its redundant enrollment banner only when Add Student supplies the equivalent inline school-year explanation.

## Follow-up: returning enrollment and navigation

User requested Edit Student back navigation to the student list and Returning Student Enrollment to match Add Student.

- [x] Point Edit Student's Back and Cancel links at `students.php`.
- [x] Apply the shared form stylesheet, breadcrumb, enrollment switch, enrollment details, existing-LRN card, identity sidebar, and save action bar to `students/enroll.php`.
- [x] Preserve the existing year/section queries, date bounds, CSRF field, required inputs, and `save_enrollment.php` action. Show the year explanation inline instead of duplicating the banner.
- [x] Check syntax, existing enrollment behavior, desktop/mobile rendering, form ownership, and navigation destinations.

Follow-up validation: PHP syntax and whitespace checks passed; 65 enrollment creation checks passed. Real PHP pages rendered with synthetic data. Chrome checks passed at 1440/1024/768/375/320px with no overflow or JavaScript errors, visible save controls, the correct active enrollment mode, year-specific navigation and date limits, required fields and CSRF submission, and successful clicks from both Edit Back and Cancel to the student-list destination. Returning enrollment desktop/mobile screenshots visually inspected in `.qa/returning-enrollment-1440.png` and `-375.png`.
