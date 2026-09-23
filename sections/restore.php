x<?php
/**
 * Restore Archived Section — sets is_active = 1
 */
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    setFlash('danger', 'Invalid section ID.');
    header('Location: sections.php?view=archived');
    exit;
}

$db   = getDB();
$stmt = $db->prepare("SELECT * FROM sections WHERE id = ? AND is_active = 0");
$stmt->execute([$id]);
$section = $stmt->fetch();

if (!$section) {
    setFlash('danger', 'Archived section not found.');
} else {
    $db->prepare("UPDATE sections SET is_active = 1 WHERE id = ?")
       ->execute([$id]);
    setFlash('success', "Section '{$section['section_name']}' restored.");
}

header('Location: sections.php?view=archived');
exit;