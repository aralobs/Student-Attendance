<?php
/**
 * Archive Section — soft delete (sets is_active = 0)
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    setFlash('danger', 'Invalid section ID.');
    header('Location: sections.php');
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM sections WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$section = $stmt->fetch();

if (!$section) {
    setFlash('danger', 'Section not found.');
} else {
    // Check if section has active students
    $stuCount = $db->prepare("SELECT COUNT(*) FROM students WHERE section_id = ? AND is_active = 1");
    $stuCount->execute([$id]);
    $count = $stuCount->fetchColumn();

    if ($count > 0) {
        setFlash('danger', "Cannot archive — this section has {$count} active student(s). Reassign them first.");
    } else {
        $db->prepare("UPDATE sections SET is_active = 0 WHERE id = ?")
           ->execute([$id]);
        setFlash('success', "Section '{$section['section_name']}' archived.");
    }
}

header('Location: sections.php');
exit;