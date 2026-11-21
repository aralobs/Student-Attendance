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
$securityTab = ($_POST['action'] ?? '') === 'change_password';
$pageTitle = 'My Profile';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/sidebar.php';
?>
<div class="card profile-card mb-4">
    <div class="card-header">
        <h1 class="h6 mb-0"><i class="bi bi-person-circle me-2 text-primary" aria-hidden="true"></i>My Profile</h1>
    </div>
    <div class="card-body">
    <p class="text-muted small mb-3">Update your details or change your password.</p>
<?php showFlash(); ?>
<?php foreach ($errors as $error): ?>
<div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endforeach; ?>
<style>
    .profile-card { border: none; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); transition: box-shadow 0.2s ease; }
    .profile-card:hover { box-shadow: 0 4px 18px rgba(0,0,0,0.1); }
    .profile-card > .card-header { background: linear-gradient(135deg, #f8fafc 0%, #eef2f7 100%); border-bottom: 1px solid #e5e9f0; border-radius: 14px 14px 0 0 !important; font-weight: 600; padding: 0.9rem 1.15rem; }
    .profile-card .profile-tile { border: 1px solid #e5e9f0; border-radius: 12px; background: #fff; box-shadow: none; min-width: 0; }
    .profile-account-summary { display: flex; flex-wrap: wrap; gap: 0.5rem 1.5rem; overflow-wrap: anywhere; }
    .profile-editor { max-width: 640px; }
    .profile-card .nav-tabs { gap: 0.25rem; border-bottom-color: #e5e9f0; }
    .profile-card .nav-link { font-weight: 600; }
    .profile-card .nav-link.active { color: var(--primary); }
</style>
<div class="profile-account-summary small text-muted mb-3">
    <span>Username: <strong class="text-body"><?= htmlspecialchars($profile['username'], ENT_QUOTES, 'UTF-8') ?></strong></span>
    <span>Role: <strong class="text-body"><?= htmlspecialchars(roleLabel($profile['role']), ENT_QUOTES, 'UTF-8') ?></strong></span>
</div>
<div class="nav nav-tabs mb-3" role="tablist" aria-label="Profile settings">
    <button class="nav-link <?= !$securityTab ? 'active' : '' ?>" id="generalProfileTab" data-bs-toggle="tab" data-bs-target="#generalProfilePanel" type="button" role="tab" aria-controls="generalProfilePanel" aria-selected="<?= !$securityTab ? 'true' : 'false' ?>"><i class="bi bi-person me-1" aria-hidden="true"></i>General Profile</button>
    <button class="nav-link <?= $securityTab ? 'active' : '' ?>" id="securityProfileTab" data-bs-toggle="tab" data-bs-target="#securityProfilePanel" type="button" role="tab" aria-controls="securityProfilePanel" aria-selected="<?= $securityTab ? 'true' : 'false' ?>"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Security</button>
</div>
<div class="tab-content profile-editor">
    <div class="tab-pane <?= !$securityTab ? 'show active' : '' ?>" id="generalProfilePanel" role="tabpanel" aria-labelledby="generalProfileTab" tabindex="0">
        <form method="POST" class="profile-tile p-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_profile">
            <h2 class="h6 mb-2">Profile details</h2>
            <p class="text-muted small mb-3">Update your full name and email address.</p>
            <div class="mb-3">
                <label for="profileName" class="form-label">Full name</label>
                <input id="profileName" name="full_name" class="form-control" required maxlength="100" autocomplete="name" value="<?= htmlspecialchars($profile['full_name'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="mb-3">
                <label for="profileEmail" class="form-label">Email</label>
                <input id="profileEmail" type="email" name="email" class="form-control" maxlength="100" autocomplete="email" value="<?= htmlspecialchars($profile['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="mb-3">
                <label for="profileCurrentPassword" class="form-label">Current password</label>
                <input id="profileCurrentPassword" type="password" name="current_password" class="form-control" required autocomplete="current-password" aria-describedby="profilePasswordHelp">
                <div id="profilePasswordHelp" class="form-text">Enter your current password to confirm changes to your profile.</div>
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Save profile</button>
        </form>
    </div>
    <div class="tab-pane <?= $securityTab ? 'show active' : '' ?>" id="securityProfilePanel" role="tabpanel" aria-labelledby="securityProfileTab" tabindex="0">
        <form method="POST" class="profile-tile p-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="change_password">
            <h2 class="h6 mb-2">Change password</h2>
            <p class="text-muted small mb-3">After changing your password, sign in again.</p>
            <div class="mb-3">
                <label for="securityCurrentPassword" class="form-label">Current password</label>
                <input id="securityCurrentPassword" type="password" name="current_password" class="form-control" required autocomplete="current-password" aria-describedby="securityPasswordHelp">
                <div id="securityPasswordHelp" class="form-text">Enter your current password to authorize the password change.</div>
            </div>
            <div class="mb-3">
                <label for="passwordNew" class="form-label">New password</label>
                <input id="passwordNew" type="password" name="new_password" class="form-control" required minlength="8" maxlength="16" autocomplete="new-password" aria-describedby="newPasswordHelp">
                <div id="newPasswordHelp" class="form-text">Use 8&ndash;16 characters.</div>
            </div>
            <div class="mb-3">
                <label for="passwordConfirm" class="form-label">Confirm new password</label>
                <input id="passwordConfirm" type="password" name="confirm_password" class="form-control" required minlength="8" maxlength="16" autocomplete="new-password">
            </div>
            <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-key me-1" aria-hidden="true"></i>Change password</button>
        </form>
    </div>
</div>
</div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
