<?php
/**
 * Login Page (index.php)
 * SPCCS Elementary Attendance System v2.0
 */

require_once 'config/database.php';
require_once 'includes/functions.php';
startSession();
require_once __DIR__ . '/includes/browser_security.php';
sendBrowserSecurityHeaders();

// Anti-cache — the login page must never be cached either.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// ═══════════════════════════════════════════════════════════
// If already logged in with a VALID token, route to the
// correct landing page for the role.
// ═══════════════════════════════════════════════════════════
if (!empty($_SESSION['user_id'])) {
    $sessionReason = checkSession(false);
    if ($sessionReason === null) {
        $destination = $_SESSION['role'] === 'scanner_operator' ? 'attendance/scanner.php' : 'dashboard.php';
        header('Location: ' . BASE_URL . $destination);
        exit;
    }
    clearLocalSession();
    startSession();
}

$error = '';
$successMessage = '';

// ── Friendly messages for forced logouts ────────────────────
$kicked = $sessionReason ?? $_GET['reason'] ?? '';
if ($kicked === 'session_taken') {
    $error = 'Your account was signed in from another browser, so this session was ended. Only one active session is allowed per account.';
} elseif ($kicked === 'timeout') {
    $error = 'Your session expired due to inactivity. Please log in again.';
} elseif ($kicked === 'invalid') {
    $error = 'Your session is no longer valid. Please log in again.';
} elseif ($kicked === 'logged_out') {
    $error = 'You have been logged out successfully.';
} elseif ($kicked === 'password_changed') {
    $successMessage = 'Your password was changed. Please sign in with your new password.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['username'] ?? null) || !is_string($_POST['password'] ?? null)) {
        http_response_code(400);
        exit('Invalid login request.');
    }
    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    if (strlen($username) > 100 || strlen($password) > 1024 || str_contains($password, "\0")) {
        http_response_code(400);
        exit('Invalid login request.');
    }
    $GLOBALS['security_audit_outcome'] = 'failure';
    $db = getDB();
    // Check the IP first: blocked requests cannot create unlimited username rows.
    foreach (loginAbuseBuckets($username) as $bucket) enforceAbuseLimits($db, [$bucket]);

    if (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        // A real account ID is stable even under case/accent-insensitive SQL
        // collations; username spelling variations cannot bypass its quota.
        if ($user) enforceAbuseLimits($db, [abuseBucket('login.account', (string)$user['id'], 10, 900)]);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);

            try {
                $token = registerSession((int)$user['id']);
            } catch (Throwable $e) {
                error_log('login.php registerSession error: ' . $e->getMessage());
                $error = 'Could not start your session. Please try again.';
                $token = null;
            }

            if ($token !== null) {
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['username']      = $user['username'];
                $_SESSION['full_name']     = $user['full_name'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['session_token'] = $token;

                securityAuditContext((int)$user['id']);
                $GLOBALS['security_audit_outcome'] = 'success';
                session_write_close();

                if (!headers_sent()) {
                    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                }

                if ($user['role'] === 'scanner_operator') {
                    header('Location: ' . BASE_URL . 'attendance/scanner.php');
                } else {
                    header('Location: ' . BASE_URL . 'dashboard.php');
                }
                exit;
            }
        } else {
            if (!$user) password_verify($password, '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
            $error = 'Invalid username or password. Please try again.';
        }
    }
}

$schoolName = getSetting('school_name') ?? 'San Pablo City Central School';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars($schoolName) ?></title>
    <meta http-equiv="Cache-Control" content="no-store, no-cache, must-revalidate, max-age=0">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/login.css" rel="stylesheet">
    <script src="assets/js/privacy_storage.js" defer></script>
</head>
<body>
<div class="login-page">
    <div class="bg-dots"></div>

    <div class="login-card fade-in">

        <div class="login-logo">
            <i class="bi bi-mortarboard-fill"></i>
        </div>

        <h1 class="text-center login-title">
            Student Attendance System
        </h1>
        <p class="text-center login-sub">
            <?= htmlspecialchars($schoolName) ?>
        </p>
        <p class="text-center mb-4">
            <span class="dept-badge">
                <span class="dot"></span>
                Elementary Department — Grades Kinder to 6
            </span>
        </p>

        <?php if ($successMessage): ?>
        <div class="alert alert-success" role="status">
            <?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert-danger-soft">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate>
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="bi bi-person"></i>
                    </span>
                    <input type="text"
                           name="username"
                           class="form-control border-start-0"
                           placeholder="Enter username"
                           value="<?= htmlspecialchars($username ?? '') ?>"
                           autocomplete="username"
                           required autofocus>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="input-group">
                    <span class="input-group-text">
                        <i class="bi bi-lock"></i>
                    </span>
                    <input type="password"
                           name="password"
                           id="passwordInput"
                           class="form-control border-start-0 border-end-0"
                           placeholder="Enter password"
                           autocomplete="current-password"
                           required>
                    <button type="button"
                            class="toggle-btn"
                            aria-label="Toggle password visibility"
                            onclick="togglePassword()">
                        <i class="bi bi-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn btn-signin w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
            </button>
        </form>

        <div class="login-footer">
            SPCCS Attendance System
            <span class="sep"></span>
            v2.0
        </div>

    </div>
</div>

<script src="<?= BASE_URL ?>assets/vendor/bootstrap-5.3.0.bundle.min.js" integrity="sha384-geWF76RCwLtnZ8qwWowPQNguL3RmwHVBC9FhGdlKrxdiJJigb/j/68SIy3Te4Bkz" crossorigin="anonymous"></script>
<script>
function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type    = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type    = 'password';
        icon.className = 'bi bi-eye';
    }
}
</script>
</body>
</html>
