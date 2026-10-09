<?php
/**
 * Login Page (index.php)
 * SPCCS Elementary Attendance System v2.0
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

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
if (!empty($_SESSION['user_id']) && !empty($_SESSION['session_token'])) {
    try {
        $db   = getDB();
        $stmt = $db->prepare("
            SELECT session_token, role
            FROM users
            WHERE id = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $row = $stmt->fetch();

        if (
            $row &&
            !empty($row['session_token']) &&
            hash_equals((string)$row['session_token'], (string)$_SESSION['session_token'])
        ) {
            $_SESSION['role'] = $row['role'];
            // Already logged in — route by role
            if ($row['role'] === 'scanner_operator') {
                header('Location: ' . BASE_URL . 'attendance/scanner.php');
            } else {
                header('Location: ' . BASE_URL . 'dashboard.php');
            }
            exit;
        }

        // Stale session — clear DB token, fall through to login form
        if ($row) {
            try {
                $db->prepare("
                    UPDATE users
                    SET session_token = NULL,
                        session_user_agent = NULL,
                        session_ip = NULL,
                        session_last_activity = NULL
                    WHERE id = ? AND session_token = ?
                ")->execute([$_SESSION['user_id'], $_SESSION['session_token']]);
            } catch (Throwable $e) {
                error_log('login.php stale cleanup error: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log('login.php session check error: ' . $e->getMessage());
    }

    // Wipe local session and fall through to login form
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    session_start();
}

$error = '';

// ── Friendly messages for forced logouts ────────────────────
$kicked = $_GET['reason'] ?? '';
if ($kicked === 'session_taken') {
    $error = 'Your account was signed in from another browser, so this session was ended. Only one active session is allowed per account.';
} elseif ($kicked === 'timeout') {
    $error = 'Your session expired due to inactivity. Please log in again.';
} elseif ($kicked === 'invalid') {
    $error = 'Your session is no longer valid. Please log in again.';
} elseif ($kicked === 'logged_out') {
    $error = 'You have been logged out successfully.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($username) || empty($password)) {
        $error = 'Please enter your username and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

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
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
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

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
