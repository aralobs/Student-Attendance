<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id      = (int)($_POST['attendance_id'] ?? 0);
$status  = $_POST['status']   ?? 'present';
$remarks = trim($_POST['remarks'] ?? '');
$date    = $_POST['redirect_date'] ?? date('Y-m-d');
$allowed = ['present','absent','late','excused'];

if ($id <= 0 || !in_array($status, $allowed)) {
    setFlash('danger', 'Invalid override data.');
    header('Location: index.php?date=' . urlencode($date));
    exit;
}

$db = getDB();
$db->prepare("UPDATE attendance SET status = ?, remarks = ?, scan_type = 'manual', recorded_by = ? WHERE id = ?")
   ->execute([$status, $remarks, currentUser()['id'], $id]);

setFlash('success', 'Attendance record updated.');
header('Location: index.php?date=' . urlencode($date));
exit;