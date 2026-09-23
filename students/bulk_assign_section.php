<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: students.php');
    exit;
}

$db         = getDB();
$sectionId  = (int)($_POST['section_id'] ?? 0);
$studentIds = $_POST['student_ids'] ?? [];

if ($sectionId <= 0 || empty($studentIds)) {
    setFlash('error', 'No section or students selected.');
    header('Location: students.php');
    exit;
}

// Get the section's grade + school year so we keep student record in sync
$secStmt = $db->prepare("SELECT grade_level, school_year FROM sections WHERE id = ?");
$secStmt->execute([$sectionId]);
$section = $secStmt->fetch();

if (!$section) {
    setFlash('error', 'Section not found.');
    header('Location: students.php');
    exit;
}

// Sanitize IDs
$studentIds = array_map('intval', $studentIds);
$placeholders = implode(',', array_fill(0, count($studentIds), '?'));

try {
    $db->beginTransaction();

    $sql = "UPDATE students 
            SET section_id = ?, grade_level = ?, school_year = ?
            WHERE id IN ($placeholders)";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge([$sectionId, $section['grade_level'], $section['school_year']], $studentIds));

    $db->commit();
    setFlash('success', count($studentIds) . ' student(s) assigned to section.');
} catch (Exception $e) {
    $db->rollBack();
    setFlash('error', 'Failed to assign section: ' . $e->getMessage());
}

header('Location: students.php');
exit;