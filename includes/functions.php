<?php
/**
 * Global Helper Functions
 * SPCCS Elementary Attendance System v2.0
 * Covers Kinder through Grade 6
 * Supports two daily scans: morning Time-In and grade-appropriate Time-Out
 * Includes single-session enforcement (one active browser per account)
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/settings_security.php';
require_once __DIR__ . '/sms_status.php';
require_once __DIR__ . '/notification_templates.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/abuse_security.php';
require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/security_audit.php';
registerSecurityRequestAudit();
if (PHP_SAPI !== 'cli' && !headers_sent()) header('X-Request-ID: ' . securityRequestId());
require_once __DIR__ . '/../config/schedule_rules.php';

// ─── Anti-cache headers ─────────────────────────────────────
/**
 * Send no-store headers so browsers never serve a cached page
 * after the session has been invalidated by another login.
 * Safe to call multiple times — only sends headers once.
 */
function sendNoCacheHeaders(): void {
    static $sent = false;
    if ($sent || headers_sent()) return;
    $sent = true;

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: Sat, 01 Jan 2000 00:00:00 GMT');
    header('Vary: Cookie');
}

// ─── Session ────────────────────────────────────────────────

/**
 * Create a fresh token, store it in the DB and in $_SESSION.
 * Overwriting the DB value is what invalidates any other browser.
 *
 * Throws RuntimeException if the DB write didn't affect a row.
 */
function registerSession(int $userId): string {
    startSession();
    $token = bin2hex(random_bytes(32));

    $db = getDB();
    $stmt = $db->prepare("
        UPDATE users
        SET session_token       = ?,
            session_user_agent  = ?,
            session_ip          = ?,
            session_last_activity = NOW()
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([
        $token,
        substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $userId,
    ]);

    if ($stmt->rowCount() === 0) {
        error_log("registerSession: 0 rows updated for user_id={$userId}");
        throw new RuntimeException('Could not register session — user not found or inactive.');
    }

    // A new login starts a fresh kiosk result, even if PHP reuses the session cookie.
    unset($_SESSION['scanner_last_result'], $_SESSION['app_csrf'], $_SESSION['settings_csrf'], $_SESSION['end_of_day_csrf']);
    $_SESSION['session_token'] = $token;
    $_SESSION['authenticated_at'] = time();
    $_SESSION['last_activity_at'] = $_SESSION['authenticated_at'];
    return $token;
}

/** Wipe the local session and send the browser back to login. */
function terminateSession(string $reason): void {
    startSession();

    clearLocalSession();

    // Build the login URL
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $url  = rtrim($base, '/') . '/index.php?reason=' . urlencode($reason);

    sendNoCacheHeaders();

    // AJAX / fetch callers get JSON instead of a redirect
    $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest')
           || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($isAjax) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success'  => false,
            'error'    => 'session_invalid',
            'reason'   => $reason,
            'redirect' => $url,
        ]);
        exit;
    }

    header('Location: ' . $url);
    exit;
}

function isLoggedIn() {
    startSession();
    return !empty($_SESSION['user_id']) && checkSession() === null;
}

function requireLogin(bool $touchActivity = true) {
    startSession();
    sendNoCacheHeaders();

    if (empty($_SESSION['user_id'])) {
        $GLOBALS['security_audit_outcome'] = 'denied';
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
    $reason = checkSession($touchActivity);
    if ($reason !== null) {
        $GLOBALS['security_audit_outcome'] = 'denied';
        terminateSession($reason);
    }
    securityAuditContext((int)$_SESSION['user_id']);
}

function isAdmin() {
    startSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        $GLOBALS['security_audit_outcome'] = 'denied';
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
        'role'      => $_SESSION['role']      ?? '',
    ];
}

// ─── Scanner operator role ──────────────────────────────────────

function roleLabel(string $role): string {
    return $role === 'scanner_operator' ? 'Scanner Operator' : ucfirst($role);
}

function isScannerOperator(): bool {
    startSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'scanner_operator';
}

function isTeacher(): bool {
    startSession();
    return isset($_SESSION['role']) && $_SESSION['role'] === 'teacher';
}

function requireScannerOperator(): void {
    requireLogin();
    if (!isScannerOperator()) {
        $GLOBALS['security_audit_outcome'] = 'denied';
        header('Location: ' . BASE_URL . 'dashboard.php');
        exit;
    }
}

function requireScannerAccess(): void {
    requireLogin();
    if (!isAdmin() && !isTeacher() && !isScannerOperator()) {
        $GLOBALS['security_audit_outcome'] = 'denied';
        header('Location: ' . BASE_URL . 'index.php');
        exit;
    }
}

