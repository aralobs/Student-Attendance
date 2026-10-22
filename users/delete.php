<?php
/**
 * Permanently Delete User
 * Only allowed for users already marked as archived (is_active = 2).
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
    setFlash('danger', 'User must be archived before permanent deletion.');
    $db->commit();
header('Location: users.php');
    exit;
}

$db->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
    auditAccountAfterWrite($db, $id, $user, 'account.delete');
setFlash('success', "User '{$user['full_name']}' permanently deleted.");
$db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) $db->rollBack();
    http_response_code(503); exit('Unable to save the account.');
}
header('Location: archived.php');
exit;
