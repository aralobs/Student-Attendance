# Hostinger Single update implementation plan

**Goal:** Prepare the current PHP release for the existing Single Web Hosting installation at springgreen-cobra-784324.hostingersite.com, preserving its database, private keys, configuration and photos.

**Architecture:** Keep the existing finalizer job. Add one CLI maintenance coordinator for the intervention and backup workers. Produce a versioned upload ZIP from the current working files; never export the local database over production. Use a read-only server preflight before reviewing the live enrollment cutover.

**Tech stack:** PHP >=8.1, MariaDB, PowerShell ZIP builder, Hostinger Connector.

## Constraints and decisions

- School year 2026–2027: 2026-06-08 through 2027-04-08, confirmed by the user.
- Retain attendance-private/hosting.php, original encryption keys and student-photos.
- A settings key file alone does not prove the app can read it: verify SETTINGS_ENCRYPTION_KEY through the existing configuration mechanism.
- The Single plan has two cron slots. Keep the first; consolidate maintenance into the second.
- Daily backups run after 02:00 Manila; queued backups run every maintenance tick. Interventions dispatch only when the existing explicit enablement setting is populated; otherwise evaluate with --dry-run.
- Work in the current checkout because the requested release consists of its existing uncommitted changes; creating a clean worktree would omit those changes. Do not commit or alter unrelated work.
- Live finalizer output already reports failure. Diagnose separately; do not declare it repaired without a successful live run.
- No overwrite before the reviewed target/recovery/cutover is ready. No guessed historical enrollment ownership or flag edits.

## Tasks

- [x] Run CLI-only preflight through a temporary cron slot; inspect counts, key availability and required binaries. Remove the temporary cron after collecting output.
- [x] Write and run failing coordinator tests for Manila scheduling, disabled intervention sending and failure isolation; retain CLI-only entrypoint guards.
- [x] Implement the coordinator with an overlap lock; rerun the focused tests. Ruling: use in-process service calls instead of subprocess isolation because live PHP disables proc_open.
- [x] Add and verify a PHP/PDO dump fallback, including a real isolated restore and a separate backup run with proc_open disabled.
- [x] Extend the existing ZIP builder with a custom output directory and coordinator allowlist entry. Build a versioned package without replacing older packages or copying private keys into the ZIP. Normalize ZIP entry paths explicitly for Linux extraction.
- [x] Document exact hPanel upload, database migration and cron steps for the verified live schema. Generate additive SQL and the confirmed, fingerprint-guarded enrollment map.
- [x] Rehearse the supplied export in an isolated database: 272 students, 1853 attendance rows, 92 sections; mapping/initialization has zero blocking issues; identities/contacts/scans/archived status preserved; 248 grade/year projections filled; original key decrypts original history. Remove the disposable database afterward.
- [x] Verify package entries, PHP syntax, coordinator tests and preflight results. All 1030 upgrade files match current source; four migration cron commands fit within 255 characters.

## Handoff boundary

Final artifacts: `deployment/hostinger-single-20261027-final`. The live database/application release was not replaced. Only the CLI preflight was uploaded, and the temporary diagnostic cron was removed. The existing failing finalizer remains unchanged. Live recovery verification, maintenance, upload, SQL/map application, live preview confirmation, backup path creation and permanent maintenance cron installation are explicit steps in START-HERE.md. No outbound test message was sent.
