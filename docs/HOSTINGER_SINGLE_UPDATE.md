# Existing Hostinger Single installation update — 27 October 2026

Target: `springgreen-cobra-784324.hostingersite.com`.
Existing database: `u788713911_spccsMonitor`. Keep this database; do not import a local database over it.

## Verified live state

- PHP 8.3.36; 272 students; 1,853 attendance rows; 92 sections, all labeled 2026–2027.
- Annual enrollment and panel tables are not installed/enabled yet.
- Private `hosting.php`, `pii_key.php`, `settings_key.php`, and `student-photos` are readable. Both configured encryption keys load; a sample of original encrypted audit history decrypts.
- `SETTINGS_ENCRYPTION_KEY` is already configured. Keep it. A private key file is named **settings_key.php**, with an `s`.
- PHP process execution is disabled. The release now uses a PHP/PDO backup fallback on this host, with an isolated restore test. A private backup directory still needs configuration.
- The existing finalizer's last output reports failure. This is a pre-existing live failure, not a verified repair.
- School-year dates confirmed by the operator: **2026-06-08 through 2027-04-08**.
- The operator confirmed that current sections correctly own the existing September–October attendance records.

## Upload artifacts

Use `hostinger-update.zip` for this existing installation. It contains the complete current application/vendor release, but intentionally **does not replace `.htaccess`** so maintenance remains active while files are extracted. `ACTIVATE.htaccess` is the new application access configuration to install as `.htaccess` only when cutover checks pass.

`database/01-additive-update.sql` is the incremental phpMyAdmin schema update. It creates missing tables/columns/indexes/foreign keys and adds the intervention SMS type. Its repeat-run test preserves legacy students, attendance, sections and users. It does not guess annual ownership or enable the model. Do not use the schema-only `attendance_system.sql` or `database_schema_update.sql` as the update to the existing school database.

The archive contains no private hosting configuration, encryption key values, student photos, database records, saved sessions, local backups, test fixtures or Git metadata. Keep the entire existing `attendance-private` directory untouched.

## Cutover order

1. Export the latest live database using phpMyAdmin, including triggers, routines and events if present. Download the current website files, original private keys/configuration and separate photos. Record checksums. Rehearse the export in an isolated database with outbound services disabled; verify counts, original keys/decryption and audit triggers. The earlier downloaded SQL is useful evidence but does not prove a fresh maintenance-window snapshot.
2. Put the site into maintenance: save the old `.htaccess` privately, then replace the live root `.htaccess` with `MAINTENANCE.htaccess`. Disable the old finalizer cron and allow running requests/workers to finish. Keep maintenance until all remaining steps pass.
3. Upload/extract **hostinger-update.zip** directly into `public_html` without deleting its uploads/storage/private data or the sibling `attendance-private` directory. It must not create a nested `public_html/public_html`. Leave the maintenance `.htaccess` in place.
4. Select **u788713911_spccsMonitor** in phpMyAdmin and import `database/01-additive-update.sql`. Inspect any SQL errors; DDL commits incrementally. Do not import a fresh schema or local data dump.
5. Complete the reviewed annual enrollment map and initialization using the commands below, through a temporary hPanel **custom** cron if SSH is unavailable. Mapping/inventory/preview files belong under `attendance-private`, not `public_html`. Remove each temporary cron after its completed output is collected. Do not set enrollment_model_version manually. **Importing step 4 alone is insufficient to enable the new screens.**
6. Create `attendance-private/backups`, with permissions restricted to the hosting account. Add this one non-secret entry to the existing `hosting.php` array without replacing its other entries:

   ```php
   'BACKUP_DIR' => '/home/u788713911/domains/springgreen-cobra-784324.hostingersite.com/attendance-private/backups',
   ```

   Leave `ATTENDANCE_INTERVENTION_ENABLED_AT` empty until the school reviews current cases, contacts and message template. Preserve all integration settings and keys. BACKUP_DUMP_BINARY is optional on this host because process execution is disabled.
7. Install the two cron jobs below only after the schema and annual model are ready. Confirm actual successful output, and verify a completed downloadable backup. Do not count dry-run scheduling output as a successful backup/finalizer run. Review login, authorized photos, QR cards, scanner eligibility, historical reports and private-path denials. Send a test SMS only with explicit authorization and a designated recipient.
8. Rename/upload `ACTIVATE.htaccess` as the root `.htaccess` to reopen the site. Remove the uploaded ZIP. Download a recovery copy off the hosting account.

