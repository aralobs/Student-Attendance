<?php
/**
 * Archive User (soft delete)
 * Sets is_active = 2 so the user is hidden from the main list
 * but kept in the database. Restorable via restore.php.
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'User not found.');
} elseif ($user['id'] == currentUser()['id']) {
    setFlash('danger', 'You cannot archive your own account.');
} elseif ($user['is_active'] == 2) {
    setFlash('warning', 'User is already archived.');
} elseif ($user['role'] === 'admin') {
    // Protect the last active admin
    $activeAdmins = (int)$db->query("
        SELECT COUNT(*) FROM users
        WHERE role = 'admin' AND is_active = 1
    ")->fetchColumn();

    if ($activeAdmins <= 1) {
        setFlash('danger', 'Cannot archive the last active admin.');
    } else {
        $db->prepare("UPDATE users SET is_active = 2 WHERE id = ?")->execute([$id]);
        setFlash('success', "User '{$user['full_name']}' archived successfully.");
    }
} else {
    $db->prepare("UPDATE users SET is_active = 2 WHERE id = ?")->execute([$id]);
    setFlash('success', "User '{$user['full_name']}' archived successfully.");
}

header('Location: users.php');
exit;