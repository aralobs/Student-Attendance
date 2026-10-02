<?php
/**
 * Logout
 * SPCCS Elementary Attendance System v2.0
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

// Only clear the DB token if it is still THIS browser's token.
// Otherwise a kicked browser clicking "Logout" would also log out the
// browser that currently owns the account.
if (!empty($_SESSION['user_id']) && !empty($_SESSION['session_token'])) {
    try {
        $db = getDB();
        $db->prepare("
            UPDATE users
            SET session_token = NULL,
                session_user_agent = NULL,
                session_ip = NULL,
                session_last_activity = NULL
            WHERE id = ? AND session_token = ?
        ")->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
    } catch (Throwable $e) {
        error_log('logout.php error: ' . $e->getMessage());
    }
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}

header('Location: ' . BASE_URL . 'index.php');
exit;