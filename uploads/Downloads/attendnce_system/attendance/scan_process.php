<?php
/**
 * Scan Process — AJAX endpoint
 * Handles QR scan, records attendance, sends SMS + Email
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

// Find student by token
$stmt = $db->prepare("
    SELECT s.*, sec.section_name
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

// Check existing attendance record for today
$existStmt = $db->prepare("SELECT * FROM attendance WHERE student_id = ? AND date = ?");
$existStmt->execute([$student['id'], $today]);
$existing = $existStmt->fetch();

$smsSent   = false;
$emailSent = false;
$type      = 'timein';
$status    = getAttendanceStatus($now);

if (!$existing) {
    // ── First scan → Time In ──────────────────────────────

    $stmt = $db->prepare("
        INSERT INTO attendance (student_id, date, time_in, status, scan_type, recorded_by)
        VALUES (?, ?, ?, ?, 'qr', ?)
    ");
    $stmt->execute([$student['id'], $today, $now, $status, currentUser()['id']]);

    // SMS — arrival
    if (!empty($student['parent_contact'])) {
        $message = buildSMSMessage('sms_arrival_template', $student);
        $smsSent = sendSMS($student['parent_contact'], $message, $student['id'], 'arrival');
    }

    // Email — arrival
    if (!empty($student['parent_email'])) {
        $emailSent = sendArrivalEmail($student);
    }

} elseif ($existing['time_in'] && !$existing['time_out']) {
    // ── Second scan → Time Out ────────────────────────────

    $timeOutStart = getSetting('time_out_start') ?? '11:00:00';

    if (strtotime($now) < strtotime($timeOutStart)) {
        echo json_encode([
            'success' => false,
            'message' => 'Too early for time-out. Time-out window starts at '
                         . date('h:i A', strtotime($timeOutStart)) . '.'
        ]);
        exit;
    }

    $type   = 'timeout';
    $status = $existing['status'];

    $db->prepare("UPDATE attendance SET time_out = ? WHERE id = ?")
       ->execute([$now, $existing['id']]);

    // SMS — departure
    if (!empty($student['parent_contact'])) {
        $message = buildSMSMessage('sms_departure_template', $student);
        $smsSent = sendSMS($student['parent_contact'], $message, $student['id'], 'departure');
    }

    // Email — departure
    if (!empty($student['parent_email'])) {
        $emailSent = sendDepartureEmail($student);
    }

} else {
    // ── Already fully recorded ────────────────────────────
    echo json_encode([
        'success' => false,
        'message' => $student['first_name'] . ' ' . $student['last_name'] .
                     ' has already been fully recorded today (Time-in and Time-out).'
    ]);
    exit;
}

echo json_encode([
    'success'    => true,
    'type'       => $type,
    'status'     => $status,
    'student'    => $student['first_name'] . ' ' . $student['last_name'],
    'section'    => $student['section_name'] ?? 'N/A',
    'time'       => date('h:i A', strtotime($now)),
    'sms_sent'   => $smsSent,
    'email_sent' => $emailSent,
]);