## Exactly two permanent cron jobs

| Schedule | Command |
| --- | --- |
| `* * * * *` | `/usr/bin/php /home/u788713911/domains/springgreen-cobra-784324.hostingersite.com/public_html/attendance/finalize_absences.php` |
| `*/5 * * * *` | `/usr/bin/php /home/u788713911/domains/springgreen-cobra-784324.hostingersite.com/public_html/tools/hostinger_maintenance.php` |

The second worker evaluates interventions without sending while enablement is empty. It processes queued backups on every tick and creates the idempotent daily backup on the first tick at/after **02:00 Asia/Manila**. Scheduling is calculated in PHP's Manila timezone, independent of hPanel's display timezone. An overlap lock prevents two maintenance runs at once; a failing worker does not suppress the other worker. Nonzero exit codes are reported per worker.

## Prepared enrollment mapping and temporary migration commands

`private-transfer/enrollment-map.json` contains record IDs and the reviewed mapping for the supplied export: 272 students, 92 sections and 1,853 attendance records. Upload **only that JSON** to the existing `attendance-private/enrollment-map.json`; do not replace any files with the other local private-transfer examples or keys.

Assignments use each learner's earliest recorded attendance/profile date, clipped to the confirmed school year, rather than claiming attendance ownership back to June without evidence. Existing archived students remain archived. Initialization synchronizes current grade/year projection fields and records encrypted audit history; it does not change identity/contact data or original scans.

The map's inventory fingerprint checks the complete original source data. If the live records have changed since the supplied export, applying the map fails safely. Export the latest drained database and regenerate/review the mapping in that case; never change the fingerprint merely to force it through.

Use a temporary `* * * * *` custom cron for **one command at a time**, collect successful output, and remove it before proceeding. `cd` keeps each command within hPanel's 255-character limit. The original finalizer must remain disabled and the web site in maintenance throughout these commands.

```text
cd /home/u788713911/domains/springgreen-cobra-784324.hostingersite.com && /usr/bin/php public_html/migrations/school_year_enrollment.php --schema
```

```text
cd /home/u788713911/domains/springgreen-cobra-784324.hostingersite.com && /usr/bin/php public_html/migrations/school_year_enrollment.php --apply-map=attendance-private/enrollment-map.json --actor-id=1
```

The exported active administrator is ID 1. The CLI rechecks its active admin authority. Then create the **live** initialization preview (do not reuse a rehearsal fingerprint):

```text
cd /home/u788713911/domains/springgreen-cobra-784324.hostingersite.com && /usr/bin/php public_html/migrations/school_year_enrollment.php --initialize-preview=1 --actor-id=1 --output=attendance-private/preview.json
```

Open `attendance-private/preview.json` in File Manager. Proceed only when `ready` is `true` and `blocking_issues` is empty. Retain/review historical notices; they qualify old records rather than changing scans. Copy its 64-character `fingerprint` into the command below in place of `LIVE_PREVIEW_FINGERPRINT`:

```text
cd /home/u788713911/domains/springgreen-cobra-784324.hostingersite.com && /usr/bin/php public_html/migrations/school_year_enrollment.php --initialize=1 --actor-id=1 --fingerprint=LIVE_PREVIEW_FINGERPRINT
```

Stop on any failed command. Existing map and initialization commands detect stale/conflicting state; do not work around their checks with direct SQL flag edits. Use a new preview filename if a fresh preview is required.

## Recovery boundary

Before new-model writes, restore the previous code/configuration in maintenance while retaining additive schema. After annual/decision writes or notifications, code-only rollback can hide records: preserve a post-change copy and reconcile or use the verified pre-change recovery snapshot with an agreed data-loss boundary. Never drop added tables or overwrite the live database as an automatic rollback.

## Current delivery status

This document describes a prepared update, not a completed live deployment. The only live additions during preparation were a CLI-only read-only preflight file and its temporary diagnostic cron. The diagnostic cron was removed after its output was collected; the original finalizer was retained. Database records and private files were not changed during preparation.
