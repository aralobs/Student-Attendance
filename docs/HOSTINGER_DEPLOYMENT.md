# Hostinger ZIP deployment

The generated `deployment/hostinger` folder contains:

| Item | Purpose |
| --- | --- |
| `hostinger-upload.zip` | Upload this ZIP and extract directly into `public_html`. |
| `public_html/` | The application files already included in that ZIP. |
| `not-for-upload/` | Development tests, documentation, QA output and build metadata. Keep locally. |
| `database/` | SQL import files; import through phpMyAdmin, not into the website folder. |
| `private-transfer/` | Existing encryption keys and a configuration example; transfer privately, outside `public_html`. |

The build copies files without moving or deleting your working source. `.git`,
existing session files, student uploads, QR images and private runtime files are
not included in the upload ZIP. Migration scripts remain in the ZIP because they
are needed for an existing database; `.htaccess` blocks HTTP access to them.

1. In hPanel, add your PHP website and enable HTTPS. Select a supported PHP
   version satisfying PHP >= 8.1. Enable PDO, pdo_mysql, calendar, GD, mbstring,
   OpenSSL and cURL (used by integrations). The ZIP includes the installed Composer `vendor` directory, so you
   do not need to install dependencies on the server for this upload.
2. Upload **only `hostinger-upload.zip`** into `public_html`, extract it, then
   remove the uploaded ZIP. `index.php` and `.htaccess` must be directly inside
   `public_html`, not inside another `public_html` folder. Back up an existing
   website before replacing its files.
3. Create a MySQL database/user in hPanel and note its exact prefixed names.
   For an existing school installation, export the CURRENT database from local
   phpMyAdmin (including data and triggers), then import it into the new empty
   Hostinger database. The included `database/attendance_system.sql` is
   **schema-only**: it contains no school records or login accounts. Use it only
   for a fresh installation. `database_schema_update.sql` is a reference schema,
   not an incremental update to import over a populated database.
4. Beside `public_html`, create `attendance-private`. For example:

   ```text
   /home/u123456789/domains/example.com/
       public_html/
       attendance-private/
           hosting.php
           pii_key.php
           student-photos/
   ```

   Copy `private-transfer/hosting.example.php` there as `hosting.php`, then edit
   its database credentials, HTTPS URL and absolute paths. Use the database host
   shown by Hostinger. Only `localhost`/loopback databases can omit `DB_TLS_CA`;
   a remote host requires a readable verified CA bundle under the app's existing
   security checks. The loader assumes the app is installed directly in
   `public_html`. Server environment variables take precedence over this file.

5. Preserve the original encryption keys when transferring existing records.
   Upload `private-transfer/pii_key.php` to `attendance-private/pii_key.php`.
   Open the original `settings_key.php` locally and copy ONLY the Base64 value
   after `settings-key:` into `SETTINGS_ENCRYPTION_KEY` in `hosting.php`.
   Do not replace existing keys with newly generated ones. Keep private folder
   permissions restricted to your hosting account (normally directories 700,
   files 600); PHP must be able to read them. Do not upload the whole deployment
   folder to the website.
6. Transfer existing student photos separately to the configured
   `attendance-private/student-photos` directory, preserving filenames. Current
   XAMPP photos normally live at `C:/xampp/private/attendance-system/student-photos`;
   check your `STUDENT_PHOTO_DIR` if you customized it. Check for any unmigrated
   images in `uploads/students` as well. Existing session files, finalizer locks
   and generated QR files are not migrated; users sign in again and can generate
   QR cards from the app.
7. For an older database, follow the existing migration notes in
   `not-for-upload/docs/`. Run required migrations through SSH/CLI with the
   migration database identity. Verify the audit tables and their four triggers
   exist after import. Use a separate runtime identity with the documented
   per-table permissions; verify your Hostinger plan permits these grants and
   triggers. Do not disable audit protections to bypass a hosting limitation.
8. In hPanel Cron Jobs, select PHP and enter the absolute script path:

   ```text
   /home/u123456789/domains/example.com/public_html/attendance/finalize_absences.php
   ```

   Set minute, hour, day, month and weekday to `*` (every minute). The PHP binary
   used by cron must also satisfy the version/extension requirements. The worker
   loads the same private `hosting.php`; Apache `SetEnv` alone does not configure
   cron. Verify its successful output and monitor later executions. If your plan
   cannot run it every minute, resolve that before switching the live school.
9. Before switching users, verify login, teacher/scanner permissions, scans,
   private student photos, QR generation, reports, audit history and cron
   finalization. Verify `/config/database.php`, `/migrations/security_audit.php`,
   `/storage/` and `/uploads/students/` return 403. Check HTTP redirects to HTTPS
   and the browser camera works over HTTPS. Test email/SMS deliberately with a
   designated test recipient; confirm transferred integration settings decrypt.

Build a new package after changing source files:

```powershell
powershell -ExecutionPolicy Bypass -File tools/hostinger/build.ps1
```

The builder refuses to overwrite an existing output folder. Rename the previous
`deployment/hostinger` folder first. Keep its private-transfer contents secure.
The ZIP is prepared locally; live Hostinger configuration is still required.

For annual enrollment, follow the additional [cutover and recovery sequence](SCHOOL_YEAR_ENROLLMENT.md)
before restarting cron or opening access. Verify the complete release and a
restorable database export with original keys/triggers and separate uploads.
Block new web writes, drain requests, and stop/drain the old worker; apply
schema and mappings, confirm the reviewed initial-year preview through CLI,
then restart only the converted worker. A ZIP/schema install alone does not
enable annual workflows.

Hostinger instructions:
- [File upload and custom PHP websites](https://www.hostinger.com/support/11792202-how-to-install-custom-php-on-hostinger-agency-plan/)
- [Database imports](https://www.hostinger.com/support/1884149-how-to-import-a-database-with-phpmyadmin-in-hostinger/)
- [Cron jobs](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/)
