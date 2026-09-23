<?php
/**
 * Edit Student
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
requireLogin();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("SELECT * FROM students WHERE id = ? AND is_active = 1");
$stmt->execute([$id]);
$student = $stmt->fetch();

if (!$student) {
    setFlash('danger', 'Student not found.');
    header('Location: index.php');
    exit;
}

$sections = $db->query("SELECT * FROM sections WHERE is_active = 1 ORDER BY section_name")->fetchAll();
$errors   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lrn           = trim($_POST['lrn'] ?? '');
    $firstName     = trim($_POST['first_name'] ?? '');
    $middleName    = trim($_POST['middle_name'] ?? '');
    $lastName      = trim($_POST['last_name'] ?? '');
    $gender        = $_POST['gender'] ?? '';
    $birthDate     = $_POST['birth_date'] ?? '';
    $address       = trim($_POST['address'] ?? '');
    $sectionId     = (int)($_POST['section_id'] ?? 0);
    $parentName    = trim($_POST['parent_name'] ?? '');
    $parentContact = trim($_POST['parent_contact'] ?? '');
    $parentEmail   = trim($_POST['parent_email'] ?? '');

    if (empty($lrn))       $errors[] = 'LRN is required.';
    if (empty($firstName)) $errors[] = 'First name is required.';
    if (empty($lastName))  $errors[] = 'Last name is required.';
    if (empty($gender))    $errors[] = 'Gender is required.';

    // Check duplicate LRN — exclude current student
    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM students WHERE lrn = ? AND id != ?");
        $check->execute([$lrn, $id]);
        if ($check->fetch()) $errors[] = "LRN {$lrn} already used by another student.";
    }

    // Handle photo upload
    $photo = $student['photo']; // keep existing by default
    if (!empty($_FILES['photo']['name'])) {
        $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Invalid image format.';
        } elseif ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo must be under 2MB.';
        } else {
            $photo = 'student_' . time() . '_' . rand(1000,9999) . '.' . $ext;
            $dest  = BASE_PATH . 'uploads/students/' . $photo;
            if (!move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
                $errors[] = 'Failed to upload photo.';
            }
        }
    }

    if (empty($errors)) {
        $db->prepare("
            UPDATE students SET
                lrn = ?, first_name = ?, middle_name = ?, last_name = ?,
                gender = ?, birth_date = ?, address = ?, section_id = ?,
                photo = ?, parent_name = ?, parent_contact = ?, parent_email = ?
            WHERE id = ?
        ")->execute([
            $lrn, $firstName, $middleName, $lastName,
            $gender, $birthDate ?: null, $address, $sectionId ?: null,
            $photo, $parentName, $parentContact, $parentEmail,
            $id
        ]);

        setFlash('success', "Student {$firstName} {$lastName} updated successfully.");
        header('Location: view.php?id=' . $id);
        exit;
    }
}

$pageTitle = 'Edit Student — ' . $student['first_name'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<?php showFlash(); ?>

<div class="page-header">
    <div>
        <h1 class="page-title">
            <i class="bi bi-pencil-square me-2 text-primary"></i>Edit Student
        </h1>
        <p class="page-subtitle">Update student information</p>
    </div>
    <div class="d-flex gap-2">
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e) echo "<li>{$e}</li>"; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <div class="row g-4">

        <!-- Photo -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">Photo</div>
                <div class="card-body text-center">
                    <img id="photoPreview"
                         src="<?= BASE_URL ?>uploads/students/<?= htmlspecialchars($student['photo']) ?>"
                         class="rounded-circle mb-3"
                         style="width:120px;height:120px;object-fit:cover;border:3px solid #1a56db"
                         onerror="this.src='https://ui-avatars.com/api/?name=<?= urlencode($student['first_name'].' '.$student['last_name']) ?>&size=120&background=1a56db&color=fff'">
                    <div>
                        <label for="photo" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-camera me-1"></i>Change Photo
                        </label>
                        <input type="file" id="photo" name="photo"
                               accept="image/*" class="d-none"
                               onchange="previewPhoto(this)">
                    </div>
                    <small class="text-muted d-block mt-1">Max 2MB (JPG, PNG)</small>

                    <hr>

                    <div class="text-start small">
                        <div class="text-muted mb-1">QR Token</div>
                        <code style="font-size:0.7rem;word-break:break-all">
                            <?= sanitize($student['qr_token']) ?>
                        </code>
                        <div class="mt-2">
                            <a href="generate_qr.php?id=<?= $id ?>"
                               class="btn btn-sm btn-outline-success w-100">
                                <i class="bi bi-qr-code me-1"></i>View QR Code
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Student Info -->
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header">
                    <i class="bi bi-person me-2"></i>Student Information
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">LRN <span class="text-danger">*</span></label>
                            <input type="text" name="lrn" class="form-control"
                                   value="<?= htmlspecialchars($_POST['lrn'] ?? $student['lrn']) ?>"
                                   maxlength="20" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">First Name <span class="text-danger">*</span></label>
                            <input type="text" name="first_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['first_name'] ?? $student['first_name']) ?>"
                                   required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['middle_name'] ?? $student['middle_name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name <span class="text-danger">*</span></label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['last_name'] ?? $student['last_name']) ?>"
                                   required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gender <span class="text-danger">*</span></label>
                            <select name="gender" class="form-select" required>
                                <option value="">Select...</option>
                                <option value="Male"
                                    <?= ($_POST['gender'] ?? $student['gender']) === 'Male' ? 'selected' : '' ?>>
                                    Male
                                </option>
                                <option value="Female"
                                    <?= ($_POST['gender'] ?? $student['gender']) === 'Female' ? 'selected' : '' ?>>
                                    Female
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Birth Date</label>
                            <input type="date" name="birth_date" class="form-control"
                                   value="<?= htmlspecialchars($_POST['birth_date'] ?? $student['birth_date']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Section</label>
                            <select name="section_id" class="form-select">
                                <option value="">Select Section</option>
                                <?php foreach ($sections as $sec): ?>
                                <option value="<?= $sec['id'] ?>"
                                    <?= ($_POST['section_id'] ?? $student['section_id']) == $sec['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($sec['section_name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($_POST['address'] ?? $student['address']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Parent Info -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-person-lines-fill me-2"></i>Parent/Guardian
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Parent/Guardian Name</label>
                            <input type="text" name="parent_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parent_name'] ?? $student['parent_name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">
                                Contact Number
                                <i class="bi bi-chat-dots-fill text-info ms-1"
                                   title="Used for SMS notifications"></i>
                            </label>
                            <input type="text" name="parent_contact" class="form-control"
                                   placeholder="09XXXXXXXXX"
                                   value="<?= htmlspecialchars($_POST['parent_contact'] ?? $student['parent_contact']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="parent_email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parent_email'] ?? $student['parent_email']) ?>">
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-circle me-1"></i>Save Changes
                </button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </div>

    </div>
</form>

<?php
$extraJS = <<<'JS'
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