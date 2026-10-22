<?php
/**
 * Archive User (soft delete)
 * Sets is_active = 2 so the user is hidden from the main list
 * but kept in the database. Restorable via restore.php.
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
        $db->prepare("UPDATE users SET session_token = NULL, session_user_agent = NULL, session_ip = NULL, session_last_activity = NULL, is_active = 2 WHERE id = ?")->execute([$id]);
    auditAccountAfterWrite($db, $id, $user, 'account.archive');
        setFlash('success', "User '{$user['full_name']}' archived successfully.");
    }
} else {
    $db->prepare("UPDATE users SET session_token = NULL, session_user_agent = NULL, session_ip = NULL, session_last_activity = NULL, is_active = 2 WHERE id = ?")->execute([$id]);
    auditAccountAfterWrite($db, $id, $user, 'account.archive');
    setFlash('success', "User '{$user['full_name']}' archived successfully.");
}

$db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(503); exit('Unable to save the account.');
}
header('Location: users.php');
exit;
