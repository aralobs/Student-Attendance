<?php
// Synthetic settings and mail sender; no database or network access.
$settings = []; $deliveries = [];
function getSetting(string $key): ?string { return $GLOBALS['settings'][$key] ?? null; }
require_once __DIR__ . '/../includes/notification_templates.php';
function templateCheck(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
foreach (notificationTemplateDefaults() as $key => $default) templateCheck(notificationTemplate($key) === $default, 'Missing defaults');
$settings['sms_am_arrival_template'] = 'Legacy AM';
$settings['sms_pm_arrival_template'] = 'Legacy PM';
templateCheck(notificationTemplate('sms_arrival_template') === 'Legacy AM', 'Legacy settings not visible');
templateCheck(notificationTemplate('sms_pm_arrival_template') === 'Legacy PM', 'Legacy event-specific template lost');
$settings['sms_arrival_template'] = 'Shared arrival';
templateCheck(notificationTemplate('sms_am_arrival_template') === 'Shared arrival'
    && notificationTemplate('sms_pm_arrival_template') === 'Shared arrival', 'Saved shared template ignored by scans');
$settings['sms_arrival_template'] = '';
templateCheck(notificationTemplate('sms_pm_arrival_template') === notificationTemplateDefaults()['sms_arrival_template'], 'Blank saved template must use default');
$settings['school_name'] = 'Test School';
$student = ['first_name'=>'Test', 'last_name'=>'Student', 'section_name'=>'A', 'parent_email'=>'test@example.invalid'];
templateCheck(renderNotificationText('{student_name}|{date}|{time}|{section}|{school_name}', $student, '2026-10-09', '09:00 AM')
    === 'Test Student|October 9, 2026|09:00 AM|A|Test School', 'Variable/date substitution incorrect');
function sendEmail(...$args): array { $GLOBALS['deliveries'][] = $args; return ['success'=>true]; }
function buildEmailTemplate($title, $body, $color): string { return $body; }
$source = file_get_contents(__DIR__ . '/../includes/mail_helper.php');
$start = strpos($source, 'function sendStudentNotificationEmail(');
eval(substr($source, $start));
$settings['email_arrival_subject'] = 'Hello {student_name}';
$settings['email_arrival_template'] = "<script>alert(1)</script>\n{student_name}\n{section}";
$student['first_name'] = "<img src=x>\r\nInjected";
templateCheck(sendArrivalEmail($student), 'Arrival helper failed');
$mail = $deliveries[0];
templateCheck(!str_contains($mail[2], "\r") && !str_contains($mail[2], "\n"), 'Subject header injection');
templateCheck(!str_contains($mail[3], '<script>') && !str_contains($mail[3], '<img'), 'Email text became HTML');
templateCheck(str_contains($mail[3], '&lt;script&gt;') && str_contains($mail[3], '<br'), 'Literal text/newlines lost');
templateCheck(str_contains($mail[4], '<script>'), 'Plain text alternative changed');
$settings['email_departure_template'] = 'Goodbye {student_name}';
templateCheck(sendDepartureEmail($student) && str_starts_with($deliveries[1][4], 'Goodbye'), 'Departure setting ignored');
$settings['email_absence_template'] = 'Absent {student_name}';
templateCheck(sendAbsenceEmail($student) && str_starts_with($deliveries[2][4], 'Absent'), 'Absence setting ignored');
$settings['email_notifications'] = '0';
templateCheck(!sendArrivalEmail($student) && count($deliveries) === 3, 'Email toggle ignored');
echo "Notification template checks passed (defaults, legacy/shared SMS, dates, email bodies, escaping, subjects and toggle; no sends).\n";