function requireStaff(): void {
    requireLogin();
    if (isScannerOperator()) {
        $GLOBALS['security_audit_outcome'] = 'denied';
        header('Location: ' . BASE_URL . 'attendance/scanner.php');
        exit;
    }
    if (!isAdmin() && !isTeacher()) {
        http_response_code(403);
        exit('Staff access is restricted to administrators and teachers.');
    }
}

function isKioskMode(): bool {
    return isScannerOperator();
}

// ─── Teacher section access ──────────────────────────────────

function getAllowedSections() {
    require_once __DIR__ . '/enrollment_access.php';
    $db   = getDB();
    $user = currentUser();

    if (enrollmentModelEnabled($db)) {
        [$scope,$params] = enrollmentSectionScope('s',$user);
        $stmt = $db->prepare("SELECT s.*,u.full_name AS adviser_name FROM sections s
            LEFT JOIN users u ON s.adviser_id=u.id
            WHERE s.is_active=1 AND ($scope) AND s.school_year_id=(SELECT id FROM school_years WHERE state='active')
            ORDER BY FIELD(s.grade_level,'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'),s.section_name");
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    if (isAdmin()) {
        return $db->query("
            SELECT s.*, u.full_name AS adviser_name
            FROM sections s
            LEFT JOIN users u ON s.adviser_id = u.id
            WHERE s.is_active = 1
            ORDER BY
                FIELD(s.grade_level,'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'),
                s.section_name
        ")->fetchAll();
    }

    $stmt = $db->prepare("
        SELECT s.*, u.full_name AS adviser_name
        FROM sections s
        LEFT JOIN users u ON s.adviser_id = u.id
        WHERE s.is_active = 1 AND s.adviser_id = ?
        ORDER BY s.section_name
    ");
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll();
}

function canAccessSection(int $sectionId): bool {
    require_once __DIR__ . '/enrollment_access.php';
    if (!isAdmin() && !isTeacher()) return false;
    if (enrollmentModelEnabled(getDB())) {
        [$scope,$params] = enrollmentSectionScope('sec',currentUser());
        $stmt=getDB()->prepare("SELECT sec.id FROM sections sec WHERE sec.id=? AND ($scope)
            AND sec.school_year_id=(SELECT id FROM school_years WHERE state='active')");
        $stmt->execute(array_merge([$sectionId],$params));
        return (bool)$stmt->fetchColumn();
    }
    if (isAdmin()) return true;
    $db   = getDB();
    $user = currentUser();
    $stmt = $db->prepare("SELECT id FROM sections WHERE id = ? AND adviser_id = ?");
    $stmt->execute([$sectionId, $user['id']]);
    return (bool)$stmt->fetch();
}

// ─── Flash messages ─────────────────────────────────────────

function setFlash(string $type, string $message) {
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

function getSetting(string $key): ?string {
    $db   = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row || $row['setting_value'] === null) return null;
    return isSecretSetting($key) ? decryptSettingSecret($row['setting_value']) : $row['setting_value'];
}

function updateSetting(string $key, string $value) {
    if (isSecretSetting($key)) $value = encryptSettingSecret($value);
    $db   = getDB();
    $stmt = $db->prepare("INSERT INTO system_settings (setting_key, setting_value)
                          VALUES (?, ?)
                          ON DUPLICATE KEY UPDATE setting_value = ?");
    return $stmt->execute([$key, $value, $value]);
}

// ─── School Calendar ─────────────────────────────────────────

function getCalendarEntry(string $date): ?array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM school_calendar WHERE date = ?");
    $stmt->execute([$date]);
    return $stmt->fetch() ?: null;
}

function isSchoolDay(string $date): bool {
    $entry = getCalendarEntry($date);
    if (!$entry) return true;
    return $entry['type'] === 'school_day';
}

function isHolidayOrNoClass(string $date): bool {
    $entry = getCalendarEntry($date);
    if (!$entry) return false;
    return in_array($entry['type'], ['holiday', 'no_class']);
}

function getCalendarMonth(int $month, int $year): array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT * FROM school_calendar
        WHERE MONTH(date) = ? AND YEAR(date) = ?
        ORDER BY date
    ");
    $stmt->execute([$month, $year]);
    $rows   = $stmt->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[$row['date']] = $row;
    }
    return $result;
}

// ─── Section helpers ─────────────────────────────────────────

function getSection(int $sectionId): ?array {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT s.*, u.full_name AS adviser_name
        FROM sections s
        LEFT JOIN users u ON s.adviser_id = u.id
        WHERE s.id = ?
    ");
    $stmt->execute([$sectionId]);
    return $stmt->fetch() ?: null;
}

function getGradeLevels(): array {
    return ['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'];
}

// ─── Attendance event detection ──────────────────────────────

/** Saved section choices take priority; grades only supply defaults. */
function getAttendanceScheduleType(array $section): string {
    if (in_array($section['schedule_type'] ?? '', ['am_only', 'pm_only', 'full_day'], true)) return $section['schedule_type'];
    $rule = getScheduleForGrade($section['grade_level'] ?? '');
    return $rule['default_type'] ?? 'full_day';
}

function getAttendanceEvents(array $section): array {
    $type = getAttendanceScheduleType($section);
    return [$type === 'pm_only' ? 'pm_in' : 'am_in', $type === 'am_only' ? 'am_out' : 'pm_out'];
}

function getNextAttendanceEvent(?array $existing, array $section, ?string $now = null): string {
    $now = $now ?: date('H:i:s');
    [$arrival, $departure] = getAttendanceEvents($section);
    if (!empty($existing[$arrival]) && !empty($existing[$departure])) return 'complete';
    $event = empty($existing[$arrival]) ? $arrival : $departure;
    $prefix = $event;
    $defaults = ['am_in' => ['00:00:00', '11:59:59'],
        'pm_in' => ['13:00:00', '13:30:00'], 'am_out' => ['11:30:00', '12:00:00'], 'pm_out' => ['12:00:00', '23:59:59']];
    $start = $section[$prefix . '_start'] ?? $defaults[$prefix][0];
    $end = $section[$prefix . '_end'] ?? $defaults[$prefix][1];
    if ($event === 'am_in' && $now >= '12:00:00') return 'outside_window';
    if (in_array($event, ['pm_in', 'pm_out'], true) && $now < '12:00:00') return 'outside_window';
    if ($event === 'am_out' && $now > '12:00:00') return 'outside_window';
    return $now >= $start && $now <= $end ? $event : 'outside_window';
}

function getSessionStatus(string $time, string $thresholdKey, array $section): string {
    $threshold = $section[$thresholdKey] ?? null;
    if (!$threshold) return 'present';
    return strtotime($time) > strtotime($threshold) ? 'late' : 'present';
}

/** One required scan is Partial; both are Full Day for every schedule type. */
function computeAttendanceType(array $record, array $section): string {
    [$arrival, $departure] = getAttendanceEvents($section);
    if (!empty($record[$arrival]) && !empty($record[$departure])) return 'full_day';
    if (!empty($record[$arrival]) || !empty($record[$departure])) return 'partial';
    if (trim($record['remarks'] ?? '') !== '') return 'remarked';
    return 'absent';
}

// ─── Dashboard stats ─────────────────────────────────────────

function getDashboardStats(?int $sectionId = null): array {
    $db    = getDB();
    $today = date('Y-m-d');
    $stats = [];

    $sectionFilter  = '';
    $sectionParams  = [];
    if ($sectionId) {
        $sectionFilter = 'AND s.section_id = ?';
        $sectionParams = [$sectionId];
    } elseif (!isAdmin()) {
        $user = currentUser();
        $sectionFilter = 'AND sec.adviser_id = ?';
        $sectionParams = [$user['id']];
    }

    $sql  = "SELECT COUNT(*) AS cnt FROM students s
             LEFT JOIN sections sec ON s.section_id = sec.id
             WHERE s.is_active = 1 {$sectionFilter}";
    $stmt = $db->prepare($sql);
    $stmt->execute($sectionParams);
    $stats['total_students'] = $stmt->fetch()['cnt'];

    $sql = "SELECT
                SUM(a.attendance_type IN ('full_day'))  AS full_day,
                SUM(a.attendance_type = 'partial')      AS partial,
                SUM(a.attendance_type = 'absent')       AS absent,
                SUM(a.am_status = 'late' OR a.pm_status = 'late') AS late
            FROM attendance a
            JOIN students s ON a.student_id = s.id
            LEFT JOIN sections sec ON s.section_id = sec.id
            WHERE a.date = ? {$sectionFilter}";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$today], $sectionParams));
    $row = $stmt->fetch();

    $stats['full_day_today'] = (int)($row['full_day'] ?? 0);
    $stats['partial_today']  = (int)($row['partial']  ?? 0);
    $stats['absent_today']   = (int)($row['absent']   ?? 0);
    $stats['late_today']     = (int)($row['late']     ?? 0);
    $stats['present_today']  = $stats['full_day_today'] + $stats['partial_today'];

    $stats['is_holiday']     = isHolidayOrNoClass($today);
    $stats['calendar_entry'] = getCalendarEntry($today);

    return $stats;
}

// ─── QR Token generator ──────────────────────────────────────

function generateQRToken(string $lrn): string {
    return newStudentQRToken($lrn);
}

// ─── Sanitize ────────────────────────────────────────────────

function sanitize($input): string {
    return htmlspecialchars(strip_tags(trim((string)$input)));
}

// ─── Format phone ────────────────────────────────────────────

function formatPhone(string $number): string {
    $number = preg_replace('/\D/', '', $number);
    if (substr($number, 0, 2) === '09') {
        return '+63' . substr($number, 1);
    }
    if (substr($number, 0, 2) === '63') {
        return '+' . $number;
    }
    return $number;
}

// ─── SMS via UniSMS ──────────────────────────────────────────

function sendSMS(string $number, string $message, int $studentId, string $type, ?string &$deliveryStatus = null): bool {
    $deliveryStatus = 'failed';
    $db     = getDB();
    $apiKey = getSetting('unisms_api_key');

    if (empty($apiKey)) {
        $db->prepare("INSERT INTO sms_logs
            (student_id, recipient_number, message, type, status, api_response)
            VALUES (?, ?, ?, ?, 'failed', 'No API key configured')")
           ->execute([$studentId, $number, $message, $type]);
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
        $response          = $client->send();

        if ($response === false) {
            $status      = 'failed';
            $apiResponse = 'cURL returned false';
        } else {
            $apiResponse = $response;
            $status = smsResponseStatus($response);
        }
    } catch (Exception $e) {
        $status      = 'failed';
        $apiResponse = $e->getMessage();
    }

    $db->prepare("INSERT INTO sms_logs
        (student_id, recipient_number, message, type, status, api_response)
        VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$studentId, $phone, $message, $type, $status, $apiResponse]);

    $deliveryStatus = $status;
    return $status === 'sent';
}

// ─── SMS message builder ─────────────────────────────────────

function buildSMSMessage(string $templateKey, array $student): string {
    return renderNotificationText(notificationTemplate($templateKey), $student);
}

// ─── Pagination ──────────────────────────────────────────────

function paginate(int $totalRecords, int $perPage, int $currentPage, string $url): string {
    $totalPages = (int)ceil($totalRecords / $perPage);
    if ($totalPages <= 1) return '';

    $html = '<nav><ul class="pagination pagination-sm mb-0">';

    $prevDisabled = $currentPage <= 1 ? 'disabled' : '';
    $prevPage     = max(1, $currentPage - 1);
    $html .= "<li class='page-item {$prevDisabled}'>
                <a class='page-link' href='{$url}&page={$prevPage}'>«</a>
              </li>";

    $start = max(1, $currentPage - 2);
    $end   = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= "<li class='page-item'><a class='page-link' href='{$url}&page=1'>1</a></li>";
        if ($start > 2) $html .= "<li class='page-item disabled'><span class='page-link'>…</span></li>";
    }

    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $currentPage ? 'active' : '';
        $html  .= "<li class='page-item {$active}'>
                     <a class='page-link' href='{$url}&page={$i}'>{$i}</a>
                   </li>";
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= "<li class='page-item disabled'><span class='page-link'>…</span></li>";
        $html .= "<li class='page-item'><a class='page-link' href='{$url}&page={$totalPages}'>{$totalPages}</a></li>";
    }

    $nextDisabled = $currentPage >= $totalPages ? 'disabled' : '';
    $nextPage     = min($totalPages, $currentPage + 1);
    $html .= "<li class='page-item {$nextDisabled}'>
                <a class='page-link' href='{$url}&page={$nextPage}'>»</a>
              </li>";

    $html .= '</ul></nav>';
    return $html;
}

// ─── Grade level sort order ───────────────────────────────────

function gradeLevelOrderSQL(string $column = 'grade_level'): string {
    return "FIELD({$column},'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6')";
}

// ─── Attendance type badge ────────────────────────────────────

function attendanceTypeBadge(string $type): string {
    return match($type) {
        'full_day' => '<span class="status-badge badge-present">Full Day</span>',
        'partial'  => '<span class="status-badge badge-partial">Partial</span>',
        'absent'   => '<span class="status-badge badge-absent">Absent</span>',
        'holiday'  => '<span class="status-badge badge-holiday">Holiday</span>',
        'remarked' => '<span class="status-badge badge-secondary">Manual remark</span>',
        default    => '<span class="status-badge badge-secondary">—</span>',
    };
}

function sessionStatusBadge(?string $status): string {
    if (!$status) return '<span class="text-muted small">—</span>';
    return match($status) {
        'present' => '<span class="status-badge badge-present">Present</span>',
        'late'    => '<span class="status-badge badge-late">Late</span>',
        'absent'  => '<span class="status-badge badge-absent">Absent</span>',
        default   => '<span class="text-muted small">—</span>',
    };
}

// ─── Calendar helpers ─────────────────────────────────────────

function entryColor(string $type): string {
    return match($type) {
        'holiday'       => 'danger',
        'no_class'      => 'warning',
        'special_event' => 'info',
        'school_day'    => 'success',
        default         => 'secondary',
    };
}
