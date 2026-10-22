<?php
/**
 * Logout
 * SPCCS Elementary Attendance System v2.0
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
startSession();

// Only clear the DB token if it is still THIS browser's token.
// Otherwise a kicked browser clicking "Logout" would also log out the
// browser that currently owns the account.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['session_token'])) {
    try {
        $db = getDB();
        $logout = $db->prepare("
            UPDATE users
            SET session_token = NULL,
                session_user_agent = NULL,
                session_ip = NULL,
                session_last_activity = NULL
            WHERE id = ? AND session_token = ?
        ");
        $logout->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
        if ($logout->rowCount() === 1) securityAuditContext((int)$_SESSION['user_id']);
    } catch (Throwable $e) {
        error_log('logout.php error: ' . $e->getMessage());
    }
}

clearLocalSession();

header('Location: ' . BASE_URL . 'index.php');
exit;
