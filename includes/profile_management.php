<?php
require_once __DIR__ . "/security_audit.php";
/** The actor ID/token must come from the authenticated session, never the form. */
function saveOwnProfile(PDO $db, int $actorId, string $sessionToken, array $input): array {
    $db->beginTransaction();
    try {
    $action = $input['action'] ?? '';
    if (!in_array($action, ['update_profile', 'change_password'], true)) {
        return ['success' => false, 'status' => 400, 'errors' => ['Invalid profile action.']];
    }
    $stmt = $db->prepare("SELECT id, full_name, email, password FROM users WHERE id = ? AND session_token = ? AND is_active = 1 AND role IN ('admin','teacher') FOR UPDATE");
    $stmt->execute([$actorId, $sessionToken]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return ['success' => false, 'status' => 403, 'errors' => ['Your account access changed. Please sign in again.']];
    $currentPassword = $input['current_password'] ?? null;
    if (!is_string($currentPassword) || strlen($currentPassword) > 1024 || str_contains($currentPassword, "\0") || !password_verify($currentPassword, $user['password'])) {
        return ['success' => false, 'status' => 400, 'errors' => ['Current password is incorrect.']];
    }
    if ($action === 'change_password') {
        $newPassword = $input['new_password'] ?? null;
        $errors = [];
        if ($error = passwordPolicyError($newPassword)) $errors[] = $error;
        if (!is_string($input['confirm_password'] ?? null) || $newPassword !== $input['confirm_password']) $errors[] = 'New passwords do not match.';
        if ($errors) return ['success' => false, 'status' => 400, 'errors' => $errors];
        $update = $db->prepare("UPDATE users SET password = ?, session_token = NULL, session_user_agent = NULL, session_ip = NULL, session_last_activity = NULL WHERE id = ? AND session_token = ? AND password = ? AND is_active = 1 AND role IN ('admin','teacher')");
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $actorId, $sessionToken, $user['password']]);
    } else {
        $name = is_string($input['full_name'] ?? null) ? trim($input['full_name']) : '';
        $email = is_string($input['email'] ?? null) ? trim($input['email']) : '';
        $errors = [];
        if ($name === '' || str_contains($name, "\0") || !mb_check_encoding($name, 'UTF-8') || mb_strlen($name, 'UTF-8') > 100) $errors[] = 'Full name must contain 1–100 characters.';
        if (!is_string($input['email'] ?? '') || ($email !== '' && (strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)))) $errors[] = 'Enter a valid email address, or leave it blank.';
        if ($errors) return ['success' => false, 'status' => 400, 'errors' => $errors];
        if ($email !== '') {
            $check = $db->prepare('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?');
            $check->execute([$email, $actorId]);
            if ((int)$check->fetchColumn() > 0) return ['success' => false, 'status' => 400, 'errors' => ['Email is already in use.']];
        }
        if ($name === $user['full_name'] && $email === (string)($user['email'] ?? '')) {
            return ['success' => true, 'password_changed' => false, 'full_name' => $name];
        }
        $update = $db->prepare("UPDATE users SET full_name = ?, email = ? WHERE id = ? AND session_token = ? AND password = ? AND is_active = 1 AND role IN ('admin','teacher')");
        $update->execute([$name, $email === '' ? null : $email, $actorId, $sessionToken, $user['password']]);
    }
    if ($update->rowCount() !== 1) {
        return ['success' => false, 'status' => 409, 'errors' => ['Your account changed while saving. Please reload or sign in again.']];
    }
    securityAuditContext($actorId);
    securityAuditEvent($db, $action === 'change_password' ? 'account.password_change' : 'account.profile_change', 'success', 'user', $actorId, $action === 'change_password');
    $db->commit();
    return ['success' => true, 'password_changed' => $action === 'change_password', 'full_name' => $name ?? $user['full_name']];
    } finally { if ($db->inTransaction()) $db->rollBack(); }
}
