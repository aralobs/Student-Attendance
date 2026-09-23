<?php
/**
 * Add Student
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$pageTitle = 'Add Student';
$db        = getDB();
$errors    = [];
$sections  = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate
    $lrn          = trim($_POST['lrn'] ?? '');
    $firstName    = trim($_POST['first_name'] ?? '');
    $middleName   = trim($_POST['middle_name'] ?? '');
    $lastName     = trim($_POST['last_name'] ?? '');
    $gender       = $_POST['gender'] ?? '';
    $birthDate    = $_POST['birth_date'] ?? '';
    $address      = trim($_POST['address'] ?? '');
    $sectionId    = (int)($_POST['section_id'] ?? 0);
    $parentName   = trim($_POST['parent_name'] ?? '');
    $parentContact= trim($_POST['parent_contact'] ?? '');
    $parentEmail  = trim($_POST['parent_email'] ?? '');

    if (empty($lrn))       $errors[] = 'LRN is required.';
    if (empty($firstName)) $errors[] = 'First name is required.';
    if (empty($lastName))  $errors[] = 'Last name is required.';
    if (empty($gender))    $errors[] = 'Gender is required.';

    // Check duplicate LRN
    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM students WHERE lrn = ?");
        $check->execute([$lrn]);
        if ($check->fetch()) $errors[] = "LRN {$lrn} already exists.";
    }

    // Handle photo upload
    $photo = 'default.png';
    if (!empty($_FILES['photo']['name'])) {
        $ext      = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed  = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Invalid image format.';
        } elseif ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo must be under 2MB.';
        } else {
            $photo    = 'student_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            $dest     = BASE_PATH . 'uploads/students/' . $photo;
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                $errors[] = 'Failed to upload photo.';
            }
        }
    }

    if (empty($errors)) {
        $qrToken = generateQRToken($lrn);

        $stmt = $db->prepare("
            INSERT INTO students
            (lrn, first_name, middle_name, last_name, gender, birth_date,
             address, section_id, photo, qr_token, parent_name, parent_contact, parent_email)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $lrn, $firstName, $middleName, $lastName, $gender, $birthDate,
            $address, $sectionId ?: null, $photo, $qrToken,
            $parentName, $parentContact, $parentEmail
        ]);

        $newId = $db->lastInsertId();

        // Auto-generate QR code
        $qrFile = 'qrcodes/qr_' . $newId . '.png';
        if (file_exists(BASE_PATH . 'vendor/phpqrcode/qrlib.php')) {
            require_once BASE_PATH . 'vendor/phpqrcode/qrlib.php';
            QRcode::png($qrToken, BASE_PATH . $qrFile, QR_ECLEVEL_M, 8, 2);
            $db->prepare("UPDATE students SET qr_code = ? WHERE id = ?")->execute([$qrFile, $newId]);
        }

        setFlash('success', "Student {$firstName} {$lastName} added successfully!");
        header('Location: index.php');
        exit;
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title"><i class="bi bi-person-plus-fill me-2 text-primary"></i>Add Student</h1>
        <p class="page-subtitle">Enroll a new kindergarten student</p>
    </div>
    <a href="index.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back to List
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="addStudentForm" novalidate>
    <div class="row g-4">
        <!-- Left column -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Photo & QR</div>
                <div class="card-body text-center">
                    <div class="mb-3">
                        <img id="photoPreview"
                             src="https://ui-avatars.com/api/?name=New+Student&size=150&background=ebf0ff&color=1a56db"
                             class="student-photo mb-2"
                             style="width:120px;height:120px">
                        <div>
                            <label for="photo" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-camera me-1"></i>Upload Photo
                            </label>
                            <input type="file" id="photo" name="photo"
                                   accept="image/*" class="d-none"
                                   onchange="previewPhoto(this)">
                        </div>
                        <small class="text-muted d-block mt-1">Max 2MB (JPG, PNG)</small>
                    </div>
                    <div class="alert alert-info py-2 text-start" style="font-size:0.8rem">
                        <i class="bi bi-info-circle me-1"></i>
                        A unique QR code will be automatically generated after saving.
                    </div>
                </div>
            </div>
        </div>

        <!-- Right column -->
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-person me-2"></i>Student Information</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">LRN <span class="text-danger">*</span></label>
                            <input type="text" name="lrn" class="form-control"
                                   placeholder="12-digit LRN"
                                   value="<?= htmlspecialchars($_POST['lrn'] ?? '') ?>"
                                   maxlength="20" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select" required>
                                <option value="">Select...</option>
                                <option value="Male"   <?= ($_POST['gender'] ?? '') === 'Male'   ? 'selected' : '' ?>>Male</option>
                                <option value="Female" <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Birth Date</label>
                            <input type="date" name="birth_date" class="form-control"
                                   value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Section</label>
                            <select name="section_id" class="form-select">
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $sec): ?>
                                <option value="<?= $sec['id'] ?>"
                                        <?= ($_POST['section_id'] ?? 0) == $sec['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($sec['section_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-person-lines-fill me-2"></i>Parent/Guardian</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Parent/Guardian Name</label>
                            <input type="text" name="parent_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parent_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Contact Number
                                <span class="text-info" title="Used for SMS notifications">
                                    <i class="bi bi-chat-dots-fill"></i>
                                </span>
                            </label>
                            <input type="text" name="parent_contact" class="form-control"
                                   placeholder="09XXXXXXXXX"
                                   value="<?= htmlspecialchars($_POST['parent_contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="parent_email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parent_email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>Save Student
                </button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php
$extraJS = <<<JS
<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('photoPreview').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>
JS;
include '../includes/footer.php';
?>