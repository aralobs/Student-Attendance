<?php
/**
 * Edit User
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'User not found.');
    header('Location: index.php');
    exit;
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username']  ?? '');
    $email    = trim($_POST['email']     ?? '');
    $role     = $_POST['role']           ?? 'teacher';
    $password = $_POST['password']       ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (empty($fullName)) $errors[] = 'Full name is required.';
    if (empty($username)) $errors[] = 'Username is required.';
    if (!empty($password) && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if (!empty($password) && $password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }

    // Check duplicate username — exclude current user
    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $check->execute([$username, $id]);
        if ($check->fetch()) $errors[] = "Username '{$username}' is already taken.";
    }

    if (empty($errors)) {
        if (!empty($password)) {
            // Update with new password
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $db->prepare("
                UPDATE users SET
                    full_name = ?, username = ?, email = ?,
                    role = ?, password = ?
                WHERE id = ?
            ")->execute([$fullName, $username, $email, $role, $hash, $id]);
        } else {
            // Update without changing password
            $db->prepare("
                UPDATE users SET
                    full_name = ?, username = ?, email = ?, role = ?
                WHERE id = ?
            ")->execute([$fullName, $username, $email, $role, $id]);
        }

        setFlash('success', "User '{$fullName}' updated successfully.");
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'Edit User — ' . $user['full_name'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-person-gear me-2 text-primary"></i>Edit User
        </h1>
        <p class="page-subtitle">Update account information</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?>
    </ul>
</div>
<?php endif; ?>

<div class="card" style="max-width:580px">
    <div class="card-body p-4">
        <form method="POST" novalidate>

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control" required
                           value="<?= htmlspecialchars($_POST['full_name'] ?? $user['full_name']) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" required
                           value="<?= htmlspecialchars($_POST['username'] ?? $user['username']) ?>">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select"
                            <?= $user['id'] === currentUser()['id'] ? 'disabled' : '' ?>>
                        <option value="teacher"
                            <?= ($_POST['role'] ?? $user['role']) === 'teacher' ? 'selected' : '' ?>>
                            Teacher
                        </option>
                        <option value="admin"
                            <?= ($_POST['role'] ?? $user['role']) === 'admin' ? 'selected' : '' ?>>
                            Admin
                        </option>
                    </select>
                    <?php if ($user['id'] === currentUser()['id']): ?>
                    <small class="text-muted">You cannot change your own role.</small>
                    <!-- Keep role value when disabled -->
                    <input type="hidden" name="role" value="<?= $user['role'] ?>">
                    <?php endif; ?>
                </div>

                <div class="col-12">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($_POST['email'] ?? $user['email']) ?>">
                </div>

                <div class="col-12">
                    <hr class="my-1">
                    <p class="fw-600 mb-2 small text-muted">
                        CHANGE PASSWORD
                        <span class="fw-normal">(leave blank to keep current password)</span>
                    </p>
                </div>

                <div class="col-md-6">
                    <label class="form-label">New Password</label>
                    <div class="input-group">
                        <input type="password" name="password"
                               id="newPass" class="form-control"
                               placeholder="Min. 6 characters"
                               minlength="6">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="togglePass('newPass','eyeNew')">
                            <i class="bi bi-eye" id="eyeNew"></i>
                        </button>
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Confirm New Password</label>
                    <div class="input-group">
                        <input type="password" name="confirm_password"
                               id="confirmPass" class="form-control"
                               placeholder="Re-enter password">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="togglePass('confirmPass','eyeConfirm')">
                            <i class="bi bi-eye" id="eyeConfirm"></i>
                        </button>
                    </div>
                    <div id="matchMsg" class="small mt-1"></div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>Save Changes
                </button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>

<?php
$extraJS = <<<'JS'
<script>
function togglePass(inputId, eyeId) {
    const input = document.getElementById(inputId);
    const eye   = document.getElementById(eyeId);
    if (input.type === 'password') {
        input.type    = 'text';
        eye.className = 'bi bi-eye-slash';
    } else {
        input.type    = 'password';
        eye.className = 'bi bi-eye';
    }
}

// Live password match checker
const np  = document.getElementById('newPass');
const cp  = document.getElementById('confirmPass');
const msg = document.getElementById('matchMsg');

function checkMatch() {
    if (!cp.value) { msg.textContent = ''; return; }
    if (np.value === cp.value) {
        msg.innerHTML = '<span class="text-success">✓ Passwords match</span>';
    } else {
        msg.innerHTML = '<span class="text-danger">✗ Passwords do not match</span>';
    }
}

np.addEventListener('input', checkMatch);
cp.addEventListener('input', checkMatch);
</script>
JS;
include '../includes/footer.php';
?>