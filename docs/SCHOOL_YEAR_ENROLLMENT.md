# Annual enrollment: cutover and recovery

Annual enrollment preserves student IDs, unique LRNs, profiles, contacts and QR
identity. Each year owns annual enrollments and dated section assignments.
Preparing next year leaves today's attendance unchanged. Graduation preserves
the learner and historical scans. No record purge is introduced.

## Deployment gate and verified recovery

Deploy the entire reviewed release containing converted student/section,
enrollment, scan, finalizer, end-of-day, QR, report and correction entrypoints.
Additive schema or a partial installation leaves `enrollment_model_version`
absent or `0`. Never enable it by hand. The flag cannot prove release completeness;
the operator must verify the release manifest and smoke tests before cutover.
Development tests do not authorize exporting or migrating a real school's data.

Before production work, preserve a complete consistent pre-change DB export,
including tables, records, primary/foreign keys, indexes and all audit triggers
(and routines/events if used). Record its SHA-256 checksum, timestamp and server
version privately. Preserve the original PII and settings encryption keys via
the existing private configuration mechanism, separately from the export.
Never record key values, credentials, exports or school records in source control
or tool output.

Restore the export to an isolated database with outbound providers disabled.
Verify counts, original keys/indexes, trigger definitions, and decryption of
representative encrypted change history and SF2 remarks using the original keys.
Confirm synthetic audit update/delete attempts fail. Record counts and pass/fail,
checksum and operator/time without plaintext or ciphertext. Preserve photos and
uploads separately, including private storage and legacy uploads: checksum a
separate file manifest and verify representative restored files through the
authorized photo handler. A database export does not preserve those files.
Never test restore over the running school DB. Agree the outage and data-loss
boundary before the maintenance window.

## Maintenance and explicit initial cutover

1. Block new web writes and drain in-flight requests. Stop the existing finalizer
   schedule and wait for any running old worker to finish. Confirm no old build
   can write. A request that selected its legacy branch before initialization
   cannot be retroactively converted by changing the model flag.
2. Install the complete release, preserving private configuration/keys/uploads.
   Use the configured private connection and a separate migration operator with
   DDL rights; do not grant DDL to the runtime web account.
3. Run additive schema installation:

   ```text
   php migrations/school_year_enrollment.php --schema
   ```

   Review failures before proceeding. DDL can commit incrementally; reruns retain
   completed progress. Schema installation invents no years or ownership.
4. Inventory the latest drained DB into a new operator-only private file outside
   the document root/checkout:

   ```text
   php migrations/school_year_enrollment.php --inventory --output=<new-private-inventory.json>
   ```

5. Review an explicit mapping JSON with `inventory_fingerprint` and four lists:
   `school_years`, `sections`, `enrollments`, `attendance`. Supply verified year
   labels and inclusive dates; section/year links; student/year enrollments with
   explicit states and nonoverlapping dated assignments; and exact attendance
   IDs referencing assignment local keys. New years omit `id` and are referenced
   by `school_year_label`; existing years use `school_year_id`. Enrollment and
   assignment `key` values are stable ASCII letters/digits or `_.:-`, 1–64 chars.
   Current placement or a previous-year label cannot prove historical dates.
   Preserve ambiguous rows until evidence supports an explicit mapping.

   ```text
   php migrations/school_year_enrollment.php --apply-map=<private-map.json> --actor-id=<active-admin-id>
   php migrations/school_year_enrollment.php --readiness=<initial-year-id> --output=<new-private-readiness.json>
   ```

   The CLI verifies active administrator authority in the DB. Mapping does not
   send messages, invent absences/scan times, duplicate profiles or activate the
   model. Identical maps reuse IDs; changed legacy data requires new inventory
   and review. Resolve all current operational issues. Historical-only issues
   remain visible as administrator report qualifications. Future planned annual
   records must remain prepared; do not silently repair states with SQL.
6. Review a separate combined initialization preview and confirm its token:

   ```text
   php migrations/school_year_enrollment.php --initialize-preview=<initial-year-id> --actor-id=<active-admin-id> --output=<new-private-preview.json>
   php migrations/school_year_enrollment.php --initialize=<initial-year-id> --fingerprint=<preview-fingerprint> --actor-id=<active-admin-id>
   ```

   Task 4 readiness's legacy-only fingerprint is not an initialization token.
   The new token covers legacy data and managed states/versions. Relevant changes
   require another preview. Confirmation atomically selects one active year,
   activates its prepared enrollments, synchronizes projections/settings, records
   encrypted audit and enables version 1. The original year/token is recorded in
   `enrollment_initialization_year_id` and `enrollment_initialization_fingerprint`.
   Repeating it is harmless even after later activation; it never reopens a year
   or changes projections. Different initial years/tokens conflict. Version 1
   without original metadata is rejected: investigate in maintenance rather
   than inventing initialization history.
7. Verify permission boundaries, scans, current roster, historical SF2/SF4 and
   reviewed corrections in the isolated installation with synthetic data and
   stub providers. Confirm next-year preparation leaves current attendance
   unchanged. Confirm the deployed private connection targets the intended DB.
8. Restart only the converted finalizer, verify a successful controlled run and
   its cursor/overlap behavior, then end maintenance. Never dual-run old/new
   workers. Record the operator checks separately from development evidence.

## Later years and corrections

Administrators add planned years with verified dates and create year-specific
sections; names may repeat across different years. Returning Student Enrollment
uses an existing profile. Moving Up selects the complete source-section roster
and previews promotion, retention or Grade 6 graduation. Preparing a destination
does not alter current scans, profiles or historical reports.

Later changes use School Years' activation preview and confirmation, never
initialization. Review outcomes, blocking mappings, historical notices and
unassigned follow-up. Activation closes the outgoing year atomically. Historical
SF2/SF4 keep original annual/assignment membership.

Managed Profile Edit changes profile fields only. Moving existing placements
uses corrections. Prepared mistakes may be revised while unused; recorded or
closed-year history requires an administrator's reason, exact affected-attendance
preview and confirmation. Corrections preserve scan values and encrypted
before/after audit. Closed years stay closed. Teachers cannot move existing
placements; scanners gain no staff/report/correction access.

## Rollback boundaries and grants

Before new-model writes, rollback may restore the prior release and disable the
model in maintenance while retaining additive schema/mappings. Stop new web
writes, drain requests and stop/drain the worker first. Preserve original
initialization metadata and document the decision. Reinitialization after a
rollback requires deliberate operator review, not an automatic fallback.

After new-model writes, reconcile them under an approved recovery plan or restore
the verified snapshot and separate uploads after an explicit outage/data-loss
assessment. Preserve a private post-cutover copy for reconciliation. Never
silently drop annual records or claim changing a flag retains post-snapshot
writes. Reverify original keys, decrypted history/remarks, triggers, photos and
consumer behavior before reopening access.

Runtime needs SELECT/INSERT/UPDATE on `school_year_control`, `school_years`,
`student_enrollments`, `enrollment_sections`, `moving_up_batches`,
`moving_up_decisions`, `enrollment_corrections`, `enrollment_migration_issues`,
and its existing rights on settings/profiles/attendance/authorized remarks.
No new DELETE or purge privilege is needed. Keep existing append-only audit
grants and triggers unchanged. Schema progress and DDL are migration-operator
concerns. Verify grants in the actual deployment.

The migration script returns 404 for HTTP before loading configuration. Retain
the web server's `/migrations/` deny rule too. Map/preview/inventory files must
be private local files; outputs must be new. Apply operating-system permissions,
including Windows ACLs where relevant. Console output is status/counts only;
errors do not expose SQL, paths, secrets or profile data.
