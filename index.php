<?php
/**
 * Login Page
 * SPCCS Elementary Attendance System v2.0
 */

session_start();
require_once 'config/database.php';
require_once 'includes/functions.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'dashboard.php');
    exit;
}

$error = '';

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
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['username']  = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role']      = $user['role'];

            header('Location: ' . BASE_URL . 'dashboard.php');
            exit;
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">

    <style>
        /* ============================================================
           LOGIN PAGE — Enhanced Design (Blue Background)
           ============================================================ */
        :root {
            --brand-primary: #2563eb;
            --brand-primary-dark: #1d4ed8;
            --brand-accent: #f59e0b;
            --brand-ink: #0f172a;
            --brand-muted: #64748b;
            --card-radius: 22px;
        }

        * { -webkit-font-smoothing: antialiased; }

        body {
            font-family: 'Nunito', system-ui, -apple-system, sans-serif;
            margin: 0;
            min-height: 100vh;
            color: var(--brand-ink);
        }

        .login-page {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            position: relative;
            overflow: hidden;
            /* Rich blue gradient background */
            background:
                radial-gradient(1200px 700px at 15% 0%,   rgba(96, 165, 250, .35), transparent 60%),
                radial-gradient(900px  600px at 100% 100%, rgba(30, 64, 175, .55), transparent 60%),
                radial-gradient(700px  500px at 50% 50%,  rgba(59, 130, 246, .25), transparent 70%),
                linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 45%, #2563eb 100%);
        }

        /* Decorative floating orbs */
        .login-page::before,
        .login-page::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            filter: blur(70px);
            opacity: .45;
            pointer-events: none;
            animation: floaty 10s ease-in-out infinite;
        }
        .login-page::before {
            width: 400px; height: 400px;
            background: #60a5fa;
            top: -120px; left: -100px;
        }
        .login-page::after {
            width: 360px; height: 360px;
            background: #fbbf24;
            bottom: -100px; right: -90px;
            animation-delay: -5s;
            opacity: .3;
        }

        /* Subtle dotted texture overlay for depth */
        .login-page .bg-dots {
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255,255,255,.12) 1px, transparent 1px);
            background-size: 22px 22px;
            pointer-events: none;
            mask-image: radial-gradient(circle at center, #000 30%, transparent 75%);
            -webkit-mask-image: radial-gradient(circle at center, #000 30%, transparent 75%);
        }

        @keyframes floaty {
            0%, 100% { transform: translateY(0) }
            50%      { transform: translateY(-22px) }
        }

        /* ===================== Card ===================== */
        .login-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 430px;
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
            border: 1px solid rgba(255,255,255,.85);
            border-radius: var(--card-radius);
            box-shadow:
                0 1px 0 rgba(255,255,255,.9) inset,
                0 30px 60px -14px rgba(2, 6, 23, .55),
                0 10px 26px -10px rgba(2, 6, 23, .35);
            padding: 42px 34px 34px;
            transition: transform .35s ease, box-shadow .35s ease;
        }
        .login-card:hover {
            transform: translateY(-3px);
            box-shadow:
                0 1px 0 rgba(255,255,255,.9) inset,
                0 40px 70px -16px rgba(2, 6, 23, .6),
                0 12px 30px -12px rgba(2, 6, 23, .4);
        }

        /* ===================== Logo ===================== */
        .login-logo {
            width: 82px;
            height: 82px;
            margin: -78px auto 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 24px;
            background: linear-gradient(145deg, var(--brand-primary), var(--brand-primary-dark));
            color: #fff;
            font-size: 2.15rem;
            box-shadow:
                0 14px 30px -8px rgba(37, 99, 235, .6),
                0 0 0 6px rgba(255,255,255,.9),
                0 0 0 8px rgba(37, 99, 235, .18);
            position: relative;
        }
        .login-logo::after {
            content: "";
            position: absolute;
            inset: -3px;
            border-radius: 26px;
            background: linear-gradient(145deg, #60a5fa, #f59e0b);
            z-index: -1;
            opacity: .55;
            filter: blur(10px);
        }

        /* ===================== Headings ===================== */
        .login-title {
            font-weight: 800;
            font-size: 1.3rem;
            letter-spacing: -0.01em;
            color: var(--brand-ink);
            margin: 0 0 6px;
        }
        .login-sub {
            color: var(--brand-muted);
            font-size: .875rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .dept-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .02em;
            padding: 6px 12px;
            border-radius: 999px;
            color: var(--brand-primary-dark);
            background: rgba(37, 99, 235, .1);
            border: 1px solid rgba(37, 99, 235, .18);
        }
        .dept-badge .dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--brand-accent);
            box-shadow: 0 0 0 3px rgba(245,158,11,.2);
        }

        /* ===================== Alert ===================== */
        .alert-danger-soft {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            border-radius: 12px;
            padding: 11px 14px;
            font-size: .85rem;
            font-weight: 600;
            margin-bottom: 18px;
            animation: shakeIn .45s ease;
        }
        .alert-danger-soft i { font-size: 1.05rem; line-height: 1.2; }

        @keyframes shakeIn {
            0%   { transform: translateX(0);    opacity: 0 }
            20%  { transform: translateX(-6px); opacity: 1 }
            40%  { transform: translateX(6px) }
            60%  { transform: translateX(-4px) }
            80%  { transform: translateX(4px) }
            100% { transform: translateX(0) }
        }

        /* ===================== Form ===================== */
        .form-label {
            font-size: .8rem;
            font-weight: 700;
            color: #334155;
            letter-spacing: .01em;
            margin-bottom: 6px;
        }

        .input-group {
            border-radius: 12px;
            transition: box-shadow .2s ease, transform .2s ease;
        }
        .input-group:focus-within {
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .14);
        }

        .input-group-text {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            color: var(--brand-muted);
        }
        .input-group > :first-child.input-group-text {
            border-right: 0;
            border-top-left-radius: 12px;
            border-bottom-left-radius: 12px;
        }
        .input-group > :last-child.input-group-text {
            border-left: 0;
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
        }

        .form-control {
            border: 1px solid #e2e8f0;
            background: #fff;
            font-size: .95rem;
            padding: 12px 14px;
            color: var(--brand-ink);
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        .form-control::placeholder { color: #94a3b8; }
        .form-control:focus {
            box-shadow: none;
            border-color: var(--brand-primary);
            background: #fff;
        }

        .form-control.border-start-0 { border-left: 0; }
        .form-control.border-end-0   { border-right: 0; }

        #passwordInput { letter-spacing: .06em; }

        /* Password toggle */
        .toggle-btn {
            cursor: pointer;
            border: 1px solid #e2e8f0;
            border-left: 0;
            background: #f8fafc;
            color: var(--brand-muted);
            transition: color .15s ease, background .15s ease;
            border-top-right-radius: 12px;
            border-bottom-right-radius: 12px;
            display: flex;
            align-items: center;
            padding: 0 14px;
        }
        .toggle-btn:hover {
            color: var(--brand-primary);
            background: #eff6ff;
        }

        /* ===================== Submit Button ===================== */
        .btn-signin {
            position: relative;
            overflow: hidden;
            font-weight: 800;
            letter-spacing: .02em;
            padding: 13px 16px;
            border-radius: 12px;
            border: none;
            color: #fff;
            background: linear-gradient(135deg, var(--brand-primary), var(--brand-primary-dark));
            box-shadow: 0 10px 22px -8px rgba(37, 99, 235, .55);
            transition: transform .2s ease, box-shadow .25s ease, filter .2s ease;
        }
        .btn-signin:hover {
            transform: translateY(-2px);
            filter: brightness(1.05);
            box-shadow: 0 16px 30px -10px rgba(37, 99, 235, .65);
            color: #fff;
        }
        .btn-signin:active {
            transform: translateY(0);
            box-shadow: 0 6px 14px -6px rgba(37, 99, 235, .5);
        }
        .btn-signin i { transition: transform .25s ease; }
        .btn-signin:hover i { transform: translateX(3px); }

        .btn-signin::before {
            content: "";
            position: absolute;
            top: 0; left: -120%;
            width: 60%; height: 100%;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,.35), transparent);
            transform: skewX(-20deg);
            transition: left .6s ease;
        }
        .btn-signin:hover::before { left: 130%; }

        /* ===================== Footer ===================== */
        .login-footer {
            text-align: center;
            margin-top: 22px;
            font-size: .75rem;
            color: #94a3b8;
            font-weight: 600;
        }
        .login-footer .sep {
            display: inline-block;
            width: 4px; height: 4px;
            border-radius: 50%;
            background: #cbd5e1;
            vertical-align: middle;
            margin: 0 8px;
        }

        /* ===================== Fade-in ===================== */
        .fade-in {
            animation: fadeInUp .55s cubic-bezier(.2,.7,.3,1) both;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(14px) scale(.98) }
            to   { opacity: 1; transform: translateY(0)    scale(1) }
        }

        /* ===================== Responsive ===================== */
        @media (max-width: 480px) {
            .login-card { padding: 34px 22px 26px; border-radius: 18px; }
            .login-logo { width: 70px; height: 70px; margin-top: -66px; font-size: 1.85rem; }
            .login-title { font-size: 1.15rem; }
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
</head>
<body>
<div class="login-page">
    <div class="bg-dots"></div>

    <div class="login-card fade-in">

        <!-- Logo -->
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