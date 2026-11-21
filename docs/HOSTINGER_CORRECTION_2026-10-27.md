# Premature-absence correction — 27 October 2026

Target: springgreen-cobra-784324.hostingersite.com, existing school database.

At 02:50 Manila the read-only live probe found 16 current-day absent records, all with no scans, no manual decisions and a full-day section schedule containing PM start/end `00:00:00`; the AM start was `08:00:00`. PHP and database clocks agreed. The operator approved removing only these erroneous automatic records after a verified recovery copy and encrypted audit trail.

The finalizer now treats a midnight cutoff as an unset school schedule and creates no automatic absence for it. It retains normal AM-only and valid full-day cutoff behavior. The shared header now versions its CSS URLs by content hash; actual browser appearance remains subject to user verification.

On the 07:35:01 Manila maintenance run, the scoped correction removed exactly 16 rows. It excluded scanned records, manual decisions, nonempty remarks, staff-recorded entries and records lacking a successful automatic-finalization audit event. It acquired structural/student/attendance locks, saved and decrypted a recovery snapshot before deletion, wrote one encrypted correction audit per row, and queued intervention recomputation. The current-day absent count afterward was zero. Both intervention and backup workers returned exit code zero. The finalizer subsequently reported zero changes.

Recovery file, relative to the configured private BACKUP_DIR:

```text
corrections/premature-absence-20261027-ec55fb87fee48e06.enc
SHA-256: 5f020d8328aa982ad32856565ab992e90c11eed43c466c3c52afb10d57ed2fd8
```

It uses the original PII key and authenticated-encryption context `attendance-recovery:2026-10-27`. Its plaintext contains the original full rows, not printed or copied into this repository. A private completion receipt prevents duplicate correction. The temporary correction hook was removed and the ordinary maintenance entry point read back successfully; the normal two permanent cron schedules were retained. The one-off CLI helper is date/time restricted and has no HTTP execution path.

Proof: isolated correction checks cover recovery failure, preservation of actual scans and manual decisions, audit records, recomputation and harmless retries. Finalizer cutoff, header asset-version, header escaping, finalizer worker and annual attendance checks passed. The delivery-finalization test uses an administrator for its synthetic historical decision so it tests finalizer protection independently of the rolling teacher editing window.

Follow-up: correct the affected sections' actual schedule type/end time through the normal section-management workflow. Automatic absence finalization is deliberately skipped for a midnight placeholder; no replacement cutoff was invented. Confirm the shared selector renders correctly after a fresh page load.
