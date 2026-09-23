<?php
/**
 * Restore Archived User
 * Only works on users marked as archived (is_active = 2).
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 2");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'Archived user not found.');
} else {
    $db->prepare("UPDATE users SET is_active = 1 WHERE id = ?")->execute([$id]);
    setFlash('success', "User '{$user['full_name']}' restored successfully.");
}

header('Location: archived.php');
exit;