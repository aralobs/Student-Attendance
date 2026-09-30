<?php
/**
 * User Management - Edit
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Edit User';
$id        = (int)($_GET['id'] ?? 0);
$db        = getDB();

// Fetch the user (do NOT filter archived here, we check it below)
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'User not found.');
    header('Location: users.php');
    exit;
}

// Block editing archived users
if ((int)$user['is_active'] === 2) {
    setFlash('warning', 'Archived users cannot be edited. Restore them first.');
    header('Location: users.php');
    exit;
}

$errors = [];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $full_name = trim($_POST['full_name'] ?? '');
    $username  = trim($_POST['username']  ?? '');
    $email     = trim($_POST['email']     ?? '');
    $role      = $_POST['role']           ?? 'teacher';
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $password  = $_POST['password']         ?? '';
    $password2 = $_POST['password_confirm'] ?? '';

    // Validation
    if ($full_name === '') {
        $errors[] = 'Full name is required.';
    }
    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Invalid email address.';
    }
    if (!in_array($role, ['admin', 'teacher', 'user'], true)) {
        $errors[] = 'Invalid role selected.';
    }
    if (!in_array($is_active, [0, 1], true)) {
        $errors[] = 'Invalid status selected.';
    }

    // Uniqueness checks (excluding current user)
    if (empty($errors)) {
        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $id]);
        if ($check->fetchColumn() > 0) {
            $errors[] = 'Username is already taken.';
        }
    }
    if (empty($errors) && $email !== '') {
        $check = $db->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
        $check->execute([$email, $id]);
        if ($check->fetchColumn() > 0) {
            $errors[] = 'Email is already in use.';
        }
    }

    // Optional password change
    if ($password !== '' || $password2 !== '') {
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if ($password !== $password2) {
            $errors[] = 'Passwords do not match.';
        }
    }

    // Save
    if (empty($errors)) {
        if ($password !== '') {
            $db->prepare("UPDATE users
                             SET full_name = ?, username = ?, email = ?, role = ?,
                                 is_active = ?, password = ?
                           WHERE id = ?")
               ->execute([
                   $full_name,
                   $username,
                   $email !== '' ? $email : null,
                   $role,
                   $is_active,
                   password_hash($password, PASSWORD_DEFAULT),
                   $id,
               ]);
        } else {
            $db->prepare("UPDATE users
                             SET full_name = ?, username = ?, email = ?, role = ?,
                                 is_active = ?
                           WHERE id = ?")
               ->execute([
                   $full_name,
                   $username,
                   $email !== '' ? $email : null,
                   $role,
                   $is_active,
                   $id,
               ]);
        }

        setFlash('success', "User '{$full_name}' updated successfully.");
        header('Location: users.php');
        exit;
    }

    // Keep submitted values so the form re-populates on error
    $user = array_merge($user, [
        'full_name' => $full_name,
        'username'  => $username,
        'email'     => $email,
        'role'      => $role,
        'is_active' => $is_active,
    ]);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit User</h1>
        <p class="page-subtitle">Update account details for <?= sanitize($user['full_name']) ?></p>
    </div>
    <a href="users.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to Users
    </a>
</div>

<div class="card">
    <div class="card-body">

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $e): ?>
                        <li><?= sanitize($e) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" novalidate>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required
                           value="<?= sanitize($user['full_name']) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required
                           value="<?= sanitize($user['username']) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= sanitize($user['email'] ?? '') ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select" required>
                        <?php foreach (['admin', 'teacher', 'user'] as $r): ?>
                            <option value="<?= $r ?>" <?= $user['role'] === $r ? 'selected' : '' ?>>
                                <?= ucfirst($r) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="is_active" class="form-select">
                        <option value="1" <?= (int)$user['is_active'] === 1 ? 'selected' : '' ?>>Hired (Active)</option>
                        <option value="0" <?= (int)$user['is_active'] === 0 ? 'selected' : '' ?>>Resigned (Inactive)</option>
                    </select>
                </div>

                <div class="col-12"><hr class="my-2"></div>

                <div class="col-md-6">
                    <label class="form-label">
                        New Password
                        <small class="text-muted">(leave blank to keep current)</small>
                    </label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check2 me-1"></i>Save Changes
                </button>
                <a href="users.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>