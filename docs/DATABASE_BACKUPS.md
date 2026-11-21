# Database backups

**Database Backups** is administrator-only. **Create Backup** queues a job; workers create a complete database copy spanning all school years. **Download** rechecks administrator authority, completed state, private path containment, and SHA-256 before returning a private attachment. Failed/incomplete artifacts are not downloadable.

## Private configuration

Create an operating-system-protected directory outside both the website document root and the source checkout. Grant only the hosting/worker account the needed access; on Windows configure an equivalent restrictive ACL, because PHP `chmod` does not establish Windows ACL isolation. The operator must verify these permissions before enabling exports.

Add to the existing private hosting configuration:

```php
'BACKUP_DIR' => '/absolute/private/attendance-backups',
'BACKUP_DUMP_BINARY' => '/absolute/path/to/mysqldump',
```

Use the actual host's supported `mysqldump`/`mariadb-dump` path. XAMPP commonly uses `C:/xampp/mysql/bin/mysqldump.exe`. Verify executable availability, PHP process execution, filesystem capacity, database grants, and TLS options on the target host. Do not point backup storage at `public_html`, `htdocs`, or the repository. The hosting configuration loader supports these keys.

The worker invokes the dump binary with an argument array and a short-lived private client option file, keeping the password off the command line. It requests consistent InnoDB export with tables/data, indexes, keys, triggers, routines, and events. Avoid concurrent migrations/DDL during backup. A restricted or unsupported dump command fails visibly; there is no partial PDO-export fallback. Preserve the existing database TLS CA configuration where used; verify the target dump client supports the supplied TLS verification options.

## Schedules

Daily export at **02:00 Asia/Manila**:

```text
php tools/backup_database.php
```

Manual queue processing every minute:

```text
php tools/backup_database.php --queued-only
```

Use Hostinger cron or Windows Task Scheduler. Convert the daily hour if the hosting scheduler uses UTC/another timezone. The worker uses Manila time for metadata/folders regardless of scheduler timezone. `--dry-run` reports queued counts without exporting or rotating. A database advisory lock prevents overlapping exports, and the daily date key prevents duplicate successful daily copies.

Backups are written privately, compressed, checksummed, and published as completed only after successful export. Files are grouped as `YYYY/MM/DD/<opaque-id>.sql.gz`, based on creation time rather than academic year. All school years remain inside every dump.

## Retention and failures

- Ordinary daily/manual copies expire after 30 daily dates.
- The first successful daily copy of a calendar month is protected until the start of that month 12 months later; ordinary daily rotation cannot remove it early.
- An administrator may set an explicit retention expiry on a completed copy.
- The newest completed copy is retained even after ordinary expiry, so failed replacements cannot remove the last usable copy.
- Rotation removes only known completed backup files under the configured root and marks their metadata expired. It does not delete any student/attendance records.

Review failed or stuck jobs, disk space, permissions, dump-client support, and grants. Failure messages are generic to avoid exposing credentials. A failed private `.partial.sql`/`.partial.gz` or unregistered final artifact may remain for operator investigation; it is never served. Review and remove only those identified failed artifacts under the configured private root before deliberately retrying. The worker refuses to overwrite an earlier artifact silently. No automatic retry promises recovery from a crash or storage failure.

Keep a protected copy outside the hosting server: download through the administrator page and transfer/store it using the school's approved private process. The app does not upload school exports to a new external service automatically.

## Restore verification

A database dump excludes photos/uploads and external encryption keys. Preserve original PII/settings keys separately through the established private key-management process, and preserve uploads with a separate manifest. Losing or replacing the original keys can make restored encrypted values unreadable.

Restore only into an isolated empty database with external providers disabled. Verify table counts, indexes/foreign keys, trigger definitions and append-only behavior, representative encrypted history/remarks with original keys, and separate uploaded files. Check the dump checksum before import. Preserve credentials, dumps and decrypted values privately, outside source control and tool output.

`php tests/backup_restore_test.php` exercises the real dump/import clients on synthetic disposable databases, including counts, indexes, a trigger, and an encrypted value with its original test key. This is development proof, not evidence that a production school export or off-server recovery copy exists.
