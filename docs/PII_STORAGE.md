# PII storage changes (F10)

Student photos now use `student_photo.php?id=...`. Every request authenticates the
user and checks the student's current section. Only administrators and assigned
teachers can obtain a photo; inactive students, unrelated sections and scanner
accounts are denied. Responses are no-store. Missing or invalid images use local
SVG initials. Student names are no longer sent to an external avatar service.
QR cards read and embed the same private image, so downloaded cards still work
offline. This does not change QR tokens.

Set **STUDENT_PHOTO_DIR** to an absolute persistent directory outside your web
document root, owned by the application account. On local XAMPP the default is
`C:/xampp/private/attendance-system/student-photos`. Grant only the application
account and designated operators access using Windows ACLs (Unix chmod alone
does not establish Windows permissions). To relocate existing photos, run:

```text
php migrations/private_student_photos.php
```

The migration preserves database filenames and refuses to overwrite conflicting
files. The old directory is denied HTTP access immediately; the photo handler
can read its files during migration. Disable static serving of that directory on
every deployed server. A successful migration removes the old public copies.

SF2 monthly remarks now live in `sf2_remarks`. Saves require authentication, a
CSRF token, an active student in an authorized active section, bounded input and
shared request limits. Optimistic versions reject stale updates rather than
silently overwriting another tab. An append-only application change history
records actor, target, version and time with encrypted before/after text.
Use database grants that allow INSERT/SELECT but not UPDATE/DELETE on this history
for the runtime account; it is not tamper-proof against a privileged DBA.
The display reports save failures and offers retry. It warns before navigating
away with unsaved changes. Printed remarks come from the current display.

Run `php migrations/pii_storage.php` after deploying to an existing database.
It creates two supporting tables and an independent encryption key; it does not
rewrite any existing student, attendance or account record. This migration was
run on this local installation. Both SQL imports contain the new structures.
The AES-256-GCM ciphertext authenticates its report/student identity; encrypted
history authenticates its unique event ID. General error logs never contain the
remark text. Other authorized teachers for that section and administrators can
see saved remarks; browser/account changes no longer isolate authorized staff
from the report's stored data.

In production set **PII_ENCRYPTION_KEY** to base64 of 32 cryptographically random
bytes in a secret manager, or **PII_KEY_FILE** to a private persistent file outside
the document root. The local fallback `config/pii_key.php` is ignored by Git and
blocked by HTTP rules. Keep this key separately from database dumps and preserve
a secure recovery copy: losing or replacing it makes existing encrypted remarks
unreadable. Do not replace the key without a controlled re-encryption migration.
Restrict secret-manager/file access to the application identity and designated
operators. Do not put keys in logs or commit them.

As explicitly selected, old `sf2_remarks_<section>_<month>_<year>` localStorage
entries are discarded when the browser opens login or a shared-header page.
They are never imported or displayed. No other browser keys are removed.
Previously used offline browsers retain their old copies until they revisit;
clear site data on retired/shared devices under your device-management process.
No new remark text is stored in localStorage or sessionStorage.

Both tracked SQL imports are schema-only, with no populated INSERTs or default
accounts. Local recovery copies are private and must be excluded from deployment,
repositories and shared archives. Current database records were not deleted.
The existing schema imports are for fresh installations; the alternate schema
file still drops/recreates tables. Do not import either over live school data.
For existing installations, use only the supporting-table migration above.
Old Git commits may still contain the original dump: review repository visibility
and exposure, coordinate any history cleanup with collaborators, and investigate
before rotating credentials or student tokens. No exposure or compromise is
asserted here and no live tokens were rotated.

`.htaccess` enforces deny rules on local Apache (mod_rewrite, AllowOverride All).
Equivalent restrictions are mandatory if production uses another server; a
server that ignores this file is not protected by it. For Nginx, place these
locations before the PHP handler and adapt the prefix if installed in a subpath:

```nginx
location ~* ^/(?:\.git|\.qa|\.agents|\.codex|config|includes|migrations|tests|docs|storage)(?:/|$) { deny all; }
location ~* ^/uploads/students(?:/|$) { deny all; }
location ~* (?:^|/)(?:\.[^/]+|[^/]+\.(?:sql|bak|backup|old|orig|log|env|ini|zip|tar|gz|7z))$ { deny all; }
location ~* /(?:composer\.(?:json|lock)|SECURITY_REVIEW\.md|README\.md)$ { deny all; }
```

Deployment assurance still required: verify encryption for database volumes,
photo storage, exports and backups; retain only necessary PII/SMS content under
the school's approved retention schedule; test backup recovery with separately
protected keys. Existing student/contact fields and attendance remarks are not
newly field-encrypted by this patch. Storage encryption mitigates lost-media and
backup disclosure but does not protect against a compromised running application
or a privileged database reader. If that is in scope, separately plan a staged
field-encryption migration for contacts and daily remarks, including notification,
search/export and retention behavior. Do not blindly encrypt columns still used
by existing queries or delete school records without an approved retention policy.

These controls follow the [OWASP Cryptographic Storage Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html)
and [OWASP Authorization Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html).

Validation: `php tests/pii_storage_test.php` uses a disposable database, synthetic
photos and an isolated key. Run the student authorization and QR card regressions
as well. Verify HTTP 403/404 for dumps, keys, internal paths and direct uploads on
the actual production host; verify guest, scanner and unrelated-section denial
on the photo and SF2-save endpoints. No real notification providers are called.
