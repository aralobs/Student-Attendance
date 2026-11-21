<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_once '../includes/absence_interventions.php';
require_once '../includes/enrollment_reports.php';
requireStaff();
require_once '../includes/adviser_workflows.php';
$db = getDB();
$pageTitle = 'Attendance Interventions';
$rows = [];
$followups=[];$followupAvailable=panelTableAvailable($db,'intervention_followups');
$error = null;
$years = $sections = $allSections = $grades = [];
$yearId = (int)($_GET['school_year_id'] ?? 0);
$sectionId = (int)($_GET['section'] ?? 0);
$gradeLevel = trim((string)($_GET['grade'] ?? ''));
$date = trim((string)($_GET['date'] ?? ''));
$typeFilter = (string)($_GET['type'] ?? '');
$typeOptions = ['warning'=>'Teacher warning', 'intervention_required'=>'Intervention required', 'needs_review'=>'Needs review', 'normal'=>'Below warning threshold'];
try {
    requirePanelFeatures($db);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        requireCsrfPost();
        saveInterventionFollowup($db,currentUser(),(int)($_POST['episode_id']??0),(string)($_POST['outcome']??''),(string)($_POST['note']??''));
        setFlash('success','Intervention follow-up saved.');
        header('Location: interventions.php');exit;
    }
    [$scope, $bindings] = enrollmentSectionScope('sec', currentUser(), true);
    $q = $db->prepare("SELECT DISTINCT y.id,y.label,y.starts_on FROM school_years y JOIN sections sec ON sec.school_year_id=y.id WHERE ($scope) ORDER BY y.starts_on DESC");
    $q->execute($bindings);
    $years = $q->fetchAll();
    $viewingYear=viewingSchoolYear($db,currentUser());
    $yearId=(int)$viewingYear['id'];
    ob_start(); renderViewingSchoolYearControl($db,currentUser(),$viewingYear); $pageSchoolYearControl=ob_get_clean();
    if (!in_array($yearId, array_map('intval', array_column($years, 'id')), true)) throw new DomainException('School-year access denied.');
    $allSections = enrollmentReportSections($db, currentUser(), $yearId);
    $grades = array_values(array_unique(array_column($allSections, 'grade_level')));
    if ($gradeLevel !== '' && !in_array($gradeLevel, $grades, true)) throw new InvalidArgumentException('Grade access denied.');
    if ($sectionId && !in_array($sectionId, array_map('intval', array_column($allSections, 'id')), true)) throw new DomainException('Section access denied.');
    $sections = array_values(array_filter($allSections, static fn($s) => $gradeLevel === '' || $s['grade_level'] === $gradeLevel));
    if ($sectionId && !in_array($sectionId, array_map('intval', array_column($sections, 'id')), true)) $sectionId = 0;
    if ($typeFilter !== '' && !isset($typeOptions[$typeFilter])) throw new InvalidArgumentException('Invalid intervention status.');
    if ($date !== '') validatedSchoolYearDate($date);
    $filters = ['e.school_year_id=?'];
    $params = array_merge($bindings, [$yearId]);
    if ($gradeLevel !== '') { $filters[] = 'sec.grade_level=?'; $params[] = $gradeLevel; }
    if ($sectionId) { $filters[] = 'sec.id=?'; $params[] = $sectionId; }
    if ($typeFilter !== '') { $filters[] = 'ep.state=?'; $params[] = $typeFilter; }
    if ($date !== '') {
        $filters[] = '(? BETWEEN ep.starts_on AND ep.ends_on OR ep.blocked_on=?)';
        $params[] = $date; $params[] = $date;
    }
    $filterSql = implode(' AND ', $filters);
    $q = $db->prepare("SELECT ep.*,s.first_name,s.last_name,sec.section_name,n.status AS notification_status,n.superseded FROM absence_episodes ep JOIN student_enrollments e ON e.id=ep.enrollment_id JOIN students s ON s.id=e.student_id JOIN enrollment_sections es ON es.enrollment_id=e.id AND ep.ends_on BETWEEN es.starts_on AND es.ends_on JOIN sections sec ON sec.id=es.section_id LEFT JOIN absence_notifications n ON n.notification_owner=ep.notification_owner WHERE ep.active=1 AND ($scope) AND (ep.state IN('warning','intervention_required','needs_review') OR n.status IS NOT NULL) AND $filterSql ORDER BY ep.ends_on DESC");
    $q->execute($params);
    $rows = $q->fetchAll();
    foreach(loadInterventionFollowups($db,currentUser(),array_column($rows,'id')) as $note) $followups[$note['episode_id']][]=$note;
} catch (DomainException|InvalidArgumentException $e) {
    http_response_code(400);
    $error = $e->getMessage();
} catch (Throwable $e) {
    $error = 'Intervention monitoring is not available yet. Contact the administrator.';
}
$states = array_count_values(array_column($rows, 'state'));
$metrics = [
    ['Evaluated cases', count($rows), 'text-secondary'],
    ['Teacher warning', $states['warning'] ?? 0, 'text-warning'],
    ['Intervention required', $states['intervention_required'] ?? 0, 'text-danger'],
    ['Needs review', $states['needs_review'] ?? 0, 'text-primary'],
];
$stateColors = ['warning' => 'warning text-dark', 'intervention_required' => 'danger', 'needs_review' => 'primary'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>
<?php showFlash(); ?>
<div class="card interventions-card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h1 class="h6 mb-0"><i class="bi bi-exclamation-triangle-fill me-2 text-primary" aria-hidden="true"></i>Attendance Interventions</h1>
        <a class="btn btn-sm btn-primary" href="attendance.php"><i class="bi bi-table me-1" aria-hidden="true"></i>Attendance Sheet</a>
    </div>
    <div class="card-body">
        <?php if ($error): ?>
        <div class="alert alert-warning mb-4" role="alert"><?= sanitize($error) ?></div>
        <?php endif; ?>
        <div class="row g-2 mb-4">
            <?php foreach ($metrics as [$label, $value, $color]): ?>
            <div class="col-6 col-md-3">
                <div class="metric-tile p-3 text-center h-100">
                    <div class="metric-label"><?= sanitize($label) ?></div>
                    <div class="metric-value <?= $color ?>"><?= $error ? '—' : number_format($value) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <h2 class="section-divider"><i class="bi bi-shield-check" aria-hidden="true"></i>Intervention Policy</h2>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="metric-tile p-3 h-100">
                    <h3 class="small fw-600 mb-2 text-muted"><i class="bi bi-exclamation-triangle me-1 text-warning" aria-hidden="true"></i>Teacher warning</h3>
                    <p class="small mb-0">Teacher warning at <strong>3 unexcused absences</strong>.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-tile p-3 h-100">
                    <h3 class="small fw-600 mb-2 text-muted"><i class="bi bi-person-exclamation me-1 text-danger" aria-hidden="true"></i>Intervention required</h3>
                    <p class="small mb-0">School intervention at <strong>5 unexcused absences</strong>.</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="metric-tile p-3 h-100">
                    <h3 class="small fw-600 mb-2 text-muted"><i class="bi bi-clipboard-check me-1 text-primary" aria-hidden="true"></i>Review &amp; follow-up</h3>
                    <p class="small mb-0">Unresolved dates require review. Failed or unconfirmed SMS requires direct follow-up; it is not automatically retried.</p>
                </div>
            </div>
        </div>
        <p class="small text-muted mb-2">Excused days do not increment or reset the unexcused streak. Unresolved attendance pauses the count, so a case needing review can show 0 counted unexcused days. The first unresolved date can precede the episode. Separate episodes for the same learner are listed individually. This view is updated by scheduled attendance evaluation.</p>
        <p id="interventionFilterHelp" class="small text-muted mb-0">Totals reflect these filters. Leave Date empty to see all cases in the school year; selecting a date finds episodes covering that date or cases with that first unresolved date.</p>
    </div>
</div>

<div class="card interventions-card mb-4">
    <div class="card-header d-flex align-items-center flex-wrap gap-2">
        <h2 class="h6 mb-0"><i class="bi bi-people-fill me-2 text-primary" aria-hidden="true"></i>Intervention Cases</h2>
    </div>
    <div class="p-3 border-bottom">
        <form method="get" class="attendance-filters d-flex flex-wrap align-items-end gap-2">
            <input type="hidden" name="school_year_id" value="<?= $yearId ?>">
            <div><label for="attendanceDate">Date (optional)</label><input id="attendanceDate" type="date" class="form-control form-control-sm" name="date" value="<?= sanitize($date) ?>" aria-describedby="interventionFilterHelp"></div>
            <div><label for="attendanceGrade">Grade</label><select id="attendanceGrade" class="form-select form-select-sm" name="grade"><option value="">All Grades</option><?php foreach ($grades as $grade): ?><option value="<?= sanitize($grade) ?>" <?= $gradeLevel===$grade?'selected':'' ?>><?= sanitize($grade) ?></option><?php endforeach; ?></select></div>
            <div><label for="attendanceSection">Section</label><select id="attendanceSection" class="form-select form-select-sm" name="section"><option value="0" <?= !$sectionId?'selected':'' ?>>All Sections</option><?php foreach ($sections as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id']===$sectionId?'selected':'' ?>><?= sanitize($s['grade_level'].' — '.$s['section_name']) ?></option><?php endforeach; ?></select></div>
            <div><label for="attendanceType">Status</label><select id="attendanceType" class="form-select form-select-sm" name="type"><option value="">All Statuses</option><?php foreach ($typeOptions as $key=>$label): ?><option value="<?= sanitize($key) ?>" <?= $typeFilter===$key?'selected':'' ?>><?= sanitize($label) ?></option><?php endforeach; ?></select></div>
            <div class="filter-action d-flex gap-2"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filter</button><a class="btn btn-sm btn-outline-secondary" href="interventions.php?<?= sanitize(http_build_query(['school_year_id'=>$yearId])) ?>">Reset</a></div>
        </form>
    </div>
    <div class="table-responsive">
        <table data-list-search="Search student name, section, date or intervention status..." data-list-title="Intervention Cases" class="table table-hover table-sm mb-0">
            <thead class="sticky-thead">
                <tr><th scope="col">Learner</th><th scope="col">Section</th><th scope="col">Episode dates</th><th scope="col" class="text-center">Counted unexcused</th><th scope="col">State</th><th scope="col">Parent SMS</th><th scope="col">Follow-up</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="fw-600 small"><?= sanitize($r['last_name'] . ', ' . $r['first_name']) ?></td>
                    <td class="small text-muted"><?= sanitize($r['section_name']) ?></td>
                    <td class="small text-muted"><?= sanitize($r['starts_on'] . ' through ' . $r['ends_on']) ?></td>
                    <td class="text-center"><span class="badge bg-<?= $stateColors[$r['state']] ?? 'secondary' ?>"><?= (int)$r['absence_count'] ?>d</span></td>
                    <td>
                        <span class="badge bg-<?= $stateColors[$r['state']] ?? 'secondary' ?>"><?= sanitize(ucwords(str_replace('_', ' ', $r['state']))) ?></span>
                        <?php if ($r['blocked_on']): ?><div class="small text-muted mt-1">First unresolved date: <?= sanitize($r['blocked_on']) ?></div><?php endif; ?>
                    </td>
                    <td class="small">
                        <?= sanitize(ucfirst($r['notification_status'] ?? 'Not sent')) ?>
                        <?php if ($r['superseded']): ?><div class="text-muted">Superseded by correction</div><?php endif; ?>
                    </td>
                    <td class="small">
                    <?php foreach($followups[$r['id']]??[] as $note): ?><details class="mb-2"><summary><?= sanitize((interventionFollowupOutcomes()[$note['outcome']]??$note['outcome']).' · '.$note['created_at']) ?></summary><p style="white-space:pre-wrap"><?= sanitize($note['note']) ?></p><small>Staff #<?= (int)$note['recorded_by'] ?></small></details><?php endforeach; ?>
                    <?php if($followupAvailable && ($viewingYear['state']??'')==='active'): ?>
                    <details><summary>Record Follow-up</summary><form method="post" class="mt-2"><?= csrfField() ?><input type="hidden" name="episode_id" value="<?= (int)$r['id'] ?>"><label class="form-label" for="outcome-<?= (int)$r['id'] ?>">Outcome</label><select id="outcome-<?= (int)$r['id'] ?>" name="outcome" class="form-select mb-2"><?php foreach(interventionFollowupOutcomes() as $value=>$label): ?><option value="<?= $value ?>"><?= sanitize($label) ?></option><?php endforeach; ?></select><label class="form-label" for="note-<?= (int)$r['id'] ?>">Follow-up note</label><textarea id="note-<?= (int)$r['id'] ?>" name="note" class="form-control mb-2" maxlength="4000" required></textarea><button class="btn btn-sm btn-primary">Save Follow-up</button><p class="small text-muted mt-2">This records staff action and sends no SMS. Attendance evidence and absence counts are preserved.</p></form></details>
                    <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-inbox me-1" aria-hidden="true"></i><?= $error ? 'Intervention cases are currently unavailable.' : 'No evaluated intervention cases match these filters.' ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<script id="attendanceSectionData" type="application/json"><?= json_encode(array_map(static fn($s)=>['id'=>(int)$s['id'],'grade'=>$s['grade_level'],'name'=>$s['section_name']],$allSections),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>assets/js/attendance_filters.js" defer></script>
<?php include '../includes/footer.php'; ?>
