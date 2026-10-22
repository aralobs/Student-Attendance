# Editable notification templates

Upload `notification-templates-update.zip` to Hostinger's `public_html`, extract
it directly there and replace its seven files. Back up the replaced files first.
Remove the ZIP afterward. It includes both required new helper files and the
previous pending-SMS fix. No database schema migration is needed.

Reload Settings with Ctrl+F5. The SMS section now shows Arrival, Departure,
Absence and Missing Time-Out messages. The email section shows subjects and
plain-text message fields for Arrival, Departure and Absence. All fields have
defaults; existing saved shared messages remain in use. Old AM/PM SMS messages
are used as fallbacks until shared arrival/departure templates are saved. Saving
the shared fields applies them to both AM and PM scan events.

Available variables are `{student_name}`, `{time}`, `{date}`, `{section}` and
`{school_name}`. The missing Time-Out SMS uses the reviewed attendance date, even
when reviewing an earlier day. Emails retain the school layout and treat edited
messages as plain text, escaping HTML. Blank saved fields use default messages.
Use **Save All Settings** after editing. Leave API key and Gmail password fields
blank to retain their saved values.

This update does not send messages or retry earlier alerts. The email test still
tests SMTP delivery using the generic test message. Check edited event messages
when the corresponding notification is next deliberately sent.

Missing Time-Out alerts remain teacher-selected SMS only. Absence templates do
not introduce automatic notifications; the cron finalizer sends no parent SMS or
email. Automatic emails on scans now respect the Enable Email Notifications
toggle. Earlier log status corrections remain a separate SQL action.
