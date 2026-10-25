<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/profile_management.php';
requireLogin();
if (!isTeacher() && !isAdmin()) { http_response_code(403); exit('Profile editing is available to teachers and administrators.'); }
guardCsrfPage();
$db = getDB();
$actorId = (int)currentUser()['id'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    enforceAbuseLimits($db, [abuseBucket('profile.user', (string)$actorId, 10, 900), abuseBucket('profile.ip', abuseClientIP(), 100, 900)]);
    try {
        $result = saveOwnProfile($db, $actorId, $_SESSION['session_token'], $_POST);
        if ($result['success']) {
            if ($result['password_changed']) {
                clearLocalSession();
                header('Location: ' . BASE_URL . 'index.php?reason=password_changed');
                exit;
            }
            $_SESSION['full_name'] = $result['full_name'];
            setFlash('success', 'Your profile was updated.');
            header('Location: ' . BASE_URL . 'profile.php');
            exit;
        }
        http_response_code($result['status']);
        $errors = $result['errors'];
    } catch (Throwable $error) {
        http_response_code(503);
        $errors = ['Your profile could not be saved. Please try again later.'];
        error_log('Profile save failed.');
    }
}
$stmt = $db->prepare('SELECT username, full_name, email, role FROM users WHERE id = ? AND is_active = 1');
$stmt->execute([$actorId]);
$profile = $stmt->fetch();
if (!$profile) terminateSession('invalid');
$pageTitle = 'My Profile';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="page-header">
    <div><h1 class="page-title"><i class="bi bi-person-circle me-2"></i>My Profile</h1>
    <p class="page-subtitle">Update your details or change your password.</p></div>
</div>
<?php showFlash(); ?>
<?php foreach ($errors as $error): ?>
<div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>
<style>
    .profile-panels { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1.5rem; }
    .profile-panel .card-body { display: flex; flex-direction: column; padding: 1rem; }
    .profile-panel-intro { min-height: 3rem; }
    .profile-panel-actions { margin-top: auto; }
    @media (max-width: 991.98px) {
        .profile-panels { grid-template-columns: minmax(0, 1fr); }
    }
</style>
<p class="text-muted small mb-3">Username: <?= htmlspecialchars($profile['username'], ENT_QUOTES, 'UTF-8') ?>
    <span class="mx-2">&middot;</span>Role: <?= htmlspecialchars(roleLabel($profile['role']), ENT_QUOTES, 'UTF-8') ?></p>
<form method="POST">
    <?= csrfField() ?>
    <div class="card mb-4"><div class="card-body">
        <div class="row align-items-center g-3">
            <div class="col-lg-6">
                <label for="profileCurrentPassword" class="form-label">Current password</label>
                <input id="profileCurrentPassword" type="password" name="current_password" class="form-control" required autocomplete="current-password">
            </div>
            <div class="col-lg-6"><p class="text-muted small mb-0">Enter your current password to save your profile or change your password.</p></div>
        </div>
    </div></div>
    <div class="profile-panels">
        <div class="card profile-panel"><div class="card-body">
            <h2 class="h5 mb-3">Profile details</h2>
            <p class="text-muted small profile-panel-intro">Update your full name and email address.</p>
            <div class="mb-3"><label for="profileName" class="form-label">Full name</label>
                <input id="profileName" name="full_name" class="form-control" required maxlength="100" autocomplete="name" value="<?= htmlspecialchars($profile['full_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="mb-3"><label for="profileEmail" class="form-label">Email</label>
                <input id="profileEmail" type="email" name="email" class="form-control" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($profile['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"></div>
            <div class="profile-panel-actions"><button type="submit" name="action" value="update_profile" class="btn btn-primary" formnovalidate>Save profile</button></div>
        </div></div>
        <div class="card profile-panel"><div class="card-body">
            <h2 class="h5 mb-3">Change password</h2>
            <p class="text-muted small profile-panel-intro">Use 8?16 characters. After changing your password, sign in again.</p>
            <div class="mb-3"><label for="passwordNew" class="form-label">New password</label>
                <input id="passwordNew" type="password" name="new_password" class="form-control" minlength="8" maxlength="16" autocomplete="new-password"></div>
            <div class="mb-3"><label for="passwordConfirm" class="form-label">Confirm new password</label>
                <input id="passwordConfirm" type="password" name="confirm_password" class="form-control" minlength="8" maxlength="16" autocomplete="new-password"></div>
            <div class="profile-panel-actions"><button type="submit" name="action" value="change_password" class="btn btn-primary" formnovalidate>Change password</button></div>
        </div></div>
    </div>
</form>
<?php include __DIR__ . '/includes/footer.php'; ?>
