<?php
/** Session-bound synchronizer tokens for authenticated application actions. */
function csrfToken(): string {
    startSession();
    if (!isset($_SESSION['app_csrf']) || !is_string($_SESSION['app_csrf'])) {
        $_SESSION['app_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['app_csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function requireCsrfPost(bool $json = false): void {
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $status = $method !== 'POST' ? 405 : 403;
    $token = $_POST['csrf_token'] ?? null;
    if ($method === 'POST' && is_string($token) && hash_equals(csrfToken(), $token)) {
        return;
    }
    http_response_code($status);
    if ($status === 405) header('Allow: POST');
    $message = $status === 405 ? 'This action requires POST.' : 'Invalid form token. Reload the page and try again.';
    header('Cache-Control: no-store');
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message, 'error' => $message]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message;
    }
    exit;
}

/** Pages can serve GET forms, but accept mutations only via validated POST. */
function guardCsrfPage(bool $json = false): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') requireCsrfPost($json);
}
