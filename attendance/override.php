<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();
requireCsrfPost();
if (!isAdmin() && !isTeacher()) { http_response_code(403); exit('Attendance editing is restricted to administrators and teachers.'); }
require_once '../includes/enrollment_operations.php';


$id           = (int)($_POST['attendance_id'] ?? 0);
$amIn         = !empty($_POST['am_in'])     ? $_POST['am_in']     : null;
$amOut        = !empty($_POST['am_out'])    ? $_POST['am_out']    : null;
$amStatus     = !empty($_POST['am_status']) ? $_POST['am_status'] : null;
$pmIn         = !empty($_POST['pm_in'])     ? $_POST['pm_in']     : null;
$pmOut        = !empty($_POST['pm_out'])    ? $_POST['pm_out']    : null;
$pmStatus     = !empty($_POST['pm_status']) ? $_POST['pm_status'] : null;
$remarks      = trim($_POST['remarks']      ?? '');
$redirectDate = $_POST['redirect_date']     ?? date('Y-m-d');

if ($id <= 0) {
    setFlash('danger', 'Invalid attendance record.');
    header('Location: attendance.php?date=' . urlencode($redirectDate));
    exit;
}

$db = getDB();
$db->beginTransaction();
try {

// Get the attendance record and its section
if (enrollmentModelEnabled($db)) {
    // Identify without locking attendance, then take the common structural prefix.
    $read=$db->prepare('SELECT student_id,date FROM attendance WHERE id=?'); $read->execute([$id]); $identity=$read->fetch();
    if (!$identity) throw new DomainException('Attendance record not found.');
    $locked=lockAttendanceEnrollment($db,(int)$identity['student_id'],$identity['date'],currentUser());
    $read=$db->prepare('SELECT * FROM attendance WHERE id=? FOR UPDATE'); $read->execute([$id]); $existing=$read->fetch();
    if (!$existing || $existing['date']!==$identity['date'] || $existing['student_id']!=$identity['student_id']) throw new DomainException('Attendance ownership changed.');
    verifyAttendanceEnrollment($existing,$locked['context']);
    $existing=array_merge($existing,['section_id'=>$locked['context']['section_id'],'grade_level'=>$locked['section']['grade_level'],'schedule_type'=>$locked['section']['schedule_type']]);
} else {
$stmt = $db->prepare("
    SELECT a.*, s.section_id, sec.grade_level, sec.schedule_type
    FROM attendance a
    JOIN students s ON a.student_id = s.id
    LEFT JOIN sections sec ON s.section_id = sec.id
    WHERE a.id = ? FOR UPDATE
");
$stmt->execute([$id]);
$existing = $stmt->fetch();
}

if (!$existing) {
    setFlash('danger', 'Attendance record not found.');
    header('Location: attendance.php?date=' . urlencode($redirectDate));
    exit;
}

$beforeAudit = $existing;
// Compute attendance_type
if (empty($existing['section_id']) || !canAccessSection((int)$existing['section_id'])) {
    http_response_code(403); exit('Access denied to this section.');
}
$mockSection = $existing;
$existing['schedule_type'] = getAttendanceScheduleType($existing);
$schedule = $existing['schedule_type'];
if ($schedule !== 'pm_only') $pmIn = null;
if ($schedule === 'pm_only') { $amIn = null; $amOut = null; $amStatus = null; }
elseif ($schedule === 'am_only') { $pmOut = null; $pmStatus = null; }
else { $amOut = null; if ($amIn && !$pmStatus) $pmStatus = 'present'; }
$mockRecord    = [
    'am_in' => $amIn,
    'pm_in' => $pmIn,
    'am_out' => $amOut,
    'pm_out' => $pmOut,
    'remarks' => $remarks,
];
$attendType = computeAttendanceType($mockRecord, $mockSection);
if ($attendType === 'remarked') { $amStatus = null; $pmStatus = null; }

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

auditAttendanceAfterWrite($db, $id, $beforeAudit, 'attendance.override');
$db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    error_log('SECURITY_ALERT attendance_write_failed request_id=' . securityRequestId());
    http_response_code(503); exit('Unable to save attendance. Please try again.');
}

setFlash('success', 'Attendance record updated successfully.');
header('Location: attendance.php?date=' . urlencode($redirectDate));
exit;
