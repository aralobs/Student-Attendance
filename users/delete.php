<?php
/**
 * Permanently Delete User
 * Only allowed for users already marked as archived (is_active = 2).
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
    setFlash('danger', 'User must be archived before permanent deletion.');
    header('Location: users.php');
    exit;
}

$db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
setFlash('success', "User '{$user['full_name']}' permanently deleted.");
header('Location: archived.php');
exit;