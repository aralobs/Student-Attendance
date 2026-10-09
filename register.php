<?php
/**
 * register.php — Simple account creation
 * Open this page directly. Creates a new account. No login required.
 */

require_once __DIR__ . '/config/database.php';

$errors  = [];
$success = '';
$old = [
    'username'  => '',
    'full_name' => '',
    'email'     => '',
    'role'      => 'scanner_operator',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $old['username']  = trim($_POST['username']  ?? '');
    $old['full_name'] = trim($_POST['full_name'] ?? '');
    $old['email']     = trim($_POST['email']     ?? '');
    $old['role']      = $_POST['role']           ?? 'scanner_operator';

    $password        = $_POST['password']         ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    // ─── Validation ─────────────────────────────────────────
    if ($old['username'] === '') {
        $errors[] = 'Username is required.';
    }

    if ($old['full_name'] === '') {
        $errors[] = 'Full name is required.';
    }

    if (!in_array($old['role'], ['admin', 'teacher', 'scanner_operator'], true)) {
        $errors[] = 'Invalid role.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }

    if ($password !== $passwordConfirm) {
        $errors[] = 'Passwords do not match.';
    }

    // ─── Check duplicate username ───────────────────────────
    if (empty($errors)) {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$old['username']]);
        if ($stmt->fetch()) {
            $errors[] = 'That username is already taken.';
        }
    }

    // ─── Insert ─────────────────────────────────────────────
    if (empty($errors)) {
        $db   = getDB();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO users (username, password, full_name, email, role, is_active)
            VALUES (?, ?, ?, ?, ?, 1)
        ");
        $stmt->execute([
            $old['username'],
            $hash,
            $old['full_name'],
            $old['email'] !== '' ? $old['email'] : null,
            $old['role'],
        ]);

        $success = "Account '{$old['username']}' created successfully!";

        // Reset form
        $old = ['username' => '', 'full_name' => '', 'email' => '', 'role' => 'scanner_operator'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 520px;">

    <h3 class="mb-4">Create Account</h3>

    <?php if ($success): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="POST">

                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <input type="text" name="username" class="form-control"
                           value="<?= htmlspecialchars($old['username']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= htmlspecialchars($old['full_name']) ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($old['email']) ?>">
                </div>

                <div class="mb-3">
                    <label class="form-label">Role</label>
                    <select name="role" class="form-select">
                        <option value="scanner_operator"    <?= $old['role'] === 'scanner_operator'    ? 'selected' : '' ?>>Scanner Operator</option>
                        <option value="teacher" <?= $old['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                        <option value="admin"   <?= $old['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required>
                </div>

                <div class="mb-3">
                    <label class="form-label">Confirm Password</label>
                    <input type="password" name="password_confirm" class="form-control" required>
                </div>

                <button type="submit" class="btn btn-primary w-100">Create Account</button>

            </form>
        </div>
    </div>

    <p class="text-center mt-3">
        <a href="index.php">← Back to Login</a>
    </p>

</div>
</body>
</html>