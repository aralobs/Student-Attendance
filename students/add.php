<?php
/**
 * Add Student — v2
 */
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/student_access.php';
require_once '../includes/student_photos.php';
requireStudentManagement();
if (!isAdmin()) { http_response_code(403); exit('Enrollment is restricted to administrators. Submit a learner change request.'); }
guardCsrfPage();

$pageTitle = 'Enroll Student';
$db        = getDB();
$errors    = [];
$managedEnrollment = enrollmentModelEnabled($db);
$enrollmentYear = null;
if ($managedEnrollment) {
    require_once '../includes/enrollment_commands.php';
    $enrollmentYears = $db->query("SELECT * FROM school_years WHERE state IN ('active','planned') ORDER BY starts_on")->fetchAll();
    $activeYear = getActiveSchoolYear($db);
    $selectedYearId = (int)($_POST['school_year_id'] ?? $_GET['school_year_id'] ?? $activeYear['id']);
    foreach ($enrollmentYears as $year) if ((int)$year['id'] === $selectedYearId) $enrollmentYear = $year;
}

// Get sections with grade level for dropdown
$sections = getAllowedSections();
if ($managedEnrollment) {
    [$scope,$params] = enrollmentSectionScope('sec',currentUser());
    $q = $db->prepare("SELECT sec.* FROM sections sec WHERE sec.is_active=1 AND sec.school_year_id=? AND ($scope) ORDER BY sec.grade_level,sec.section_name");
    $q->execute(array_merge([$selectedYearId],$params)); $sections = $q->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $lrn           = trim($_POST['lrn']            ?? '');
    $firstName     = mb_convert_case(trim($_POST['first_name'] ?? ''), MB_CASE_TITLE, 'UTF-8');
    $middleName    = mb_convert_case(trim($_POST['middle_name'] ?? ''), MB_CASE_TITLE, 'UTF-8');
    $lastName      = mb_convert_case(trim($_POST['last_name'] ?? ''), MB_CASE_TITLE, 'UTF-8');
    $gender        = $_POST['gender']              ?? '';
    $birthDate     = $_POST['birth_date']          ?? '';
    $address       = trim($_POST['address']        ?? '');
    $sectionId     = (int)($_POST['section_id']    ?? 0);
    $parentName    = trim($_POST['parent_name']    ?? '');
    $parentContact = trim($_POST['parent_contact'] ?? '');
    $parentEmail   = trim($_POST['parent_email']   ?? '');
    if (!$managedEnrollment) requireStudentCreationSection($sectionId);

    if (empty($lrn))       $errors[] = 'LRN is required.';
    if (empty($firstName)) $errors[] = 'First name is required.';
    if (empty($lastName))  $errors[] = 'Last name is required.';
    if (empty($gender))    $errors[] = 'Gender is required.';

    $gradeLevel = null;
    $schoolYear = null;
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
        $check = $db->prepare("SELECT id FROM students WHERE lrn = ?");
        $check->execute([$lrn]);
        if ($check->fetch()) $errors[] = $managedEnrollment ? 'This LRN already exists. Use Returning Student Enrollment; contact an administrator if the profile is unavailable.' : "LRN {$lrn} already exists.";
    }

    // Photo upload
    $photo = 'default.png';
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
        $qrToken = generateQRToken($lrn);

        if ($managedEnrollment) {
            try {
                $newId = createStudentWithEnrollment($db,currentUser(),[
                    'lrn'=>$lrn,'first_name'=>$firstName,'middle_name'=>$middleName,'last_name'=>$lastName,
                    'gender'=>$gender,'birth_date'=>$birthDate ?: null,'address'=>$address,'photo'=>$photo,'qr_token'=>$qrToken,
                    'parent_name'=>$parentName,'parent_contact'=>$parentContact,'parent_email'=>$parentEmail,
                ],$selectedYearId,$sectionId,(string)($_POST['starts_on'] ?? ''),(string)($_POST['ends_on'] ?? ''));
            } catch (DomainException|InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
            catch (Throwable $error) { $errors[] = 'Unable to save the student and enrollment. Please try again.'; }
        } else {

        $insertSql = "
            INSERT INTO students
                (lrn, first_name, middle_name, last_name, gender, birth_date,
                 address, section_id, grade_level, school_year, photo, qr_token,
                 parent_name, parent_contact, parent_email)
        ";
        $insertParams = [
            $lrn, $firstName, $middleName, $lastName,
            $gender, $birthDate ?: null, $address,
            $sectionId ?: null, $gradeLevel, $schoolYear, $photo, $qrToken,
            $parentName, $parentContact, $parentEmail
        ];
        if (isAdmin()) {
            $insertSql .= ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
        } else {
            [$sectionScope, $sectionParams] = studentSectionScope('sec');
            $insertSql .= " SELECT ?,?,?,?,?,?,?,?,?,?,?,?,?,?,? FROM sections sec WHERE sec.id = ? AND ({$sectionScope})";
            $insertParams = array_merge($insertParams, [$sectionId], $sectionParams);
        }
        $stmt = $db->prepare($insertSql);
        $stmt->execute($insertParams);
        if ($stmt->rowCount() !== 1) { http_response_code(403); exit('Section access has changed. Reload the student form.'); }

        $newId  = $db->lastInsertId();
        }

        if (empty($errors)) {
        // Auto-generate QR code using BaconQrCode
        if (file_exists(BASE_PATH . 'includes/qr_helper.php')) {
            require_once BASE_PATH . 'includes/qr_helper.php';
            $qrFile = 'qrcodes/qr_' . $newId . '.png';
            if (generateQRCode($qrToken, BASE_PATH . $qrFile, 300)) {
                $db->prepare("UPDATE students SET qr_code = ? WHERE id = ?")
                   ->execute([$qrFile, $newId]);
            }
        }

        setFlash('success', "Student {$firstName} {$lastName} added successfully!");
        header('Location: students.php');
        exit;
        }
    }
}

