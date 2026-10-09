<?php

/**
 * System Settings
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireAdmin();

$pageTitle = 'Settings';
$db        = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keys = [
        'school_name',
        'school_id',
        'school_head',
        'school_address',
        'school_year',
        'grade_level',
        'time_in_start',
        'time_in_end',
        'late_threshold',
        'time_out_start',
        'time_out_end',
        'unisms_api_key',
        'unisms_sender_id',
        'sms_arrival_template',
        'sms_departure_template',
        'sms_absence_template',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_from_name',
        'mail_from_email',
        'email_notifications'
    ];
    try {
        if (!validSettingsCsrf($_POST['csrf_token'] ?? null)) {
            throw new InvalidArgumentException('Please reload Settings and try again.');
        }
        foreach ($keys as $key) {
            if (isset($_POST[$key]) && !is_string($_POST[$key])) {
                throw new InvalidArgumentException('Invalid settings value.');
            }
        }
        if (trim($_POST['school_name'] ?? '') === '') {
            throw new InvalidArgumentException('Please enter the school name.');
        }
        foreach (['school_name' => 255, 'school_id' => 30, 'school_head' => 200] as $key => $maxLength) {
            if (mb_strlen(trim($_POST[$key] ?? ''), 'UTF-8') > $maxLength) {
                throw new InvalidArgumentException('School information exceeds the allowed length.');
            }
        }
        $db->beginTransaction();
        foreach ($keys as $key) {
            if (isSecretSetting($key)) {
                $replacement = trim($_POST[$key] ?? '');
                if ($replacement !== '') {
                    updateSetting($key, $replacement);
                } else {
                    // Preserve blank fields and encrypt any credentials saved by older versions.
                    $stored = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
                    $stored->execute([$key]);
                    $oldValue = $stored->fetchColumn();
                    if (is_string($oldValue) && $oldValue !== '' && strncmp($oldValue, 'enc:v1:', 7) !== 0) {
                        updateSetting($key, $oldValue);
                    }
                }
                continue;
            }
            if (isset($_POST[$key])) {
                updateSetting($key, trim($_POST[$key]));
            }
        }
        updateSetting('email_notifications', isset($_POST['email_notifications']) ? '1' : '0');
        $db->commit();
        setFlash('success', 'Settings saved successfully.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($e instanceof InvalidArgumentException) {
            setFlash('danger', $e->getMessage());
        } else {
            error_log('Settings save failed: ' . $e->getMessage());
            setFlash('danger', 'Settings could not be saved. Please try again.');
        }
    }
    header('Location: settings.php');
    exit;
}

// Load all settings
$settings = [];
$rows = $db->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll();
foreach ($rows as $row) {
    $settings[$row['setting_key']] = isSecretSetting($row['setting_key'])
        ? ($row['setting_value'] !== null && $row['setting_value'] !== '') : $row['setting_value'];
}
unset($rows, $row);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-gear-fill me-2 text-primary"></i>System Settings
        </h1>
        <p class="page-subtitle">Configure school info, schedule, SMS and email</p>
    </div>
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" id="settingsCsrf" value="<?= htmlspecialchars(settingsCsrfToken()) ?>">
    <div class="row g-4">


        <!-- School Information -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-building me-2 text-primary"></i>School Information
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">These details are saved for school reports, including SF2 and SF4.</p>
                    <div class="row g-3 mb-4">
                        <div class="col-lg-6">
                            <label for="schoolName" class="form-label">School Name <span class="text-danger">*</span></label>
                            <input type="text" id="schoolName" name="school_name" class="form-control"
                                maxlength="255" required autocomplete="organization"
                                value="<?= htmlspecialchars($settings['school_name'] ?? '') ?>">
                        </div>
                        <div class="col-lg-2 col-md-4">
                            <label for="schoolId" class="form-label">School ID</label>
                            <input type="text" id="schoolId" name="school_id" class="form-control"
                                maxlength="30" placeholder="Enter school ID"
                                value="<?= htmlspecialchars($settings['school_id'] ?? '') ?>">
                        </div>
                        <div class="col-lg-4 col-md-8">
                            <label for="schoolHead" class="form-label">Current Principal / School Head</label>
                            <input type="text" id="schoolHead" name="school_head" class="form-control"
                                maxlength="200" placeholder="Enter full name"
                                value="<?= htmlspecialchars($settings['school_head'] ?? '') ?>">
                        </div>
                        <div class="col-md-8">
                            <label for="schoolAddress" class="form-label">School Address</label>
                            <input type="text" id="schoolAddress" name="school_address" class="form-control"
                                value="<?= htmlspecialchars($settings['school_address'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="schoolYear" class="form-label">School Year</label>
                            <input type="text" id="schoolYear" name="school_year" class="form-control"
                                placeholder="2026-2027"
                                value="<?= htmlspecialchars($settings['school_year'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">School Logo</label>
                        <div class="d-flex align-items-center gap-3">
                            <img src="<?= BASE_URL ?>assets/img/school_logo.png"
                                style="width:60px;height:60px;object-fit:contain;border:1px solid #e5e7eb;border-radius:8px;padding:4px"
                                onerror="this.style.display='none'"
                                id="logoPreview"
                                alt="School Logo">
                            <div>
                                <label for="logoUpload" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-upload me-1"></i>Upload Logo (PNG/JPG)
                                </label>
                                <input type="file" id="logoUpload" accept="image/png,image/jpeg" class="d-none"
                                    onchange="uploadLogo(this)">
                                <div class="text-muted small mt-1">
                                    Recommended: 960×960px PNG. Used in SF2 and SF4 reports.
                </div>
            </div>
        </div>
    </div>
                </div>
            </div>
        </div>

        <!-- UniSMS Settings -->
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-chat-dots-fill me-2 text-primary"></i>
                    UniSMS API Settings
                    <a href="https://unismsapi.com" target="_blank"
                        class="btn btn-sm btn-outline-info ms-2">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Get API Key
                    </a>
                </div>
                <div class="card-body">

                    <div class="alert alert-info py-2 mb-3" style="font-size:0.82rem">
                        <i class="bi bi-info-circle me-1"></i>
                        Register at <strong>unismsapi.com</strong>, go to Dashboard
                        → copy your <strong>Secret Key</strong>.
                        Sender ID must be approved by UniSMS
                        (default: <code>UnisoftSMS</code>).
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label for="apiKeyInput" class="form-label"><?= !empty($settings['unisms_api_key']) ? 'Replace API key' : 'UniSMS Secret Key' ?></label>
                            <p class="small text-muted mb-2"><?= !empty($settings['unisms_api_key']) ? 'SMS key configured. Send a test SMS to check the connection. Leave this field blank to keep the saved key.' : 'Paste your UniSMS Secret Key here, then click Save All Settings.' ?></p>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="bi bi-key text-muted"></i>
                                </span>
                                <input type="password"
                                    name="unisms_api_key"
                                    id="apiKeyInput"
                                    class="form-control font-monospace"
                                    placeholder="sk_xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                                    autocomplete="new-password"
                                    value="">
                                <button type="button"
                                    class="btn btn-outline-secondary"
                                    onclick="toggleField('apiKeyInput','apiKeyEye')">
                                    <i class="bi bi-eye" id="apiKeyEye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Sender ID</label>
                            <input type="text" name="unisms_sender_id" class="form-control"
                                maxlength="11"
                                placeholder="UnisoftSMS"
                                value="<?= htmlspecialchars($settings['unisms_sender_id'] ?? 'UnisoftSMS') ?>">
                            <small class="text-muted">Max 11 characters</small>
                        </div>
                    </div>

                    <!-- Test SMS -->
                    <div class="mb-4">
                        <p class="small text-muted">Tests use saved settings. Save any changes before testing.</p>
                        <button type="button" class="btn btn-sm btn-outline-success"
                            onclick="testSMS()">
                            <i class="bi bi-send me-1"></i>Send Test SMS
                        </button>
                        <input type="text" id="testNumber"
                            class="form-control d-inline-block ms-2"
                            placeholder="+639XXXXXXXXX"
                            style="width:200px;display:inline-block!important">
                        <span id="testSMSResult" class="ms-2 small"></span>
                    </div>

                    <hr>

                    <p class="fw-600 mb-1">SMS Templates</p>
                    <small class="text-muted d-block mb-3">
                        Variables: <code>{student_name}</code>
                        <code>{time}</code> <code>{date}</code>
                    </small>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">
                                <i class="bi bi-box-arrow-in-right text-success me-1"></i>
                                Arrival Message
                            </label>
                            <textarea name="sms_arrival_template"
                                class="form-control" rows="4"><?= htmlspecialchars($settings['sms_arrival_template'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                <i class="bi bi-box-arrow-right text-info me-1"></i>
                                Departure Message
                            </label>
                            <textarea name="sms_departure_template"
                                class="form-control" rows="4"><?= htmlspecialchars($settings['sms_departure_template'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                <i class="bi bi-exclamation-circle text-danger me-1"></i>
                                Absence Alert
                            </label>
                            <textarea name="sms_absence_template"
                                class="form-control" rows="4"><?= htmlspecialchars($settings['sms_absence_template'] ?? '') ?></textarea>
                        </div>
                    </div>

                </div>
            </div>
        </div>

        <!-- Email Settings -->
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex align-items-center">
                    <i class="bi bi-envelope-fill me-2 text-primary"></i>
                    Email Notifications (Gmail SMTP)
                </div>
                <div class="card-body">

                    <div class="alert alert-info py-2 mb-3" style="font-size:0.82rem">
                        <i class="bi bi-info-circle me-1"></i>
                        Use a Gmail account with an <strong>App Password</strong>
                        (not your regular Gmail password).
                        Enable 2FA on Gmail first, then go to
                        <strong>myaccount.google.com → Security → App Passwords</strong>
                        to generate one.
                    </div>

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Gmail Address</label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="bi bi-envelope text-muted"></i>
                                </span>
                                <input type="email" name="mail_username" class="form-control"
                                    placeholder="your_gmail@gmail.com"
                                    value="<?= htmlspecialchars($settings['mail_username'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label for="mailPassInput" class="form-label"><?= !empty($settings['mail_password']) ? 'Replace Gmail App Password' : 'Gmail App Password' ?></label>
                            <p class="small text-muted mb-2"><?= !empty($settings['mail_password']) ? 'Email password configured. Leave this field blank to keep the saved password.' : 'Paste your Gmail App Password, then click Save All Settings.' ?></p>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="bi bi-shield-lock text-muted"></i>
                                </span>
                                <input type="password"
                                    name="mail_password"
                                    id="mailPassInput"
                                    class="form-control font-monospace"
                                    placeholder="xxxx xxxx xxxx xxxx"
                                    autocomplete="new-password"
                                    value="">
                                <button type="button"
                                    class="btn btn-outline-secondary"
                                    onclick="toggleField('mailPassInput','mailPassEye')">
                                    <i class="bi bi-eye" id="mailPassEye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">From Name</label>
                            <input type="text" name="mail_from_name" class="form-control"
                                value="<?= htmlspecialchars($settings['mail_from_name'] ?? 'SPCCS Kinder Attendance') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">From Email</label>
                            <input type="email" name="mail_from_email" class="form-control"
                                placeholder="same as Gmail address"
                                value="<?= htmlspecialchars($settings['mail_from_email'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" name="mail_host" class="form-control"
                                value="<?= htmlspecialchars($settings['mail_host'] ?? 'smtp.gmail.com') ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">SMTP Port</label>
                            <input type="number" name="mail_port" class="form-control"
                                value="<?= htmlspecialchars($settings['mail_port'] ?? '587') ?>">
                        </div>
                        <div class="col-md-3 d-flex align-items-end">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                    name="email_notifications"
                                    id="emailNotifToggle" value="1"
                                    <?= ($settings['email_notifications'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <label class="form-check-label fw-600" for="emailNotifToggle">
                                    Enable Email Notifications
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Test Email -->
                    <div class="mt-3">
                        <p class="small text-muted">Tests use saved settings. Save any changes before testing.</p>
                        <button type="button" class="btn btn-sm btn-outline-success"
                            onclick="testEmail()">
                            <i class="bi bi-envelope me-1"></i>Send Test Email
                        </button>
                        <input type="email" id="testEmailAddr"
                            class="form-control d-inline-block ms-2"
                            placeholder="test@gmail.com"
                            style="width:220px;display:inline-block!important">
                        <span id="testEmailResult" class="ms-2 small"></span>
                    </div>

                </div>
            </div>
        </div>

        <!-- Save Button -->
        <div class="col-12">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="bi bi-save me-1"></i>Save All Settings
            </button>
        </div>

    </div>

</form>

<?php
$extraJS = <<<'JS'
<script>
// Toggle password/key visibility
function toggleField(inputId, eyeId) {
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

// Test SMS
async function testSMS() {
    const number = document.getElementById('testNumber').value.trim();
    const result = document.getElementById('testSMSResult');

    if (!number) {
        result.innerHTML = '<span class="text-danger">Enter a phone number first.</span>';
        return;
    }

    result.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Sending...</span>';

    try {
        const res  = await fetch('test_sms.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({number, csrf_token: document.getElementById('settingsCsrf').value})
        });
        const text = await res.text();
        try {
            const data = JSON.parse(text);
            result.className = 'ms-2 small ' + (data.success ? 'text-success' : 'text-danger');
            result.textContent = data.success ? 'SMS sent successfully!' : data.message;
        } catch (e) {
            result.textContent = 'Could not send the test. Reload Settings and try again.';
        }
    } catch (e) {
        result.textContent = 'Network error. Please try again.';
    }
}

// Test Email
async function testEmail() {
    const email  = document.getElementById('testEmailAddr').value.trim();
    const result = document.getElementById('testEmailResult');

    if (!email) {
        result.innerHTML = '<span class="text-danger">Enter an email address first.</span>';
        return;
    }

    result.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split me-1"></i>Sending...</span>';

    try {
        const res  = await fetch('test_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({email, csrf_token: document.getElementById('settingsCsrf').value})
        });
        const text = await res.text();
        try {
            const data = JSON.parse(text);
            result.className = 'ms-2 small ' + (data.success ? 'text-success' : 'text-danger');
            result.textContent = data.success ? 'Email sent! Check your inbox.' : data.message;
        } catch (e) {
            result.textContent = 'Could not send the test. Reload Settings and try again.';
        }
    } catch (e) {
        result.textContent = 'Network error. Please try again.';
    }
}

async function uploadLogo(input) {
    if (!input.files || !input.files[0]) return;

    const formData = new FormData();
    formData.append('logo', input.files[0]);
    formData.append('csrf_token', document.getElementById('settingsCsrf').value);

    try {
        const res  = await fetch('upload_logo.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.success) {
            document.getElementById('logoPreview').src =
                data.url + '?v=' + Date.now();
            document.getElementById('logoPreview').style.display = '';
            showMessage('Logo uploaded successfully!', { title: 'Logo uploaded', tone: 'success' });
        } else {
            showMessage('Upload failed: ' + data.message, { title: 'Upload failed' });
        }
    } catch (e) {
        showMessage('Network error uploading logo. Please try again.', { title: 'Upload failed' });
    }
}
</script>
JS;
include '../includes/footer.php';
?>
