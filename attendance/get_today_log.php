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
requireLogin(false);
$user = currentUser();
$role = $user['role'] ?? '';
if (!in_array($role, ['admin', 'teacher', 'scanner_operator'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied to the attendance log.']);
    exit;
}
$isScanner = $role === 'scanner_operator';

$db    = getDB();
require_once '../includes/enrollment_operations.php';
$today = date('Y-m-d');
$now   = date('H:i:s');

// ── Role-aware adviser filter ──────────────────────────────
// Teachers always receive only their assigned active sections, even with no assignments.
// Admins may review the school; kiosks receive only a minimal recent scan feed.
$adviserFilter = '';
$params        = [$today];

if ($role === 'teacher') {
    $adviserFilter = ' AND sec.adviser_id = ?';
    $params[] = (int)$user['id'];
}

$scansOnly = $isScanner || ($_GET['scans_only'] ?? '') === '1';
$limit = $isScanner ? 50 : 200;
$lrnColumn = $isScanner ? '' : ', s.lrn';
$scanFilter = $scansOnly ? 'AND (a.am_in IS NOT NULL OR a.am_out IS NOT NULL OR a.pm_in IS NOT NULL OR a.pm_out IS NOT NULL)' : '';
$logOrder = $scansOnly
    ? "GREATEST(COALESCE(a.am_in, '00:00:00'), COALESCE(a.am_out, '00:00:00'), COALESCE(a.pm_in, '00:00:00'), COALESCE(a.pm_out, '00:00:00')) DESC, a.id DESC"
    : "FIELD(sec.grade_level, 'Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'), sec.section_name, s.last_name, s.first_name";
$placementJoin='LEFT JOIN sections sec ON sec.id = s.section_id';
$ownershipJoin=''; $enrollmentFilter="AND s.enrollment_status = 'active'";
if (enrollmentModelEnabled($db)) {
    $placementJoin='JOIN student_enrollments e ON e.student_id=s.id JOIN school_years y ON y.id=e.school_year_id
        JOIN enrollment_sections es ON es.enrollment_id=e.id JOIN sections sec ON sec.id=es.section_id';
    $ownershipJoin=' AND a.enrollment_id=e.id AND a.assignment_id=es.id';
    $enrollmentFilter="AND y.state='active' AND e.state='active' AND sec.school_year_id=y.id AND sec.grade_level=es.grade_level
        AND ? BETWEEN y.starts_on AND y.ends_on AND ? BETWEEN e.starts_on AND e.ends_on AND ? BETWEEN es.starts_on AND es.ends_on";
    // This predicate precedes the adviser predicate in the SQL below.
    $params=array_merge([$today,$today,$today,$today],$role==='teacher'?[(int)$user['id']]:[]);
}
$stmt = $db->prepare("
    SELECT
        s.id             AS student_id,
        s.first_name, s.last_name {$lrnColumn},
        sec.id           AS section_id,
        sec.grade_level, sec.section_name, sec.schedule_type,
        sec.am_in_start, sec.am_in_end, sec.am_late_threshold,
        sec.am_out_start, sec.am_out_end,
        sec.pm_in_start, sec.pm_in_end, sec.pm_late_threshold,
        sec.pm_out_start, sec.pm_out_end,
        a.id             AS log_id,
        a.am_in, a.am_out, a.pm_in, a.pm_out,
        a.am_status, a.pm_status,
        a.attendance_type, a.remarks
    FROM students s
    {$placementJoin}
    LEFT JOIN attendance a
           ON a.student_id = s.id AND a.date = ? {$ownershipJoin}
    WHERE s.is_active = 1
      {$enrollmentFilter}
      AND sec.is_active = 1
      {$adviserFilter}
      {$scanFilter}
    ORDER BY {$logOrder}
    LIMIT {$limit}
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$result = [];

foreach ($rows as $r) {
    $type = getAttendanceScheduleType($r);
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
    [$requiredIn, $requiredOut] = getAttendanceEvents($r);
    if ($attendanceType !== 'holiday' && (!empty($r[$requiredIn]) || !empty($r[$requiredOut]))) {
        $attendanceType = computeAttendanceType($r, $r);
    }

    if (!$amIn && !$pmIn && !$amOut && !$pmOut) {
        $fullyAbsent = false;
        if ($type === 'am_only'  && $amAbsent)              $fullyAbsent = true;
        if ($type === 'pm_only'  && $pmAbsent)              $fullyAbsent = true;
        if ($type === 'full_day' && $amAbsent && $pmAbsent) $fullyAbsent = true;

        if (trim($r['remarks'] ?? '') !== '') { $attendanceType = 'remarked'; $amAbsent = false; $pmAbsent = false; }
        elseif ($fullyAbsent)           $attendanceType = 'absent';
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

    $row = [
        'student_id'      => (int)$r['student_id'],
        'time_in' => ($in = $r[getAttendanceEvents($r)[0]]) ? date('h:i A', strtotime($in)) : null,
        'arrival_status' => $type === 'pm_only' ? $r['pm_status'] : $r['am_status'],
        'time_out' => ($out = $r[getAttendanceEvents($r)[1]]) ? date('h:i A', strtotime($out)) : null,
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
    if ($isScanner) {
        // Keep only the fields rendered by the kiosk's five-column log.
        $row = array_intersect_key($row, array_flip([
            'student_id', 'name', 'grade', 'section', 'time_in', 'time_out',
            'arrival_status', 'attendance_type', 'last_event', 'last_event_time',
        ]));
    }
    $result[] = $row;
}

echo json_encode($result);
