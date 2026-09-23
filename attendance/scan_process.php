<?php
/**
 * Scan Process — AJAX endpoint
 * 4-event attendance: AM IN → AM OUT → PM IN → PM OUT
 * Time-aware event selection (fixes PM-scan-recorded-as-AM bug).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/mail_helper.php';
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
$existStmt = $db->prepare("SELECT * FROM attendance WHERE student_id = ? AND date = ?");
$existStmt->execute([$student['id'], $today]);
$existing = $existStmt->fetch() ?: null;

// ── Section array for helpers ────────────────────────────────
$section = [
    'schedule_type'      => $student['schedule_type'],
    'am_late_threshold'  => $student['am_late_threshold'],
    'pm_late_threshold'  => $student['pm_late_threshold'],
];

// ── Determine next event (TIME-AWARE) ────────────────────────
$nextEvent = getNextAttendanceEvent($existing, $section, $now);

if ($nextEvent === 'complete') {
    echo json_encode([
        'success' => false,
        'message' => $student['first_name'] . ' ' . $student['last_name'] .
                     ' has completed all attendance events for today.'
    ]);
    exit;
}

// ── Create today's row if missing ────────────────────────────
$smsSent    = false;
$emailSent  = false;
$eventLabel = '';
$smsType    = '';

if (!$existing) {
    $db->prepare("
        INSERT INTO attendance
            (student_id, date, attendance_type, recorded_by)
        VALUES (?, ?, 'absent', ?)
    ")->execute([$student['id'], $today, currentUser()['id']]);

    $existStmt->execute([$student['id'], $today]);
    $existing = $existStmt->fetch();
}

// ── Record the event ─────────────────────────────────────────
switch ($nextEvent) {

    case 'am_in':
        $amStatus = getSessionStatus($now, 'am_late_threshold', $section);
        $db->prepare("UPDATE attendance SET am_in = ?, am_status = ? WHERE id = ?")
           ->execute([$now, $amStatus, $existing['id']]);
        $eventLabel = 'AM In';
        $smsType    = 'am_arrival';
        if (!empty($student['parent_contact'])) {
            $msg     = buildSMSMessage('sms_am_arrival_template', $student);
            $smsSent = sendSMS($student['parent_contact'], $msg, $student['id'], 'am_arrival');
        }
        if (!empty($student['parent_email'])) {
            $emailSent = sendArrivalEmail($student, 'AM');
        }
        break;

    case 'am_out':
        $db->prepare("UPDATE attendance SET am_out = ? WHERE id = ?")
           ->execute([$now, $existing['id']]);
        $eventLabel = 'AM Out';
        $smsType    = 'am_departure';
        if (!empty($student['parent_contact'])) {
            $msg     = buildSMSMessage('sms_am_departure_template', $student);
            $smsSent = sendSMS($student['parent_contact'], $msg, $student['id'], 'am_departure');
        }
        if (!empty($student['parent_email'])) {
            $emailSent = sendDepartureEmail($student, 'AM');
        }
        break;

    case 'pm_in':
        $pmStatus = getSessionStatus($now, 'pm_late_threshold', $section);
        $db->prepare("UPDATE attendance SET pm_in = ?, pm_status = ? WHERE id = ?")
           ->execute([$now, $pmStatus, $existing['id']]);
        $eventLabel = 'PM In';
        $smsType    = 'pm_arrival';
        if (!empty($student['parent_contact'])) {
            $msg     = buildSMSMessage('sms_pm_arrival_template', $student);
            $smsSent = sendSMS($student['parent_contact'], $msg, $student['id'], 'pm_arrival');
        }
        if (!empty($student['parent_email'])) {
            $emailSent = sendArrivalEmail($student, 'PM');
        }
        break;

    case 'pm_out':
        $db->prepare("UPDATE attendance SET pm_out = ? WHERE id = ?")
           ->execute([$now, $existing['id']]);
        $eventLabel = 'PM Out';
        $smsType    = 'pm_departure';
        if (!empty($student['parent_contact'])) {
            $msg     = buildSMSMessage('sms_pm_departure_template', $student);
            $smsSent = sendSMS($student['parent_contact'], $msg, $student['id'], 'pm_departure');
        }
        if (!empty($student['parent_email'])) {
            $emailSent = sendDepartureEmail($student, 'PM');
        }
        break;
}

// ── Recompute attendance_type ─────────────────────────────────
$existStmt->execute([$student['id'], $today]);
$updated    = $existStmt->fetch();
$attendType = computeAttendanceType($updated, $section);
$db->prepare("UPDATE attendance SET attendance_type = ? WHERE id = ?")
   ->execute([$attendType, $existing['id']]);

// ── Remaining events ─────────────────────────────────────────
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