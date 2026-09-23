<?php
/**
 * Mail Helper
 * Uses PHPMailer + Gmail SMTP
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once dirname(__DIR__) . '/vendor/autoload.php';

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
function sendArrivalEmail(array $student): bool {
    if (empty($student['parent_email'])) return false;

    $name    = $student['first_name'] . ' ' . $student['last_name'];
    $time    = date('h:i A');
    $date    = date('F j, Y');
    $section = $student['section_name'] ?? 'Kindergarten';

    $subject = "✅ {$name} has arrived at school";

    $body = <<<HTML
        <p>Dear Parent/Guardian,</p>
        <p>We are pleased to inform you that your child has arrived safely at school.</p>

        <table width="100%" cellpadding="0" cellspacing="0"
               style="background:#f0fdf4;border:1px solid #bbf7d0;
                      border-radius:8px;padding:20px;margin:20px 0">
            <tr>
                <td>
                    <p style="margin:0 0 8px"><strong>👤 Student:</strong> {$name}</p>
                    <p style="margin:0 0 8px"><strong>🏫 Section:</strong> {$section}</p>
                    <p style="margin:0 0 8px"><strong>📅 Date:</strong> {$date}</p>
                    <p style="margin:0"><strong>🕐 Time In:</strong> {$time}</p>
                </td>
            </tr>
        </table>

        <p>Your child is now in school and under our supervision. Have a great day!</p>
        <p>Best regards,<br><strong>SPCCS Kindergarten Department</strong></p>
HTML;

    $html   = buildEmailTemplate("Arrival Notification", $body, '#0e9f6e');
    $result = sendEmail($student['parent_email'], 'Parent/Guardian', $subject, $html);

    return $result['success'];
}

/**
 * Send departure email to parent
 */
function sendDepartureEmail(array $student): bool {
    if (empty($student['parent_email'])) return false;

    $name    = $student['first_name'] . ' ' . $student['last_name'];
    $time    = date('h:i A');
    $date    = date('F j, Y');
    $section = $student['section_name'] ?? 'Kindergarten';

    $subject = "🚪 {$name} has left school";

    $body = <<<HTML
        <p>Dear Parent/Guardian,</p>
        <p>This is to inform you that your child has been dismissed from school.</p>

        <table width="100%" cellpadding="0" cellspacing="0"
               style="background:#eff6ff;border:1px solid #bfdbfe;
                      border-radius:8px;padding:20px;margin:20px 0">
            <tr>
                <td>
                    <p style="margin:0 0 8px"><strong>👤 Student:</strong> {$name}</p>
                    <p style="margin:0 0 8px"><strong>🏫 Section:</strong> {$section}</p>
                    <p style="margin:0 0 8px"><strong>📅 Date:</strong> {$date}</p>
                    <p style="margin:0"><strong>🕐 Time Out:</strong> {$time}</p>
                </td>
            </tr>
        </table>

        <p>Please ensure someone is available to receive your child. Thank you!</p>
        <p>Best regards,<br><strong>SPCCS Kindergarten Department</strong></p>
HTML;

    $html   = buildEmailTemplate("Departure Notification", $body, '#1a56db');
    $result = sendEmail($student['parent_email'], 'Parent/Guardian', $subject, $html);

    return $result['success'];
}

/**
 * Send absence email to parent
 */
function sendAbsenceEmail(array $student): bool {
    if (empty($student['parent_email'])) return false;

    $name    = $student['first_name'] . ' ' . $student['last_name'];
    $date    = date('F j, Y');
    $section = $student['section_name'] ?? 'Kindergarten';

    $subject = "⚠️ Absence Notice — {$name}";

    $body = <<<HTML
        <p>Dear Parent/Guardian,</p>
        <p>We would like to inform you that your child was marked <strong>absent</strong> today.</p>

        <table width="100%" cellpadding="0" cellspacing="0"
               style="background:#fef2f2;border:1px solid #fecaca;
                      border-radius:8px;padding:20px;margin:20px 0">
            <tr>
                <td>
                    <p style="margin:0 0 8px"><strong>👤 Student:</strong> {$name}</p>
                    <p style="margin:0 0 8px"><strong>🏫 Section:</strong> {$section}</p>
                    <p style="margin:0"><strong>📅 Date:</strong> {$date}</p>
                </td>
            </tr>
        </table>

        <p>If this absence was unplanned, please contact the school at your earliest convenience.
           If your child is ill or has a valid reason, please provide a letter upon return.</p>
        <p>Best regards,<br><strong>SPCCS Kindergarten Department</strong></p>
HTML;

    $html   = buildEmailTemplate("Absence Notification", $body, '#e02424');
    $result = sendEmail($student['parent_email'], 'Parent/Guardian', $subject, $html);

    return $result['success'];
}