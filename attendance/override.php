<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: attendance.php');
    exit;
}

$id           = (int)($_POST['attendance_id'] ?? 0);
$amIn         = !empty($_POST['am_in'])     ? $_POST['am_in']     : null;
$amOut        = !empty($_POST['am_out'])    ? $_POST['am_out']    : null;
$amStatus     = !empty($_POST['am_status']) ? $_POST['am_status'] : null;
$pmIn         = !empty($_POST['pm_in'])     ? $_POST['pm_in']     : null;
$pmOut        = !empty($_POST['pm_out'])    ? $_POST['pm_out']    : null;
$pmStatus     = !empty($_POST['pm_status']) ? $_POST['pm_status'] : null;
$remarks      = trim($_POST['remarks']      ?? '');
$scheduleType = $_POST['schedule_type']     ?? 'full_day';
$redirectDate = $_POST['redirect_date']     ?? date('Y-m-d');

if ($id <= 0) {
    setFlash('danger', 'Invalid attendance record.');
    header('Location: attendance.php?date=' . urlencode($redirectDate));
    exit;
}

$db = getDB();

// Get the attendance record and its section
$stmt = $db->prepare("
    SELECT a.*, sec.schedule_type
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE a.id = ?
");
$stmt->execute([$id]);
$existing = $stmt->fetch();

if (!$existing) {
    setFlash('danger', 'Attendance record not found.');
    header('Location: attendance.php?date=' . urlencode($redirectDate));
    exit;
}

// Compute attendance_type
$mockSection   = ['schedule_type' => $scheduleType];
$mockRecord    = [
    'am_in' => $amIn,
    'pm_in' => $pmIn,
];
$attendType = computeAttendanceType($mockRecord, $mockSection);

$db->prepare("
    UPDATE attendance SET
        am_in = ?, am_out = ?, am_status = ?,
        pm_in = ?, pm_out = ?, pm_status = ?,
        attendance_type = ?,
        remarks = ?,
        recorded_by = ?
    WHERE id = ?
")->execute([
    $amIn, $amOut, $amStatus,
    $pmIn, $pmOut, $pmStatus,
    $attendType,
    $remarks,
    currentUser()['id'],
    $id,
]);

setFlash('success', 'Attendance record updated successfully.');
header('Location: attendance.php?date=' . urlencode($redirectDate));
exit;