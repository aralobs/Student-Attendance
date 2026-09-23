<?php
/**
 * SF2 Excel Export
 * Uses native PHP to generate CSV (opens in Excel)
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$month     = (int)($_GET['month']   ?? date('n'));
$year      = (int)($_GET['year']    ?? date('Y'));
$sectionId = (int)($_GET['section'] ?? 0);

$db           = getDB();
$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$monthLabel   = date('F_Y', mktime(0,0,0,$month,1,$year));

// Section info
$secStmt = $db->prepare("SELECT * FROM sections WHERE id = ?");
$secStmt->execute([$sectionId]);
$section = $secStmt->fetch();

$students = $db->prepare("SELECT * FROM students WHERE section_id = ? AND is_active = 1 ORDER BY last_name, first_name");
$students->execute([$sectionId]);
$students = $students->fetchAll();

// Attendance matrix
$attMatrix = [];
$stmt = $db->prepare("SELECT student_id, DAY(date) AS day, status FROM attendance WHERE MONTH(date)=? AND YEAR(date)=? AND student_id IN (SELECT id FROM students WHERE section_id=? AND is_active=1)");
$stmt->execute([$month, $year, $sectionId]);
foreach ($stmt->fetchAll() as $r) {
    $attMatrix[$r['student_id']][$r['day']] = $r['status'];
}

// Output CSV
$filename = "SF2_{$monthLabel}_{$section['section_name']}.csv";
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// Header rows
fputcsv($out, [getSetting('school_name') . ' — Kindergarten Department']);
fputcsv($out, ['SCHOOL FORM 2 — DAILY ATTENDANCE RECORD']);
fputcsv($out, ['Month/Year: ' . date('F Y', mktime(0,0,0,$month,1,$year))]);
fputcsv($out, ['Section: ' . ($section['section_name'] ?? '')]);
fputcsv($out, []);

// Column headers
$headers = ['#', 'Student Name (Last, First)'];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $headers[] = $d;
}
$headers = array_merge($headers, ['Present', 'Late', 'Absent', 'Excused']);
fputcsv($out, $headers);

foreach ($students as $idx => $stu) {
    $p = $l = $a = $e = 0;
    $row = [
        $idx + 1,
        $stu['last_name'] . ', ' . $stu['first_name'] . ($stu['middle_name'] ? ' ' . substr($stu['middle_name'],0,1) . '.' : '')
    ];
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $dayOfWeek = date('N', mktime(0,0,0,$month,$d,$year));
        $isWeekend = in_array($dayOfWeek, [6,7]);
        if ($isWeekend) { $row[] = '/'; continue; }
        $stat = $attMatrix[$stu['id']][$d] ?? '';
        switch ($stat) {
            case 'present': $row[] = 'P'; $p++; break;
            case 'late':    $row[] = 'L'; $l++; break;
            case 'absent':  $row[] = 'A'; $a++; break;
            case 'excused': $row[] = 'E'; $e++; break;
            default:        $row[] = '';
        }
    }
    $row = array_merge($row, [$p, $l, $a, $e]);
    fputcsv($out, $row);
}

fclose($out);
exit;