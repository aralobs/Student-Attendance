<?php
/**
 * Scan Process — AJAX endpoint
 * 4-event attendance: AM IN → AM OUT → PM IN → PM OUT
 * Time-aware event selection (fixes PM-scan-recorded-as-AM bug).
 * Includes 5-minute anti-double-scan lock.
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mail_helper.php';
require_once '../includes/attendance_scan.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$token = trim($_POST['token'] ?? '');
if (empty($token)) {
    echo json_encode(['success' => false, 'message' => 'Empty QR token.']);
    exit;
}

$db    = getDB();
$today = date('Y-m-d');
$now   = date('H:i:s');

// ── Holiday check ─────────────────────────────────────────────
if (isHolidayOrNoClass($today)) {
    $entry = getCalendarEntry($today);
    echo json_encode([
        'success' => false,
        'message' => "Today is a {$entry['type']}: {$entry['title']}. No attendance recording."
    ]);
    exit;
}

// ── Find student + section schedule ───────────────────────────
$stmt = $db->prepare("
    SELECT s.*,
           sec.section_name, sec.schedule_type,
           sec.am_in_start, sec.am_in_end, sec.am_late_threshold,
           sec.am_out_start, sec.am_out_end,
           sec.pm_in_start, sec.pm_in_end, sec.pm_late_threshold,
           sec.pm_out_start, sec.pm_out_end
    FROM students s
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE s.qr_token = ? AND s.is_active = 1
");
$stmt->execute([$token]);
$student = $stmt->fetch();

if (!$student) {
    echo json_encode(['success' => false, 'message' => 'Unknown QR code. Student not found.']);
    exit;
}
if (empty($student['schedule_type'])) {
    echo json_encode(['success' => false, 'message' => 'Student is not assigned to an active section.']);
    exit;
}

$scheduleType = $student['schedule_type'];
$isPastNoon   = $now >= '12:00:00';

// ── Reject scans outside the section's active window ─────────
if ($scheduleType === 'am_only' && $isPastNoon) {
    echo json_encode([
        'success' => false,
        'message' => 'This is an AM-only section. Attendance is closed for the day.'
    ]);
    exit;
}
if ($scheduleType === 'pm_only' && !$isPastNoon) {
    echo json_encode([
        'success' => false,
        'message' => 'This is a PM-only section. Attendance opens at noon.'
    ]);
    exit;
}

// ── Existing attendance row ──────────────────────────────────
// Selection, cooldown and saving share the same student/date row lock.
$section = [
    'schedule_type'     => $student['schedule_type'],
    'am_late_threshold' => $student['am_late_threshold'],
    'pm_late_threshold' => $student['pm_late_threshold'],
];
try {
    $scan = saveAttendanceScan($db, (int)$student['id'], $today, $section, (int)currentUser()['id']);
} catch (Throwable $e) {
    error_log('Attendance scan save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to record attendance. Please try again.']);
    exit;
}
if (!$scan['success']) {
    $message = !empty($scan['complete'])
        ? $student['first_name'] . ' ' . $student['last_name'] . ' has completed all attendance events for today.'
        : $scan['message'];
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$nextEvent = $scan['event'];
$now = $scan['time'];
$updated = $scan['record'];
$attendType = $updated['attendance_type'];
$events = [
    'am_in'  => ['AM In', 'am_arrival', 'AM', true],
    'am_out' => ['AM Out', 'am_departure', 'AM', false],
    'pm_in'  => ['PM In', 'pm_arrival', 'PM', true],
    'pm_out' => ['PM Out', 'pm_departure', 'PM', false],
];
[$eventLabel, $smsType, $session, $isArrival] = $events[$nextEvent];
$smsSent = false;
$emailSent = false;
// Only the request that committed this new event reaches notification delivery.
// Keep provider I/O outside the transaction. Never retry delivery automatically:
// a provider may accept a message even when its response or log write fails.
if (!empty($student['parent_contact'])) {
    try {
        $msg = buildSMSMessage('sms_' . $smsType . '_template', $student);
        $smsSent = sendSMS($student['parent_contact'], $msg, $student['id'], $smsType);
    } catch (Throwable $e) {
        error_log('Attendance scan SMS failed: ' . $e->getMessage());
    }
}
if (!empty($student['parent_email'])) {
    try {
        $emailSent = $isArrival ? sendArrivalEmail($student, $session) : sendDepartureEmail($student, $session);
    } catch (Throwable $e) {
        error_log('Attendance scan email failed: ' . $e->getMessage());
    }
}

// Remaining events
$remaining = [];
if ($scheduleType !== 'pm_only' && empty($updated['am_in']))  $remaining[] = 'AM In';
if ($scheduleType !== 'pm_only' && empty($updated['am_out'])) $remaining[] = 'AM Out';
if ($scheduleType !== 'am_only' && empty($updated['pm_in']))  $remaining[] = 'PM In';
if ($scheduleType !== 'am_only' && empty($updated['pm_out'])) $remaining[] = 'PM Out';

echo json_encode([
    'success'         => true,
    'event'           => $nextEvent,
    'event_label'     => $eventLabel,
    'attendance_type' => $attendType,
    'student'         => $student['first_name'] . ' ' . $student['last_name'],
    'section'         => $student['section_name'] ?? 'N/A',
    'grade'           => $student['grade_level']  ?? '',
    'schedule_type'   => $scheduleType,
    'time'            => date('h:i A', strtotime($now)),
    'sms_sent'        => $smsSent,
    'email_sent'      => $emailSent,
    'remaining'       => $remaining,
    'am_in'           => $updated['am_in']  ? date('h:i A', strtotime($updated['am_in']))  : null,
    'am_out'          => $updated['am_out'] ? date('h:i A', strtotime($updated['am_out'])) : null,
    'pm_in'           => $updated['pm_in']  ? date('h:i A', strtotime($updated['pm_in']))  : null,
    'pm_out'          => $updated['pm_out'] ? date('h:i A', strtotime($updated['pm_out'])) : null,
]);