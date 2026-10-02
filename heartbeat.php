<?php
/**
 * Heartbeat endpoint
 * Returns 200 if the session is still valid, 401 if it was kicked.
 * Called every 10s by includes/footer.php from every logged-in page.
 */

require_once 'config/database.php';
require_once 'includes/functions.php';

startSession();

// ── No-cache so proxies don't fake a valid response ────────
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}
header('Content-Type: application/json');

// ── Not logged in at all ───────────────────────────────────
if (empty($_SESSION['user_id']) || empty($_SESSION['session_token'])) {
    http_response_code(401);
    echo json_encode([
        'success'  => false,
        'error'    => 'session_invalid',
        'reason'   => 'invalid',
        'redirect' => BASE_URL . 'index.php?reason=invalid',
    ]);
    exit;
}

// ── Validate the session ───────────────────────────────────
$reason = checkSession();
if ($reason !== null) {
    $url = BASE_URL . 'index.php?reason=' . urlencode($reason);
    http_response_code(401);
    echo json_encode([
        'success'  => false,
        'error'    => 'session_invalid',
        'reason'   => $reason,
        'redirect' => $url,
    ]);
    exit;
}

// ── All good ───────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'ts'      => time(),
]);