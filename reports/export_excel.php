<?php
/**
 * Export Daily Attendance as CSV/Excel — v2
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireStaff();

$date       = $_GET['date']    ?? date('Y-m-d');
$gradeLevel = $_GET['grade']   ?? '';
$sectionId  = (int)($_GET['section'] ?? 0);

$db              = getDB();
$allowedSections = getAllowedSections();

$where  = ['a.date = ?', 's.is_active = 1'];
$params = [$date];

if (!isAdmin()) {
    $ids          = array_column($allowedSections, 'id') ?: [0];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $where[]      = "s.section_id IN ({$placeholders})";
    $params       = array_merge($params, $ids);
}
if (!empty($gradeLevel)) { $where[] = 'sec.grade_level = ?'; $params[] = $gradeLevel; }
if ($sectionId > 0)      { $where[] = 's.section_id = ?';   $params[] = $sectionId; }

$whereSQL = implode(' AND ', $where);
$orderSQL = gradeLevelOrderSQL('sec.grade_level');

$stmt = $db->prepare("
    SELECT a.*,
           s.first_name, s.last_name, s.lrn,
           sec.section_name, sec.grade_level, sec.schedule_type
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE {$whereSQL}
    ORDER BY {$orderSQL}, sec.section_name, s.last_name
");
$stmt->execute($params);
$records = $stmt->fetchAll();

$filename = 'Attendance_' . $date . '.csv';
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$out = fopen('php://output', 'w');

// School header
fputcsv($out, [getSetting('school_name') ?? 'SPCCS']);
fputcsv($out, ['Daily Attendance Report']);
fputcsv($out, ['Date: ' . date('F j, Y', strtotime($date))]);
fputcsv($out, ['Generated: ' . date('F j, Y h:i A')]);
fputcsv($out, []);

// Column headers
fputcsv($out, [
    '#', 'LRN', 'Last Name', 'First Name',
    'Grade', 'Section', 'Schedule',
    'Time-In', 'Time-Out', 'AM Status', 'PM Status',
    'Attendance Type', 'Remarks'
]);

foreach ($records as $i => $r) {
    fputcsv($out, [
        $i + 1,
        $r['lrn'],
        $r['last_name'],
        $r['first_name'],
        $r['grade_level']   ?? '',
        $r['section_name']  ?? '',
        $r['schedule_type'] ?? '',
        $r[getAttendanceEvents($r)[0]]  ? date('h:i A', strtotime($r[getAttendanceEvents($r)[0]]))  : '',
        ($out = $r[getAttendanceEvents($r)[1]]) ? date('h:i A', strtotime($out)) : '',
        $r['am_status'] ?? '',
        $r['pm_status'] ?? '',
        $r['attendance_type'] ?? '',
        $r['remarks'] ?? '',
    ]);
}

fclose($out);
exit;
