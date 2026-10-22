<?php
/**
 * Restore Archived User
 * Only works on users marked as archived (is_active = 2).
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();
requireCsrfPost();

$id = (int)($_POST['id'] ?? 0);
$db = getDB();
$db->beginTransaction();
try {

$stmt = $db->prepare("SELECT * FROM users WHERE id = ? AND is_active = 2 FOR UPDATE");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'Archived user not found.');
} else {
    $db->prepare("UPDATE users SET session_token = NULL, session_user_agent = NULL, session_ip = NULL, session_last_activity = NULL, is_active = 1 WHERE id = ?")->execute([$id]);
    auditAccountAfterWrite($db, $id, $user, 'account.restore');
    setFlash('success', "User '{$user['full_name']}' restored successfully.");
}

$db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(503); exit('Unable to save the account.');
}
header('Location: archived.php');
exit;