include '../includes/header.php';
$pageEnrollmentNoticeInline = true;
include '../includes/sidebar.php';
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/student_form.css">
<div class="students-page workflow-page student-form-page">
<?php showFlash(); ?>
<nav aria-label="Breadcrumb" class="student-breadcrumb"><a href="students.php">Students</a><span aria-hidden="true">/</span><span>Enroll Student</span></nav>
<header class="student-form-heading">
    <div><h1>Enroll Student</h1><p>Create a student record and enroll them in a school year.</p></div>
    <a href="students.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Students</a>
</header>
<?php if ($managedEnrollment): ?>
<nav class="student-mode" aria-label="Enrollment type">
    <a class="active" href="add.php?school_year_id=<?= $selectedYearId ?>" aria-current="page"><i class="bi bi-person-plus" aria-hidden="true"></i>New Student</a>
    <a href="enroll.php?school_year_id=<?= $selectedYearId ?>"><i class="bi bi-people" aria-hidden="true"></i>Returning Student</a>
</nav>
<form method="get" id="student-year-picker"></form>
<?php endif; ?>

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
                <div class="card-header"><h2 id="enrollment-heading">Enrollment Details</h2></div>
                <div class="card-body"><div class="row g-3">
                    <?php if ($managedEnrollment): ?>
                    <div class="col-md-6">
                        <label class="form-label" for="enrollmentYear">School Year</label>
                        <select class="form-select" id="enrollmentYear" name="school_year_id" form="student-year-picker" onchange="this.form.submit()" aria-describedby="year-help">
                        <?php foreach ($enrollmentYears as $year): ?>
                            <option value="<?= (int)$year['id'] ?>" <?= (int)$year['id'] === $selectedYearId ? 'selected' : '' ?>><?= sanitize($year['label']) ?></option>
                        <?php endforeach; ?>
                        </select>
                        <small id="year-help" class="student-field-help">This student will be enrolled in the selected school year. Choose the year before entering student details.</small>
                        <noscript><button form="student-year-picker" class="btn btn-outline-secondary mt-2">Choose Year</button></noscript>
                    </div>
                    <?php endif; ?>
                        <div class="col-md-6">
                            <label class="form-label" for="section_id">Section</label>
                            <select id="section_id" name="section_id" class="form-select">
                                <option value="">Select Section</option>
                                <?php
                                $currentGrade = '';
                                foreach ($sections as $sec):
                                    // Group by grade level
                                    if ($sec['grade_level'] !== $currentGrade):
                                        if ($currentGrade !== '') echo '</optgroup>';
                                        echo '<optgroup label="' . sanitize($sec['grade_level']) . '">';
                                        $currentGrade = $sec['grade_level'];
                                    endif;
                                ?>
                                <option value="<?= $sec['id'] ?>"
                                        <?= ($_POST['section_id'] ?? 0) == $sec['id'] ? 'selected' : '' ?>>
                                    <?= sanitize($sec['section_name']) ?>
                                    (<?= ucfirst(str_replace('_',' ',$sec['schedule_type'])) ?>)
                                </option>
                                <?php endforeach; ?>
                                <?php if ($currentGrade !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                    <?php if ($managedEnrollment && $enrollmentYear): ?>
                    <input type="hidden" name="school_year_id" value="<?= $selectedYearId ?>">
                    <div class="col-md-6">
                        <label class="form-label" for="starts_on">Start Date <span class="text-danger">*</span></label>
                        <input class="form-control" id="starts_on" type="date" name="starts_on" required min="<?= sanitize($enrollmentYear['starts_on']) ?>" max="<?= sanitize($enrollmentYear['ends_on']) ?>" value="<?= sanitize($_POST['starts_on'] ?? ($enrollmentYear['state'] === 'active' ? min($enrollmentYear['ends_on'],max(date('Y-m-d'),$enrollmentYear['starts_on'])) : $enrollmentYear['starts_on'])) ?>" aria-describedby="start-help">
                        <small id="start-help" class="student-field-help">For late enrollment, use the student's actual first day.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="ends_on">End Date <span class="text-danger">*</span></label>
                        <input class="form-control" id="ends_on" type="date" name="ends_on" required min="<?= sanitize($enrollmentYear['starts_on']) ?>" max="<?= sanitize($enrollmentYear['ends_on']) ?>" value="<?= sanitize($_POST['ends_on'] ?? $enrollmentYear['ends_on']) ?>">
                    </div>
                    <?php endif; ?>
                </div></div>
            </section>

            <section class="card students-card student-form-card mb-4" aria-labelledby="student-info-heading">
                <div class="card-header student-card-title"><h2 id="student-info-heading">Student Information</h2><small><span class="text-danger">*</span> Required fields</small></div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label" for="lrn">LRN <span class="text-danger">*</span></label>
                            <input type="text" id="lrn" name="lrn" class="form-control"
                                   placeholder="12-digit LRN"
                                   value="<?= htmlspecialchars($_POST['lrn'] ?? '') ?>"
                                   inputmode="numeric" maxlength="20" required>
                        </div>

                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="last_name">Last Name <span class="text-danger">*</span></label>
                            <input type="text" id="last_name" name="last_name" class="form-control" data-title-case
                                   value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="first_name">First Name <span class="text-danger">*</span></label>
                            <input type="text" id="first_name" name="first_name" class="form-control" data-title-case
                                   value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="middle_name">Middle Name <span class="student-optional">(optional)</span></label>
                            <input type="text" id="middle_name" name="middle_name" class="form-control" data-title-case
                                   value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="birth_date">Birth Date</label>
                            <input type="date" id="birth_date" name="birth_date" class="form-control"
                                   value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="gender">Gender <span class="text-danger">*</span></label>
                            <select id="gender" name="gender" class="form-select" required>
                                <option value="">Select...</option>
                                <option value="Male"
                                    <?= ($_POST['gender'] ?? '') === 'Male' ? 'selected' : '' ?>>Male</option>
                                <option value="Female"
                                    <?= ($_POST['gender'] ?? '') === 'Female' ? 'selected' : '' ?>>Female</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address">Address</label>
                            <textarea id="address" name="address" class="form-control" rows="2"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
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
                                   value="<?= htmlspecialchars($_POST['parent_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="parent_contact">
                                Contact
                                <i class="bi bi-chat-dots-fill text-info ms-1"
                                   title="Used for SMS"></i>
                            </label>
                            <input type="text" id="parent_contact" name="parent_contact" class="form-control"
                                   placeholder="09XXXXXXXXX"
                                   value="<?= htmlspecialchars($_POST['parent_contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="parent_email">Email</label>
                            <input type="email" id="parent_email" name="parent_email" class="form-control"
                                   value="<?= htmlspecialchars($_POST['parent_email'] ?? '') ?>">
                        </div>

                </div></div>
            </section>
        </div>
        <aside class="student-form-sidebar" aria-label="Student photo and QR identity">
            <section class="card students-card student-form-card">
                <div class="card-header student-card-title"><h2>Student Photo</h2><small>Optional</small></div>
                <div class="card-body text-center">
                    <img id="photoPreview" src="<?= BASE_URL ?>assets/img/student-placeholder.svg" class="student-photo-preview" alt="Student photo preview" width="120" height="120">
                    <div class="student-photo-upload">
                        <input type="file" id="photo" name="photo" accept="image/*" onchange="previewPhoto(this)" aria-label="Upload Photo" aria-describedby="photo-help">
                        <span class="btn btn-outline-primary" aria-hidden="true"><i class="bi bi-upload me-1"></i>Upload Photo</span>
                    </div>
                    <small id="photo-help" class="student-field-help">Max 2 MB · JPG, PNG, GIF or WebP</small>
                    <div class="student-qr-identity">
                        <i class="bi bi-qr-code-scan" aria-hidden="true"></i>
                        <h3>QR Identity</h3>
                        <p class="student-field-help mb-0">A QR code will be generated after saving.</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
    <div class="student-form-actions">
        <p>Review the details before enrolling.</p>
        <div class="student-action-buttons">
            <a href="students.php" class="btn btn-outline-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2" aria-hidden="true"></i>Enroll Student</button>
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
$extraJS .= '<script src="' . BASE_URL . 'assets/js/new_entry_names.js"></script>';
include '../includes/footer.php';
?>
