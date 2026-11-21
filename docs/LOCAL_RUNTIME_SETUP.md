# Local ASAM runtime setup

Verified on 26 October 2026, Asia/Manila, for this XAMPP checkout and local `attendance_system` database on port 3306. This record does not describe a remote/Hostinger deployment.

## Database and recovery

Annual enrollment was already initialized for 2026–2027, and the panel tables were already present. The additive `migrations/panel_revisions.php` migration was applied again successfully. The finalizer reported zero attendance changes during verification.

Before migration, a full database export and SHA-256 checksum, original PII/settings key files, uploaded files, original application access rules, and the prior finalizer task definition were preserved privately in:

```text
C:\xampp\asam-private\recovery\20261026-114011
```

This is a local recovery snapshot. A restore of this school database and an off-machine copy were not performed.

## Private configuration and backups

The application's existing private configuration loader reads:

```text
C:\xampp\htdocs\attendance-private\hosting.php
```

The directory has inheritance disabled and access limited to the local operator, SYSTEM, and Administrators. Its `.htaccess` denies HTTP access; requesting the configuration returned HTTP 403. The login page and school logo returned HTTP 200 after maintenance ended.

Configuration sets the dump binary to `C:/xampp/mysql/bin/mysqldump.exe` and backup storage to:

```text
C:\xampp\asam-private\backups
```

This storage is outside `htdocs` and the checkout, with restrictive Windows ACLs. One managed daily backup completed; its stored checksum/path verification passed. The existing year/month/date organization and retention rules apply. Later runs on the same date reuse that completed daily backup.

## Scheduled workers

| Windows task | Schedule in Asia/Manila | Function |
| --- | --- | --- |
| `AttendanceSystem-FinalizeAbsences` | Every minute | Attendance finalization; existing task corrected from its old daily trigger |
| `ASAM-AbsenceInterventions` | Every five minutes | Recompute warnings and process eligible intervention notifications |
| `ASAM-BackupQueue` | Every minute | Process administrator-requested backups |
| `ASAM-DailyBackup` | Daily at 02:00 | Full daily database backup and retention |

All four tasks had successful last-run results (`0`) when checked. The first manual scheduled finalizer launch was terminated without a completion log; its subsequent ordinary scheduled run completed with exit `0`, confirming the registered action worked. A further diagnostic launch was rejected by automatic approval review and was not retried.

Tasks use the signed-in `jeanp` account with limited privileges and hidden windows. They require that account to remain signed in and XAMPP MySQL to be running. They do not install a continuously available Windows database service. Start-when-available is enabled for missed triggers. The host timezone is UTC+08:00, matching Manila for these schedules.

The private launcher is `C:\xampp\asam-private\Run-Worker.ps1`. Per-day worker logs are under `C:\xampp\asam-private\logs`. Monitor task exit codes, failed backup jobs, free space, and failed/unknown notification outcomes.

## SMS activation and verification limits

`ATTENDANCE_INTERVENTION_ENABLED_AT` is set to **2026-10-26 11:40:24 Manila time**. Existing saved SMS credentials, sender configuration, and the intervention template were retained. Daily manual-absence SMS remains separate from intervention activation.

The evaluation dry-run completed. At verification, 481 active episodes were marked `needs_review`; these are episodes, not a count of distinct learners. None was intervention-required. Staff must resolve the underlying attendance evidence through the normal review workflow.

Scheduled intervention processing completed with zero sent, failed, or unknown messages. The two pre-existing failed SMS log entries were unchanged. No test SMS was sent. An unauthenticated HTTPS HEAD request verified certificate/hostname trust and provider connectivity (HTTP 404); it does not validate account balance, API credentials, sender approval, or end-to-end delivery.

The 3/5 thresholds remain school intervention policy. Current official DepEd applicability remains subject to the verification limits in [the policy note](ATTENDANCE_INTERVENTION_POLICY.md).

See [deployment instructions](PANEL_REVISIONS_DEPLOYMENT.md) and [backup operation/recovery requirements](DATABASE_BACKUPS.md) for continued operation and future installations.
