# Integration credentials

Administrators paste credentials into Settings and save. Saved credentials are never populated in the browser. Blank replacement fields preserve existing credentials; tests use the saved values. A configured status means a credential is saved, not that the provider has verified it.

SMS keys, Gmail App Passwords, and Calendarific API keys are encrypted using AES-256-GCM when written to `system_settings`. Existing plaintext credentials continue working and are encrypted on the next successful settings save. Old database backups may still contain plaintext credentials.

Calendarific credentials are managed by administrators from School Calendar > Calendarific API, beside Add Entry. Save API Key stores a replacement; a blank field preserves the saved key. Test Connection and Import Holidays use the saved key through server-side HTTPS requests with certificate verification. Imports request Philippine national holidays for the selected calendar year, preserve every existing calendar entry, and combine holidays sharing a date. Import each calendar year separately for a school year spanning two years. Imports are manual, not scheduled refreshes; they do not correct seeded dates or replace existing entries. PHP cURL and mbstring are required. No database migration is needed. Run `php tests/calendarific_test.php` for isolated response-validation and transactional-import checks; it uses neither the database nor the provider.

PHP's OpenSSL extension is required for encrypted settings (enabled in the standard XAMPP installation).

For local XAMPP installations, the application automatically creates `config/settings_key.php` on the first encrypted save. This PHP file produces no output when requested directly and is ignored by Git. Restrict its filesystem access to the application account, preserve it across upgrades, and back it up securely separately from the database. Losing this key requires entering the provider credentials again.

For hosted deployments (especially ephemeral Railway deployments), the operator must supply a stable `SETTINGS_ENCRYPTION_KEY` environment variable before saving credentials. Its value is a base64-encoded random 32-byte key. Keep this value across deployments; do not switch between a generated local key and an environment key without migrating credentials. School staff do not need to manage this variable.

The encryption protects database-only exposure; an attacker controlling the server or an authorized credential-changing administrator can still misuse the integration. HTTPS is required when administering the system over a network.

Run the isolated encryption and CSRF checks with `php tests/settings_security_test.php`. They do not access the database or send SMS/email.
