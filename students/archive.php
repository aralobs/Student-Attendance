<?php
/**
 * Archive Student (soft delete - sets is_active = 0)
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    setFlash('danger', 'Invalid student ID.');
    header('Location: students.php');
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('danger', 'Student not found or already archived.');
} else {
    $db->prepare("UPDATE students SET is_active = 0 WHERE id = ?")
       ->execute([$id]);
    setFlash('success', "Student {$student['first_name']} {$student['last_name']} has been archived.");
}

header('Location: students.php');
exit;