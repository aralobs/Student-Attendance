<?php
/**
 * Edit Student — v2
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/student_access.php';
require_once '../includes/student_photos.php';
requireStudentManagement();
if (!isAdmin()) { http_response_code(403); exit('Profile changes require administrator review. Submit a learner change request.'); }
guardCsrfPage();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();
$managedEnrollment = enrollmentModelEnabled($db);
[$studentScope, $scopeParams] = studentRecordScope('s');

$stmt = $db->prepare("SELECT s.* FROM students s WHERE s.id = ? AND s.is_active = 1 AND ({$studentScope})");
$stmt->execute(array_merge([$id], $scopeParams));
$student = $stmt->fetch();

if (!$student) {
    $GLOBALS['security_audit_outcome'] = 'denied';
    setFlash('danger', 'Student not found.');
    header('Location: students.php');
    exit;
}

$sections = getAllowedSections();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lrn           = trim($_POST['lrn']            ?? '');
    $firstName     = trim($_POST['first_name']     ?? '');
    $middleName    = trim($_POST['middle_name']    ?? '');
    $lastName      = trim($_POST['last_name']      ?? '');
    $gender        = $_POST['gender']              ?? '';
    $birthDate     = $_POST['birth_date']          ?? '';
    $address       = trim($_POST['address']        ?? '');
    $sectionId     = (int)($_POST['section_id']    ?? $student['section_id']);
    $parentName    = trim($_POST['parent_name']    ?? '');
    $parentContact = trim($_POST['parent_contact'] ?? '');
    $parentEmail   = trim($_POST['parent_email']   ?? '');

    // A teacher cannot transfer even between sections they advise.
    if (($managedEnrollment || !isAdmin()) && $sectionId !== (int)$student['section_id']) {
        http_response_code(403);
        exit('Use the enrollment workflow to change student placement.');
    }

    if (empty($lrn))       $errors[] = 'LRN is required.';
    if (empty($firstName)) $errors[] = 'First name is required.';
    if (empty($lastName))  $errors[] = 'Last name is required.';
    if (empty($gender))    $errors[] = 'Gender is required.';

    $gradeLevel = $student['grade_level'];
    $schoolYear = $student['school_year'];
    if ($sectionId > 0) {
        $sectionStmt = $db->prepare("SELECT grade_level, school_year FROM sections WHERE id = ? AND is_active = 1");
        $sectionStmt->execute([$sectionId]);
        $section = $sectionStmt->fetch();
        if (!$section) {
            $errors[] = 'Please choose an active section.';
        } else {
            $gradeLevel = $section['grade_level'];
            $schoolYear = $section['school_year'];
        }
    }

    if (empty($errors)) {
        $check = $db->prepare("SELECT id FROM students WHERE lrn = ? AND id != ?");
        $check->execute([$lrn, $id]);
        if ($check->fetch()) $errors[] = "LRN {$lrn} already used by another student.";
    }

    // Photo upload
    $photo = $student['photo'];
    if (!empty($_FILES['photo']['name'])) {
        $ext     = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','gif','webp'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'Invalid image format.';
        } elseif ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
            $errors[] = 'Photo must be under 2MB.';
        } else {
            try {
                $photo = storeStudentPhotoUpload($_FILES['photo']);
            } catch (Throwable $error) {
                $errors[] = 'Failed to upload photo.';
            }
        }
    }

    if (empty($errors)) {
        $writeScope = $studentScope;
        $writeParams = $scopeParams;
        if (!isAdmin()) {
            $writeScope .= ' AND s.section_id = ?';
            $writeParams[] = (int)$student['section_id'];
        }
        $db->beginTransaction();
        try {
        if ($managedEnrollment) {
            requireEnrollmentWrite($db,currentUser(),$id,date('Y-m-d'));
        }
        $auditRead = $db->prepare("SELECT s.* FROM students s WHERE s.id = ? AND s.is_active = 1 AND ({$writeScope}) FOR UPDATE");
        $auditRead->execute(array_merge([$id], $writeParams));
        $beforeAudit = $auditRead->fetch();
        if (!$beforeAudit) { $db->rollBack(); http_response_code(403); exit('Student access has changed.'); }
        if ($managedEnrollment) {
            // Profile edits never overwrite placement, including a stale form's projection.
            $sectionId = (int)$beforeAudit['section_id'];
            $gradeLevel = $beforeAudit['grade_level'];
            $schoolYear = $beforeAudit['school_year'];
        }
        $save = $db->prepare("
            UPDATE students s SET
                lrn=?, first_name=?, middle_name=?, last_name=?,
                gender=?, birth_date=?, address=?, section_id=?,
                grade_level=?, school_year=?,
                photo=?, parent_name=?, parent_contact=?, parent_email=?
            WHERE s.id=? AND s.is_active = 1 AND ({$writeScope})
        ");
        $save->execute(array_merge([
            $lrn, $firstName, $middleName, $lastName,
            $gender, $birthDate ?: null, $address, $sectionId ?: null,
            $gradeLevel, $schoolYear,
            $photo, $parentName, $parentContact, $parentEmail,
            $id
        ], $writeParams));
        // Reassignment or archiving during editing must revoke write access.
        if ($save->rowCount() === 0) {
            $checkAccess = $db->prepare("SELECT s.id FROM students s WHERE s.id = ? AND s.is_active = 1 AND ({$writeScope})");
            $checkAccess->execute(array_merge([$id], $writeParams));
            if (!$checkAccess->fetch()) { http_response_code(403); exit('Student access has changed. Reload the student list.'); }
        }

        $auditRead->execute(array_merge([$id], $writeParams));
        $afterAudit = $auditRead->fetch();
        // A transfer by an administrator remains in the admin scope.
        if (!$afterAudit) throw new RuntimeException('Student history target is missing.');
        $auditFields = array_flip(['lrn','first_name','middle_name','last_name','gender','birth_date','section_id','grade_level','school_year','parent_name','parent_contact','parent_email','address','photo']);
        $beforeValues = array_intersect_key($beforeAudit, $auditFields);
        $afterValues = array_intersect_key($afterAudit, $auditFields);
        // Keep only changed fields; avoid duplicating unrelated PII in history.
        foreach ($beforeValues as $field => $value) if ($value === $afterValues[$field]) { unset($beforeValues[$field], $afterValues[$field]); }
        $contactChanged = false;
        foreach (['parent_name','parent_contact','parent_email'] as $field) if ($beforeAudit[$field] !== $afterAudit[$field]) $contactChanged = true;
        securityAuditChange($db, $contactChanged ? 'student.contact_change' : 'student.update', 'student', $id, $beforeValues, $afterValues, $contactChanged);
        $db->commit();
        } catch (DomainException $error) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(403); exit('Student access has changed. Reload the student list.');
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(503); exit('Unable to save the student.');
        }
        setFlash('success', "Student {$firstName} {$lastName} updated successfully.");
        header('Location: view.php?id=' . $id);
        exit;
    }
}

$pageTitle = 'Edit Student — ' . $student['first_name'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/student_form.css">
<div class="students-page workflow-page student-form-page">
<?php showFlash(); ?>
<nav aria-label="Breadcrumb" class="student-breadcrumb"><a href="students.php">Students</a><span aria-hidden="true">/</span><span>Edit Student</span></nav>
<header class="student-form-heading">
    <div><h1>Edit Student</h1><p>Update the student profile and parent or guardian details.</p></div>
    <a href="students.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Students</a>
</header>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger" role="alert" tabindex="-1" id="student-form-errors">
    <strong>Please check the following details:</strong>
    <ul class="mb-0 mt-2"><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<form method="POST" enctype="multipart/form-data" novalidate class="student-profile-form">
<?= csrfField() ?>
    <div class="student-form-layout">
        <div class="student-form-main">
            <section class="card students-card student-form-card mb-4" aria-labelledby="enrollment-heading">
                <div class="card-header"><h2 id="enrollment-heading">Student Placement</h2></div>
                <div class="card-body"><div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="section_id">Section</label>
                            <?php if ($managedEnrollment || !isAdmin()): ?>
                            <input type="hidden" name="section_id" value="<?= (int)$student['section_id'] ?>">
                            <?php endif; ?>
                            <select id="section_id" name="section_id" class="form-select" <?= $managedEnrollment || !isAdmin() ? 'disabled' : '' ?>>
                                <option value="">Select Section</option>
                                <?php
                                $currentGrade = '';
                                foreach ($sections as $sec):
                                    if ($sec['grade_level'] !== $currentGrade):
                                        if ($currentGrade !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . sanitize($sec['grade_level']) . '">';
                                        $currentGrade = $sec['grade_level'];
                                    endif;
                                ?>
                                <option value="<?= $sec['id'] ?>"
                                    <?= ($_POST['section_id'] ?? $student['section_id']) == $sec['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($sec['section_name']) ?>
                                    (<?= ucfirst(str_replace('_',' ',$sec['schedule_type'])) ?>)
                                </option>
                                <?php endforeach; ?>
                                <?php if ($currentGrade !== '') echo '</optgroup>'; ?>
                            </select>
                            <?php if ($managedEnrollment): ?><small class="text-muted">Placement changes require an administrator. <?php if (isAdmin()): ?><a href="enrollment_corrections.php?student_id=<?= (int)$student['id'] ?>">Review enrollment correction</a><?php endif; ?></small><?php elseif (!isAdmin()): ?><small class="text-muted">Contact an administrator to transfer this student.</small><?php endif; ?>
                        </div>
                    <div class="col-md-6">
                        <label class="form-label" for="placementYear">School Year</label>
                        <input id="placementYear" class="form-control" value="<?= sanitize($student['school_year'] ?? '') ?>" readonly>
                        <small class="student-field-help">Current student placement. Profile changes keep enrollment history intact.</small>
                    </div>
                </div></div>
            </section>

            <section class="card students-card student-form-card mb-4" aria-labelledby="student-info-heading">
                <div class="card-header student-card-title"><h2 id="student-info-heading">Student Information</h2><small><span class="text-danger">*</span> Required fields</small></div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="lrn">LRN <span class="text-danger">*</span></label>
                            <input type="text" id="lrn" name="lrn" class="form-control"
                                   value="<?= htmlspecialchars($_POST['lrn'] ?? $student['lrn']) ?>"
                                   inputmode="numeric" maxlength="20" required>
                        </div>

                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
                            <input type="text" id="last_name" name="last_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['last_name'] ?? $student['last_name']) ?>"
                                   required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
                            <input type="text" id="first_name" name="first_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['first_name'] ?? $student['first_name']) ?>"
                                   required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="middle_name">Middle Name <span class="student-optional">(optional)</span></label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['middle_name'] ?? $student['middle_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="birth_date">Birth Date</label>
                            <input type="date" id="birth_date" name="birth_date" class="form-control"
                                   value="<?= htmlspecialchars($_POST['birth_date'] ?? $student['birth_date']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="gender">Gender <span class="text-danger">*</span></label>
                            <select id="gender" name="gender" class="form-select" required>
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
                        <div class="col-12">
                            <label class="form-label" for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" rows="2"><?= htmlspecialchars($_POST['address'] ?? $student['address']) ?></textarea>
                        </div>

                    </div>
                </div>
            </section>
            <section class="card students-card student-form-card" aria-labelledby="guardian-heading">
                <div class="card-header"><h2 id="guardian-heading">Parent / Guardian</h2></div>
                <div class="card-body"><div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="parent_name">Name</label>
                            <input type="text" id="parent_name" name="parent_name" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parent_name'] ?? $student['parent_name']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="parent_contact">
                                Contact
                                <i class="bi bi-chat-dots-fill text-info ms-1"
                                   title="Used for SMS notifications"></i>
                            </label>
                            <input type="text" id="parent_contact" name="parent_contact" class="form-control"
                                   placeholder="09XXXXXXXXX"
                                   value="<?= htmlspecialchars($_POST['parent_contact'] ?? $student['parent_contact']) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="parent_email">Email</label>
                            <input type="email" id="parent_email" name="parent_email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parent_email'] ?? $student['parent_email']) ?>">
                        </div>

                </div></div>
            </section>
        </div>
        <aside class="student-form-sidebar" aria-label="Student photo and QR identity">
            <section class="card students-card student-form-card">
                <div class="card-header student-card-title"><h2>Student Photo</h2><small>Optional</small></div>
                <div class="card-body text-center">
                    <img id="photoPreview" src="<?= BASE_URL ?>student_photo.php?id=<?= (int)$student['id'] ?>" class="student-photo-preview" alt="Student photo preview" width="120" height="120">
                    <div class="student-photo-upload">
                        <input type="file" id="photo" name="photo" accept="image/*" onchange="previewPhoto(this)" aria-label="Change Photo" aria-describedby="photo-help">
                        <span class="btn btn-outline-primary" aria-hidden="true"><i class="bi bi-upload me-1"></i>Change Photo</span>
                    </div>
                    <small id="photo-help" class="student-field-help">Max 2 MB · JPG, PNG, GIF or WebP</small>
                    <div class="student-qr-identity">
                        <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
                        <h3>QR Identity</h3>
                        <a href="generate_qr.php?id=<?= $id ?>" class="btn btn-outline-primary"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>View QR Code</a>
                        <p class="student-field-help mt-2 mb-0">The student's existing QR identity is retained.</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
    <div class="student-form-actions">
        <p>Review the changes before saving.</p>
        <div class="student-action-buttons">
            <a href="students.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2" aria-hidden="true"></i>Save Changes</button>
        </div>
    </div>
</form>
</div>
<?php
$extraJS = <<<'JS'
<script>
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { document.getElementById('photoPreview').src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    }
}
const studentErrors = document.getElementById('student-form-errors');
if (studentErrors) studentErrors.focus();
</script>
JS;

include '../includes/footer.php';
?>
