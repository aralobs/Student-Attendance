<?php
/**
 * Global helper functions
 */

require_once __DIR__ . '/../config/database.php';

// ─── Session ────────────────────────────────────────────────

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function isLoggedIn() {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

function isAdmin() {
    startSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit;
    }
}

function currentUser() {
    startSession();
    return [
        'id'        => $_SESSION['user_id']   ?? null,
        'username'  => $_SESSION['username']  ?? '',
        'full_name' => $_SESSION['full_name'] ?? '',
        'role'      => $_SESSION['role']       ?? '',
    ];
}

// ─── Flash messages ─────────────────────────────────────────

function setFlash($type, $message) {
    startSession();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    startSession();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

function showFlash() {
    $flash = getFlash();
    if ($flash) {
        $type = htmlspecialchars($flash['type']);
        $msg  = htmlspecialchars($flash['message']);
        echo "<div class='alert alert-{$type} alert-dismissible fade show' role='alert'>
                {$msg}
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
              </div>";
    }
}

// ─── Settings ────────────────────────────────────────────────

function getSetting($key) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : null;
}

function updateSetting($key, $value) {
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value)
                          VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

// ─── Attendance helpers ──────────────────────────────────────

function getAttendanceStatus($timeIn) {
    $lateThreshold = getSetting('late_threshold') ?? '07:31:00';
    if (strtotime($timeIn) > strtotime($lateThreshold)) {
        return 'late';
    }
    return 'present';
}

function getDashboardStats() {
    $db    = getDB();
    $today = date('Y-m-d');
    $stats = [];

    $stmt = $db->query("SELECT COUNT(*) as cnt FROM students WHERE is_active = 1");
    $stats['total_students'] = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM attendance WHERE date = ? AND status IN ('present','late')");
    $stmt->execute([$today]);
    $stats['present_today'] = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM attendance WHERE date = ? AND status = 'absent'");
    $stmt->execute([$today]);
    $stats['absent_today'] = $stmt->fetch()['cnt'];

    $stmt = $db->prepare("SELECT COUNT(*) as cnt FROM attendance WHERE date = ? AND status = 'late'");
    $stmt->execute([$today]);
    $stats['late_today'] = $stmt->fetch()['cnt'];

    return $stats;
}

// ─── QR Token generator ──────────────────────────────────────

function generateQRToken($lrn) {
    return 'STU-' . $lrn . '-' . strtoupper(substr(md5(uniqid($lrn, true)), 0, 6));
}

// ─── Sanitize ────────────────────────────────────────────────

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

// ─── Format phone number for UniSMS (+63 format) ─────────────

function formatPhone($number) {
    $number = preg_replace('/\D/', '', $number); // strip non-digits
    if (substr($number, 0, 2) === '09') {
        $number = '+63' . substr($number, 1);    // 09XX → +639XX
    } elseif (substr($number, 0, 2) === '63') {
        $number = '+' . $number;                 // 639XX → +639XX
    }
    return $number;
}

// ─── SMS via UniSMS API ───────────────────────────────────────

function sendSMS($number, $message, $studentId, $type) {
    $db     = getDB();
    $apiKey = getSetting('unisms_api_key');

    if (empty($apiKey)) {
        $stmt = $db->prepare("INSERT INTO sms_logs
            (student_id, recipient_number, message, type, status, api_response)
            VALUES (?, ?, ?, ?, 'failed', 'No API key configured')");
        $stmt->execute([$studentId, $number, $message, $type]);
        return false;
    }

    require_once BASE_PATH . 'includes/UniSms.php';

    $phone    = formatPhone($number);
    $senderId = getSetting('unisms_sender_id') ?? 'UnisoftSMS';

    try {
        $client            = new UniSms($apiKey);
        $client->recipient = $phone;
        $client->content   = $message;
        $client->sender_id = $senderId;

        $response = $client->send();

        if ($response === false) {
            $status      = 'failed';
            $apiResponse = 'cURL returned false';
        } else {
            $decoded     = json_decode($response, true);
            $apiResponse = $response;

            // Check all possible success indicators from UniSMS
            $status = 'failed';
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (
                    // Format 1: message.status = sent
                    (isset($decoded['message']['status']) && $decoded['message']['status'] === 'sent') ||
                    // Format 2: event = message.sent
                    (isset($decoded['event']) && $decoded['event'] === 'message.sent') ||
                    // Format 3: status = sent at root level
                    (isset($decoded['status']) && $decoded['status'] === 'sent')
                ) {
                    $status = 'sent';
                }
            }
        }

    } catch (Exception $e) {
        $status      = 'failed';
        $apiResponse = $e->getMessage();
    }

    $stmt = $db->prepare("INSERT INTO sms_logs
        (student_id, recipient_number, message, type, status, api_response)
        VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$studentId, $phone, $message, $type, $status, $apiResponse]);

    return $status === 'sent';
}
// ─── SMS message builder ─────────────────────────────────────

function buildSMSMessage($templateKey, $student) {
    $template = getSetting($templateKey) ?? '';
    $replacements = [
        '{student_name}' => $student['first_name'] . ' ' . $student['last_name'],
        '{time}'         => date('h:i A'),
        '{date}'         => date('F j, Y'),
    ];
    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

// ─── Pagination helper ───────────────────────────────────────

function paginate($totalRecords, $perPage, $currentPage, $url) {
    $totalPages = ceil($totalRecords / $perPage);
    if ($totalPages <= 1) return '';

    $html = '<nav><ul class="pagination pagination-sm mb-0">';

    $prevDisabled = $currentPage <= 1 ? 'disabled' : '';
    $prevPage     = max(1, $currentPage - 1);
    $html .= "<li class='page-item {$prevDisabled}'>
                <a class='page-link' href='{$url}&page={$prevPage}'>«</a>
              </li>";

    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i === $currentPage ? 'active' : '';
        $html  .= "<li class='page-item {$active}'>
                     <a class='page-link' href='{$url}&page={$i}'>{$i}</a>
                   </li>";
    }

    $nextDisabled = $currentPage >= $totalPages ? 'disabled' : '';
    $nextPage     = min($totalPages, $currentPage + 1);
    $html .= "<li class='page-item {$nextDisabled}'>
                <a class='page-link' href='{$url}&page={$nextPage}'>»</a>
              </li>";

    $html .= '</ul></nav>';
    return $html;
}