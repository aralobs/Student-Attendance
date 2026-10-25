<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/student_access.php';
requireStudentManagement();
$db = getDB();
if (!enrollmentModelEnabled($db)) { http_response_code(409); exit('Annual enrollment is not enabled.'); }
$pageTitle = 'Returning Student Enrollment';
$years = $db->query("SELECT * FROM school_years WHERE state IN ('active','planned') ORDER BY starts_on")->fetchAll();
$activeYear = getActiveSchoolYear($db);
$yearId = (int)($_GET['school_year_id'] ?? $activeYear['id']);
$year = null;
foreach ($years as $row) if ((int)$row['id'] === $yearId) $year = $row;
if (!$year) { http_response_code(400); exit('Choose an open school year.'); }
[$scope,$params] = enrollmentSectionScope('sec',currentUser());
$q = $db->prepare("SELECT sec.id,sec.section_name,sec.grade_level FROM sections sec WHERE sec.school_year_id=? AND sec.is_active=1 AND ($scope) ORDER BY sec.grade_level,sec.section_name");
$q->execute(array_merge([$yearId],$params)); $sections = $q->fetchAll();
include '../includes/header.php'; include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="page-header"><div><h1 class="page-title">Returning Student Enrollment</h1><p class="page-subtitle">Reuse the student's LRN and existing QR identity.</p></div><a href="students.php" class="btn btn-outline-secondary">Back to students</a></div>
<form method="get" class="mb-3"><label for="year" class="form-label">School year</label><select id="year" name="school_year_id" class="form-select" onchange="this.form.submit()">
<?php foreach ($years as $row): ?><option value="<?= (int)$row['id'] ?>" <?= (int)$row['id'] === $yearId ? 'selected' : '' ?>><?= sanitize($row['label']) ?> (<?= sanitize($row['state']) ?>)</option><?php endforeach; ?></select><noscript><button class="btn btn-secondary">Choose year</button></noscript></form>
<form method="post" action="save_enrollment.php" class="card"><div class="card-body">
<?= csrfField() ?><input type="hidden" name="school_year_id" value="<?= $yearId ?>">
<div class="mb-3"><label for="lrn" class="form-label">Existing LRN</label><input id="lrn" name="lrn" maxlength="20" class="form-control" required autocomplete="off"><small>Contact an administrator if the existing profile is unavailable. Create a new profile only for a new LRN.</small></div>
<div class="mb-3"><label for="section" class="form-label">Section</label><select id="section" name="section_id" class="form-select" required><option value="">Choose section</option><?php foreach ($sections as $section): ?><option value="<?= (int)$section['id'] ?>"><?= sanitize($section['grade_level'].' — '.$section['section_name']) ?></option><?php endforeach; ?></select></div>
<div class="row g-3 mb-3"><div class="col-md-6"><label class="form-label" for="start">Enrollment starts on</label><input type="date" id="start" name="starts_on" class="form-control" required min="<?= sanitize($year['starts_on']) ?>" max="<?= sanitize($year['ends_on']) ?>" value="<?= sanitize($year['state'] === 'active' ? min($year['ends_on'],max(date('Y-m-d'),$year['starts_on'])) : $year['starts_on']) ?>"><small>For late entry, enter the student's actual first day.</small></div>
<div class="col-md-6"><label class="form-label" for="end">Enrollment ends on</label><input type="date" id="end" name="ends_on" class="form-control" required min="<?= sanitize($year['starts_on']) ?>" max="<?= sanitize($year['ends_on']) ?>" value="<?= sanitize($year['ends_on']) ?>"></div></div>
<button class="btn btn-primary" type="submit">Create Annual Enrollment</button></div></form>
<?php include '../includes/footer.php'; ?>
