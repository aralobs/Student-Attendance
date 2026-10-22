<?php
/**
 * Toggle User Active Status
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();
requireCsrfPost();

$id = (int)($_POST['id'] ?? 0);
$db = getDB();
$db->beginTransaction();
try {

$stmt = $db->prepare("SELECT * FROM users WHERE id = ? FOR UPDATE");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'User not found.');
} elseif ((int)$user['is_active'] === 2) {
    setFlash('warning', 'Archived users cannot be toggled. Restore them first.');
} elseif ((int)$user['id'] === (int)currentUser()['id']) {
    setFlash('danger', 'You cannot deactivate your own account.');
} else {
    $newStatus = ((int)$user['is_active'] === 1) ? 0 : 1;
    $db->prepare("UPDATE users SET session_token = NULL, session_user_agent = NULL, session_ip = NULL, session_last_activity = NULL, is_active = ? WHERE id = ?")->execute([$newStatus, $id]);
    auditAccountAfterWrite($db, $id, $user, 'account.toggle');
    setFlash('success', "User '{$user['full_name']}' " . ($newStatus ? 'activated' : 'deactivated') . '.');
}

$db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(503); exit('Unable to save the account.');
}
header('Location: users.php');
exit;
