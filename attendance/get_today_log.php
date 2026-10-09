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
require_once '../includes/attendance_finalizer.php';

$db    = getDB();
$today = date('Y-m-d');
finalizeAttendanceAbsences($today);
$now   = date('H:i:s');
$user  = currentUser();

// ── Role-aware adviser filter ──────────────────────────────
// Only *teachers* are scoped to sections they actually advise.
// Admin, user (staff), registrar, etc. see the full log.
$adviserFilter = '';
$params        = [$today];

$role = strtolower($user['role'] ?? '');

if ($role === 'teacher') {
    $check = $db->prepare("SELECT COUNT(*) FROM sections WHERE adviser_id = ? AND is_active = 1");
    $check->execute([$user['id']]);
    if ((int)$check->fetchColumn() > 0) {
        $adviserFilter = ' AND sec.adviser_id = ?';
        $params[] = $user['id'];
    }
}

$scansOnly = ($_GET['scans_only'] ?? '') === '1';
$scanFilter = $scansOnly ? 'AND (a.am_in IS NOT NULL OR a.am_out IS NOT NULL OR a.pm_in IS NOT NULL OR a.pm_out IS NOT NULL)' : '';
$logOrder = $scansOnly
    ? "GREATEST(COALESCE(a.am_in, '00:00:00'), COALESCE(a.am_out, '00:00:00'), COALESCE(a.pm_in, '00:00:00'), COALESCE(a.pm_out, '00:00:00')) DESC, a.id DESC"
    : "FIELD(sec.grade_level, 'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'), sec.section_name, s.last_name, s.first_name";
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
      {$scanFilter}
    ORDER BY {$logOrder}
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

    // Closed-session absences have been persisted; manual statuses remain authoritative.
    $amAbsent = ($r['am_status'] ?? '') === 'absent';
    $pmAbsent = ($r['pm_status'] ?? '') === 'absent';

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

    // ── NEW: determine the most recent event scanned today ──
    // Priority order follows the real-world sequence of the day.
    // Each later event can't exist without the earlier one, so
    // simply overwriting as we walk forward yields the last one.
    $lastEvent     = null;
    $lastEventTime = null;

    if ($amIn)  { $lastEvent = 'am_in';  $lastEventTime = $amIn;  }
    if ($amOut) { $lastEvent = 'am_out'; $lastEventTime = $amOut; }
    if ($pmIn)  { $lastEvent = 'pm_in';  $lastEventTime = $pmIn;  }
    if ($pmOut) { $lastEvent = 'pm_out'; $lastEventTime = $pmOut; }

    $result[] = [
        'student_id'      => (int)$r['student_id'],
        'name'            => htmlspecialchars($r['first_name'] . ' ' . $r['last_name']),
        'lrn'             => htmlspecialchars($r['lrn'] ?? ''),
        'grade'           => htmlspecialchars($r['grade_level'] ?? ''),
        'section'         => htmlspecialchars($r['section_name'] ?? ''),
        'am_in'           => $amIn  ? date('h:i A', strtotime($amIn))  : null,
        'am_out'          => $amOut ? date('h:i A', strtotime($amOut)) : null,
        'pm_in'           => $pmIn  ? date('h:i A', strtotime($pmIn))  : null,
        'pm_out'          => $pmOut ? date('h:i A', strtotime($pmOut)) : null,
        'am_status'       => $r['am_status'] ?? ($amLate ? 'late' : ($amIn ? 'present' : null)),
        'pm_status'       => $r['pm_status'] ?? ($pmLate ? 'late' : ($pmIn ? 'present' : null)),
        'am_absent'       => $amAbsent,
        'pm_absent'       => $pmAbsent,
        'attendance_type' => $attendanceType,
        'last_event'      => $lastEvent,
        'last_event_time' => $lastEventTime
            ? date('h:i A', strtotime($lastEventTime))
            : null,
    ];
}

echo json_encode($result);
