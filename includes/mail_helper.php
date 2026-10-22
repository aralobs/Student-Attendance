<?php
/**
 * Mail Helper
 * Uses PHPMailer + Gmail SMTP
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/notification_templates.php';

/**
 * Send email notification
 *
 * @param string $toEmail    Recipient email
 * @param string $toName     Recipient name
 * @param string $subject    Email subject
 * @param string $htmlBody   HTML email body
 * @param string $plainBody  Plain text fallback
 * @return array             ['success' => bool, 'message' => string]
 */
function sendEmail(string $toEmail, string $toName, string $subject, string $htmlBody, string $plainBody = ''): array {
    $host      = getSetting('mail_host')      ?? 'smtp.gmail.com';
    $port      = (int)(getSetting('mail_port') ?? 587);
    $username  = getSetting('mail_username')  ?? '';
    $password  = getSetting('mail_password')  ?? '';
    $fromName  = getSetting('mail_from_name') ?? 'SPCCS Kinder Attendance';
    $fromEmail = getSetting('mail_from_email') ?? $username;

    if (empty($username) || empty($password)) {
        return ['success' => false, 'message' => 'Email not configured in Settings.'];
    }

    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = $host;
        $mail->SMTPAuth   = true;
        $mail->Username   = $username;
        $mail->Password   = $password;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port;
        $mail->SMTPDebug  = SMTP::DEBUG_OFF;

        // Sender
        $mail->setFrom($fromEmail, $fromName);
        $mail->addReplyTo($fromEmail, $fromName);

        // Recipient
        $mail->addAddress($toEmail, $toName);

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainBody ?: strip_tags($htmlBody);

        $mail->send();
        return ['success' => true, 'message' => 'Email sent successfully.'];

    } catch (Exception $e) {
        error_log('PHPMailer Error: ' . $mail->ErrorInfo);
        return ['success' => false, 'message' => $mail->ErrorInfo];
    }
}

/**
 * Build HTML email template
 * Wraps content in a clean school-branded email layout
 */
function buildEmailTemplate(string $title, string $body, string $color = '#1a56db'): string {
    $schoolName = getSetting('school_name') ?? 'San Pablo City Central School';
    $schoolYear = getSetting('school_year') ?? '';

    return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:30px 0">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0"
                       style="background:#ffffff;border-radius:12px;overflow:hidden;
                              box-shadow:0 4px 20px rgba(0,0,0,0.08);max-width:600px;width:100%">

                    <!-- Header -->
                    <tr>
                        <td style="background:{$color};padding:28px 32px;text-align:center">
                            <div style="font-size:28px;margin-bottom:8px">🎓</div>
                            <h1 style="color:#ffffff;margin:0;font-size:20px;font-weight:700">
                                {$schoolName}
                            </h1>
                            <p style="color:rgba(255,255,255,0.8);margin:4px 0 0;font-size:13px">
                                Kindergarten Department &bull; S.Y. {$schoolYear}
                            </p>
                        </td>
                    </tr>

                    <!-- Title bar -->
                    <tr>
                        <td style="background:#f8fafc;padding:16px 32px;
                                   border-bottom:2px solid #e5e7eb">
                            <h2 style="margin:0;font-size:16px;color:#374151;font-weight:700">
                                {$title}
                            </h2>
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:28px 32px;color:#374151;font-size:15px;line-height:1.7">
                            {$body}
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f8fafc;padding:20px 32px;
                                   border-top:1px solid #e5e7eb;text-align:center">
                            <p style="margin:0;font-size:12px;color:#9ca3af">
                                This is an automated message from the SPCCS Attendance System.<br>
                                Please do not reply to this email.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
}

/**
 * Send arrival email to parent
 */
function sendStudentNotificationEmail(array $student, string $event): bool {
    if (empty($student['parent_email']) || getSetting('email_notifications') === '0') return false;
    $labels = ['arrival' => ['Arrival Notification', '#0e9f6e'],
        'departure' => ['Departure Notification', '#1a56db'],
        'absence' => ['Absence Notification', '#e02424']];
    if (!isset($labels[$event])) throw new InvalidArgumentException('Unsupported email notification');
    [$title, $color] = $labels[$event];
    $subject = renderNotificationText(notificationTemplate('email_' . $event . '_subject'), $student);
    $subject = str_replace(["\r", "\n"], ' ', $subject);
    $plain = renderNotificationText(notificationTemplate('email_' . $event . '_template'), $student);
    $body = '<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>';
    $result = sendEmail($student['parent_email'], 'Parent/Guardian', $subject,
        buildEmailTemplate($title, $body, $color), $plain);
    return $result['success'];
}

function sendArrivalEmail(array $student): bool {
    return sendStudentNotificationEmail($student, 'arrival');
}

function sendDepartureEmail(array $student): bool {
    return sendStudentNotificationEmail($student, 'departure');
}

function sendAbsenceEmail(array $student): bool {
    return sendStudentNotificationEmail($student, 'absence');
}
