<?php
/**
 * Global Helper Functions
 * SPCCS Elementary Attendance System v2.0
 * Covers Kinder through Grade 6
 * Supports 4-event attendance: AM IN, AM OUT, PM IN, PM OUT
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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
        'role'      => $_SESSION['role']      ?? '',
    ];
}

// ─── Teacher section access ──────────────────────────────────

function getAllowedSections() {
    $db   = getDB();
    $user = currentUser();

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
    return $row ? $row['setting_value'] : null;
}

function updateSetting(string $key, string $value) {
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

/**
 * Determine which attendance event to record next.
 *
 * TIME-AWARE: If the current time is at/after noon, prefer PM events
 * even if AM events are empty — this prevents late-afternoon scans from
 * being stored as AM In/Out when the student never scanned in the morning.
 *
 * Returns: 'am_in' | 'am_out' | 'pm_in' | 'pm_out' | 'complete'
 */
function getNextAttendanceEvent(?array $existing = null, array $section, ?string $now = null): string {
    $scheduleType = $section['schedule_type'] ?? 'full_day';
    $now          = $now ?: date('H:i:s');

    $usesAM = in_array($scheduleType, ['full_day', 'am_only'], true);
    $usesPM = in_array($scheduleType, ['full_day', 'pm_only'], true);

    // No record yet — decide based on time of day
    if (!$existing) {
        if ($usesAM && $now < '12:00:00') return 'am_in';
        if ($usesPM)                      return 'pm_in';
        if ($usesAM)                      return 'am_in'; // fallback for am_only past noon
        return 'complete';
    }

    $amIn  = !empty($existing['am_in']);
    $amOut = !empty($existing['am_out']);
    $pmIn  = !empty($existing['pm_in']);
    $pmOut = !empty($existing['pm_out']);

    // Whether we're in the PM window (for full_day sections)
    $isPMTime = $usesPM && $now >= '12:00:00';

    // ── AM sequence (only if currently AM-time, or section is am_only) ──
    if ($usesAM && !$isPMTime) {
        if (!$amIn)  return 'am_in';
        if (!$amOut) return 'am_out';
        if ($scheduleType === 'am_only') return 'complete';
    }

    // ── PM sequence ─────────────────────────────────────────
    if ($usesPM) {
        if (!$pmIn)  return 'pm_in';
        if (!$pmOut) return 'pm_out';
    }

    return 'complete';
}

/**
 * Determine AM or PM status (present/late) based on section's late threshold.
 * Falls back to 'present' if no threshold is set.
 */
function getSessionStatus(string $time, string $thresholdKey, array $section): string {
    $threshold = $section[$thresholdKey] ?? null;
    if (!$threshold) return 'present';
    return strtotime($time) > strtotime($threshold) ? 'late' : 'present';
}

/**
 * Compute overall attendance_type from all 4 events.
 * Handles all three schedule types (full_day, am_only, pm_only).
 */
function computeAttendanceType(array $record, array $section): string {
    $scheduleType = $section['schedule_type'] ?? 'full_day';

    $amIn  = !empty($record['am_in']);
    $amOut = !empty($record['am_out']);
    $pmIn  = !empty($record['pm_in']);
    $pmOut = !empty($record['pm_out']);

    if ($scheduleType === 'am_only') {
        if ($amIn && $amOut) return 'full_day';
        if ($amIn || $amOut) return 'partial';
        return 'absent';
    }

    if ($scheduleType === 'pm_only') {
        if ($pmIn && $pmOut) return 'full_day';
        if ($pmIn || $pmOut) return 'partial';
        return 'absent';
    }

    // full_day
    if ($amIn && $amOut && $pmIn && $pmOut) return 'full_day';
    if ($amIn || $amOut || $pmIn || $pmOut) return 'partial';
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
    return 'STU-' . $lrn . '-' . strtoupper(substr(md5(uniqid($lrn, true)), 0, 6));
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

function sendSMS(string $number, string $message, int $studentId, string $type): bool {
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
            $decoded     = json_decode($response, true);
            $apiResponse = $response;
            $status      = 'failed';

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                if (
                    (isset($decoded['message']['status']) && $decoded['message']['status'] === 'sent') ||
                    (isset($decoded['event']) && $decoded['event'] === 'message.sent') ||
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

    $db->prepare("INSERT INTO sms_logs
        (student_id, recipient_number, message, type, status, api_response)
        VALUES (?, ?, ?, ?, ?, ?)")
       ->execute([$studentId, $phone, $message, $type, $status, $apiResponse]);

    return $status === 'sent';
}

// ─── SMS message builder ─────────────────────────────────────

function buildSMSMessage(string $templateKey, array $student): string {
    $template = getSetting($templateKey) ?? '';
    return str_replace(
        ['{student_name}', '{time}', '{date}'],
        [
            $student['first_name'] . ' ' . $student['last_name'],
            date('h:i A'),
            date('F j, Y'),
        ],
        $template
    );
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