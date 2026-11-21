<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/student_access.php';
requireStudentManagement();
if (!isAdmin()) { http_response_code(403); exit('Enrollment is restricted to administrators. Submit a learner change request.'); }
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
require_once '../includes/returning_student_search.php';
$search=trim(mb_substr(is_string($_GET['search'] ?? null) ? $_GET['search'] : '',0,100));
$selectedStudentId=(int)(filter_var($_GET['student_id'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0);
$selectedStudent=$selectedStudentId ? selectReturningStudent($db,currentUser(),$selectedStudentId) : null;
$searchResults=searchReturningStudents($db,currentUser(),$search);
$studentName=static fn(array $row): string=>trim($row['last_name'].', '.$row['first_name'].' '.($row['middle_name'] ?? ''));
include '../includes/header.php';
$pageEnrollmentNoticeInline = true;
include '../includes/sidebar.php';
?>
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/student_form.css">
<div class="students-page workflow-page student-form-page">
<?php showFlash(); ?>
<nav aria-label="Breadcrumb" class="student-breadcrumb"><a href="students.php">Students</a><span aria-hidden="true">/</span><span>Returning Student Enrollment</span></nav>
<header class="student-form-heading">
    <div><h1>Returning Student Enrollment</h1><p>Enroll an existing student using their LRN and QR identity.</p></div>
    <a href="students.php" class="btn btn-outline-primary"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back to Students</a>
</header>
<nav class="student-mode" aria-label="Enrollment type">
    <a href="add.php?school_year_id=<?= $yearId ?>"><i class="bi bi-person-plus" aria-hidden="true"></i>New Student</a>
    <a class="active" href="enroll.php?school_year_id=<?= $yearId ?>" aria-current="page"><i class="bi bi-people" aria-hidden="true"></i>Returning Student</a>
</nav>
<form method="get" id="student-year-picker">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') ?>">
    <?php if ($selectedStudent): ?><input type="hidden" name="student_id" value="<?= (int)$selectedStudent['id'] ?>"><?php endif; ?>
</form>
<form method="get" id="returning-student-search" action="enroll.php#returning-student-heading">
    <input type="hidden" name="school_year_id" value="<?= $yearId ?>">
</form>
<form method="post" action="save_enrollment.php" class="student-profile-form">
<?= csrfField() ?><input type="hidden" name="school_year_id" value="<?= $yearId ?>">
    <div class="student-form-layout">
        <div class="student-form-main">
            <section class="card students-card student-form-card mb-4" aria-labelledby="enrollment-heading">
                <div class="card-header student-card-title"><h2 id="enrollment-heading">Enrollment Details</h2><small><span class="text-danger">*</span> Required fields</small></div>
                <div class="card-body"><div class="row g-3">
                    <div class="col-md-6">
                        <label for="year" class="form-label">School Year</label>
                        <select id="year" name="school_year_id" form="student-year-picker" class="form-select" onchange="this.form.submit()" aria-describedby="year-help">
                            <?php foreach ($years as $row): ?>
                            <option value="<?= (int)$row['id'] ?>" <?= (int)$row['id'] === $yearId ? 'selected' : '' ?>><?= sanitize($row['label']) ?> (<?= sanitize($row['state']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <small id="year-help" class="student-field-help">This student will be enrolled in the selected school year. Choose the year before entering student details.</small>
                        <noscript><button form="student-year-picker" class="btn btn-outline-secondary mt-2">Choose Year</button></noscript>
                    </div>
                    <div class="col-md-6">
                        <label for="section" class="form-label">Section <span class="text-danger">*</span></label>
                        <select id="section" name="section_id" class="form-select" required>
                            <option value="">Choose Section</option>
                            <?php foreach ($sections as $section): ?>
                            <option value="<?= (int)$section['id'] ?>"><?= sanitize($section['grade_level'].' — '.$section['section_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="start">Start Date <span class="text-danger">*</span></label>
                        <input type="date" id="start" name="starts_on" class="form-control" required min="<?= sanitize($year['starts_on']) ?>" max="<?= sanitize($year['ends_on']) ?>" value="<?= sanitize($year['state'] === 'active' ? min($year['ends_on'],max(date('Y-m-d'),$year['starts_on'])) : $year['starts_on']) ?>" aria-describedby="start-help">
                        <small id="start-help" class="student-field-help">For late enrollment, use the student's actual first day.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="end">End Date <span class="text-danger">*</span></label>
                        <input type="date" id="end" name="ends_on" class="form-control" required min="<?= sanitize($year['starts_on']) ?>" max="<?= sanitize($year['ends_on']) ?>" value="<?= sanitize($year['ends_on']) ?>">
                    </div>
                </div></div>
            </section>
            <section class="card students-card student-form-card" aria-labelledby="returning-student-heading">
                <div class="card-header"><h2 id="returning-student-heading">Returning Student Details</h2></div>
                <div class="card-body">
                    <label for="student-search" class="form-label">Find Student by Name or LRN</label>
                    <div class="student-search-controls">
                        <input type="search" id="student-search" name="search" form="returning-student-search" class="form-control" value="<?= htmlspecialchars($search,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8') ?>" placeholder="Enter name or LRN" minlength="2" maxlength="100" aria-describedby="student-search-help">
                        <button type="submit" form="returning-student-search" class="btn btn-outline-primary"><i class="bi bi-search me-2" aria-hidden="true"></i>Search</button>
                    </div>
                    <small id="student-search-help" class="student-field-help">Enter at least 2 characters. <?= isAdmin() ? 'Archived matches must be restored before enrollment.' : 'Results include students from your authorized sections across school years. Ask an administrator if the student is unavailable.' ?></small>
                    <?php if ($selectedStudentId && !$selectedStudent): ?>
                    <p class="alert alert-warning mt-3" role="alert">The selected student is unavailable. Search again or contact an administrator.</p>
                    <?php endif; ?>
                    <?php if (mb_strlen($search)>=2): ?>
                    <div class="student-search-results mt-3" aria-label="Student search results">
                        <?php if (!$searchResults): ?>
                        <p class="mb-0">No matching students are available. Try another name or LRN.<?= isAdmin() ? ' Check Archived Students and the viewing school year if needed.' : ' Contact an administrator if the student belongs to another section.' ?></p>
                        <?php else: ?>
                        <p class="student-field-help mb-2"><?= count($searchResults) ?> match<?= count($searchResults)===1?'':'es' ?><?= count($searchResults)===20?' shown. Refine your search if needed.':'. Select the correct student.' ?></p>
                        <ul class="student-search-list">
                            <?php foreach ($searchResults as $match): ?>
                            <li>
                                <div><strong><?= sanitize($studentName($match)) ?></strong><small class="student-field-help">LRN <?= sanitize($match['lrn']) ?></small></div>
                                <?php if ((int)$match['is_active']): ?>
                                <a class="btn btn-outline-primary" href="enroll.php?<?= sanitize(http_build_query(['school_year_id'=>$yearId,'search'=>$search,'student_id'=>(int)$match['id']])) ?>#returning-student-heading" aria-label="<?= sanitize('Select '.$studentName($match).' (LRN '.$match['lrn'].')') ?>">Select</a>
                                <?php else: ?>
                                <div class="student-archived-status"><strong>Archived</strong><small class="student-field-help">Restore in <a href="archived.php?<?= sanitize(http_build_query(['search'=>$match['lrn']])) ?>">Archived Students</a> first.</small></div>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <?php if ($selectedStudent): ?>
                    <div class="student-selected-identity mt-3" role="status">
                        <i class="bi bi-person-check me-2" aria-hidden="true"></i><strong><?= sanitize($studentName($selectedStudent)) ?></strong>
                        <span>selected</span>
                        <a href="enroll.php?<?= sanitize(http_build_query(['school_year_id'=>$yearId,'search'=>$search])) ?>#returning-student-heading">Choose Another Student</a>
                    </div>
                    <?php endif; ?>
                    <div class="row g-3 mt-2"><div class="col-md-6">
                        <label for="lrn" class="form-label">Existing LRN <span class="text-danger">*</span></label>
                        <input id="lrn" name="lrn" maxlength="20" inputmode="numeric" class="form-control" placeholder="Enter existing LRN" value="<?= sanitize($selectedStudent['lrn'] ?? '') ?>" <?= $selectedStudent?'readonly':'' ?> required autocomplete="off" aria-describedby="lrn-help">
                        <small id="lrn-help" class="student-field-help"><?= $selectedStudent ? 'Filled from the selected student record.' : 'Select a search result to fill the LRN, or enter a known LRN directly.' ?></small>
                    </div></div>
                </div>
            </section>
        </div>
        <aside class="student-form-sidebar" aria-label="Existing student identity">
            <section class="card students-card student-form-card">
                <div class="card-header"><h2>Student Identity</h2></div>
                <div class="card-body text-center">
                    <img src="<?= BASE_URL ?>assets/img/student-placeholder.svg" class="student-photo-preview" alt="" width="120" height="120">
                    <p class="student-field-help">Use the student's existing LRN to keep their profile and enrollment history together.</p>
                    <div class="student-qr-identity">
                        <i class="bi bi-qr-code-scan" aria-hidden="true"></i><h3>QR Identity</h3>
                        <p class="student-field-help mb-0">The student's existing QR identity is retained.</p>
                    </div>
                </div>
            </section>
        </aside>
    </div>
    <div class="student-form-actions">
        <p>Review the details before enrolling.</p>
        <div class="student-action-buttons">
            <a href="students.php" class="btn btn-outline-secondary">Cancel</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-check-lg me-2" aria-hidden="true"></i>Enroll Student</button>
        </div>
    </div>
</form>
</div>
<?php include '../includes/footer.php'; ?>
