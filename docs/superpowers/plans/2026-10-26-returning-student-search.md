# Returning Student Search Implementation Plan

**Goal:** Let staff search by name or LRN and select an existing student on Returning Student Enrollment.

**Design:** Add a server-rendered GET search in the existing returning-student card. Show up to 20 minimal identity results (name, LRN, archive status), with explicit selection that fills the existing enrollment LRN input. Preserve school year and query when selecting a result. Keep manual LRN entry available. No search on blank or fewer than two characters. No contacts, photos, or QR tokens in results. Administrators can see archived matches with a restore link; archived identities cannot be selected for enrollment. Teachers can search active profiles with active/completed enrollment history in sections currently assigned to them, across school years. Selection rechecks that scope. Enrollment writes retain existing validation and permissions.

**Architecture:** A focused PHP read helper provides bounded, parameterized search and selection. The existing page owns GET forms and rendering. Existing POST save endpoint remains authoritative and unchanged. No new dependencies or schema changes.

**Tasks**

- [x] Add failing integration tests for name/LRN matching, literal wildcards, bounded results, archived records, teacher and denied-role scopes, revoked access, minimal returned fields, and valid/invalid selection.
- [x] Implement the read helper and run the tests.
- [x] Add search/results/selection UI using the shared form design, with separate GET and POST form ownership and year persistence.
- [x] Verify synthetic rendering, browser search/selection, mobile layout, and existing enrollment/authorization behavior.

**Related question:** Investigate and explain current transfer-out handling. No transfer-out feature was requested. Archive toggles profile visibility and operational eligibility while preserving enrollment history; it does not record transfer date/destination or shorten annual enrollment.

## Verification

- Search integration tests: 24 passed, after the empty implementation failed the admin name-match assertion.
- Enrollment creation/page integration: 71 passed, including selected LRN/year, restricted selection after adviser revocation, and no contact disclosure. The fixture explicitly revokes adviser ownership before the search checks; positive selection uses the administrator and negative selection uses that revoked teacher.
- Existing student authorization: 47 cases and four managed scopes passed. Existing enrollment workflow: 121 checks passed.
- Real PHP rendering: eight synthetic states (initial, results, selected, empty, teacher, forged selection, archived selection, and HTML-like search text), no PHP diagnostics or QR token exposure.
- Chrome: search submission works independently of incomplete enrollment fields; select fills a readonly LRN; change selection restores manual entry; year picker retains query/selection; main POST contains only the original fields and CSRF token. Archived/foreign selection is rejected, HTML-like input safely round-trips, and 1440/1024/768/375/320px layouts have no overflow and visible save controls. Screenshots inspected at `.qa/returning-search-1440.png` and `-375.png`.
- A browser check caught `sanitize()` stripping tags from the displayed search text; search values now use HTML escaping to preserve the query without interpreting markup.
- PHP syntax and whitespace checks passed. No application database changes or external services used.

## Transfer findings

The legacy profile schema includes `transferred_out`, and migration excludes such profiles from active mappings. Current annual enrollment UI offers no dedicated transfer-out action. Archiving sets `is_active=0` and preserves annual enrollment/assignment dates; operational scan/write contexts reject archived profiles, while historical rosters remain date-based. A complete transfer-out workflow needs an effective departure date, destination/reason, preserved pre-departure attendance, and a reviewed change to future enrollment eligibility. This feature was not added as part of student search.
