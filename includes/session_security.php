<?php
/** Central startup and validation for authenticated PHP sessions. */
function sessionTimeoutSetting(string $name, int $default): int {
    $value = filter_var(getenv($name), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);
    return $value === false ? $default : $value;
}
if (!defined('SESSION_IDLE_TIMEOUT')) define('SESSION_IDLE_TIMEOUT', sessionTimeoutSetting('SESSION_IDLE_TIMEOUT', 1800));
if (!defined('SESSION_ABSOLUTE_TIMEOUT')) define('SESSION_ABSOLUTE_TIMEOUT', sessionTimeoutSetting('SESSION_ABSOLUTE_TIMEOUT', 28800));
if (!defined('SCANNER_SESSION_IDLE_TIMEOUT')) define('SCANNER_SESSION_IDLE_TIMEOUT', sessionTimeoutSetting('SCANNER_SESSION_IDLE_TIMEOUT', 14400));
if (!defined('SCANNER_SESSION_ABSOLUTE_TIMEOUT')) define('SCANNER_SESSION_ABSOLUTE_TIMEOUT', sessionTimeoutSetting('SCANNER_SESSION_ABSOLUTE_TIMEOUT', 43200));

function sessionCookieSecure(): bool {
    $https = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    $configuredHost = parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_HOST);
    $requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    $loopback = ['localhost', '127.0.0.1', '::1'];
    // HTTP is permitted only for direct loopback development. Production must
    // use HTTPS; forwarded headers cannot disable the Secure flag.
    $localDevelopment = getenv('APP_ENV') !== 'production' && !$https
        && in_array($configuredHost, $loopback, true)
        && in_array($requestHost, $loopback, true)
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && parse_url(defined('BASE_URL') ? BASE_URL : '', PHP_URL_SCHEME) !== 'https';
    return !$localDevelopment;
}

function startSession(): void {
    if (session_status() !== PHP_SESSION_NONE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    // Keep server storage long enough for kiosk operation; validation below
    // enforces per-role idle and absolute expiration independently of GC.
    ini_set('session.gc_maxlifetime', (string)max(SESSION_ABSOLUTE_TIMEOUT, SCANNER_SESSION_ABSOLUTE_TIMEOUT));
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'domain' => '',
        'secure' => sessionCookieSecure(), 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if (!session_start()) sessionValidationUnavailable();
}

function clearLocalSession(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000, 'path' => $params['path'],
            'domain' => $params['domain'], 'secure' => $params['secure'],
            'httponly' => $params['httponly'], 'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    session_id('');
}

function sessionValidationUnavailable(): void {
    $requestId = bin2hex(random_bytes(12));
    error_log('Session validation temporarily unavailable. request_id=' . $requestId);
    http_response_code(503);
    header('Cache-Control: no-store');
    header('Retry-After: 30');
    $message = 'Session validation temporarily unavailable. Please try again later.';
    $json = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    if ($json) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'session_unavailable', 'message' => $message, 'request_id' => $requestId]);
    } else {
        header('Content-Type: text/plain; charset=UTF-8');
        echo $message . ' Reference: ' . $requestId;
    }
    exit;
}

/** null means valid; any DB failure stops the request with HTTP 503. */
function checkSession(bool $touchActivity = true): ?string {
    startSession();
    if (empty($_SESSION['user_id']) || !is_string($_SESSION['session_token'] ?? null) || $_SESSION['session_token'] === '') return 'invalid';
    try {
        $db = getDB(true);
        $stmt = $db->prepare('SELECT session_token, role FROM users WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();
    } catch (Throwable $error) {
        sessionValidationUnavailable();
    }
    if (!$row || empty($row['session_token'])) return 'invalid';
    if (!hash_equals((string)$row['session_token'], $_SESSION['session_token'])) return 'session_taken';
    if (!in_array($row['role'], ['admin', 'teacher', 'scanner_operator'], true)) return 'invalid';
    if (($_SESSION['role'] ?? null) !== $row['role']) return 'invalid';
    $now = time();
    $created = $_SESSION['authenticated_at'] ?? null;
    $lastActivity = $_SESSION['last_activity_at'] ?? null;
    // Legacy sessions have no reliable creation time: require a fresh login.
    if (!is_int($created) || !is_int($lastActivity) || $created <= 0 || $lastActivity < $created || $lastActivity > $now) return 'invalid';
    $scanner = $row['role'] === 'scanner_operator';
    $absolute = $scanner ? SCANNER_SESSION_ABSOLUTE_TIMEOUT : SESSION_ABSOLUTE_TIMEOUT;
    $idle = $scanner ? SCANNER_SESSION_IDLE_TIMEOUT : SESSION_IDLE_TIMEOUT;
    if ($now - $created >= $absolute || $now - $lastActivity >= $idle) return 'timeout';
    if ($touchActivity && $now - $lastActivity >= 60) {
        try {
            // Bind the update to this token so a replaced/revoked session
            // cannot touch the new session's activity record.
            $db->prepare('UPDATE users SET session_last_activity = NOW(), session_ip = ? WHERE id = ? AND session_token = ? AND is_active = 1')
                ->execute([$_SERVER['REMOTE_ADDR'] ?? null, $_SESSION['user_id'], $_SESSION['session_token']]);
        } catch (Throwable $error) {
            sessionValidationUnavailable();
        }
    }
    if ($touchActivity) $_SESSION['last_activity_at'] = $now;
    return null;
}
