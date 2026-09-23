<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM students WHERE id = ?");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('danger', 'Student not found.');
} else {
    // Soft delete
    $db->prepare("UPDATE students SET is_active = 0 WHERE id = ?")->execute([$id]);
    setFlash('success', "Student {$student['first_name']} {$student['last_name']} deleted.");
}

header('Location: index.php');
exit;