<?php
/**
 * Today's Log — AJAX endpoint
 * Lists all active students in active sections with live attendance status,
 * including students who haven't scanned yet (marked absent once their
 * window has closed).
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$db    = getDB();
$today = date('Y-m-d');
$now   = date('H:i:s');
$user  = currentUser();

// Filter by adviser if teacher
$adviserFilter = '';
$params        = [$today];
if (!isAdmin()) {
    $adviserFilter = 'AND sec.adviser_id = ?';
    $params[]      = $user['id'];
}

$stmt = $db->prepare("
    SELECT
        s.id             AS student_id,
        s.first_name, s.last_name, s.lrn,
        sec.id           AS section_id,
        sec.grade_level, sec.section_name, sec.schedule_type,
        sec.am_in_start, sec.am_in_end, sec.am_late_threshold,
        sec.am_out_start, sec.am_out_end,
        sec.pm_in_start, sec.pm_in_end, sec.pm_late_threshold,
        sec.pm_out_start, sec.pm_out_end,
        a.id             AS log_id,
        a.am_in, a.am_out, a.pm_in, a.pm_out,
        a.am_status, a.pm_status,
        a.attendance_type
    FROM students s
    LEFT JOIN sections sec ON sec.id = s.section_id
    LEFT JOIN attendance a
           ON a.student_id = s.id AND a.date = ?
    WHERE s.is_active = 1
      AND s.enrollment_status = 'active'
      AND sec.is_active = 1
      {$adviserFilter}
    ORDER BY
        FIELD(sec.grade_level, 'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'),
        sec.section_name,
        s.last_name, s.first_name
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$result = [];

foreach ($rows as $r) {
    $type  = $r['schedule_type'] ?? 'full_day';
    $amIn  = $r['am_in'];
    $amOut = $r['am_out'];
    $pmIn  = $r['pm_in'];
    $pmOut = $r['pm_out'];

    // Determine if a window has closed and the student never scanned IN
    $amAbsent = false;
    $pmAbsent = false;

    if (in_array($type, ['full_day', 'am_only'], true)) {
        if (!$amIn && $r['am_out_end'] && $now > $r['am_out_end']) {
            $amAbsent = true;
        }
    }
    if (in_array($type, ['full_day', 'pm_only'], true)) {
        if (!$pmIn && $r['pm_out_end'] && $now > $r['pm_out_end']) {
            $pmAbsent = true;
        }
    }

    // Late flags
    $amLate = $amIn && $r['am_late_threshold'] && $amIn > $r['am_late_threshold'];
    $pmLate = $pmIn && $r['pm_late_threshold'] && $pmIn > $r['pm_late_threshold'];

    // Overall attendance type
    $attendanceType = $r['attendance_type'];

    if (!$amIn && !$pmIn && !$amOut && !$pmOut) {
        $fullyAbsent = false;
        if ($type === 'am_only'  && $amAbsent)              $fullyAbsent = true;
        if ($type === 'pm_only'  && $pmAbsent)              $fullyAbsent = true;
        if ($type === 'full_day' && $amAbsent && $pmAbsent) $fullyAbsent = true;

        if ($fullyAbsent)               $attendanceType = 'absent';
        elseif ($amAbsent || $pmAbsent) $attendanceType = 'partial';
        else                            $attendanceType = 'pending';
    }

    $result[] = [
        'name'            => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
        'lrn'             => htmlspecialchars($r['lrn']),
        'grade'           => htmlspecialchars($r['grade_level'] ?? ''),
        'section'         => htmlspecialchars($r['section_name'] ?? ''),
        'am_in'           => $amIn  ? date('h:i A', strtotime($amIn))  : null,
        'am_out'          => $amOut ? date('h:i A', strtotime($amOut)) : null,
        'pm_in'           => $pmIn  ? date('h:i A', strtotime($pmIn))  : null,
        'pm_out'          => $pmOut ? date('h:i A', strtotime($pmOut)) : null,
        'am_status'       => $amAbsent ? 'absent' : ($amLate ? 'late' : ($amIn ? 'present' : null)),
        'pm_status'       => $pmAbsent ? 'absent' : ($pmLate ? 'late' : ($pmIn ? 'present' : null)),
        'am_absent'       => $amAbsent,
        'pm_absent'       => $pmAbsent,
        'attendance_type' => $attendanceType,
    ];
}

echo json_encode($result);